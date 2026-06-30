<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganMeshOrchestrator;
use Tests\TestCase;

final class AtlasExternalBrainOrganMeshOrchestratorTest extends TestCase
{
    private function svc(): AtlasExternalBrainOrganMeshOrchestrator
    {
        return new AtlasExternalBrainOrganMeshOrchestrator;
    }

    /** Build a full set of clean organ results for all 6 phases. */
    private function allClean(array $overrides = []): array
    {
        $base = [];
        foreach (AtlasExternalBrainOrganMeshOrchestrator::PHASES as $phase) {
            $base[$phase] = ['summary' => "{$phase} ok"];
        }

        return array_merge($base, $overrides);
    }

    private function orchestrate(array $organResults): array
    {
        return $this->svc()->orchestrate(['organ_results' => $organResults]);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_all_phases_present_and_clean_proceeds(): void
    {
        $r = $this->orchestrate($this->allClean());

        $this->assertSame('proceed', $r['final_decision']);
        $this->assertNull($r['blocking_phase']);
        $this->assertCount(6, $r['compact_trace']);
    }

    public function test_all_trace_statuses_are_passed_when_clean(): void
    {
        $r = $this->orchestrate($this->allClean());

        $statuses = array_column($r['compact_trace'], 'status');
        $this->assertSame(array_fill(0, 6, 'passed'), $statuses);
    }

    // ── fail-closed: missing phase ────────────────────────────────────────────

    public function test_missing_context_phase_blocks(): void
    {
        $results = $this->allClean();
        unset($results['context']);

        $r = $this->orchestrate($results);

        $this->assertSame('blocked', $r['final_decision']);
        $this->assertSame('context', $r['blocking_phase']);
    }

    public function test_missing_critique_phase_blocks_at_critique(): void
    {
        $results = $this->allClean();
        unset($results['critique']);

        $r = $this->orchestrate($results);

        $this->assertSame('critique', $r['blocking_phase']);
    }

    public function test_trace_ends_at_missing_phase_with_missing_status(): void
    {
        $results = $this->allClean();
        unset($results['value']);

        $r = $this->orchestrate($results);

        $lastEntry = end($r['compact_trace']);
        $this->assertSame('value', $lastEntry['phase']);
        $this->assertSame('missing', $lastEntry['status']);
    }

    // ── fail-closed: stale phase ──────────────────────────────────────────────

    public function test_stale_phase_blocks_fail_closed(): void
    {
        $r = $this->orchestrate($this->allClean(['proposal' => ['summary' => 'old', 'stale' => true]]));

        $this->assertSame('blocked', $r['final_decision']);
        $this->assertSame('proposal', $r['blocking_phase']);
        $staleEntry = array_filter($r['compact_trace'], static fn ($e) => $e['phase'] === 'proposal');
        $this->assertSame('stale', array_values($staleEntry)[0]['status']);
    }

    // ── fail-closed: contradictory phase ─────────────────────────────────────

    public function test_contradictory_phase_blocks_fail_closed(): void
    {
        $r = $this->orchestrate($this->allClean(['readiness' => ['summary' => 'conflict', 'contradictory' => true]]));

        $this->assertSame('blocked', $r['final_decision']);
        $this->assertSame('readiness', $r['blocking_phase']);
        $entry = array_filter($r['compact_trace'], static fn ($e) => $e['phase'] === 'readiness');
        $this->assertSame('contradictory', array_values($entry)[0]['status']);
    }

    // ── first blocking phase wins ─────────────────────────────────────────────

    public function test_first_stale_phase_in_order_is_blocking_phase(): void
    {
        $r = $this->orchestrate($this->allClean([
            'context' => ['stale' => true],
            'proposal' => ['stale' => true],
        ]));

        $this->assertSame('context', $r['blocking_phase']);
    }

    // ── constraints aggregation ───────────────────────────────────────────────

    public function test_constraints_aggregated_from_passing_phases(): void
    {
        $r = $this->orchestrate($this->allClean([
            'critique' => ['constraints' => ['no_proxy_tasks']],
            'value' => ['constraints' => ['prefer_high_leverage']],
        ]));

        $this->assertContains('no_proxy_tasks', $r['next_batch_constraints']);
        $this->assertContains('prefer_high_leverage', $r['next_batch_constraints']);
    }

    public function test_constraints_not_collected_after_blocking_phase(): void
    {
        // context blocks; critique has constraints but should not be reached
        $r = $this->orchestrate([
            'context' => ['stale' => true],
            'critique' => ['constraints' => ['should_not_appear']],
        ]);

        $this->assertNotContains('should_not_appear', $r['next_batch_constraints']);
    }

    // ── phase_outputs only contains passing phases ────────────────────────────

    public function test_phase_outputs_excludes_blocking_phase(): void
    {
        $r = $this->orchestrate($this->allClean(['critique' => ['contradictory' => true]]));

        $this->assertArrayNotHasKey('critique', $r['phase_outputs']);
        $this->assertArrayHasKey('context', $r['phase_outputs']);
        $this->assertArrayHasKey('proposal', $r['phase_outputs']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->orchestrate([]);

        $this->assertSame(AtlasExternalBrainOrganMeshOrchestrator::SCHEMA, $r['schema_version']);
    }

    // ── buildMesh(): typed organ circuits ─────────────────────────────────────

    private function organ(string $id, array $inputs, array $outputs): array
    {
        return ['organ_id' => $id, 'input_contracts' => $inputs, 'output_contracts' => $outputs];
    }

    public function test_build_mesh_has_required_keys(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [$this->organ('a', [], ['x'])],
            'edges' => [],
        ]);

        foreach (['organs', 'execution_order', 'cycle_detected', 'feedback_edges', 'orphan_organs', 'mesh_status', 'invalid_edges', 'next_integration_task'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_valid_chain_produces_execution_order_and_valid_status(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [
                $this->organ('a', [], ['evidence']),
                $this->organ('b', ['evidence'], ['candidates']),
                $this->organ('c', ['candidates'], ['decision']),
            ],
            'edges' => [
                ['from' => 'a', 'to' => 'b', 'type' => 'data'],
                ['from' => 'b', 'to' => 'c', 'type' => 'data'],
            ],
        ]);

        $this->assertSame('valid', $result['mesh_status']);
        $this->assertSame(['a', 'b', 'c'], $result['execution_order']);
        $this->assertSame([], $result['invalid_edges']);
        $this->assertSame([], $result['orphan_organs']);
        $this->assertFalse($result['cycle_detected']);
        $this->assertNull($result['next_integration_task']);
    }

    public function test_missing_contract_match_is_flagged_invalid(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [
                $this->organ('a', [], ['evidence']),
                $this->organ('b', ['candidates'], ['decision']),
            ],
            'edges' => [
                ['from' => 'a', 'to' => 'b', 'type' => 'data'],
            ],
        ]);

        $this->assertSame('invalid', $result['mesh_status']);
        $this->assertCount(1, $result['invalid_edges']);
        $this->assertSame('missing_contract_match', $result['invalid_edges'][0]['reason']);
        $this->assertNotNull($result['next_integration_task']);
    }

    public function test_cycle_without_termination_is_detected(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [
                $this->organ('a', ['z'], ['x']),
                $this->organ('b', ['x'], ['z']),
            ],
            'edges' => [
                ['from' => 'a', 'to' => 'b', 'type' => 'data'],
                ['from' => 'b', 'to' => 'a', 'type' => 'data'],
            ],
        ]);

        $this->assertTrue($result['cycle_detected']);
        $this->assertSame('invalid', $result['mesh_status']);
        $this->assertSame([], $result['execution_order']);
    }

    public function test_feedback_edges_do_not_count_as_data_cycles(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [
                $this->organ('a', ['z'], ['x']),
                $this->organ('b', ['x'], ['z']),
            ],
            'edges' => [
                ['from' => 'a', 'to' => 'b', 'type' => 'data'],
                ['from' => 'b', 'to' => 'a', 'type' => 'feedback'],
            ],
        ]);

        $this->assertFalse($result['cycle_detected']);
        $this->assertSame('valid', $result['mesh_status']);
        $this->assertCount(1, $result['feedback_edges']);
        $this->assertSame(['a', 'b'], $result['execution_order']);
    }

    public function test_orphan_organ_with_no_edges_is_flagged(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [
                $this->organ('a', [], ['x']),
                $this->organ('b', ['x'], ['y']),
                $this->organ('orphan', [], ['unused']),
            ],
            'edges' => [
                ['from' => 'a', 'to' => 'b', 'type' => 'data'],
            ],
        ]);

        $this->assertContains('orphan', $result['orphan_organs']);
        $this->assertSame('orphan', $result['orphan_organs'][0]);
    }

    public function test_unknown_organ_in_edge_is_invalid(): void
    {
        $result = $this->svc()->buildMesh([
            'organs' => [$this->organ('a', [], ['x'])],
            'edges' => [['from' => 'a', 'to' => 'ghost', 'type' => 'data']],
        ]);

        $this->assertSame('invalid', $result['mesh_status']);
        $this->assertSame('unknown_organ_in_edge', $result['invalid_edges'][0]['reason']);
    }
}
