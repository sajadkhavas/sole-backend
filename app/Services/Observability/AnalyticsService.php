<?php

namespace App\Services\Observability;

use App\Models\AnalyticsEvent;
use App\Models\ConsentRecord;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class AnalyticsService
{
    public function __construct(private readonly AnalyticsTaxonomy $taxonomy) {}

    public function hasConsent(User $user): bool
    {
        $record = ConsentRecord::query()
            ->where('user_id', $user->id)
            ->where('type', 'analytics')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->first();

        return $record?->granted === true;
    }

    /** @param array<string, mixed> $properties */
    public function recordClient(User $user, string $sessionId, string $eventName, string $routeName, array $properties, ?string $traceId): AnalyticsEvent
    {
        if (! $this->hasConsent($user)) {
            throw new \DomainException('ANALYTICS_CONSENT_REQUIRED');
        }
        if (! Str::isUuid($sessionId)) {
            throw new \DomainException('ANALYTICS_SESSION_INVALID');
        }
        if (! in_array($routeName, AnalyticsTaxonomy::ROUTES, true)) {
            throw new \DomainException('ANALYTICS_ROUTE_NOT_ALLOWED');
        }

        return AnalyticsEvent::query()->create([
            'session_id' => $sessionId,
            'taxonomy_version' => AnalyticsTaxonomy::VERSION,
            'event_name' => $eventName,
            'route_name' => $routeName,
            'properties' => $this->taxonomy->sanitizeClient($eventName, $properties),
            'trace_id' => $this->validTraceId($traceId),
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    /** @param array<string, scalar> $properties */
    public function recordAuthoritative(Request $request, string $eventName, string $routeName, array $properties = []): ?AnalyticsEvent
    {
        try {
            if (! in_array($eventName, AnalyticsTaxonomy::SERVER_EVENTS, true)) {
                return null;
            }
            $user = $request->user('sanctum') ?? $request->user();
            if (! $user instanceof User || ! $this->hasConsent($user)) {
                return null;
            }
            $sessionId = trim((string) $request->header('X-Sole-Analytics-Session'));
            if (! Str::isUuid($sessionId) || ! in_array($routeName, AnalyticsTaxonomy::ROUTES, true)) {
                return null;
            }
            foreach ($properties as $key => $value) {
                if (! is_string($key) || ! is_scalar($value) || strlen((string) $value) > 120) {
                    return null;
                }
            }
            $context = $request->attributes->get('sole.observability');
            $traceId = is_array($context) ? ($context['trace_id'] ?? null) : null;

            return AnalyticsEvent::query()->create([
                'session_id' => $sessionId,
                'taxonomy_version' => AnalyticsTaxonomy::VERSION,
                'event_name' => $eventName,
                'route_name' => $routeName,
                'properties' => $properties,
                'trace_id' => $this->validTraceId(is_string($traceId) ? $traceId : null),
                'occurred_at' => now(),
                'received_at' => now(),
            ]);
        } catch (Throwable $exception) {
            try {
                Log::channel('telemetry')->warning('analytics.authoritative_event_dropped', [
                    'event_name' => $eventName,
                    'exception_class' => $exception::class,
                ]);
            } catch (Throwable) {
                // Observability must remain non-blocking.
            }

            return null;
        }
    }

    public function recordOutcomeFromResponse(Request $request, Response $response): void
    {
        $route = $request->route()?->getName();
        $status = $response->getStatusCode();

        if ($route === 'api.v1.commerce.cart.items.put' && $status >= 200 && $status < 300) {
            $this->recordAuthoritative($request, 'cart_engaged', 'cart');
            return;
        }
        if ($route === 'api.v1.commerce.checkout.create' && $status === 201) {
            $this->recordAuthoritative($request, 'order_created', 'checkout');
            return;
        }
        if (in_array($route, ['api.v1.commerce.payments.verify', 'api.v1.commerce.payments.reconcile'], true) && $status >= 200 && $status < 300) {
            $decoded = json_decode((string) $response->getContent(), true);
            if (is_array($decoded) && ($decoded['data']['status'] ?? null) === 'paid') {
                $this->recordAuthoritative($request, 'payment_paid', 'orders');
            }
        }
    }

    private function validTraceId(?string $traceId): ?string
    {
        if (! is_string($traceId)) return null;
        $traceId = strtolower($traceId);

        return preg_match('/^[0-9a-f]{32}$/', $traceId) === 1 && $traceId !== str_repeat('0', 32) ? $traceId : null;
    }
}
