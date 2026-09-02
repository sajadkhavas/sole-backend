<?php

namespace App\Services\Observability;

use App\Models\ObservabilityErrorEvent;
use App\Models\ObservabilityRequestMetric;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class RequestTelemetry
{
    /** @param array{request_id:string,trace_id:string,parent_span_id:?string,span_id:string,trace_flags:string,traceparent:string} $context */
    public function recordResponse(Request $request, array $context, int $statusCode, float $durationMs): void
    {
        $routeName = $this->routeName($request);
        $method = strtoupper($request->method());
        $statusClass = intdiv(max(100, min(599, $statusCode)), 100).'xx';

        try {
            Log::channel('telemetry')->info('http.server.request', [
                'request_id' => $context['request_id'],
                'trace_id' => $context['trace_id'],
                'span_id' => $context['span_id'],
                'parent_span_id' => $context['parent_span_id'],
                'http.request.method' => $method,
                'http.route' => $routeName,
                'http.response.status_code' => $statusCode,
                'http.status_class' => $statusClass,
                'http.server.request.duration_ms' => round($durationMs, 3),
                'authenticated' => $request->user() !== null,
            ]);
        } catch (Throwable) {
            // Observability is never allowed to break the product request.
        }

        try {
            $this->incrementMetric($routeName, $method, $statusClass, $statusCode, $durationMs);
        } catch (Throwable) {
            // Metrics are best-effort during P11; request truth takes precedence.
        }
    }

    /** @param array{request_id:string,trace_id:string,parent_span_id:?string,span_id:string,trace_flags:string,traceparent:string} $context */
    public function recordException(Request $request, array $context, Throwable $exception, float $durationMs): void
    {
        $routeName = $this->routeName($request);
        $method = strtoupper($request->method());
        $class = $exception::class;
        $fingerprint = hash('sha256', $class.'|'.$routeName.'|'.$method);

        try {
            ObservabilityErrorEvent::query()->create([
                'request_id' => $context['request_id'],
                'trace_id' => $context['trace_id'],
                'span_id' => $context['span_id'],
                'route_name' => $routeName,
                'method' => $method,
                'status_code' => 500,
                'exception_class' => $class,
                'fingerprint' => $fingerprint,
                'occurred_at' => now(),
            ]);
        } catch (Throwable) {
            // Never replace the original exception with a telemetry failure.
        }

        try {
            Log::channel('telemetry')->error('http.server.exception', [
                'request_id' => $context['request_id'],
                'trace_id' => $context['trace_id'],
                'span_id' => $context['span_id'],
                'http.request.method' => $method,
                'http.route' => $routeName,
                'http.response.status_code' => 500,
                'http.server.request.duration_ms' => round($durationMs, 3),
                'exception.class' => $class,
                'exception.fingerprint' => $fingerprint,
            ]);
        } catch (Throwable) {
            // Never replace the original exception with a logging failure.
        }

        try {
            $this->incrementMetric($routeName, $method, '5xx', 500, $durationMs);
        } catch (Throwable) {
            // Best-effort metric persistence.
        }
    }

    private function routeName(Request $request): string
    {
        $name = $request->route()?->getName();

        return is_string($name) && $name !== '' ? substr($name, 0, 160) : 'unmatched';
    }

    private function incrementMetric(string $routeName, string $method, string $statusClass, int $statusCode, float $durationMs): void
    {
        $bucketStartedAt = now()->startOfMinute();

        DB::transaction(function () use ($bucketStartedAt, $routeName, $method, $statusClass, $statusCode, $durationMs): void {
            ObservabilityRequestMetric::query()->insertOrIgnore([[
                'bucket_started_at' => $bucketStartedAt,
                'route_name' => $routeName,
                'method' => $method,
                'status_class' => $statusClass,
                'created_at' => now(),
                'updated_at' => now(),
            ]]);

            $metric = ObservabilityRequestMetric::query()
                ->where('bucket_started_at', $bucketStartedAt)
                ->where('route_name', $routeName)
                ->where('method', $method)
                ->where('status_class', $statusClass)
                ->lockForUpdate()
                ->firstOrFail();

            $metric->request_count++;
            if ($statusCode >= 500) {
                $metric->error_count++;
            }
            $metric->duration_sum_ms = (float) $metric->duration_sum_ms + $durationMs;
            $metric->duration_max_ms = max((float) $metric->duration_max_ms, $durationMs);
            $bucket = $this->durationBucket($durationMs);
            $metric->{$bucket} = (int) $metric->{$bucket} + 1;
            $metric->save();
        }, 3);
    }

    private function durationBucket(float $durationMs): string
    {
        return match (true) {
            $durationMs <= 100 => 'duration_le_100_ms',
            $durationMs <= 250 => 'duration_le_250_ms',
            $durationMs <= 500 => 'duration_le_500_ms',
            $durationMs <= 1000 => 'duration_le_1000_ms',
            $durationMs <= 2500 => 'duration_le_2500_ms',
            $durationMs <= 5000 => 'duration_le_5000_ms',
            default => 'duration_gt_5000_ms',
        };
    }
}
