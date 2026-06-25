<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionFinalEvidenceSourceRegistry;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceSourceRegistryTest extends TestCase
{
    public function test_describe_lists_all_required_sources(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $ids = array_column($verdict['required_sources'], 'id');
        $expected = [
            'task_serving_contract_sentinel',
            'code_index_readiness_bridge',
            'multi_project_governance_dossier',
            'native_worker_readiness',
            'verification_court',
            'merge_governor',
            'rollback',
            'receipts',
            'learning_transfer',
            'docs_health',
            'knowledge_sync',
            'task_graph_coverage_dossier',
            'task_graph_autonomous_replenisher',
        ];
        foreach ($expected as $id) {
            $this->assertContains($id, $ids, "missing required source: {$id}");
        }
        $this->assertSame(count($expected), count($ids));
    }

    public function test_task_graph_autonomous_replenisher_is_required_with_correct_fields(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'task_graph_autonomous_replenisher') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row, 'task_graph_autonomous_replenisher must be in the required sources list');
        $this->assertTrue($row['blocking']);
        $this->assertTrue($row['refreshable']);
        $this->assertSame('task_fabric_final_coverage', $row['group']);
        $this->assertContains('task_graph_autonomous_replenisher', $verdict['blocking_source_ids']);
        foreach (['plan_hash', 'dry_run', 'applied_count', 'withheld_count', 'duplicate_count', 'replenisher_hash'] as $field) {
            $this->assertContains($field, $row['required_fields'], "missing required field: {$field}");
        }
    }

    public function test_completion_not_ready_when_replenisher_source_absent(): void
    {
        // The evidence verifier marks every blocking source missing the matching `sources` entry as missing.
        // Here we simulate the legacy facts shape without any replenisher signal — the registry guarantees
        // it remains a blocking required source so the verifier MUST flag it as missing.
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $ids = array_column($verdict['required_sources'], 'id');
        $this->assertContains('task_graph_autonomous_replenisher', $ids);

        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verifierVerdict = $verifier->verify([
            'sources' => [
                'task_serving_contract_sentinel' => ['status' => 'pass'],
                'code_index_readiness_bridge' => ['status' => 'pass'],
                'multi_project_governance_dossier' => ['status' => 'pass'],
                'native_worker_readiness' => ['status' => 'pass'],
                'verification_court' => ['status' => 'pass'],
                'merge_governor' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
                'receipts' => ['status' => 'pass'],
                'learning_transfer' => ['status' => 'pass'],
                'docs_health' => ['status' => 'pass'],
                'knowledge_sync' => ['status' => 'pass'],
                'task_graph_coverage_dossier' => ['status' => 'pass'],
                // task_graph_autonomous_replenisher is INTENTIONALLY absent.
            ],
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
        ]);

        $this->assertFalse($verifierVerdict['passed']);
        $this->assertContains('source_missing:task_graph_autonomous_replenisher', $verifierVerdict['blockers']);
    }

    public function test_completion_can_accept_a_green_replenisher_receipt(): void
    {
        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verifierVerdict = $verifier->verify([
            'sources' => [
                'task_serving_contract_sentinel' => ['status' => 'pass'],
                'code_index_readiness_bridge' => ['status' => 'pass'],
                'multi_project_governance_dossier' => ['status' => 'pass'],
                'native_worker_readiness' => ['status' => 'pass'],
                'verification_court' => ['status' => 'pass'],
                'merge_governor' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
                'receipts' => ['status' => 'pass'],
                'learning_transfer' => ['status' => 'pass'],
                'docs_health' => ['status' => 'pass'],
                'knowledge_sync' => ['status' => 'pass'],
                'task_graph_coverage_dossier' => ['status' => 'pass'],
                'task_graph_autonomous_replenisher' => ['status' => 'pass'],
            ],
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
        ]);

        $this->assertTrue($verifierVerdict['passed']);
        $this->assertSame([], $verifierVerdict['blockers']);
    }

    public function test_no_human_or_external_provider_dependency_was_introduced(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'task_graph_autonomous_replenisher') {
                $row = $s;
                break;
            }
        }
        $json = (string) json_encode($row);
        // The replenisher source MUST NOT carry any operator/human/provider markers.
        $this->assertStringNotContainsStringIgnoringCase('operator_required', $json);
        $this->assertStringNotContainsStringIgnoringCase('human_required', $json);
        $this->assertStringNotContainsStringIgnoringCase('external_provider', $json);
    }

    public function test_every_required_source_is_blocking(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        foreach ($verdict['required_sources'] as $s) {
            $this->assertTrue($s['blocking'], "source {$s['id']} must be blocking for final ready state");
        }
        $this->assertSame(count($verdict['required_sources']), count($verdict['blocking_source_ids']));
    }

    public function test_refreshable_sources_are_labelled_separately_from_unsafe_blockers(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $refreshable = array_values(array_filter($verdict['required_sources'], fn (array $s): bool => $s['refreshable']));
        $unsafe = array_values(array_filter($verdict['required_sources'], fn (array $s): bool => ! $s['refreshable']));

        $this->assertGreaterThan(0, count($refreshable), 'at least one refreshable source must exist');
        $this->assertGreaterThan(0, count($unsafe), 'at least one unsafe (non-refreshable) blocker must exist');
        $this->assertSame(count($refreshable), $verdict['proof_summary']['refreshable_count']);
        $this->assertSame(count($unsafe), $verdict['proof_summary']['unsafe_blocker_count']);

        $refreshIds = array_column($refreshable, 'id');
        $this->assertContains('code_index_readiness_bridge', $refreshIds);
        $this->assertContains('docs_health', $refreshIds);
        $this->assertContains('knowledge_sync', $refreshIds);

        $unsafeIds = array_column($unsafe, 'id');
        $this->assertContains('task_serving_contract_sentinel', $unsafeIds);
        $this->assertContains('verification_court', $unsafeIds);
        $this->assertContains('rollback', $unsafeIds);
    }

    public function test_output_is_deterministic_across_invocations(): void
    {
        $a = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $b = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $this->assertSame(
            json_encode($a, JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_no_duplicate_source_ids(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $ids = array_column($verdict['required_sources'], 'id');
        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_task_graph_coverage_dossier_is_blocking_and_grouped_correctly(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'task_graph_coverage_dossier') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row, 'task_graph_coverage_dossier must be in the required sources list');
        $this->assertTrue($row['blocking']);
        $this->assertSame('task_fabric_final_coverage', $row['group']);
        $this->assertContains('task_graph_coverage_dossier', $verdict['blocking_source_ids']);
        $this->assertArrayHasKey('task_fabric_final_coverage', $verdict['source_groups']);
        $this->assertContains('task_graph_coverage_dossier', $verdict['source_groups']['task_fabric_final_coverage']);
    }
}
