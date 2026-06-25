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
}
