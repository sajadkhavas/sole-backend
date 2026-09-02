<?php

namespace App\Services\Observability;

use App\Models\AnalyticsEvent;
use App\Models\Experiment;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ExperimentService
{
    public function __construct(private readonly AnalyticsService $analytics) {}

    public function activate(Experiment $experiment, User $actor): Experiment
    {
        $this->validateDefinition($experiment);

        return DB::transaction(function () use ($experiment, $actor): Experiment {
            $locked = Experiment::query()->lockForUpdate()->findOrFail($experiment->id);
            if (! in_array($locked->status, ['draft', 'paused'], true)) {
                throw new DomainException('EXPERIMENT_NOT_ACTIVATABLE');
            }
            $locked->forceFill([
                'status' => 'running',
                'activated_by' => $actor->id,
                'starts_at' => $locked->starts_at ?? now(),
            ])->save();

            return $locked->refresh();
        }, 3);
    }

    public function pause(Experiment $experiment): Experiment
    {
        if ($experiment->status !== 'running') throw new DomainException('EXPERIMENT_NOT_RUNNING');
        $experiment->forceFill(['status' => 'paused'])->save();
        return $experiment->refresh();
    }

    /** @return list<array{key:string,version:int,surface:string,variant:string}> */
    public function assignments(User $user, string $sessionId): array
    {
        if (! Str::isUuid($sessionId) || ! $this->analytics->hasConsent($user)) {
            throw new DomainException('ANALYTICS_CONSENT_REQUIRED');
        }

        return Experiment::query()
            ->where('status', 'running')
            ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
            ->where(fn ($query) => $query->whereNull('stops_at')->orWhere('stops_at', '>', now()))
            ->orderBy('key')
            ->get()
            ->map(function (Experiment $experiment) use ($sessionId): array {
                $this->validateDefinition($experiment);
                return [
                    'key' => $experiment->key,
                    'version' => (int) $experiment->version,
                    'surface' => $experiment->surface,
                    'variant' => $this->variantFor($experiment, $sessionId),
                ];
            })->all();
    }

    public function recordExposure(User $user, string $sessionId, Experiment $experiment, string $variant, ?string $traceId): AnalyticsEvent
    {
        if (! $this->analytics->hasConsent($user) || ! Str::isUuid($sessionId)) {
            throw new DomainException('ANALYTICS_CONSENT_REQUIRED');
        }
        $this->validateDefinition($experiment);
        if ($experiment->status !== 'running' || ! in_array($variant, $experiment->variants, true)) {
            throw new DomainException('EXPERIMENT_EXPOSURE_INVALID');
        }

        $existing = AnalyticsEvent::query()
            ->where('session_id', $sessionId)
            ->where('event_name', 'experiment_exposure')
            ->where('properties->experiment_key', $experiment->key)
            ->where('properties->version', (int) $experiment->version)
            ->first();
        if ($existing !== null) return $existing;

        return AnalyticsEvent::query()->create([
            'session_id' => $sessionId,
            'taxonomy_version' => AnalyticsTaxonomy::VERSION,
            'event_name' => 'experiment_exposure',
            'route_name' => 'other',
            'properties' => [
                'experiment_key' => $experiment->key,
                'version' => (int) $experiment->version,
                'variant' => $variant,
                'surface' => $experiment->surface,
            ],
            'trace_id' => $traceId,
            'occurred_at' => now(),
            'received_at' => now(),
        ]);
    }

    public function validateDefinition(Experiment $experiment): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9_-]{2,79}$/', $experiment->key) !== 1) throw new DomainException('EXPERIMENT_KEY_INVALID');
        if (trim((string) $experiment->hypothesis) === '' || mb_strlen($experiment->hypothesis) > 500) throw new DomainException('EXPERIMENT_HYPOTHESIS_REQUIRED');
        if (! in_array($experiment->primary_metric, AnalyticsTaxonomy::EXPERIMENT_METRICS, true)) throw new DomainException('EXPERIMENT_PRIMARY_METRIC_INVALID');
        foreach ($experiment->guardrail_metrics ?? [] as $metric) {
            if (! is_string($metric) || ! in_array($metric, AnalyticsTaxonomy::EXPERIMENT_METRICS, true)) throw new DomainException('EXPERIMENT_GUARDRAIL_INVALID');
        }
        $variants = $experiment->variants ?? [];
        if (count($variants) < 2 || count($variants) > 5 || count(array_unique($variants)) !== count($variants)) throw new DomainException('EXPERIMENT_VARIANTS_INVALID');
        foreach ($variants as $variant) {
            if (! is_string($variant) || preg_match('/^[a-z0-9][a-z0-9_-]{0,31}$/', $variant) !== 1) throw new DomainException('EXPERIMENT_VARIANT_INVALID');
        }
        $allocation = $experiment->allocation_basis_points ?? [];
        if (array_keys($allocation) !== $variants || array_sum($allocation) !== 10_000) throw new DomainException('EXPERIMENT_ALLOCATION_INVALID');
        foreach ($allocation as $basisPoints) {
            if (! is_int($basisPoints) || $basisPoints <= 0) throw new DomainException('EXPERIMENT_ALLOCATION_INVALID');
        }
        if ((int) $experiment->minimum_sample_size < 100) throw new DomainException('EXPERIMENT_SAMPLE_PLAN_REQUIRED');
        if (trim((string) $experiment->rollback_plan) === '') throw new DomainException('EXPERIMENT_ROLLBACK_REQUIRED');
        if ($experiment->starts_at !== null && $experiment->stops_at !== null && $experiment->stops_at <= $experiment->starts_at) throw new DomainException('EXPERIMENT_WINDOW_INVALID');
    }

    private function variantFor(Experiment $experiment, string $sessionId): string
    {
        $key = (string) config('app.key', 'sole-p11-experiment-assignment');
        $hex = substr(hash_hmac('sha256', "{$experiment->key}:{$experiment->version}:{$sessionId}", $key), 0, 8);
        $slot = hexdec($hex) % 10_000;
        $cursor = 0;
        foreach ($experiment->allocation_basis_points as $variant => $basisPoints) {
            $cursor += $basisPoints;
            if ($slot < $cursor) return $variant;
        }

        return $experiment->variants[array_key_last($experiment->variants)];
    }
}
