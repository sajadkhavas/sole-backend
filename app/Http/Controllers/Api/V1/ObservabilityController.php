<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ConsentRecord;
use App\Models\Experiment;
use App\Services\Observability\AnalyticsService;
use App\Services\Observability\AnalyticsTaxonomy;
use App\Services\Observability\ExperimentService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ObservabilityController extends Controller
{
    public function consentStatus(Request $request): JsonResponse
    {
        $record = ConsentRecord::query()->where('user_id', $request->user()->id)->where('type', 'analytics')
            ->orderByDesc('occurred_at')->orderByDesc('id')->first();

        return response()->json(['data' => [
            'granted' => $record?->granted ?? false,
            'policy_version' => $record?->policy_version,
            'occurred_at' => $record?->occurred_at?->toISOString(),
        ]]);
    }

    public function consent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'granted' => ['required', 'boolean'],
            'policy_version' => ['required', 'string', 'max:64'],
        ]);
        $record = ConsentRecord::query()->create([
            'user_id' => $request->user()->id,
            'type' => 'analytics',
            'granted' => $data['granted'],
            'policy_version' => $data['policy_version'],
            'source' => 'p11_observability',
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        return response()->json(['data' => [
            'granted' => $record->granted,
            'policy_version' => $record->policy_version,
            'occurred_at' => $record->occurred_at?->toISOString(),
        ]], 201);
    }

    public function event(Request $request, AnalyticsService $analytics): JsonResponse
    {
        $data = $request->validate([
            'taxonomy_version' => ['required', 'integer', 'in:'.AnalyticsTaxonomy::VERSION],
            'event_name' => ['required', 'string', 'max:64'],
            'route_name' => ['required', 'string', 'max:64'],
            'properties' => ['present', 'array', 'max:8'],
        ]);
        $sessionId = trim((string) $request->header('X-Sole-Analytics-Session'));
        $context = $request->attributes->get('sole.observability');
        $traceId = is_array($context) && is_string($context['trace_id'] ?? null) ? $context['trace_id'] : null;

        try {
            $analytics->recordClient($request->user(), $sessionId, $data['event_name'], $data['route_name'], $data['properties'], $traceId);
        } catch (DomainException $exception) {
            $status = $exception->getMessage() === 'ANALYTICS_CONSENT_REQUIRED' ? 403 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['accepted' => true, 'taxonomy_version' => AnalyticsTaxonomy::VERSION], 202);
    }

    public function experiments(Request $request, ExperimentService $experiments): JsonResponse
    {
        $sessionId = trim((string) $request->header('X-Sole-Analytics-Session'));
        try {
            $assignments = $experiments->assignments($request->user(), $sessionId);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage(), 'data' => []], 403);
        }

        return response()->json([
            'data' => $assignments,
            'meta' => ['provider' => 'first_party', 'assignment_scope' => 'consented_session', 'business_truth_mutation' => false],
        ]);
    }

    public function exposure(Request $request, ExperimentService $experiments): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'string', 'max:80'],
            'version' => ['required', 'integer', 'min:1'],
            'variant' => ['required', 'string', 'max:32'],
        ]);
        $sessionId = trim((string) $request->header('X-Sole-Analytics-Session'));
        if (! Str::isUuid($sessionId)) {
            return response()->json(['message' => 'ANALYTICS_SESSION_INVALID'], 422);
        }

        $experiment = Experiment::query()->where('key', $data['key'])->where('version', $data['version'])->where('status', 'running')->firstOrFail();
        $context = $request->attributes->get('sole.observability');
        $traceId = is_array($context) && is_string($context['trace_id'] ?? null) ? $context['trace_id'] : null;

        try {
            $experiments->recordExposure($request->user(), $sessionId, $experiment, $data['variant'], $traceId);
        } catch (DomainException $exception) {
            $status = $exception->getMessage() === 'ANALYTICS_CONSENT_REQUIRED' ? 403 : 422;

            return response()->json(['message' => $exception->getMessage()], $status);
        }

        return response()->json(['accepted' => true], 202);
    }
}
