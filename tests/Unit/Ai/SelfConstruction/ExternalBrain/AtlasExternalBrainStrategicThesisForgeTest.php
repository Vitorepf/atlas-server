<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainStrategicThesisForge;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainStrategicThesisForgeTest extends TestCase
{
    private AtlasExternalBrainStrategicThesisForge $forge;

    protected function setUp(): void
    {
        $this->forge = new AtlasExternalBrainStrategicThesisForge;
    }

    private function validCluster(array $overrides = []): array
    {
        return array_merge([
            'cluster_id' => 'cluster-01',
            'theme' => 'contract_mismatch_detection',
            'capability_delta' => 'atlas_can_detect_interface_implementation_drift_at_ci_time_instead_of_never',
            'acceptance_path' => 'php artisan atlas:ci:contract-check exits 1 when drift exists and 0 when clean',
            'opportunities' => [
                ['description' => 'Wiring gap: AtlasFoo implements IFoo but IFoo changed 3 weeks ago'],
            ],
            'urgency' => 'high',
            'risk' => 'medium',
        ], $overrides);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_valid_cluster_produces_thesis_with_all_required_keys(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);

        $this->assertSame(AtlasExternalBrainStrategicThesisForge::SCHEMA, $r['schema']);
        $this->assertCount(1, $r['theses']);
        $this->assertSame([], $r['rejected']);

        $thesis = $r['theses'][0];
        foreach (['thesis_id', 'cluster_id', 'title', 'why_it_matters', 'structural_leverage',
                  'capability_delta', 'acceptance_path', 'evidence_demand', 'risk', 'task_shapes'] as $key) {
            $this->assertArrayHasKey($key, $thesis, "thesis must contain {$key}");
        }
        $this->assertSame('cluster-01', $thesis['cluster_id']);
        $this->assertSame('thesis:cluster-01', $thesis['thesis_id']);
    }

    public function test_multiple_clusters_produce_multiple_theses(): void
    {
        $c1 = $this->validCluster(['cluster_id' => 'c1', 'theme' => 'theme_a']);
        $c2 = $this->validCluster(['cluster_id' => 'c2', 'theme' => 'theme_b']);

        $r = $this->forge->forge([$c1, $c2]);

        $this->assertCount(2, $r['theses']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_thesis_carries_leverage_claim_and_task_shapes(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $thesis = $r['theses'][0];

        $this->assertNotEmpty($thesis['structural_leverage']);
        $this->assertIsArray($thesis['task_shapes']);
        $this->assertNotEmpty($thesis['task_shapes']);
        foreach ($thesis['task_shapes'] as $shape) {
            $this->assertArrayHasKey('shape', $shape);
            $this->assertArrayHasKey('description', $shape);
        }
    }

    public function test_evidence_demand_is_non_empty_list_of_strings(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $demand = $r['theses'][0]['evidence_demand'];

        $this->assertIsArray($demand);
        $this->assertNotEmpty($demand);
        foreach ($demand as $d) {
            $this->assertIsString($d);
            $this->assertNotEmpty($d);
        }
    }

    // ── rejection: missing capability_delta ───────────────────────────────────

    public function test_rejects_thesis_when_capability_delta_is_empty(): void
    {
        $r = $this->forge->forge([$this->validCluster(['capability_delta' => ''])]);

        $this->assertSame([], $r['theses']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('cluster-01', $r['rejected'][0]['cluster_id']);
        $this->assertStringContainsString('capability_delta', $r['rejected'][0]['reason']);
    }

    public function test_rejects_thesis_when_capability_delta_is_absent(): void
    {
        $cluster = $this->validCluster();
        unset($cluster['capability_delta']);

        $r = $this->forge->forge([$cluster]);

        $this->assertCount(1, $r['rejected']);
        $this->assertStringContainsString('capability_delta', $r['rejected'][0]['reason']);
    }

    // ── rejection: missing or non-falsifiable acceptance_path ─────────────────

    public function test_rejects_thesis_when_acceptance_path_is_empty(): void
    {
        $r = $this->forge->forge([$this->validCluster(['acceptance_path' => ''])]);

        $this->assertSame([], $r['theses']);
        $this->assertStringContainsString('acceptance_path', $r['rejected'][0]['reason']);
    }

    public function test_rejects_thesis_with_vague_acceptance_path(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'acceptance_path' => 'this will be better for Atlas going forward',
        ])]);

        $this->assertSame([], $r['theses']);
        $this->assertStringContainsString('not_falsifiable', $r['rejected'][0]['reason']);
    }

    public function test_rejects_thesis_with_improve_things_acceptance_path(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'acceptance_path' => 'should help improve things across the board',
        ])]);

        $this->assertSame([], $r['theses']);
        $this->assertStringContainsString('not_falsifiable', $r['rejected'][0]['reason']);
    }

    // ── mixed accept + reject ─────────────────────────────────────────────────

    public function test_some_clusters_accepted_some_rejected(): void
    {
        $good = $this->validCluster(['cluster_id' => 'good-01']);
        $bad = $this->validCluster(['cluster_id' => 'bad-01', 'capability_delta' => '']);

        $r = $this->forge->forge([$good, $bad]);

        $this->assertCount(1, $r['theses']);
        $this->assertCount(1, $r['rejected']);
        $this->assertSame('good-01', $r['theses'][0]['cluster_id']);
        $this->assertSame('bad-01', $r['rejected'][0]['cluster_id']);
    }

    // ── risk propagation ──────────────────────────────────────────────────────

    public function test_risk_propagates_from_cluster(): void
    {
        $r = $this->forge->forge([$this->validCluster(['risk' => 'high'])]);
        $this->assertSame(AtlasExternalBrainStrategicThesisForge::RISK_HIGH, $r['theses'][0]['risk']);
    }

    public function test_risk_falls_back_to_urgency_when_absent(): void
    {
        $cluster = $this->validCluster(['urgency' => 'low']);
        unset($cluster['risk']);

        $r = $this->forge->forge([$cluster]);
        $this->assertSame(AtlasExternalBrainStrategicThesisForge::RISK_LOW, $r['theses'][0]['risk']);
    }

    // ── opportunity-count in evidence demand ──────────────────────────────────

    public function test_evidence_demand_counts_opportunities(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'opportunities' => [
                ['description' => 'opp-1'],
                ['description' => 'opp-2'],
                ['description' => 'opp-3'],
            ],
        ])]);

        $demandStr = implode(' ', $r['theses'][0]['evidence_demand']);
        $this->assertStringContainsString('3', $demandStr, 'evidence demand must reference opportunity count');
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_input_returns_empty_result(): void
    {
        $r = $this->forge->forge([]);
        $this->assertSame([], $r['theses']);
        $this->assertSame([], $r['rejected']);
    }

    // ── AC1: task_chain on accepted thesis ────────────────────────────────────

    public function test_accepted_thesis_has_task_chain_key(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $this->assertArrayHasKey('task_chain', $r['theses'][0]);
    }

    public function test_task_chain_has_steps_array(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $chain = $r['theses'][0]['task_chain'];
        $this->assertArrayHasKey('steps', $chain);
        $this->assertIsArray($chain['steps']);
        $this->assertNotEmpty($chain['steps']);
    }

    public function test_each_task_chain_step_has_required_fields(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        foreach ($r['theses'][0]['task_chain']['steps'] as $step) {
            $this->assertArrayHasKey('order', $step);
            $this->assertArrayHasKey('shape', $step);
            $this->assertArrayHasKey('description', $step);
            $this->assertArrayHasKey('prerequisite_signals', $step);
            $this->assertArrayHasKey('expected_leverage_delta', $step);
            $this->assertArrayHasKey('proof_required', $step);
            $this->assertIsInt($step['order']);
            $this->assertIsArray($step['prerequisite_signals']);
            $this->assertIsString($step['expected_leverage_delta']);
            $this->assertIsString($step['proof_required']);
        }
    }

    public function test_task_chain_steps_are_ordered_sequentially(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $steps = $r['theses'][0]['task_chain']['steps'];
        foreach ($steps as $i => $step) {
            $this->assertSame($i + 1, $step['order']);
        }
    }

    public function test_implement_capability_step_has_high_leverage_and_prerequisite_signals(): void
    {
        $r = $this->forge->forge([$this->validCluster()]);
        $implStep = null;
        foreach ($r['theses'][0]['task_chain']['steps'] as $step) {
            if ($step['shape'] === 'implement_capability') {
                $implStep = $step;
                break;
            }
        }
        $this->assertNotNull($implStep, 'implement_capability step must exist');
        $this->assertSame('high', $implStep['expected_leverage_delta']);
        $this->assertNotEmpty($implStep['prerequisite_signals']);
        $this->assertNotEmpty($implStep['proof_required']);
    }

    // ── AC2: rejection when task_shapes incoherent ────────────────────────────

    public function test_rejects_cluster_when_task_shapes_lack_implementation_step(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'task_shapes' => [
                ['shape' => 'verify_acceptance', 'description' => 'Run tests to verify'],
                ['shape' => 'wire_to_consumers', 'description' => 'Wire to consumers'],
            ],
        ])]);

        $this->assertSame([], $r['theses']);
        $this->assertCount(1, $r['rejected']);
        $this->assertStringContainsString('task_shapes_incoherent', $r['rejected'][0]['reason']);
        $this->assertStringContainsString('missing_implementation_step', $r['rejected'][0]['reason']);
    }

    public function test_rejects_cluster_when_task_shapes_lack_verification_step(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'task_shapes' => [
                ['shape' => 'implement_capability', 'description' => 'Implement the service'],
                ['shape' => 'wire_to_consumers',   'description' => 'Wire to consumers'],
            ],
        ])]);

        $this->assertSame([], $r['theses']);
        $this->assertCount(1, $r['rejected']);
        $this->assertStringContainsString('task_shapes_incoherent', $r['rejected'][0]['reason']);
        $this->assertStringContainsString('missing_verification_step', $r['rejected'][0]['reason']);
    }

    public function test_accepts_cluster_with_coherent_custom_task_shapes(): void
    {
        $r = $this->forge->forge([$this->validCluster([
            'task_shapes' => [
                ['shape' => 'implement_capability', 'description' => 'Implement the service'],
                ['shape' => 'verify_acceptance',    'description' => 'Write tests'],
            ],
        ])]);

        $this->assertCount(1, $r['theses']);
        $this->assertSame([], $r['rejected']);
        $this->assertCount(2, $r['theses'][0]['task_chain']['steps']);
    }

    public function test_ac2_rejected_reason_identifies_which_step_is_missing(): void
    {
        // Test-only shapes → missing impl
        $r = $this->forge->forge([$this->validCluster([
            'task_shapes' => [
                ['shape' => 'add_tests', 'description' => 'Write tests'],
            ],
        ])]);

        $this->assertStringContainsString('missing_implementation_step', $r['rejected'][0]['reason']);
    }
}
