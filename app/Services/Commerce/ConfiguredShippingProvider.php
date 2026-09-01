<?php

namespace App\Services\Commerce;

use App\Contracts\ShippingProvider;
use App\Models\BusinessSetting;
use App\Models\Cart;
use App\Models\CustomerAddress;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use RuntimeException;

class ConfiguredShippingProvider implements ShippingProvider
{
    public function provider(): string
    {
        return 'configured';
    }

    public function quotes(User $user, Cart $cart, CustomerAddress $address, int $subtotalMinor, string $currency): array
    {
        $policy = BusinessSetting::query()->where('key', 'shipping_provider_policy')->first()?->value;

        if (! is_array($policy) || ! isset($policy['services']) || ! is_array($policy['services'])) {
            throw new RuntimeException('Authoritative shipping provider policy is not configured.');
        }

        $ttl = filter_var($policy['quote_ttl_minutes'] ?? 15, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 5, 'max_range' => 60],
        ]);
        if ($ttl === false) {
            throw new RuntimeException('Shipping quote TTL is invalid.');
        }

        $quotes = [];
        foreach ($policy['services'] as $service) {
            if (! is_array($service)) {
                continue;
            }

            $allowed = $service['allowed_country_codes'] ?? [];
            if (! is_array($allowed) || ! in_array($address->country_code, $allowed, true)) {
                continue;
            }

            $serviceCurrency = strtoupper((string) ($service['currency'] ?? ''));
            $amount = filter_var($service['amount_minor'] ?? null, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 0],
            ]);
            $code = trim((string) ($service['code'] ?? ''));
            $label = trim((string) ($service['label'] ?? ''));

            if ($serviceCurrency !== strtoupper($currency) || $amount === false || $code === '' || $label === '') {
                continue;
            }

            $freeThreshold = $service['free_threshold_minor'] ?? null;
            if (is_int($freeThreshold) && $subtotalMinor >= $freeThreshold) {
                $amount = 0;
            }

            $etaMin = isset($service['eta_min_days']) ? (int) $service['eta_min_days'] : null;
            $etaMax = isset($service['eta_max_days']) ? (int) $service['eta_max_days'] : null;
            if (($etaMin !== null && $etaMin < 0) || ($etaMax !== null && $etaMax < 0) || ($etaMin !== null && $etaMax !== null && $etaMax < $etaMin)) {
                throw new RuntimeException('Shipping ETA policy is invalid.');
            }

            $quotes[] = [
                'service_code' => $code,
                'label' => $label,
                'amount_minor' => $amount,
                'currency' => $serviceCurrency,
                'eta_min_days' => $etaMin,
                'eta_max_days' => $etaMax,
                'expires_at' => now()->addMinutes($ttl),
            ];
        }

        if ($quotes === []) {
            throw new RuntimeException('No authoritative shipping service is eligible for this address.');
        }

        return $quotes;
    }

    public function createFulfillment(Order $order, Shipment $shipment): array
    {
        return [
            'provider_reference' => null,
            'tracking_number' => $shipment->tracking_number,
            'status' => 'pending',
        ];
    }
}
