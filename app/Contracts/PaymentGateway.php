<?php

namespace App\Contracts;

use App\Models\Order;
use App\Models\PaymentAttempt;
use App\Models\RefundRequest;

interface PaymentGateway
{
    public function provider(): string;

    /** @return array{authority:string,redirect_url:string,provider_code:string} */
    public function initiate(Order $order, PaymentAttempt $attempt): array;

    /** @return array{verified:bool,reference_id:?string,provider_code:string,reconciliation_required:bool} */
    public function verify(Order $order, PaymentAttempt $attempt, string $authority, string $callbackStatus): array;

    /** @return array{observed_status:string,provider_code:string,payload_hash:?string} */
    public function reconcile(Order $order, PaymentAttempt $attempt): array;

    /** @return array{accepted:bool,provider_reference:?string,provider_code:string} */
    public function refund(Order $order, PaymentAttempt $attempt, RefundRequest $refund): array;
}
