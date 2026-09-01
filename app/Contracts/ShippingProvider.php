<?php

namespace App\Contracts;

use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;

interface ShippingProvider
{
    public function provider(): string;

    /**
     * @return list<array{service_code:string,label:string,amount_minor:int,currency:string,eta_min_days:?int,eta_max_days:?int,expires_at:\DateTimeInterface}>
     */
    public function quotes(User $user, Cart $cart, CustomerAddress $address, int $subtotalMinor, string $currency): array;

    /** @return array{provider_reference:?string,tracking_number:?string,status:string} */
    public function createFulfillment(Order $order, Shipment $shipment): array;
}
