<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionAtlasNativeEvidenceVerifier;
use Tests\TestCase;

final class AtlasSelfConstructionAtlasNativeEvidenceVerifierTest extends TestCase
{
    /** @return array<string,mixed> */
    private function readyFacts(array $overrides = []): array
    {
        return array_replace_recursive([
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
            'sources' => [
                'code_index_readiness_bridge' => ['status' => 'pass'],
                'docs_health' => ['status' => 'pass'],
                'knowledge_sync' => ['status' => 'pass'],
                'learning_transfer' => ['status' => 'pass'],
                'merge_governor' => ['status' => 'pass'],
                'multi_project_governance_dossier' => ['status' => 'pass'],
                'multi_project_runtime_instances' => ['status' => 'pass'],
                'native_worker_readiness' => ['status' => 'pass'],
                'native_worker_runtime' => ['status' => 'pass'],
                'receipts' => ['status' => 'pass'],
                'rollback' => ['status' => 'pass'],
                'runtime_daemon' => ['status' => 'pass'],
                'runtime_soak' => ['status' => 'pass'],
                'scope_expansion_governor' => ['status' => 'pass'],
                'task_graph_autonomous_replenisher' => ['status' => 'pass'],
                'task_graph_coverage_dossier' => ['status' => 'pass'],
                'task_serving_contract_sentinel' => ['status' => 'pass'],
                'unattended_runtime_supervisor' => ['status' => 'pass'],
                'verification_court' => ['status' => 'pass'],
            ],
        ], $overrides);
    }

    public function test_ready_when_all_sources_pass(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyFacts());

        $this->assertTrue($verdict['passed']);
        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_READY, $verdict['status']);
        $this->assertSame([], $verdict['source_blockers']);
        $this->assertSame([], $verdict['autonomy_contract_blockers']);
    }

    public function test_hold_when_code_index_readiness_bridge_missing_refreshable(): void
    {
        $facts = $this->readyFacts();
        unset($facts['sources']['code_index_readiness_bridge']);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_HOLD, $verdict['status']);
        $sourceIds = array_column($verdict['source_blockers'], 'source_id');
        $this->assertContains('code_index_readiness_bridge', $sourceIds);
        $row = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['source_id'] === 'code_index_readiness_bridge'))[0];
        $this->assertSame('source_missing', $row['kind']);
        $this->assertTrue($row['refreshable']);
    }

    public function test_hold_when_docs_health_is_stale_refreshable(): void
    {
        $facts = $this->readyFacts(['sources' => ['docs_health' => ['status' => 'stale']]]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_HOLD, $verdict['status']);
        $sourceIds = array_column($verdict['source_blockers'], 'source_id');
        $this->assertContains('docs_health', $sourceIds);
    }

    public function test_blocked_when_task_serving_contract_sentinel_fails_unsafe(): void
    {
        $facts = $this->readyFacts(['sources' => ['task_serving_contract_sentinel' => ['status' => 'fail', 'note' => 'simplicity_contract_violation']]]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $row = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['source_id'] === 'task_serving_contract_sentinel'))[0];
        $this->assertSame('source_failed', $row['kind']);
        $this->assertFalse($row['refreshable']);
    }

    public function test_blocked_when_multi_project_governance_dossier_contradictory_leak(): void
    {
        $facts = $this->readyFacts(['sources' => ['multi_project_governance_dossier' => ['status' => 'contradictory', 'note' => 'cross_project_leak']]]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $row = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['source_id'] === 'multi_project_governance_dossier'))[0];
        $this->assertSame('source_contradictory', $row['kind']);
    }

    public function test_blocked_when_merge_governor_fails_unsafe(): void
    {
        $facts = $this->readyFacts(['sources' => ['merge_governor' => ['status' => 'fail']]]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $row = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['source_id'] === 'merge_governor'))[0];
        $this->assertSame('source_failed', $row['kind']);
    }

    public function test_blocked_when_autonomy_contract_violated(): void
    {
        $facts = $this->readyFacts();
        $facts['autonomy_dependencies']['depends_on_claude_code'] = true;

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('autonomy_dependency_true:depends_on_claude_code', $verdict['autonomy_contract_blockers']);
    }

    // --- source binding tests ------------------------------------------------

    /** All 11 sources with the 5 required binding fields. */
    private function readyBoundFacts(array $overrides = []): array
    {
        $sourceIds = [
            'code_index_readiness_bridge', 'docs_health', 'knowledge_sync',
            'learning_transfer', 'merge_governor', 'multi_project_governance_dossier',
            'multi_project_runtime_instances', 'native_worker_readiness', 'native_worker_runtime',
            'receipts', 'rollback', 'runtime_daemon', 'runtime_soak',
            'scope_expansion_governor', 'task_graph_autonomous_replenisher',
            'task_graph_coverage_dossier', 'task_serving_contract_sentinel',
            'unattended_runtime_supervisor', 'verification_court',
        ];
        $sources = [];
        foreach ($sourceIds as $id) {
            $sources[$id] = [
                'status' => 'pass',
                'source_id' => $id,
                'evidence_kind' => 'test_gate',
                'generated_at_unix' => 1751284800,
                'workspace_id' => 'atlas-server',
                'receipt_hash' => 'abc123receipt',
                'source_hash' => 'sha256:aabbccdd',
                'observed_at' => 1751284800,
                'replay_command_hash' => 'sha256:replay001',
            ];
        }

        return array_replace_recursive([
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => 'atlas_server',
            'autonomy_dependencies' => [
                'depends_on_operator' => false,
                'depends_on_claude_code' => false,
                'depends_on_codex' => false,
                'depends_on_external_provider_network' => false,
            ],
            'require_source_binding' => true,
            'workspace_id' => 'atlas-server',
            'sources' => $sources,
        ], $overrides);
    }

    public function test_missing_source_id_in_bound_source_blocks_evidence(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['task_serving_contract_sentinel' => ['source_id' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_source_id:task_serving_contract_sentinel', $verdict['blockers']);
    }

    public function test_wrong_evidence_kind_blocks_evidence(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['native_worker_readiness' => ['evidence_kind' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_evidence_kind:native_worker_readiness', $verdict['blockers']);
    }

    public function test_missing_generated_at_unix_blocks_evidence(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['merge_governor' => ['generated_at_unix' => 0]],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_generated_at_unix:merge_governor', $verdict['blockers']);
    }

    public function test_workspace_mismatch_blocks_evidence(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['rollback' => ['workspace_id' => 'wrong-workspace']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $found = false;
        foreach ($verdict['blockers'] as $b) {
            if (str_starts_with($b, 'source_binding_workspace_mismatch:') && str_contains($b, 'rollback')) {
                $found = true;
            }
        }
        $this->assertTrue($found, 'workspace mismatch blocker must be present for rollback source');
    }

    public function test_missing_receipt_hash_blocks_evidence(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['knowledge_sync' => ['receipt_hash' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_receipt_hash:knowledge_sync', $verdict['blockers']);
    }

    public function test_complete_bound_source_evidence_passes(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyBoundFacts());

        $this->assertTrue($verdict['passed']);
        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_READY, $verdict['status']);
        $this->assertSame([], $verdict['source_blockers']);
    }

    // ── replay-proof binding fields ───────────────────────────────────────────

    public function test_missing_workspace_id_in_bound_source_becomes_source_blocker(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['task_serving_contract_sentinel' => ['workspace_id' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_workspace_id:task_serving_contract_sentinel', $verdict['blockers']);
    }

    public function test_missing_source_hash_in_bound_source_becomes_source_blocker(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['merge_governor' => ['source_hash' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_source_hash:merge_governor', $verdict['blockers']);
    }

    public function test_missing_observed_at_in_bound_source_becomes_source_blocker(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['verification_court' => ['observed_at' => 0]],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_observed_at:verification_court', $verdict['blockers']);
    }

    public function test_missing_replay_command_hash_in_bound_source_becomes_source_blocker(): void
    {
        $facts = $this->readyBoundFacts([
            'sources' => ['rollback' => ['replay_command_hash' => '']],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $this->assertContains('source_binding_missing_replay_command_hash:rollback', $verdict['blockers']);
    }

    public function test_require_source_binding_false_preserves_existing_ready_path(): void
    {
        // require_source_binding absent/false — new binding checks must NOT fire.
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyFacts());

        $this->assertTrue($verdict['passed']);
        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_READY, $verdict['status']);
        $this->assertSame([], $verdict['source_blockers']);
    }

    // ── freshness-window checks ───────────────────────────────────────────────

    public function test_freshness_window_exceeded_on_refreshable_source_yields_hold(): void
    {
        $now = 1_751_290_000;
        $facts = $this->readyFacts([
            'sources' => [
                'docs_health' => ['status' => 'pass', 'generated_at_unix' => $now - 7200],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts, $now);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_HOLD, $verdict['status']);
        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertContains('stale_by_freshness_window', $kinds);
    }

    public function test_freshness_window_exceeded_on_non_refreshable_source_yields_blocked(): void
    {
        $now = 1_751_290_000;
        $facts = $this->readyFacts([
            'sources' => [
                'task_serving_contract_sentinel' => ['status' => 'pass', 'generated_at_unix' => $now - 200_000],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts, $now);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertContains('stale_by_freshness_window', $kinds);
        $row = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['kind'] === 'stale_by_freshness_window'))[0];
        $this->assertFalse($row['refreshable']);
    }

    public function test_freshness_window_not_exceeded_does_not_add_blocker(): void
    {
        $now = 1_751_290_000;
        $facts = $this->readyFacts([
            'sources' => [
                'docs_health' => ['status' => 'pass', 'generated_at_unix' => $now - 60],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts, $now);

        $this->assertTrue($verdict['passed']);
        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_READY, $verdict['status']);
    }

    public function test_nowunix_zero_skips_freshness_check(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'docs_health' => ['status' => 'pass', 'generated_at_unix' => 1],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts, 0);

        $this->assertTrue($verdict['passed']);
    }

    // ── required-fields checks ────────────────────────────────────────────────

    public function test_missing_required_field_on_source_with_fields_list_adds_blocker(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'task_graph_autonomous_replenisher' => [
                    'status' => 'pass',
                    'fields' => ['dry_run', 'applied_count'],
                ],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertContains('missing_required_field:plan_hash', $kinds);
    }

    public function test_source_with_all_required_fields_does_not_add_blocker(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'task_graph_autonomous_replenisher' => [
                    'status' => 'pass',
                    'fields' => ['plan_hash', 'dry_run', 'applied_count', 'withheld_count', 'duplicate_count', 'replenisher_hash'],
                ],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $kinds = array_column($verdict['source_blockers'], 'kind');
        $missingKinds = array_filter($kinds, fn (string $k): bool => str_starts_with($k, 'missing_required_field:'));
        $this->assertEmpty($missingKinds);
    }

    public function test_source_without_fields_key_skips_required_fields_check(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'task_graph_autonomous_replenisher' => ['status' => 'pass'],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $kinds = array_column($verdict['source_blockers'], 'kind');
        $missingKinds = array_filter($kinds, fn (string $k): bool => str_starts_with($k, 'missing_required_field:'));
        $this->assertEmpty($missingKinds);
    }

    // ── cross-source consistency ──────────────────────────────────────────────

    public function test_group_consistency_gap_when_one_source_fails_and_sibling_passes(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'merge_governor' => ['status' => 'fail'],
                'multi_project_governance_dossier' => ['status' => 'pass'],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertContains('group_consistency_gap', $kinds);
        $gapRow = array_values(array_filter($verdict['source_blockers'], fn (array $r): bool => $r['kind'] === 'group_consistency_gap'))[0];
        $this->assertSame('governance', $gapRow['source_id']);
    }

    public function test_group_consistency_gap_when_contradictory_alongside_pass(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'merge_governor' => ['status' => 'contradictory'],
                'multi_project_governance_dossier' => ['status' => 'pass'],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $this->assertSame(AtlasSelfConstructionAtlasNativeEvidenceVerifier::STATUS_BLOCKED, $verdict['status']);
        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertContains('group_consistency_gap', $kinds);
    }

    public function test_missing_alongside_pass_in_same_group_does_not_trigger_consistency_gap(): void
    {
        $facts = $this->readyFacts();
        unset($facts['sources']['knowledge_sync']);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertNotContains('group_consistency_gap', $kinds);
    }

    public function test_all_sources_fail_in_group_does_not_trigger_consistency_gap(): void
    {
        $facts = $this->readyFacts([
            'sources' => [
                'merge_governor' => ['status' => 'fail'],
                'multi_project_governance_dossier' => ['status' => 'fail'],
            ],
        ]);

        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($facts);

        $kinds = array_column($verdict['source_blockers'], 'kind');
        $this->assertNotContains('group_consistency_gap', $kinds);
    }

    // ── evidence_refs ─────────────────────────────────────────────────────────

    public function test_evidence_refs_present_and_lists_checked_source_ids(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyFacts());

        $this->assertArrayHasKey('evidence_refs', $verdict);
        $this->assertIsArray($verdict['evidence_refs']);
        $this->assertNotEmpty($verdict['evidence_refs']);
        $this->assertContains('merge_governor', $verdict['evidence_refs']);
        $this->assertContains('task_serving_contract_sentinel', $verdict['evidence_refs']);
    }

    public function test_evidence_refs_stable_sorted_order(): void
    {
        $verdict = (new AtlasSelfConstructionAtlasNativeEvidenceVerifier)->verify($this->readyFacts());

        $refs = $verdict['evidence_refs'];
        $sorted = $refs;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $refs, 'evidence_refs must be in byte-stable sorted order');
    }
}
