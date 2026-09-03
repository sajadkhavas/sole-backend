<?php

namespace App\Http\Middleware;

use App\Services\Observability\AnalyticsService;
use App\Services\Observability\RequestTelemetry;
use App\Services\Observability\TraceContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ObserveRequest
{
    public function __construct(
        private readonly TraceContext $traceContext,
        private readonly RequestTelemetry $telemetry,
        private readonly AnalyticsService $analytics,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $context = $this->traceContext->forRequest($request);
        $request->attributes->set('sole.observability', $context);
        $startedAt = hrtime(true);

        try {
            $response = $next($request);
        } catch (Throwable $exception) {
            $this->telemetry->recordException($request, $context, $exception, $this->durationMs($startedAt));
            throw $exception;
        }

        $response->headers->set('X-Request-ID', $context['request_id']);
        $response->headers->set('traceparent', $context['traceparent']);
        $this->telemetry->recordResponse($request, $context, $response->getStatusCode(), $this->durationMs($startedAt));
        $this->analytics->recordOutcomeFromResponse($request, $response);

        return $response;
    }

    private function durationMs(int $startedAt): float
    {
        return max(0, (hrtime(true) - $startedAt) / 1_000_000);
    }
}
