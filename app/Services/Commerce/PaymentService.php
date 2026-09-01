<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\PaymentReconciliation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentService
{
    public function __construct(
        private readonly PaymentGateway $gateway,
        private readonly OrderStateService $orders,
        private readonly ShippingService $shipping,
    ) {}

    /** @return array<string, mixed> */
    public function initiate(User $user, Order $order, string $idempotencyKey): array
    {
        if ($order->user_id !== $user->id) {
            throw new RuntimeException('Order does not belong to this customer.');
        }
        if ($order->status !== 'awaiting_payment') {
            throw new RuntimeException('Order is not awaiting payment.');
        }
        if ($order->reservation_expires_at?->isPast()) {
            throw new RuntimeException('Order reservation has expired.');
        }
        if ($this->gateway->provider() === 'disabled') {
            throw new RuntimeException('Payment provider is not enabled.');
        }

        $fingerprint = hash('sha256', json_encode([
            'order_id' => $order->public_id,
            'amount_minor' => (int) $order->total_minor,
            'currency' => $order->currency,
            'provider' => $this->gateway->provider(),
        ], JSON_THROW_ON_ERROR));

        $attempt = DB::transaction(function () use ($order, $idempotencyKey, $fingerprint): PaymentAttempt {
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== 'awaiting_payment') {
                throw new RuntimeException('Order payment state changed.');
            }

            $existing = PaymentAttempt::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing !== null) {
                if ($existing->request_fingerprint !== $fingerprint || $existing->order_id !== $lockedOrder->id) {
                    throw new RuntimeException('Payment idempotency key was reused with different input.');
                }

                return $existing;
            }

            return PaymentAttempt::query()->create([
                'public_id' => (string) Str::uuid(),
                'order_id' => $lockedOrder->id,
                'provider' => $this->gateway->provider(),
                'idempotency_key' => $idempotencyKey,
                'request_fingerprint' => $fingerprint,
                'currency' => $lockedOrder->currency,
                'amount_minor' => $lockedOrder->total_minor,
                'status' => 'initiating',
                'started_at' => now(),
            ]);
        }, 3);

        if (in_array($attempt->status, ['pending', 'paid'], true)) {
            return $this->payload($attempt);
        }
        if ($attempt->status === 'failed') {
            throw new RuntimeException('This payment attempt has already failed; use a new idempotency key.');
        }

        try {
            $provider = $this->gateway->initiate($order->fresh(), $attempt);
            $attempt->forceFill([
                'status' => 'pending',
                'authority' => $provider['authority'],
                'provider_code' => $provider['provider_code'],
            ])->save();
        } catch (Throwable $exception) {
            $attempt->forceFill(['status' => 'failed', 'failed_at' => now()])->save();
            throw $exception;
        }

        return $this->payload($attempt->refresh(), $provider['redirect_url']);
    }

    /** @return array<string, mixed> */
    public function verify(User $user, PaymentAttempt $attempt, string $authority, string $callbackStatus): array
    {
        $attempt->loadMissing('order');
        if ($attempt->order->user_id !== $user->id) {
            throw new RuntimeException('Payment attempt does not belong to this customer.');
        }
        if ($attempt->provider !== $this->gateway->provider()) {
            throw new RuntimeException('Payment provider mismatch.');
        }
        if ($attempt->status === 'paid') {
            return $this->payload($attempt);
        }
        if (! in_array($attempt->status, ['pending', 'initiating'], true)) {
            throw new RuntimeException('Payment attempt cannot be verified in its current state.');
        }

        $result = $this->gateway->verify($attempt->order, $attempt, $authority, $callbackStatus);
        if (! $result['verified']) {
            if ($result['reconciliation_required']) {
                $this->recordReconciliation($attempt, 'paid', 'unknown', 'manual_review', $result['provider_code'], null);
            } else {
                $attempt->forceFill([
                    'status' => 'failed',
                    'provider_code' => $result['provider_code'],
                    'failed_at' => now(),
                ])->save();
            }

            return $this->payload($attempt->refresh());
        }

        $paid = $this->markPaid($attempt, $result['reference_id'], $result['provider_code']);
        $this->shipping->ensureShipment($paid->order()->firstOrFail());

        return $this->payload($paid->refresh());
    }

    /** @return array<string, mixed> */
    public function reconcile(User $user, PaymentAttempt $attempt): array
    {
        $attempt->loadMissing('order');
        if ($attempt->order->user_id !== $user->id) {
            throw new RuntimeException('Payment attempt does not belong to this customer.');
        }
        if ($attempt->provider !== $this->gateway->provider()) {
            throw new RuntimeException('Payment provider mismatch.');
        }

        $observed = $this->gateway->reconcile($attempt->order, $attempt);
        $outcome = $observed['observed_status'] === 'paid' ? 'matched' : 'manual_review';
        $this->recordReconciliation(
            $attempt,
            $attempt->order->status === 'paid' ? 'paid' : 'awaiting_payment',
            $observed['observed_status'],
            $outcome,
            $observed['provider_code'],
            $observed['payload_hash'],
        );

        if ($observed['observed_status'] === 'paid' && $attempt->status !== 'paid') {
            $verification = $this->gateway->verify($attempt->order, $attempt, (string) $attempt->authority, 'OK');
            if ($verification['verified']) {
                $paid = $this->markPaid($attempt, $verification['reference_id'], $verification['provider_code']);
                $this->shipping->ensureShipment($paid->order()->firstOrFail());
                $attempt = $paid;
            }
        }

        return $this->payload($attempt->refresh());
    }

    private function markPaid(PaymentAttempt $attempt, ?string $referenceId, string $providerCode): PaymentAttempt
    {
        return DB::transaction(function () use ($attempt, $referenceId, $providerCode): PaymentAttempt {
            $lockedAttempt = PaymentAttempt::query()->whereKey($attempt->id)->lockForUpdate()->firstOrFail();
            $order = Order::query()->whereKey($lockedAttempt->order_id)->lockForUpdate()->firstOrFail();

            if ($lockedAttempt->status === 'paid') {
                return $lockedAttempt;
            }
            if ($order->status !== 'awaiting_payment') {
                throw new RuntimeException('Order can no longer accept payment.');
            }
            if ($order->reservation_expires_at?->isPast()) {
                throw new RuntimeException('Order reservation expired before payment verification.');
            }
            if ((int) $lockedAttempt->amount_minor !== (int) $order->total_minor || $lockedAttempt->currency !== $order->currency) {
                throw new RuntimeException('Payment amount or currency no longer matches the order.');
            }

            $lockedAttempt->forceFill([
                'status' => 'paid',
                'reference_id' => $referenceId,
                'provider_code' => $providerCode,
                'verified_at' => now(),
                'failed_at' => null,
            ])->save();
            $this->orders->transition($order, 'paid', 'payment_verified', [
                'payment_attempt_id' => $lockedAttempt->public_id,
                'provider' => $lockedAttempt->provider,
                'reference_id' => $referenceId,
            ]);

            return $lockedAttempt->refresh();
        }, 3);
    }

    private function recordReconciliation(PaymentAttempt $attempt, string $expected, string $observed, string $outcome, string $providerCode, ?string $payloadHash): void
    {
        PaymentReconciliation::query()->create([
            'order_id' => $attempt->order_id,
            'payment_attempt_id' => $attempt->id,
            'expected_status' => $expected,
            'observed_status' => $observed,
            'outcome' => $outcome.':'.$providerCode,
            'payload_hash' => $payloadHash,
            'reconciled_at' => now(),
        ]);
    }

    /** @return array<string, mixed> */
    public function payload(PaymentAttempt $attempt, ?string $redirectUrl = null): array
    {
        if ($redirectUrl === null && $attempt->provider === 'zarinpal' && is_string($attempt->authority) && $attempt->authority !== '') {
            $redirectUrl = rtrim((string) config('commerce.payment.zarinpal.gateway_url'), '/').'/'.$attempt->authority;
        }

        return [
            'id' => $attempt->public_id,
            'order_id' => $attempt->order?->public_id ?? $attempt->order()->value('public_id'),
            'provider' => $attempt->provider,
            'status' => $attempt->status,
            'currency' => $attempt->currency,
            'amount_minor' => $attempt->amount_minor,
            'redirect_url' => $attempt->status === 'pending' ? $redirectUrl : null,
            'reference_id' => $attempt->status === 'paid' ? $attempt->reference_id : null,
            'verified_at' => $attempt->verified_at?->toISOString(),
        ];
    }
}
