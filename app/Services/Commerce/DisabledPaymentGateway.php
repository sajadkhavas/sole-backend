<?php

namespace App\Services\Commerce;

use App\Contracts\PaymentGateway;
use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RefundRequest;
use RuntimeException;

class DisabledPaymentGateway implements PaymentGateway
{
    public function provider(): string
    {
        return 'disabled';
    }

    public function initiate(Order $order, PaymentAttempt $attempt): array
    {
        throw new RuntimeException('Payment provider is not enabled.');
    }

    public function verify(Order $order, PaymentAttempt $attempt, string $authority, string $callbackStatus): array
    {
        throw new RuntimeException('Payment provider is not enabled.');
    }

    public function reconcile(Order $order, PaymentAttempt $attempt): array
    {
        throw new RuntimeException('Payment provider is not enabled.');
    }

    public function refund(Order $order, PaymentAttempt $attempt, RefundRequest $refund): array
    {
        throw new RuntimeException('Payment provider is not enabled.');
    }
}
