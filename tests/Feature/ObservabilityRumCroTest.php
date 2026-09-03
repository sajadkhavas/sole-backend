<?php

namespace Tests\Feature;

use App\Models\AnalyticsEvent;
use App\Models\ConsentRecord;
use App\Models\Experiment;
use App\Models\ObservabilityErrorEvent;
use App\Models\ObservabilityRequestMetric;
use App\Models\User;
use App\Services\Observability\AnalyticsService;
use App\Services\Observability\AnalyticsTaxonomy;
use App\Services\Observability\ExperimentService;
use App\Services\Observability\FunnelSnapshotService;
use App\Services\Observability\RequestTelemetry;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class ObservabilityRumCroTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_requests_receive_w3c_correlation_and_bounded_red_metrics(): void
    {
        $incomingTrace = '4bf92f3577b34da6a3ce929d0e0e4736';
        $incomingParent = '00f067aa0ba902b7';
        $response = $this->getJson('/api/ready', ['traceparent' => "00-{$incomingTrace}-{$incomingParent}-01"])->assertOk();
        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-ID')));
        $this->assertMatchesRegularExpression('/^00-'.$incomingTrace.'-[0-9a-f]{16}-01$/', (string) $response->headers->get('traceparent'));
        $this->assertDatabaseHas('observability_request_metrics', [
            'route_name' => 'api.ready', 'method' => 'GET', 'status_class' => '2xx', 'request_count' => 1, 'error_count' => 0,
        ]);
        $this->assertGreaterThanOrEqual(0, ObservabilityRequestMetric::query()->firstOrFail()->duration_sum_ms);
    }

    public function test_invalid_trace_context_is_replaced_not_reflected(): void
    {
        $response = $this->getJson('/api/ready', [
            'traceparent' => '00-'.str_repeat('0', 32).'-'.str_repeat('0', 16).'-01',
        ])->assertOk();
        $traceparent = (string) $response->headers->get('traceparent');
        $this->assertMatchesRegularExpression('/^00-[0-9a-f]{32}-[0-9a-f]{16}-00$/', $traceparent);
        $this->assertStringNotContainsString(str_repeat('0', 32), $traceparent);
    }

    public function test_analytics_is_explicitly_consented_allow_listed_and_contains_no_identity_column(): void
    {
        $user = User::factory()->create();
        $session = (string) Str::uuid();
        $headers = ['X-Sole-Analytics-Session' => $session];
        $payload = [
            'taxonomy_version' => 1, 'event_name' => 'rum_lcp', 'route_name' => 'home',
            'properties' => ['value' => 1200, 'rating' => 'good', 'navigation_type' => 'navigate'],
        ];
        $this->actingAs($user)->postJson('/api/v1/observability/events', $payload, $headers)->assertForbidden();
        $this->actingAs($user)->putJson('/api/v1/observability/consent', [
            'granted' => true, 'policy_version' => 'p11-analytics-v1',
        ])->assertCreated()->assertJsonPath('data.granted', true);
        $accepted = $this->actingAs($user)->postJson('/api/v1/observability/events', $payload, $headers);
        $this->assertSame(202, $accepted->status(), $accepted->getContent());
        $this->actingAs($user)->postJson('/api/v1/observability/events', [
            'taxonomy_version' => 1, 'event_name' => 'product_view', 'route_name' => 'product',
            'properties' => ['email' => $user->email],
        ], $headers)->assertUnprocessable();

        $event = AnalyticsEvent::query()->firstOrFail();
        $this->assertSame($session, $event->session_id);
        $this->assertSame(['value' => 1200, 'rating' => 'good', 'navigation_type' => 'navigate'], $event->properties);
        $this->assertFalse(array_key_exists('user_id', $event->getAttributes()));
        $this->assertDatabaseCount('analytics_events', 1);
    }

    public function test_client_cannot_claim_server_authoritative_commerce_outcomes(): void
    {
        $user = User::factory()->create();
        $this->grantAnalytics($user);
        $this->actingAs($user)->postJson('/api/v1/observability/events', [
            'taxonomy_version' => 1, 'event_name' => 'payment_paid', 'route_name' => 'orders', 'properties' => [],
        ], ['X-Sole-Analytics-Session' => (string) Str::uuid()])->assertUnprocessable();
        $this->assertDatabaseMissing('analytics_events', ['event_name' => 'payment_paid']);
    }

    public function test_revoking_analytics_consent_stops_new_events(): void
    {
        $user = User::factory()->create();
        $session = (string) Str::uuid();
        $this->grantAnalytics($user);
        $analytics = app(AnalyticsService::class);
        $analytics->recordClient($user, $session, 'catalog_view', 'catalog', ['result_band' => '11_30'], null);
        ConsentRecord::query()->create([
            'user_id' => $user->id, 'type' => 'analytics', 'granted' => false, 'policy_version' => 'p11-analytics-v1',
            'source' => 'test', 'occurred_at' => now()->addSecond(),
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('ANALYTICS_CONSENT_REQUIRED');
        $analytics->recordClient($user, $session, 'catalog_view', 'catalog', ['result_band' => '11_30'], null);
    }

    public function test_error_evidence_is_sanitized_and_append_only(): void
    {
        $request = Request::create('/api/v1/private?email=person@example.com', 'GET');
        $context = [
            'request_id' => (string) Str::uuid(), 'trace_id' => bin2hex(random_bytes(16)), 'parent_span_id' => null,
            'span_id' => bin2hex(random_bytes(8)), 'trace_flags' => '00', 'traceparent' => '',
        ];
        app(RequestTelemetry::class)->recordException($request, $context, new RuntimeException('secret person@example.com'), 12.5);
        $event = ObservabilityErrorEvent::query()->firstOrFail();
        $this->assertSame(RuntimeException::class, $event->exception_class);
        $encoded = json_encode($event->getAttributes());
        $this->assertStringNotContainsString('person@example.com', (string) $encoded);
        $this->assertStringNotContainsString('secret', (string) $encoded);
        $this->expectException(LogicException::class);
        $event->delete();
    }

    public function test_experiments_require_guardrails_and_assignment_is_stable_per_consented_session(): void
    {
        $user = User::factory()->create();
        $this->grantAnalytics($user);
        $experiment = Experiment::query()->create([
            'key' => 'p11_home_cta', 'version' => 1, 'status' => 'draft', 'surface' => 'home',
            'hypothesis' => 'A clearer CTA can improve product discovery without harming Core Web Vitals.',
            'primary_metric' => 'catalog_to_product_rate', 'guardrail_metrics' => ['rum_lcp', 'rum_cls'],
            'variants' => ['control', 'treatment'], 'allocation_basis_points' => ['control' => 5000, 'treatment' => 5000],
            'minimum_sample_size' => 1000, 'rollback_plan' => 'Pause the experiment and render the control presentation.',
            'created_by' => $user->id,
        ]);
        $service = app(ExperimentService::class);
        $service->activate($experiment, $user);
        $session = (string) Str::uuid();
        $first = $service->assignments($user, $session);
        $second = $service->assignments($user, $session);
        $this->assertSame($first, $second);
        $this->assertCount(1, $first);
        $this->assertContains($first[0]['variant'], ['control', 'treatment']);
        $this->assertFalse(array_key_exists('price', $first[0]));
        $service->recordExposure($user, $session, $experiment->fresh(), $first[0]['variant'], null);
        $service->recordExposure($user, $session, $experiment->fresh(), $first[0]['variant'], null);
        $this->assertDatabaseCount('analytics_events', 1);
    }

    public function test_invalid_experiment_sample_plan_cannot_activate(): void
    {
        $user = User::factory()->create();
        $experiment = Experiment::query()->create([
            'key' => 'bad_sample', 'version' => 1, 'status' => 'draft', 'surface' => 'home',
            'hypothesis' => 'A hypothesis exists.', 'primary_metric' => 'catalog_to_product_rate',
            'guardrail_metrics' => ['rum_lcp'], 'variants' => ['control', 'treatment'],
            'allocation_basis_points' => ['control' => 5000, 'treatment' => 5000], 'minimum_sample_size' => 10,
            'rollback_plan' => 'Restore control.',
        ]);
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('EXPERIMENT_SAMPLE_PLAN_REQUIRED');
        app(ExperimentService::class)->activate($experiment, $user);
    }

    public function test_funnel_snapshot_counts_distinct_sessions_not_raw_event_volume(): void
    {
        $day = CarbonImmutable::parse('2026-09-02 12:00:00');
        $sessionA = (string) Str::uuid();
        $sessionB = (string) Str::uuid();
        foreach ([
            [$sessionA, 'catalog_view'], [$sessionA, 'catalog_view'], [$sessionA, 'product_view'], [$sessionA, 'cart_engaged'],
            [$sessionA, 'checkout_view'], [$sessionA, 'order_created'], [$sessionA, 'payment_paid'],
            [$sessionB, 'catalog_view'], [$sessionB, 'product_view'],
        ] as [$session, $event]) {
            AnalyticsEvent::query()->create([
                'session_id' => $session, 'taxonomy_version' => AnalyticsTaxonomy::VERSION, 'event_name' => $event,
                'route_name' => 'other', 'properties' => [], 'occurred_at' => $day, 'received_at' => $day,
            ]);
        }
        $snapshot = app(FunnelSnapshotService::class)->rebuild($day);
        $this->assertSame(2, $snapshot->catalog_sessions);
        $this->assertSame(2, $snapshot->product_sessions);
        $this->assertSame(1, $snapshot->cart_sessions);
        $this->assertSame(1, $snapshot->checkout_sessions);
        $this->assertSame(1, $snapshot->order_sessions);
        $this->assertSame(1, $snapshot->paid_sessions);
    }

    private function grantAnalytics(User $user): void
    {
        ConsentRecord::query()->create([
            'user_id' => $user->id, 'type' => 'analytics', 'granted' => true,
            'policy_version' => 'p11-analytics-v1', 'source' => 'test', 'occurred_at' => now(),
        ]);
    }
}
