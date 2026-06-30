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
            'unattended_runtime_supervisor',
            'scope_expansion_governor',
            'native_worker_runtime',
            'runtime_daemon',
            'runtime_soak',
            'multi_project_runtime_instances',
        ];
        foreach ($expected as $id) {
            $this->assertContains($id, $ids, "missing required source: {$id}");
        }
        $this->assertSame(count($expected), count($ids));
    }

    public function test_native_worker_runtime_source_present_with_runtime_proof_and_no_external_dependency(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'native_worker_runtime') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertTrue($row['blocking']);
        $this->assertSame('atlas.native_worker.pool_supervisor.v1', $row['schema_version']);
        foreach (['claim', 'envelope', 'materialization', 'command_gates', 'evidence_write', 'report_outcome_mapping', 'one_dry_run_cycle', 'one_apply_mode_cycle'] as $proof) {
            $this->assertContains($proof, $row['required_runtime_proof']);
        }
        foreach (['operator', 'human', 'claude_code', 'codex', 'cursor', 'external_provider'] as $forbidden) {
            $this->assertContains($forbidden, $row['requires_no_dependency_on']);
        }
    }

    public function test_completion_cannot_ignore_missing_native_worker_runtime_proof(): void
    {
        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verdict = $verifier->verify([
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
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                'scope_expansion_governor' => ['status' => 'pass'],
                // native_worker_runtime intentionally absent
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

        $this->assertFalse($verdict['passed']);
        $this->assertContains('source_missing:native_worker_runtime', $verdict['blockers']);
    }

    public function test_scope_expansion_governor_is_required_with_correct_schema_and_fields(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'scope_expansion_governor') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row, 'scope_expansion_governor must be in the required sources list');
        $this->assertTrue($row['blocking']);
        $this->assertTrue($row['refreshable']);
        $this->assertSame('atlas.self_construction.scope_expansion_governor_cycle.v1', $row['schema_version']);
        $this->assertContains('scope_expansion_governor', $verdict['blocking_source_ids']);
        foreach (['admitted_count', 'withheld_count', 'applied_actions', 'blocked_actions', 'withheld_actions', 'governor_cycle_hash'] as $field) {
            $this->assertContains($field, $row['required_fields'], "missing required field: {$field}");
        }
        foreach (['operator', 'human', 'external_provider'] as $forbidden) {
            $this->assertContains($forbidden, $row['requires_no_dependency_on']);
        }
        $this->assertSame('multi_project_governance_dossier', $row['links_to_when_external_project_lane']);
    }

    public function test_final_completion_cannot_ignore_missing_scope_expansion_governor_proof(): void
    {
        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verdict = $verifier->verify([
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
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                // scope_expansion_governor intentionally absent.
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

        $this->assertFalse($verdict['passed']);
        $this->assertContains('source_missing:scope_expansion_governor', $verdict['blockers']);
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
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                'scope_expansion_governor' => ['status' => 'pass'],
                'native_worker_runtime' => ['status' => 'pass'],
                'runtime_daemon' => ['status' => 'pass'],
                'runtime_soak' => ['status' => 'pass'],
                'multi_project_runtime_instances' => ['status' => 'pass'],
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

    public function test_multi_project_runtime_instances_source_present_with_correct_schemas(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'multi_project_runtime_instances') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertTrue($row['blocking']);
        foreach (['atlas.project_lane.runtime_instance_registry.v1', 'atlas.project_lane.runtime_instance_scheduler.v1', 'atlas.project_lane.runtime_instance_cycle_runner.v1', 'atlas.project_lane.runtime_instance_soak.v1'] as $schema) {
            $this->assertContains($schema, $row['schema_versions']);
        }
        foreach (['lane_runtime_instance_registry_built', 'lane_scheduler_plan_emitted', 'lane_cycle_runner_dry_run_and_apply', 'cross_project_soak_isolation_proven'] as $p) {
            $this->assertContains($p, $row['required_runtime_proof']);
        }
        foreach (['operator', 'human', 'claude_code', 'codex', 'cursor', 'external_provider', 'git', 'network', 'unrestricted_shell'] as $forbidden) {
            $this->assertContains($forbidden, $row['requires_no_dependency_on']);
        }
        $this->assertContains('runtime_soak', $row['serialized_after']);
    }

    public function test_final_completion_cannot_ignore_missing_multi_project_runtime_proof(): void
    {
        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verdict = $verifier->verify([
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
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                'scope_expansion_governor' => ['status' => 'pass'],
                'native_worker_runtime' => ['status' => 'pass'],
                'runtime_daemon' => ['status' => 'pass'],
                'runtime_soak' => ['status' => 'pass'],
                // multi_project_runtime_instances intentionally absent.
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

        $this->assertFalse($verdict['passed']);
        $this->assertContains('source_missing:multi_project_runtime_instances', $verdict['blockers']);
    }

    public function test_runtime_daemon_source_present_with_schemas_and_runtime_proof(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'runtime_daemon') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertTrue($row['blocking']);
        $this->assertContains('atlas.self_construction.runtime_daemon_cycle.v1', $row['schema_versions']);
        $this->assertContains('atlas.self_construction.runtime_scheduler_manifest.v1', $row['schema_versions']);
        foreach (['daemon_dry_run_tick', 'daemon_apply_mode_tick_through_injected_callbacks', 'heartbeat_and_state_handling', 'scheduler_manifest_present', 'safety_stop_blocks_apply'] as $p) {
            $this->assertContains($p, $row['required_runtime_proof']);
        }
        foreach (['operator', 'human', 'claude_code', 'codex', 'cursor', 'external_provider', 'git', 'network', 'unrestricted_shell'] as $forbidden) {
            $this->assertContains($forbidden, $row['requires_no_dependency_on']);
        }
    }

    public function test_final_completion_cannot_ignore_missing_runtime_daemon_proof(): void
    {
        $verifier = new \App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier();
        $verdict = $verifier->verify([
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
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                'scope_expansion_governor' => ['status' => 'pass'],
                'native_worker_runtime' => ['status' => 'pass'],
                // runtime_daemon intentionally absent
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

        $this->assertFalse($verdict['passed']);
        $this->assertContains('source_missing:runtime_daemon', $verdict['blockers']);
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

    /**
     * Helper that mirrors how an upstream verifier interprets an unattended-supervisor receipt
     * against the registry. Returns one of: missing | stale | blocked | unapplied | pass.
     *
     * @param  array<string,mixed>|null  $receipt
     */
    private function evaluateUnattended(?array $receipt): string
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $source = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'unattended_runtime_supervisor') {
                $source = $s;
                break;
            }
        }
        $this->assertNotNull($source);
        if ($receipt === null) {
            return 'missing';
        }
        foreach ((array) $source['required_fields'] as $field) {
            if (! array_key_exists($field, $receipt)) {
                return 'stale';
            }
        }
        $classification = (string) ($receipt['classification'] ?? '');
        if (in_array($classification, (array) $source['unsafe_classifications'], true)) {
            return 'blocked';
        }
        $planned = (array) ($receipt['planned_actions'] ?? []);
        $applied = (array) ($receipt['applied_actions'] ?? []);
        $dryRun = (bool) ($receipt['dry_run'] ?? true);
        if ($planned !== [] && $dryRun) {
            return 'unapplied';
        }
        if ($planned !== [] && count($applied) < count($planned)) {
            return 'unapplied';
        }
        if (in_array($classification, (array) $source['safe_classifications'], true)) {
            return 'pass';
        }

        return 'blocked';
    }

    public function test_unattended_runtime_supervisor_source_is_present_with_required_fields(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'unattended_runtime_supervisor') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row, 'unattended_runtime_supervisor must be in the registry');
        $this->assertTrue($row['blocking']);
        foreach (['snapshot_hash', 'classifier_hash', 'plan_hash', 'supervisor_cycle_hash', 'dry_run', 'applied_actions', 'blocked_actions'] as $field) {
            $this->assertContains($field, $row['required_fields'], "missing required field: {$field}");
        }
        $this->assertContains('unattended_runtime_supervisor', $verdict['blocking_source_ids']);
    }

    public function test_unattended_supervisor_missing_keeps_completion_not_ready(): void
    {
        $this->assertSame('missing', $this->evaluateUnattended(null));
    }

    public function test_unattended_supervisor_stale_or_unsafe_blocks_completion(): void
    {
        $stale = ['classification' => 'healthy', 'snapshot_hash' => 'h'];
        $this->assertSame('stale', $this->evaluateUnattended($stale));

        $unsafe = $this->fullSupervisorReceipt(['classification' => 'unsafe_stop']);
        $this->assertSame('blocked', $this->evaluateUnattended($unsafe));
    }

    public function test_unattended_supervisor_unapplied_safe_actions_blocks_completion(): void
    {
        $receipt = $this->fullSupervisorReceipt([
            'classification' => 'queue_dry',
            'dry_run' => true,
            'planned_actions' => [['action' => 'run_replenisher_dry_run']],
            'applied_actions' => [],
        ]);

        $this->assertSame('unapplied', $this->evaluateUnattended($receipt));
    }

    public function test_unattended_supervisor_green_receipt_passes(): void
    {
        $healthy = $this->fullSupervisorReceipt(['classification' => 'healthy']);
        $this->assertSame('pass', $this->evaluateUnattended($healthy));

        $appliedThroughCallbacks = $this->fullSupervisorReceipt([
            'classification' => 'queue_dry',
            'dry_run' => false,
            'planned_actions' => [['action' => 'run_replenisher_dry_run']],
            'applied_actions' => [['action' => 'run_replenisher_dry_run', 'applied' => true]],
        ]);
        $this->assertSame('pass', $this->evaluateUnattended($appliedThroughCallbacks));
    }

    public function test_unattended_supervisor_source_introduces_no_non_atlas_actor(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'unattended_runtime_supervisor') {
                $row = $s;
                break;
            }
        }
        $json = strtolower((string) json_encode($row));
        $this->assertStringNotContainsString('claude_code', $json);
        $this->assertStringNotContainsString('codex', $json);
        $this->assertStringNotContainsString('operator_required', $json);
        $this->assertStringNotContainsString('external_assistant', $json);
        $this->assertStringNotContainsString('external_provider', $json);
    }

    /**
     * @param  array<string,mixed>  $override
     * @return array<string,mixed>
     */
    private function fullSupervisorReceipt(array $override = []): array
    {
        return array_replace([
            'classification' => 'healthy',
            'snapshot_hash' => 'snap_hash',
            'classifier_hash' => 'classifier_hash',
            'plan_hash' => 'plan_hash',
            'supervisor_cycle_hash' => 'cycle_hash',
            'dry_run' => false,
            'planned_actions' => [],
            'applied_actions' => [],
            'blocked_actions' => [],
        ], $override);
    }

    public function test_runtime_soak_source_is_registered_with_required_schemas_and_cases(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'runtime_soak') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row, 'runtime_soak source must be in the registry');
        $this->assertTrue($row['blocking']);
        $this->assertContains('atlas.self_construction.runtime_soak_runner.v1', $row['schema_versions']);
        $this->assertContains('atlas.self_construction.runtime_regression_auditor.v1', $row['schema_versions']);
        foreach (['green_cycle', 'empty_queue_replenish', 'give_back_repair', 'failed_gate_hold', 'stale_heartbeat_recovery', 'pause_resume', 'safety_stop', 'scope_expansion'] as $case) {
            $this->assertContains($case, $row['required_cases'], "runtime_soak must require case: {$case}");
        }
        foreach (['operator', 'human', 'external_provider', 'claude_code', 'codex', 'cursor', 'git', 'network', 'unrestricted_shell'] as $forbidden) {
            $this->assertContains($forbidden, $row['requires_no_dependency_on'], "runtime_soak must forbid dependency on: {$forbidden}");
        }
        foreach (['soak_report_missing', 'fake_green_soak', 'zero_tick_soak', 'missing_recovery_evidence', 'dependency_regression_present'] as $reject) {
            $this->assertContains($reject, $row['rejects_when'], "runtime_soak must reject when: {$reject}");
        }
    }

    public function test_verify_observed_sources_missing_required_id_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        // Supply every required source except 'rollback'.
        $description = $registry->describe();
        $observed = [];
        foreach ($description['required_sources'] as $source) {
            if ($source['id'] !== 'rollback') {
                $observed[$source['id']] = ['evidence_kind' => $source['evidence_kinds'][0], 'stale' => false];
            }
        }

        $result = $registry->verifyObservedSources($observed);

        $this->assertFalse($result['passed']);
        $this->assertContains('source_missing:rollback', $result['blockers']);
    }

    public function test_verify_observed_sources_unknown_extra_id_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $observed[$source['id']] = ['evidence_kind' => $source['evidence_kinds'][0], 'stale' => false];
        }
        $observed['ghost_source'] = ['evidence_kind' => 'ghost_kind'];

        $result = $registry->verifyObservedSources($observed);

        $this->assertFalse($result['passed']);
        $this->assertContains('source_unknown:ghost_source', $result['blockers']);
    }

    public function test_verify_observed_sources_evidence_kind_mismatch_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $kind = $source['id'] === 'rollback' ? 'wrong_kind_entirely' : $source['evidence_kinds'][0];
            $observed[$source['id']] = ['evidence_kind' => $kind, 'stale' => false];
        }

        $result = $registry->verifyObservedSources($observed);

        $this->assertFalse($result['passed']);
        $mismatch = array_values(array_filter($result['blockers'], static fn (string $b): bool => str_starts_with($b, 'evidence_kind_mismatch:rollback:')));
        $this->assertNotEmpty($mismatch, 'expected evidence_kind_mismatch:rollback:... blocker');
    }

    public function test_verify_observed_sources_stale_refreshable_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $stale = $source['id'] === 'docs_health'; // docs_health is refreshable
            $observed[$source['id']] = ['evidence_kind' => $source['evidence_kinds'][0], 'stale' => $stale];
        }

        $result = $registry->verifyObservedSources($observed);

        $this->assertFalse($result['passed']);
        $this->assertContains('source_stale_refreshable:docs_health', $result['blockers']);
    }

    public function test_verify_observed_sources_complete_facts_produce_no_blockers(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $observed[$source['id']] = ['evidence_kind' => $source['evidence_kinds'][0], 'stale' => false];
        }

        $result = $registry->verifyObservedSources($observed);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blockers']);
    }

    public function test_runtime_soak_is_serialized_after_runtime_daemon_and_replenisher(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $row = null;
        foreach ($verdict['required_sources'] as $s) {
            if ($s['id'] === 'runtime_soak') {
                $row = $s;
                break;
            }
        }
        $this->assertNotNull($row);
        $this->assertContains('task_graph_autonomous_replenisher', $row['serialized_after']);
        $this->assertContains('scope_expansion_governor', $row['serialized_after']);
    }

    // ── freshness_window_seconds + authority_level ────────────────────────────

    public function test_every_source_has_freshness_window_and_authority_level(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        foreach ($verdict['required_sources'] as $s) {
            $this->assertArrayHasKey('freshness_window_seconds', $s, "{$s['id']} missing freshness_window_seconds");
            $this->assertGreaterThan(0, $s['freshness_window_seconds'], "{$s['id']} freshness_window_seconds must be > 0");
            $this->assertArrayHasKey('authority_level', $s, "{$s['id']} missing authority_level");
            $this->assertNotEmpty($s['authority_level'], "{$s['id']} authority_level must not be empty");
        }
    }

    public function test_refreshable_sources_have_tighter_freshness_window_than_unsafe(): void
    {
        $verdict = (new AtlasSelfConstructionFinalEvidenceSourceRegistry)->describe();
        $refreshableWindows = [];
        $unsafeWindows = [];
        foreach ($verdict['required_sources'] as $s) {
            if ($s['refreshable']) {
                $refreshableWindows[] = (int) $s['freshness_window_seconds'];
            } else {
                $unsafeWindows[] = (int) $s['freshness_window_seconds'];
            }
        }
        $this->assertLessThanOrEqual(min($unsafeWindows), max($refreshableWindows), 'refreshable sources must not exceed the tightest unsafe-blocker freshness window');
    }

    // ── stale timestamp check ─────────────────────────────────────────────────

    public function test_verify_observed_sources_stale_timestamp_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $rollback = null;
        foreach ($description['required_sources'] as $s) {
            if ($s['id'] === 'rollback') {
                $rollback = $s;
                break;
            }
        }
        $this->assertNotNull($rollback);

        $window = (int) $rollback['freshness_window_seconds'];
        $nowUnix = 1_000_000_000;
        $tooOld  = $nowUnix - $window - 1;

        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $observed[$source['id']] = [
                'evidence_kind'   => $source['evidence_kinds'][0],
                'stale'           => false,
                'generated_at_unix' => $source['id'] === 'rollback' ? $tooOld : $nowUnix,
            ];
        }

        $result = $registry->verifyObservedSources($observed, $nowUnix);

        $this->assertFalse($result['passed']);
        $this->assertContains('source_stale_timestamp:rollback', $result['blockers']);
    }

    public function test_verify_observed_sources_fresh_timestamp_passes(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();
        $nowUnix = 1_000_000_000;

        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $observed[$source['id']] = [
                'evidence_kind'    => $source['evidence_kinds'][0],
                'stale'            => false,
                'generated_at_unix'=> $nowUnix - 60, // 60s old — within any reasonable window
            ];
        }

        $result = $registry->verifyObservedSources($observed, $nowUnix);

        $this->assertTrue($result['passed']);
        $this->assertSame([], $result['blockers']);
    }

    // ── required fields check ─────────────────────────────────────────────────

    public function test_verify_observed_sources_missing_required_field_produces_blocker(): void
    {
        $registry = new AtlasSelfConstructionFinalEvidenceSourceRegistry;
        $description = $registry->describe();

        // Find a source that has required_fields.
        $targetSource = null;
        foreach ($description['required_sources'] as $s) {
            if (! empty($s['required_fields'])) {
                $targetSource = $s;
                break;
            }
        }
        $this->assertNotNull($targetSource, 'at least one source must have required_fields');

        $observed = [];
        foreach ($description['required_sources'] as $source) {
            $entry = ['evidence_kind' => $source['evidence_kinds'][0], 'stale' => false];
            if ($source['id'] === $targetSource['id']) {
                // Supply the fields list but deliberately omit the first required field.
                $missing = $targetSource['required_fields'][0];
                $entry['fields'] = array_slice($targetSource['required_fields'], 1);
            }
            $observed[$source['id']] = $entry;
        }

        $result = $registry->verifyObservedSources($observed);

        $this->assertFalse($result['passed']);
        $found = false;
        foreach ($result['blockers'] as $b) {
            if (str_starts_with($b, 'source_missing_required_field:'.$targetSource['id'].':')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'expected source_missing_required_field blocker for '.$targetSource['id']);
    }
}
