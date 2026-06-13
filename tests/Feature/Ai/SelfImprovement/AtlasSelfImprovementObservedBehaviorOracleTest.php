<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementResultLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfImprovementObservedBehaviorOracleTest extends TestCase
{
    public function test_uncovered_observed_behavior_drift_blocks_even_when_test_spec_is_green(): void
    {
        $before = $this->snapshot(100.0, coveredByTest: false);
        $after = $this->snapshot(95.0, coveredByTest: false);

        $report = app(AtlasSelfImprovementRegressionSentinelService::class)->scan(
            $before,
            $after,
            ['test_spec_status' => 'green'],
        );

        $this->assertSame(AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED, $report['status']);
        $this->assertSame('drift_detected', data_get($report, 'observed_behavior_oracle.status'));
        $this->assertSame(1, data_get($report, 'observed_behavior_oracle.drift_count'));
        $this->assertTrue(data_get($report, 'invariants.uncovered_observed_behavior_drift_blocks_promotion'));
        $this->assertContains(
            'observed_behavior_drift_uncovered_by_test',
            data_get($report, 'observed_behavior_oracle.finding_ids'),
        );
    }

    public function test_test_covered_observed_behavior_is_skipped_by_l6_7_oracle(): void
    {
        $report = app(AtlasSelfImprovementRegressionSentinelService::class)->scan(
            $this->snapshot(100.0, coveredByTest: true),
            $this->snapshot(95.0, coveredByTest: true),
            ['test_spec_status' => 'green'],
        );

        $this->assertSame(AtlasSelfImprovementRegressionSentinelService::STATUS_CLEAR, $report['status']);
        $this->assertSame('clear', data_get($report, 'observed_behavior_oracle.status'));
        $this->assertSame(0, data_get($report, 'observed_behavior_oracle.drift_count'));
        $this->assertSame(1, data_get($report, 'observed_behavior_oracle.covered_contracts_skipped'));
    }

    public function test_command_fixture_fails_strict_for_uncovered_drift_and_writes_safe_receipt(): void
    {
        $exit = Artisan::call('atlas:self-improvement:regression-sentinel', [
            '--fixture' => 'uncovered-drift',
            '--strict' => true,
            '--json' => true,
        ]);
        $blocked = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame(AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED, $blocked['status']);
        $this->assertSame('uncovered-drift', $blocked['fixture']);
        $this->assertSame('drift_detected', data_get($blocked, 'observed_behavior_oracle.status'));

        $receipt = storage_path('framework/testing/l6-7-observed-behavior-oracle.json');
        @unlink($receipt);
        $exit = Artisan::call('atlas:self-improvement:regression-sentinel', [
            '--fixture' => 'safe',
            '--write-receipt' => true,
            '--receipt' => $receipt,
            '--json' => true,
        ]);
        $safe = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfImprovementRegressionSentinelService::STATUS_CLEAR, $safe['status']);
        $this->assertSame($receipt, $safe['receipt_path']);
        $this->assertFileExists($receipt);
    }

    public function test_result_ledger_persists_observed_behavior_oracle_for_long_horizon_replay(): void
    {
        Storage::fake('local');

        $entry = app(AtlasSelfImprovementResultLedgerService::class)->record([
            'proposal_id' => 'prop_l6_7_observed_oracle',
            'obra_id' => 'obra_l6_7_observed_oracle',
            'before_snapshot' => $this->resultSnapshot(100.0),
            'after_snapshot' => $this->resultSnapshot(95.0),
            'reviewer' => 'l6-7-test',
            'reason' => 'observed behavior drift fixture',
            'context' => [
                'evidence_refs' => [
                    'storage/app/atlas/evidence/l6-7/observed-behavior-oracle-uncovered-drift-strict-2026-06-13.json',
                ],
                'proposal_packet' => [
                    'proposal_id' => 'prop_l6_7_observed_oracle',
                    'canonical_docs' => ['docs/fable-lista-6-14-itens.md'],
                    'risk_classification' => [
                        'risk_level' => 'medium',
                        'rollback_required' => false,
                    ],
                ],
            ],
        ]);

        $this->assertSame('regressed', $entry['delta_grade']);
        $this->assertSame('drift_detected', data_get($entry, 'observed_behavior_oracle.status'));
        $this->assertSame(1, data_get($entry, 'observed_behavior_oracle.drift_count'));

        $path = 'atlas/self-improvement/result-ledger/'.$entry['result_entry_id'].'.json';
        $this->assertTrue(Storage::disk('local')->exists($path));
        $stored = json_decode((string) Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('drift_detected', data_get($stored, 'observed_behavior_oracle.status'));
    }

    public function test_schedule_lists_l6_7_observed_behavior_oracle(): void
    {
        Artisan::call('schedule:list');
        $output = Artisan::output();

        $this->assertStringContainsString(
            'atlas:self-improvement:regression-sentinel --fixture=safe --write-receipt --json',
            $output,
        );
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshot(float $observedValue, bool $coveredByTest): array
    {
        return [
            'observed_behavior' => [
                'contracts' => [
                    [
                        'id' => 'atlas.invoice.total.rounding',
                        'source' => 'live_observation_fixture',
                        'observed_value' => $observedValue,
                        'covered_by_test' => $coveredByTest,
                        'tolerance_abs' => 0.01,
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function resultSnapshot(float $observedValue): array
    {
        return array_merge([
            'metrics' => $this->flatMetrics(),
        ], $this->snapshot($observedValue, coveredByTest: false));
    }

    /**
     * @return array<string,float>
     */
    private function flatMetrics(): array
    {
        return array_fill_keys([
            'functional_correctness',
            'business_rule_alignment',
            'canonical_documentation_adherence',
            'test_and_risk_coverage',
            'enterprise_architecture_quality',
            'governance_integrity',
            'operator_experience',
            'automation_level',
            'human_intervention_load',
            'evidence_and_observability',
            'runtime_safety',
            'provider_cost_token_impact',
            'regressions_and_new_blockers',
        ], 8.0);
    }
}
