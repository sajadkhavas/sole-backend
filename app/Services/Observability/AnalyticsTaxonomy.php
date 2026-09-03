<?php

namespace App\Services\Observability;

use DomainException;

class AnalyticsTaxonomy
{
    public const VERSION = 1;

    public const ROUTES = ['home', 'catalog', 'product', 'cart', 'checkout', 'account', 'wishlist', 'orders', 'content', 'other'];

    public const CLIENT_EVENTS = ['catalog_view', 'product_view', 'checkout_view', 'client_error', 'rum_lcp', 'rum_inp', 'rum_cls', 'rum_ttfb'];

    public const SERVER_EVENTS = ['cart_engaged', 'order_created', 'payment_paid', 'experiment_exposure'];

    public const EXPERIMENT_METRICS = [
        'catalog_to_product_rate', 'product_to_cart_rate', 'cart_to_checkout_rate', 'checkout_to_order_rate',
        'order_to_paid_rate', 'rum_lcp', 'rum_inp', 'rum_cls',
    ];

    /** @param array<string, mixed> $properties @return array<string, scalar> */
    public function sanitizeClient(string $eventName, array $properties): array
    {
        if (! in_array($eventName, self::CLIENT_EVENTS, true)) {
            throw new DomainException('ANALYTICS_EVENT_NOT_ALLOWED');
        }

        return match ($eventName) {
            'catalog_view' => $this->pickEnums($properties, [
                'result_band' => ['0', '1_10', '11_30', '31_plus'],
                'source' => ['navigation', 'search', 'filter'],
            ]),
            'product_view' => $this->pickEnums($properties, ['availability' => ['in_stock', 'out_of_stock', 'unknown']]),
            'checkout_view' => $this->pickEnums($properties, ['cart_size_band' => ['1', '2_3', '4_plus']]),
            'client_error' => $this->pickEnums($properties, ['kind' => ['window_error', 'unhandled_rejection', 'route_boundary']]),
            'rum_lcp' => $this->rum($properties, 0, 60_000),
            'rum_inp' => $this->rum($properties, 0, 60_000),
            'rum_cls' => $this->rum($properties, 0, 10),
            'rum_ttfb' => $this->rum($properties, 0, 60_000),
            default => [],
        };
    }

    /** @param array<string, mixed> $properties */
    private function rum(array $properties, float $minimum, float $maximum): array
    {
        $value = $properties['value'] ?? null;
        if (! is_int($value) && ! is_float($value)) {
            throw new DomainException('ANALYTICS_RUM_VALUE_REQUIRED');
        }
        $value = (float) $value;
        if (! is_finite($value) || $value < $minimum || $value > $maximum) {
            throw new DomainException('ANALYTICS_RUM_VALUE_INVALID');
        }

        $metadata = $properties;
        unset($metadata['value']);
        $enums = $this->pickEnums($metadata, [
            'rating' => ['good', 'needs_improvement', 'poor'],
            'navigation_type' => ['navigate', 'reload', 'back_forward', 'prerender'],
        ]);

        return ['value' => round($value, 3), ...$enums];
    }

    /** @param array<string, mixed> $properties @param array<string, list<string>> $rules @return array<string, string> */
    private function pickEnums(array $properties, array $rules): array
    {
        $clean = [];
        foreach ($rules as $key => $allowed) {
            if (! array_key_exists($key, $properties)) {
                continue;
            }
            $value = $properties[$key];
            if (! is_string($value) || ! in_array($value, $allowed, true)) {
                throw new DomainException("ANALYTICS_PROPERTY_INVALID:{$key}");
            }
            $clean[$key] = $value;
        }
        if (array_diff(array_keys($properties), array_keys($rules)) !== []) {
            throw new DomainException('ANALYTICS_PROPERTY_NOT_ALLOWED');
        }

        return $clean;
    }
}
