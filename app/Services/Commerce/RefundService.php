<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RefundRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class RefundService
{
    /** @return array<string, mixed> */
    public function request(User $user, Order $order, string $idempotencyKey, string $reason): array
    {
        if ($order->user_id !== $user->id) {
            throw new RuntimeException('Order does not belong to this customer.');
        }
        if (! in_array($order->status, ['paid', 'processing', 'fulfilled', 'cancelled'], true)) {
            throw new RuntimeException('Order is not eligible for a refund request.');
        }

        $allowedReasons = ['customer_cancellation', 'approved_return', 'damaged', 'wrong_item', 'other'];
        if (! in_array($reason, $allowedReasons, true)) {
            throw new RuntimeException('Refund reason is not supported.');
        }

        $refund = DB::transaction(function () use ($user, $order, $idempotencyKey, $reason): RefundRequest {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = RefundRequest::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->order_id !== $lockedOrder->id || $existing->user_id !== $user->id || $existing->reason !== $reason) {
                    throw new RuntimeException('Refund idempotency key was reused with different input.');
                }

                return $existing;
            }

            $payment = PaymentAttempt::query()
                ->where('order_id', $lockedOrder->id)
                ->where('status', 'paid')
                ->latest('verified_at')
                ->lockForUpdate()
                ->first();
            if ($payment === null) {
                throw new RuntimeException('No verified payment exists for this order.');
            }

            $alreadyReserved = (int) RefundRequest::query()
                ->where('order_id', $lockedOrder->id)
                ->whereIn('status', ['requested', 'processing', 'manual_review', 'completed'])
                ->sum('amount_minor');
            $remaining = max(0, (int) $payment->amount_minor - $alreadyReserved);
            if ($remaining <= 0) {
                throw new RuntimeException('No refundable amount remains on this order.');
            }

            return RefundRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $lockedOrder->id,
                'payment_attempt_id' => $payment->id,
                'user_id' => $user->id,
                'idempotency_key' => $idempotencyKey,
                'amount_minor' => $remaining,
                'reason' => $reason,
                'status' => 'requested',
                'requested_at' => now(),
            ]);
        }, 3);

        return $this->payload($refund);
    }

    public function transition(RefundRequest $refund, string $toStatus, ?string $providerReference = null): RefundRequest
    {
        $allowed = [
            'requested' => ['processing', 'manual_review', 'failed'],
            'processing' => ['completed', 'failed', 'manual_review'],
            'manual_review' => ['processing', 'completed', 'failed'],
            'completed' => [],
            'failed' => [],
        ];

        return DB::transaction(function () use ($refund, $toStatus, $providerReference, $allowed): RefundRequest {
            $locked = RefundRequest::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $toStatus) {
                return $locked;
            }
            if (! in_array($toStatus, $allowed[$locked->status] ?? [], true)) {
                throw new RuntimeException("Invalid refund transition from {$locked->status} to {$toStatus}.");
            }

            RefundRequest::withinStateTransition(function () use ($locked, $toStatus, $providerReference): void {
                $changes = ['status' => $toStatus];
                if ($providerReference !== null && trim($providerReference) !== '') {
                    $changes['provider_reference'] = trim($providerReference);
                }
                if ($toStatus === 'completed') {
                    $changes['completed_at'] = now();
                }
                if ($toStatus === 'failed') {
                    $changes['failed_at'] = now();
                }
                $locked->forceFill($changes)->save();
            });

            return $locked->refresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function payload(RefundRequest $refund): array
    {
        return [
            'id' => $refund->public_id,
            'order_id' => $refund->order?->public_id ?? $refund->order()->value('public_id'),
            'status' => $refund->status,
            'amount_minor' => $refund->amount_minor,
            'reason' => $refund->reason,
            'provider_reference' => $refund->provider_reference,
            'requested_at' => $refund->requested_at?->toISOString(),
            'completed_at' => $refund->completed_at?->toISOString(),
            'failed_at' => $refund->failed_at?->toISOString(),
        ];
    }
}
