<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopResourceGovernorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderBudgetFailoverSignalContract;
use Tests\TestCase;

final class LoopResourceGovernorServiceTest extends TestCase
{
    private function service(): LoopResourceGovernorService
    {
        return app(LoopResourceGovernorService::class);
    }

    /**
     * A healthy run comfortably under every ceiling. Tests mutate a copy of this to
     * push a single metric past a soft/hard ceiling.
     *
     * @return array<string,mixed>
     */
    private function healthyFixture(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'run_id' => 'run-001',
            'cycle_index' => 3,
            'usage' => [
                'provider_calls' => 20,
                'token_estimate' => 250000,
                'wall_time_seconds' => 3600,
                'memory_mb' => 1024,
                'disk_growth_mb' => 256,
                'ledger_bytes' => 1048576,
                'provider_process_count' => 1,
                'worktree_count' => 2,
                'branch_count' => 3,
                'blocked_streak' => 1,
                'retries_per_finding' => 1,
                'retries_per_packet' => 1,
            ],
        ];
    }

    public function test_under_ceilings_is_ok(): void
    {
        $report = $this->service()->evaluate($this->healthyFixture());

        $this->assertSame(LoopResourceGovernorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame('LHL-08', $report['slice_id']);
        $this->assertSame(LoopResourceGovernorService::STATUS_OK, $report['status']);
        $this->assertSame([], $report['breaches']);
        $this->assertSame([], $report['blockers']);
        $this->assertNull($report['stop_receipt']);
        $this->assertSame('continue', $report['next_action']);
    }

    public function test_exceeding_a_hard_ceiling_stops_and_records_breach_with_receipt(): void
    {
        $input = $this->healthyFixture();
        // Push provider_calls far past the hard ceiling (default 240).
        $input['usage']['provider_calls'] = 1000;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $report['status']);
        // The breach is recorded with hard severity + a stop action.
        $breach = $this->breachFor($report['breaches'], 'provider_calls');
        $this->assertNotNull($breach, 'a provider_calls breach must be recorded');
        $this->assertSame(LoopResourceGovernorService::SEVERITY_HARD, $breach['severity']);
        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $breach['action']);
        $this->assertSame(1000, $breach['value']);
        $this->assertContains('hard_ceiling_exceeded:provider_calls', $report['blockers']);
        // A stop carries an auditable receipt — never a silent halt.
        $this->assertIsArray($report['stop_receipt']);
        $this->assertSame('hard_resource_ceiling_exceeded', $report['stop_receipt']['reason']);
        $this->assertContains('provider_calls', $report['stop_receipt']['breached_metrics']);
        $this->assertTrue($report['stop_receipt']['requires_operator_or_maintenance']);
        $this->assertSame('stop_resource_ceiling', $report['next_action']);
    }

    public function test_exceeding_a_soft_ceiling_pauses_and_is_never_dressed_as_ok(): void
    {
        $input = $this->healthyFixture();
        // disk_growth_mb soft ceiling default 3072, hard 4096 — land in between.
        $input['usage']['disk_growth_mb'] = 3500;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopResourceGovernorService::STATUS_PAUSE, $report['status']);
        $this->assertNotSame(LoopResourceGovernorService::STATUS_OK, $report['status']);
        $breach = $this->breachFor($report['breaches'], 'disk_growth_mb');
        $this->assertNotNull($breach);
        $this->assertSame(LoopResourceGovernorService::SEVERITY_SOFT, $breach['severity']);
        $this->assertSame(LoopResourceGovernorService::STATUS_PAUSE, $breach['action']);
        $this->assertContains('soft_ceiling_exceeded:disk_growth_mb', $report['warnings']);
        // A pause is not a stop: no stop receipt.
        $this->assertNull($report['stop_receipt']);
        $this->assertSame('pause_resource_pressure', $report['next_action']);
    }

    public function test_hard_breach_wins_over_soft_breach(): void
    {
        $input = $this->healthyFixture();
        // Soft pressure on disk, hard breach on wall_time => overall STOP.
        $input['usage']['disk_growth_mb'] = 3500;          // soft only
        $input['usage']['wall_time_seconds'] = 200000;     // hard (default 86400)

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $report['status']);
        $this->assertNotNull($this->breachFor($report['breaches'], 'wall_time_seconds'));
        $this->assertContains('wall_time_seconds', $report['stop_receipt']['breached_metrics']);
    }

    public function test_token_estimate_is_produced_when_real_tokens_null(): void
    {
        $input = $this->healthyFixture();
        // Remove any real token measure; supply calls + changed files for the estimate.
        unset($input['usage']['token_estimate']);
        $input['usage']['provider_calls'] = 10;
        $input['usage']['changed_files'] = 4;

        $report = $this->service()->evaluate($input);

        $expected = (10 * LoopResourceGovernorService::TOKENS_PER_CALL_ESTIMATE)
            + (4 * LoopResourceGovernorService::TOKENS_PER_CHANGED_FILE_ESTIMATE);
        $this->assertSame($expected, $report['usage']['token_estimate']);
        $this->assertTrue($report['usage']['token_estimate_is_approximation'], 'an estimated token count must be flagged as an approximation');
        $this->assertTrue($report['resource_summary']['token_estimate_is_approximation']);
    }

    public function test_real_tokens_are_used_verbatim_and_not_flagged_as_approximation(): void
    {
        $input = $this->healthyFixture();
        $input['usage']['token_estimate'] = 777777;
        $input['usage']['provider_calls'] = 10; // would-be estimate differs from real

        $report = $this->service()->evaluate($input);

        $this->assertSame(777777, $report['usage']['token_estimate']);
        $this->assertFalse($report['usage']['token_estimate_is_approximation']);
    }

    public function test_resource_summary_is_present_and_shaped(): void
    {
        $report = $this->service()->evaluate($this->healthyFixture());

        $this->assertArrayHasKey('resource_summary', $report);
        $summary = $report['resource_summary'];
        $this->assertSame(LoopResourceGovernorService::STATUS_OK, $summary['status']);
        $this->assertSame(20, $summary['provider_calls']);
        $this->assertSame(0, $summary['breach_count']);
        $this->assertSame(0, $summary['hard_breach_count']);
        $this->assertSame(0, $summary['soft_breach_count']);
        $this->assertArrayHasKey('headroom', $summary);
        $this->assertArrayHasKey('provider_calls', $summary['headroom']);
        $this->assertSame(20, $summary['headroom']['provider_calls']['value']);
        $this->assertGreaterThan(0, $summary['headroom']['provider_calls']['remaining_to_hard']);
    }

    public function test_operator_can_override_ceilings_via_input_seam(): void
    {
        $input = $this->healthyFixture();
        // 20 calls is fine by default, but a strict run lowers the hard ceiling to 5.
        $input['hard_ceilings'] = ['provider_calls' => 5];

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $report['status']);
        $this->assertSame(5, $report['ceilings']['hard']['provider_calls']);
        $breach = $this->breachFor($report['breaches'], 'provider_calls');
        $this->assertNotNull($breach);
        $this->assertSame(5, $breach['ceiling']);
        $this->assertSame(LoopResourceGovernorService::SEVERITY_HARD, $breach['severity']);
    }

    public function test_generic_ceilings_override_applies_to_both_hard_and_soft(): void
    {
        $input = $this->healthyFixture();
        // The generic `ceilings` map applies to both bands; lowering blocked_streak
        // hard ceiling forces a stop when streak exceeds it.
        $input['ceilings'] = ['blocked_streak' => 2];
        $input['usage']['blocked_streak'] = 3;

        $report = $this->service()->evaluate($input);

        $this->assertSame(2, $report['ceilings']['hard']['blocked_streak']);
        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $report['status']);
    }

    public function test_retries_per_finding_accepts_a_map_and_takes_the_max(): void
    {
        $input = $this->healthyFixture();
        // A per-id map of retry counts; the governor takes the highest.
        $input['usage']['retries_per_finding'] = ['AAEOS-001' => 2, 'AAEOS-002' => 9];

        $report = $this->service()->evaluate($input);

        $this->assertSame(9, $report['usage']['retries_per_finding']);
        // 9 > default hard ceiling (6) => stop.
        $this->assertSame(LoopResourceGovernorService::STATUS_STOP, $report['status']);
        $this->assertNotNull($this->breachFor($report['breaches'], 'retries_per_finding'));
    }

    public function test_accepts_usage_record_via_fixture_input_seam(): void
    {
        // The wiring phase passes the whole usage record under `fixture`.
        $report = $this->service()->evaluate(['fixture' => $this->healthyFixture()]);

        $this->assertSame(LoopResourceGovernorService::STATUS_OK, $report['status']);
        $this->assertSame(20, $report['usage']['provider_calls']);
    }

    public function test_empty_input_provider_budget_failover_signal_returns_default_contract(): void
    {
        $signal = $this->service()->evaluateProviderBudgetFailoverSignal([]);

        $this->assertSame(
            ProviderBudgetFailoverSignalContract::defaults()->toArray(),
            $signal,
        );
        $this->assertSame(ProviderBudgetFailoverSignalContract::SCHEMA, $signal['schema_version']);
        $this->assertSame('provider_budget_exhausted', $signal['signal_id']);
        $this->assertSame(100, $signal['outputs']['remaining_provider_budget_pct']);
        $this->assertFalse($signal['outputs']['triggers_provider_failover']);
    }

    public function test_provider_budget_failover_signal_emits_failover_when_remaining_below_threshold(): void
    {
        $signal = $this->service()->evaluateProviderBudgetFailoverSignal([
            'run_id' => 'run-budget-001',
            'provider_calls' => 200,
            'provider_calls_hard_ceiling' => 240,
        ]);

        $this->assertSame('run-budget-001', $signal['inputs']['run_id']);
        $this->assertSame(200, $signal['inputs']['provider_calls']);
        $this->assertSame(16, $signal['outputs']['remaining_provider_budget_pct']);
        $this->assertTrue($signal['outputs']['triggers_provider_failover']);
        $this->assertSame('provider_budget_exhausted', $signal['outputs']['signal_id']);
        $this->assertNotSame(
            ProviderBudgetFailoverSignalContract::defaults()->toArray(),
            $signal,
            'non-empty seam must not return the empty-input default shape',
        );
    }

    public function test_evaluate_pauses_for_provider_budget_failover_signal(): void
    {
        $input = $this->healthyFixture();
        $input['usage']['remaining_provider_budget_pct'] = 15;

        $report = $this->service()->evaluate($input);

        $this->assertSame(LoopResourceGovernorService::STATUS_PAUSE, $report['status']);
        $this->assertSame('prepare_provider_failover', $report['next_action']);
        $this->assertContains('provider_budget_failover:provider_budget_exhausted', $report['warnings']);
        $this->assertTrue($report['resource_summary']['triggers_provider_failover']);
        $this->assertSame(15, $report['resource_summary']['remaining_provider_budget_pct']);

        $breach = $this->breachFor($report['breaches'], 'provider_budget_exhausted');
        $this->assertNotNull($breach);
        $this->assertSame(LoopResourceGovernorService::SEVERITY_SOFT, $breach['severity']);
        $this->assertSame(LoopResourceGovernorService::STATUS_PAUSE, $breach['action']);
        $this->assertSame(15, $breach['value']);
        $this->assertSame(ProviderBudgetFailoverSignalContract::FAILOVER_THRESHOLD_PCT, $breach['ceiling']);

        $this->assertArrayHasKey('provider_budget_exhausted', $report);
        $this->assertTrue($report['provider_budget_exhausted']['outputs']['triggers_provider_failover']);
    }

    public function test_default_empty_input_does_not_crash_and_reports_ok(): void
    {
        // Diagnostic default: an empty/clean run analyzes as zero usage => ok, no crash.
        $report = $this->service()->evaluate();

        $this->assertSame(LoopResourceGovernorService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertSame(LoopResourceGovernorService::STATUS_OK, $report['status']);
        $this->assertSame('LHL-08', $report['slice_id']);
        $this->assertSame('AP-808', $report['ap_contract']);
        $this->assertSame(0, $report['usage']['provider_calls']);
        $this->assertSame(0, $report['usage']['token_estimate']);
        $this->assertTrue($report['usage']['token_estimate_is_approximation']);
        $this->assertSame([], $report['breaches']);
        $this->assertNull($report['stop_receipt']);
    }

    public function test_emits_a_stable_report_hash(): void
    {
        $input = $this->healthyFixture();

        $first = $this->service()->evaluate($input);
        $second = $this->service()->evaluate($input);

        $this->assertArrayHasKey('report_hash', $first);
        $this->assertStringStartsWith('sha256:', $first['report_hash']);
        $this->assertSame(
            $first['report_hash'],
            $second['report_hash'],
            'same input must produce an identical report_hash (volatile fields stripped)',
        );

        // A stopping input must hash stably too AND differ from the ok hash.
        $stop = $input;
        $stop['usage']['provider_calls'] = 1000;
        $s1 = $this->service()->evaluate($stop);
        $s2 = $this->service()->evaluate($stop);
        $this->assertSame($s1['report_hash'], $s2['report_hash']);
        $this->assertNotSame($first['report_hash'], $s1['report_hash']);
    }

    /**
     * @param  list<array<string,mixed>>  $breaches
     * @return array<string,mixed>|null
     */
    private function breachFor(array $breaches, string $metric): ?array
    {
        foreach ($breaches as $breach) {
            if (($breach['metric'] ?? null) === $metric) {
                return $breach;
            }
        }

        return null;
    }
}
