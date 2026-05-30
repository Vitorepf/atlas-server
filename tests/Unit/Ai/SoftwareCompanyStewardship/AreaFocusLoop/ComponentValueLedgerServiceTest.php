<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\MetricLedgerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Rsi\ComponentValueLedgerService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * RSI Part B · ComponentValueLedgerService contract + multi-cycle attribution.
 *
 * Proves the append-only value-per-token attribution is honest (no fabricated
 * value on unproven cycles), deterministic, and that after N cycles the WEAKEST
 * token-spending component is correctly identified — while a sacred-guarded
 * component can never be selected.
 */
final class ComponentValueLedgerServiceTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_component_value_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    /** A real MetricLedger "outcome MET" event for a given delta. */
    private function metOutcome(float $delta): array
    {
        return [
            'status' => MetricLedgerService::STATUS_OUTCOME_MET,
            'outcome_met' => true,
            'measured_value' => 100.0 + $delta,
            'measured_delta' => $delta,
        ];
    }

    private function ledger(): ComponentValueLedgerService
    {
        $ledger = new ComponentValueLedgerService();
        $ledger->setStorageRootForTesting($this->tmp);

        return $ledger;
    }

    public function test_records_append_only_and_attributes_proven_value_per_token(): void
    {
        $ledger = $this->ledger();

        $event = $ledger->recordCycle(
            ['area_id' => 'agentic_engineering_os', 'focus' => 'dev_forge', 'cycle_id' => 'c1', 'merge_hash' => 'abc'],
            $this->metOutcome(5.0),
            [
                'session_ap786' => ['tokens' => 1000, 'flags' => ['provider_invoked' => true]],
                'metric_ledger' => ['flags' => ['outcome_met' => true]],
                'planner_axis_n' => ['flags' => ['batch_produced' => true]],
            ],
        );

        $this->assertSame(ComponentValueLedgerService::OUTCOME_PROVEN, $event['outcome_status']);
        $this->assertEqualsWithDelta(5.0, $event['proven_value_delta'], 0.0001);
        $this->assertSame(1000, $event['total_tokens_cycle']);
        $this->assertContains('session_ap786', $event['live_components']);
        $this->assertContains('metric_ledger', $event['deterministic_components']);
        $this->assertContains('planner_axis_n', $event['deterministic_components']);

        // Deterministic components carry zero token cost even if a caller passes tokens.
        $byId = [];
        foreach ($event['components'] as $component) {
            $byId[$component['component_id']] = $component;
        }
        $this->assertSame(0, $byId['metric_ledger']['tokens_consumed']);
        $this->assertSame(1000, $byId['session_ap786']['tokens_consumed']);

        // Replay sees exactly one appended event (append-only).
        $this->assertCount(1, $ledger->replay('agentic_engineering_os', 'dev_forge'));
    }

    public function test_unproven_cycle_never_fabricates_value(): void
    {
        $ledger = $this->ledger();

        $event = $ledger->recordCycle(
            ['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'],
            [
                'status' => MetricLedgerService::STATUS_MEASUREMENT_FAILED,
                'outcome_met' => false,
                'measured_value' => null,
                'measured_delta' => null,
            ],
            ['session_ap786' => ['tokens' => 800], 'metric_ledger' => []],
        );

        $this->assertSame(ComponentValueLedgerService::OUTCOME_UNPROVEN, $event['outcome_status']);
        $this->assertNull($event['proven_value_delta']);
        $this->assertNull($event['value_per_token_cycle']);
        foreach ($event['components'] as $component) {
            // Honest: no component imputes value on an unproven cycle.
            $this->assertNull($component['measured_value_contribution']);
        }
    }

    public function test_after_n_cycles_weakest_token_spending_component_is_identified(): void
    {
        // Two token-spending profiles simulated as two areas/foci of the SAME
        // ledger? No — weakest is per-component. Here we prove the selector picks
        // the component with the LOWEST value-per-token among token-spenders, and
        // that deterministic (zero-token) components are excluded.
        $ledger = $this->ledger();

        // 5 proven cycles. session_ap786 spends heavily for modest proven value:
        // total proven value = 5 * 2.0 = 10.0; total tokens = 5 * 2000 = 10000
        // value_per_token = 0.001 (LOW efficiency = weakest legitimate target).
        // metric_ledger participates every cycle with ZERO tokens (deterministic).
        for ($i = 1; $i <= 5; $i++) {
            $ledger->recordCycle(
                ['area_id' => 'area', 'focus' => 'focus', 'cycle_id' => 'c'.$i],
                $this->metOutcome(2.0),
                [
                    'session_ap786' => ['tokens' => 2000, 'flags' => ['provider_invoked' => true]],
                    'metric_ledger' => ['flags' => ['outcome_met' => true]],
                    'planner_axis_n' => ['flags' => ['batch_produced' => true]],
                ],
            );
        }

        $stats = $ledger->valuePerTokenByComponent('area', 'focus');

        // Deterministic components have no finite value-per-token (zero tokens).
        $this->assertNull($stats['metric_ledger']['value_per_token']);
        $this->assertNull($stats['planner_axis_n']['value_per_token']);
        // Session has a finite, low value-per-token.
        $this->assertEqualsWithDelta(0.001, $stats['session_ap786']['value_per_token'], 0.0000001);
        $this->assertSame(5, $stats['session_ap786']['proven_cycles']);

        $weakest = $ledger->weakestComponent('area', 'focus');
        $this->assertNotNull($weakest);
        // Only token-spending component is the legitimate weakest target.
        $this->assertSame('session_ap786', $weakest['component_id']);
    }

    public function test_weakest_excludes_components_with_no_proven_signal(): void
    {
        $ledger = $this->ledger();

        // Token spent but every cycle is UNPROVEN -> no proven signal -> not weakest.
        $ledger->recordCycle(
            ['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c1'],
            ['status' => MetricLedgerService::STATUS_OUTCOME_NOT_MET, 'outcome_met' => false, 'measured_delta' => -1.0],
            ['session_ap786' => ['tokens' => 5000]],
        );

        $this->assertNull($ledger->weakestComponent('a', 'f'));
    }

    public function test_sacred_guarded_component_is_never_the_weakest_target(): void
    {
        $registry = new ImmutableInvariantRegistryService();
        $ledger = new ComponentValueLedgerService($registry);
        $ledger->setStorageRootForTesting($this->tmp);

        // session_ap786 maps to the Ap786OwnerFlowExecutor path, which the
        // Immutable Invariant Registry protects under provider_proof_sec_001.
        $this->assertTrue($ledger->isSacredComponent('session_ap786'));

        for ($i = 1; $i <= 3; $i++) {
            $ledger->recordCycle(
                ['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c'.$i],
                $this->metOutcome(1.0),
                ['session_ap786' => ['tokens' => 9000], 'metric_ledger' => []],
            );
        }

        // The only token-spender is sacred -> RSI may not target it -> no weakest.
        $this->assertNull($ledger->weakestComponent('a', 'f'));
    }

    public function test_selection_is_deterministic_and_replayable(): void
    {
        $ledger = $this->ledger();

        for ($i = 1; $i <= 4; $i++) {
            $ledger->recordCycle(
                ['area_id' => 'a', 'focus' => 'f', 'cycle_id' => 'c'.$i],
                $this->metOutcome(3.0),
                ['session_ap786' => ['tokens' => 1500], 'metric_ledger' => []],
            );
        }

        $records = $ledger->replay('a', 'f');
        $this->assertCount(4, $records);

        // Pure computation over injected records matches the on-disk replay.
        $fromDisk = $ledger->weakestComponent('a', 'f');
        $fromRecords = $ledger->weakestComponent('a', 'f', $records);
        $this->assertEquals($fromDisk, $fromRecords);
    }
}
