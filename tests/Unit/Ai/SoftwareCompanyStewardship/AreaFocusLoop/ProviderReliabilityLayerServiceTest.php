<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderReliabilityLayerService;
use Tests\TestCase;

final class ProviderReliabilityLayerServiceTest extends TestCase
{
    private function service(): ProviderReliabilityLayerService
    {
        return app(ProviderReliabilityLayerService::class);
    }

    /**
     * A healthy two-provider fleet with no faults, no rate limits, healthy quality,
     * budget remaining, and no fallback in play. Tests mutate a copy to drive each
     * negative case.
     *
     * @return array<string,mixed>
     */
    private function healthyFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'budget' => [
                'cap_usd' => 100.0,
                'spent_usd' => 12.0,
                'remaining_usd' => 88.0,
            ],
            'providers' => [
                [
                    'id' => 'cursor',
                    'lane' => 'dev',
                    'timeout_rate' => 0.02,
                    'transient_failures' => 0,
                    'permanent_failures' => 0,
                    'cost_per_call' => 0.04,
                    'approx_token_usage' => 12000,
                    'model_quality_by_lane' => 0.92,
                    'rate_limited' => false,
                    'fallback_availability' => true,
                ],
                [
                    'id' => 'claude',
                    'lane' => 'forge',
                    'timeout_rate' => 0.01,
                    'transient_failures' => 0,
                    'permanent_failures' => 0,
                    'cost_per_call' => 0.09,
                    'approx_token_usage' => 30000,
                    'model_quality_by_lane' => 0.95,
                    'rate_limited' => false,
                    'fallback_availability' => true,
                ],
            ],
        ];
    }

    public function test_healthy_fleet_is_ok_with_no_breach_or_open_circuit(): void
    {
        $report = $this->service()->assess($this->healthyFixture());

        $this->assertSame(ProviderReliabilityLayerService::STATUS_OK, $report['status']);
        $this->assertFalse($report['budget_breach']);
        $this->assertSame([], $report['blockers']);
        $this->assertNull($report['fallback_decision']);
        $this->assertSame('continue', $report['next_action']);
        $this->assertSame(0, $report['circuit_open_count']);
        foreach ($report['providers'] as $provider) {
            $this->assertSame(ProviderReliabilityLayerService::CIRCUIT_CLOSED, $provider['circuit_state']);
            $this->assertSame(ProviderReliabilityLayerService::MODE_NORMAL, $provider['recommended_mode']);
        }
    }

    public function test_repeated_permanent_failures_open_the_circuit(): void
    {
        // Tests bullet: repeated permanent failures open the circuit.
        $input = $this->healthyFixture();
        $input['providers'][0]['permanent_failures'] = ProviderReliabilityLayerService::PERMANENT_FAILURE_OPEN_THRESHOLD;
        // A recorded fallback so the open circuit is the isolated cause of status.
        $input['fallback_decision'] = [
            'from' => 'cursor',
            'to' => 'claude',
            'reason' => 'cursor circuit open after repeated permanent failures',
            'decided_by' => 'atlas_decide',
        ];

        $report = $this->service()->assess($input);

        $this->assertSame(ProviderReliabilityLayerService::STATUS_CIRCUIT_OPEN, $report['status']);
        $this->assertContains('provider_circuit_open:cursor', $report['blockers']);

        $cursor = $this->providerById($report, 'cursor');
        $this->assertSame(ProviderReliabilityLayerService::CIRCUIT_OPEN, $cursor['circuit_state']);
        $this->assertSame(ProviderReliabilityLayerService::MODE_CIRCUIT_OPEN, $cursor['recommended_mode']);
        $this->assertContains('repeated_permanent_failures', $cursor['reasons']);
        $this->assertGreaterThanOrEqual(1, $report['circuit_open_count']);
    }

    public function test_transient_burst_does_not_open_circuit_or_permanently_quarantine(): void
    {
        // Tests bullet: a transient burst does NOT open the circuit / does not
        // permanently quarantine.
        $input = $this->healthyFixture();
        $input['providers'][0]['transient_failures'] = 25; // large burst
        $input['providers'][0]['permanent_failures'] = 0;

        $report = $this->service()->assess($input);

        $cursor = $this->providerById($report, 'cursor');
        $this->assertSame(ProviderReliabilityLayerService::CIRCUIT_CLOSED, $cursor['circuit_state']);
        $this->assertNotSame(ProviderReliabilityLayerService::MODE_CIRCUIT_OPEN, $cursor['recommended_mode']);
        $this->assertSame(25, $cursor['transient']);
        // It is NOT counted as a permanent fault / quarantine, and the breaker stays
        // closed for the whole fleet on transient signal alone.
        $this->assertSame(0, $cursor['permanent']);
        $this->assertSame(0, $report['circuit_open_count']);
        $this->assertContains('provider_transient_burst_tolerated:cursor', $report['warnings']);
        $this->assertNotSame(ProviderReliabilityLayerService::STATUS_CIRCUIT_OPEN, $report['status']);
    }

    public function test_degraded_mode_when_timeout_rate_high_or_quality_rotten(): void
    {
        // Degraded mode lowers risk/concurrency rather than tripping the breaker.
        $input = $this->healthyFixture();
        $input['providers'][0]['timeout_rate'] = 0.35; // above degraded threshold
        $input['providers'][1]['model_quality_by_lane'] = 0.40; // quality rot

        $report = $this->service()->assess($input);

        $this->assertSame(ProviderReliabilityLayerService::STATUS_DEGRADED, $report['status']);
        $this->assertSame('lower_risk_and_concurrency', $report['loop_action']);

        $cursor = $this->providerById($report, 'cursor');
        $this->assertSame(ProviderReliabilityLayerService::MODE_DEGRADED, $cursor['recommended_mode']);
        $this->assertContains('timeout_rate_high', $cursor['reasons']);

        $claude = $this->providerById($report, 'claude');
        $this->assertSame(ProviderReliabilityLayerService::MODE_DEGRADED, $claude['recommended_mode']);
        $this->assertContains('model_quality_degraded', $claude['reasons']);

        // Degraded is NOT a tripped circuit.
        $this->assertSame(ProviderReliabilityLayerService::CIRCUIT_CLOSED, $cursor['circuit_state']);
        $this->assertSame(0, $report['circuit_open_count']);
    }

    public function test_fallback_requires_recorded_auditable_decision(): void
    {
        // Tests bullet: fallback requires recorded auditable decision.
        // An open circuit needs a fallback, but no auditable reason is supplied =>
        // the service REFUSES a silent swap and BLOCKS.
        $unrecorded = $this->healthyFixture();
        $unrecorded['providers'][0]['permanent_failures'] = 5; // opens circuit, needs fallback

        $r1 = $this->service()->assess($unrecorded);
        $this->assertSame(ProviderReliabilityLayerService::STATUS_CIRCUIT_OPEN, $r1['status']);
        $this->assertContains('fallback_requires_auditable_decision', $r1['blockers']);
        $this->assertIsArray($r1['fallback_decision']);
        $this->assertFalse($r1['fallback_decision']['recorded']);

        // Now supply an auditable decision (explicit reason + concrete target).
        $recorded = $unrecorded;
        $recorded['fallback_decision'] = [
            'from' => 'cursor',
            'to' => 'claude',
            'reason' => 'cursor breaker open; route forge-capable lane to claude',
            'decided_by' => 'atlas_decide',
        ];

        $r2 = $this->service()->assess($recorded);
        $this->assertIsArray($r2['fallback_decision']);
        $this->assertTrue($r2['fallback_decision']['recorded']);
        $this->assertSame('claude', $r2['fallback_decision']['to']);
        $this->assertSame('atlas_decide', $r2['fallback_decision']['decided_by']);
        $this->assertNotContains('fallback_requires_auditable_decision', $r2['blockers']);
        $this->assertContains('provider_fallback_route_recorded', $r2['warnings']);
    }

    public function test_budget_breach_pauses_the_loop(): void
    {
        // Tests bullet: budget breach => pause.
        $input = $this->healthyFixture();
        $input['budget'] = [
            'cap_usd' => 100.0,
            'spent_usd' => 142.0, // over cap
            'remaining_usd' => 0.0,
        ];

        $report = $this->service()->assess($input);

        $this->assertTrue($report['budget_breach']);
        $this->assertSame(ProviderReliabilityLayerService::STATUS_CIRCUIT_OPEN, $report['status']);
        $this->assertContains('provider_budget_breach_pauses_loop', $report['blockers']);
        $this->assertSame('pause_loop_budget_breach', $report['loop_action']);
        // A budget breach is NEVER dressed as ok.
        $this->assertNotSame(ProviderReliabilityLayerService::STATUS_OK, $report['status']);
    }

    public function test_explicit_open_circuit_state_is_honored(): void
    {
        // The breaker may report half_open/open directly via the seam.
        $input = $this->healthyFixture();
        $input['providers'][0]['circuit_breaker_state'] = 'open';
        $input['fallback_decision'] = [
            'to' => 'claude',
            'reason' => 'cursor breaker reported open by runtime',
        ];

        $report = $this->service()->assess($input);

        $cursor = $this->providerById($report, 'cursor');
        $this->assertSame(ProviderReliabilityLayerService::CIRCUIT_OPEN, $cursor['circuit_state']);
        $this->assertContains('circuit_breaker_open', $cursor['reasons']);
        $this->assertSame(ProviderReliabilityLayerService::STATUS_CIRCUIT_OPEN, $report['status']);
    }

    public function test_rate_limited_provider_is_degraded(): void
    {
        $input = $this->healthyFixture();
        $input['providers'][0]['rate_limited'] = true;

        $report = $this->service()->assess($input);

        $cursor = $this->providerById($report, 'cursor');
        $this->assertTrue($cursor['rate_limited']);
        $this->assertSame(ProviderReliabilityLayerService::MODE_DEGRADED, $cursor['recommended_mode']);
        $this->assertContains('rate_limited', $cursor['reasons']);
        $this->assertSame(ProviderReliabilityLayerService::STATUS_DEGRADED, $report['status']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        // Tests bullet: deterministic hash. Same input twice => identical report_hash.
        $input = $this->healthyFixture();

        $first = $this->service()->assess($input);
        $second = $this->service()->assess($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A circuit-open input must hash stably too AND differ from the healthy hash.
        $open = $input;
        $open['providers'][0]['permanent_failures'] = 9;
        $open['fallback_decision'] = ['to' => 'claude', 'reason' => 'breaker open'];
        $o1 = $this->service()->assess($open);
        $o2 = $this->service()->assess($open);
        $this->assertSame($o1['report_hash'], $o2['report_hash']);
        $this->assertNotSame($first['report_hash'], $o1['report_hash']);
    }

    public function test_accepts_snapshot_via_fixture_input_seam(): void
    {
        // The wiring phase passes the whole reliability snapshot under `fixture`.
        $report = $this->service()->assess(['fixture' => $this->healthyFixture()]);

        $this->assertSame(ProviderReliabilityLayerService::STATUS_OK, $report['status']);
        $this->assertCount(2, $report['providers']);
    }

    public function test_default_empty_input_does_not_crash_and_reports_ok_empty_fleet(): void
    {
        // Diagnostic default: empty fleet analyzes as ok with no breach, never a crash.
        $report = $this->service()->assess();

        $this->assertSame(ProviderReliabilityLayerService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(ProviderReliabilityLayerService::STATUS_OK, $report['status']);
        $this->assertSame('LHL-16', $report['slice_id']);
        $this->assertSame('AP-809', $report['ap_contract']);
        $this->assertSame([], $report['providers']);
        $this->assertFalse($report['budget_breach']);
        $this->assertNull($report['fallback_decision']);
        $this->assertStringStartsWith('sha256:', $report['report_hash']);
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function providerById(array $report, string $id): array
    {
        foreach ($report['providers'] as $provider) {
            if (($provider['id'] ?? null) === $id) {
                return $provider;
            }
        }

        $this->fail("provider {$id} not found in report");
    }
}
