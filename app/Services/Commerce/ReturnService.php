<?php

namespace App\Services\Commerce;

use App\Models\Order;
use App\Models\ReturnRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class ReturnService
{
    /** @return array<string, mixed> */
    public function request(User $user, Order $order, string $reason, ?string $reasonText): array
    {
        if ($order->user_id !== $user->id) {
            throw new RuntimeException('Order does not belong to this customer.');
        }
        if ($order->status !== 'fulfilled' || $order->shipment?->status !== 'delivered') {
            throw new RuntimeException('Returns are available only after confirmed delivery.');
        }

        $allowedReasons = ['size_or_fit', 'damaged', 'wrong_item', 'not_as_expected', 'other'];
        if (! in_array($reason, $allowedReasons, true)) {
            throw new RuntimeException('Return reason is not supported.');
        }
        if ($reason === 'other' && trim((string) $reasonText) === '') {
            throw new RuntimeException('A reason description is required.');
        }

        $request = DB::transaction(function () use ($user, $order, $reason, $reasonText): ReturnRequest {
            $locked = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            $existing = ReturnRequest::query()->where('order_id', $locked->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing;
            }

            return ReturnRequest::query()->create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $locked->id,
                'user_id' => $user->id,
                'status' => 'requested',
                'reason' => $reason,
                'reason_text' => $reasonText !== null ? trim($reasonText) : null,
                'requested_at' => now(),
            ]);
        }, 3);

        return $this->payload($request);
    }

    public function transition(ReturnRequest $request, string $toStatus): ReturnRequest
    {
        $allowed = [
            'requested' => ['approved', 'rejected'],
            'approved' => ['received', 'rejected'],
            'received' => ['closed'],
            'rejected' => [],
            'closed' => [],
        ];

        return DB::transaction(function () use ($request, $toStatus, $allowed): ReturnRequest {
            $locked = ReturnRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === $toStatus) {
                return $locked;
            }
            if (! in_array($toStatus, $allowed[$locked->status] ?? [], true)) {
                throw new RuntimeException("Invalid return transition from {$locked->status} to {$toStatus}.");
            }

            ReturnRequest::withinStateTransition(function () use ($locked, $toStatus): void {
                $attributes = ['status' => $toStatus];
                if ($toStatus === 'approved') {
                    $attributes['approved_at'] = now();
                }
                if ($toStatus === 'received') {
                    $attributes['received_at'] = now();
                }
                if (in_array($toStatus, ['closed', 'rejected'], true)) {
                    $attributes['closed_at'] = now();
                }
                $locked->forceFill($attributes)->save();
            });

            return $locked->refresh();
        }, 3);
    }

    /** @return array<string, mixed> */
    public function payload(ReturnRequest $request): array
    {
        return [
            'id' => $request->public_id,
            'order_id' => $request->order?->public_id ?? $request->order()->value('public_id'),
            'status' => $request->status,
            'reason' => $request->reason,
            'reason_text' => $request->reason_text,
            'requested_at' => $request->requested_at?->toISOString(),
            'approved_at' => $request->approved_at?->toISOString(),
            'received_at' => $request->received_at?->toISOString(),
            'closed_at' => $request->closed_at?->toISOString(),
        ];
    }
}
