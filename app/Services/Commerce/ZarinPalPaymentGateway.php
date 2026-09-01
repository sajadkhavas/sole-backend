<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RefundRequest;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ZarinPalPaymentGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'zarinpal';
    }

    public function initiate(Order $order, PaymentAttempt $attempt): array
    {
        $merchantId = $this->merchantId();
        $callbackUrl = $this->callbackUrl();

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->retry(2, 200, throw: false)
                ->post((string) config('commerce.payment.zarinpal.request_url'), [
                    'merchant_id' => $merchantId,
                    'amount' => $order->total_minor,
                    'currency' => $order->currency,
                    'callback_url' => $callbackUrl,
                    'description' => 'SOLE order '.$order->public_id,
                    'metadata' => [
                        'order_id' => $order->public_id,
                        'payment_attempt' => $attempt->public_id,
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Payment provider request timed out.', previous: $exception);
        }

        $code = data_get($response->json(), 'data.code');
        $authority = data_get($response->json(), 'data.authority');

        if (! $response->successful() || (int) $code !== 100 || ! is_string($authority) || $authority === '') {
            throw new RuntimeException('Payment provider did not accept the request.');
        }

        $gateway = rtrim((string) config('commerce.payment.zarinpal.gateway_url'), '/');

        return [
            'authority' => $authority,
            'redirect_url' => $gateway.'/'.$authority,
            'provider_code' => (string) $code,
        ];
    }

    public function verify(Order $order, PaymentAttempt $attempt, string $authority, string $callbackStatus): array
    {
        if (! hash_equals((string) $attempt->authority, $authority)) {
            throw new RuntimeException('Payment authority does not match the payment attempt.');
        }

        if (strtoupper($callbackStatus) !== 'OK') {
            return [
                'verified' => false,
                'reference_id' => null,
                'provider_code' => 'callback_not_ok',
                'reconciliation_required' => false,
            ];
        }

        try {
            $response = Http::acceptJson()
                ->asJson()
                ->timeout($this->timeout())
                ->retry(2, 200, throw: false)
                ->post((string) config('commerce.payment.zarinpal.verify_url'), [
                    'merchant_id' => $this->merchantId(),
                    'amount' => $order->total_minor,
                    'authority' => $authority,
                ]);
        } catch (ConnectionException $exception) {
            throw new RuntimeException('Payment verification timed out.', previous: $exception);
        }

        $code = (int) data_get($response->json(), 'data.code', 0);
        $reference = data_get($response->json(), 'data.ref_id');

        if ($response->successful() && $code === 100) {
            return [
                'verified' => true,
                'reference_id' => is_scalar($reference) ? (string) $reference : null,
                'provider_code' => '100',
                'reconciliation_required' => false,
            ];
        }

        if ($response->successful() && $code === 101) {
            return [
                'verified' => $attempt->status === 'paid',
                'reference_id' => $attempt->reference_id,
                'provider_code' => '101',
                'reconciliation_required' => $attempt->status !== 'paid',
            ];
        }

        return [
            'verified' => false,
            'reference_id' => null,
            'provider_code' => (string) ($code ?: $response->status()),
            'reconciliation_required' => true,
        ];
    }

    public function reconcile(Order $order, PaymentAttempt $attempt): array
    {
        if ($attempt->status === 'paid') {
            return [
                'observed_status' => 'paid',
                'provider_code' => (string) ($attempt->provider_code ?? 'local_verified'),
                'payload_hash' => null,
            ];
        }

        if (! is_string($attempt->authority) || $attempt->authority === '') {
            return [
                'observed_status' => 'not_initiated',
                'provider_code' => 'missing_authority',
                'payload_hash' => null,
            ];
        }

        $verification = $this->verify($order, $attempt, $attempt->authority, 'OK');

        return [
            'observed_status' => $verification['verified'] ? 'paid' : 'unknown',
            'provider_code' => $verification['provider_code'],
            'payload_hash' => hash('sha256', json_encode($verification, JSON_THROW_ON_ERROR)),
        ];
    }

    public function refund(Order $order, PaymentAttempt $attempt, RefundRequest $refund): array
    {
        return [
            'accepted' => false,
            'provider_reference' => null,
            'provider_code' => 'provider_refund_activation_deferred',
        ];
    }

    private function merchantId(): string
    {
        $merchantId = trim((string) config('commerce.payment.zarinpal.merchant_id'));
        if ($merchantId === '') {
            throw new RuntimeException('ZarinPal merchant ID is not configured.');
        }

        return $merchantId;
    }

    private function callbackUrl(): string
    {
        $callback = trim((string) config('commerce.payment.callback_url'));
        if ($callback === '') {
            throw new RuntimeException('Payment callback URL is not configured.');
        }

        if (app()->isProduction() && ! str_starts_with($callback, 'https://')) {
            throw new RuntimeException('Production payment callback URL must use HTTPS.');
        }

        return $callback;
    }

    private function timeout(): int
    {
        return max(2, min(20, (int) config('commerce.payment.timeout_seconds', 8)));
    }
}
