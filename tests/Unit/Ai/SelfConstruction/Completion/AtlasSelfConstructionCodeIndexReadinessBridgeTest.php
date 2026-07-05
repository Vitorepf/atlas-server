<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Completion;

use App\Services\Ai\SelfConstruction\Completion\AtlasSelfConstructionCodeIndexReadinessBridge;
use Tests\TestCase;

class AtlasSelfConstructionCodeIndexReadinessBridgeTest extends TestCase
{
    public function test_ready_when_all_facts_pass(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_READY, $verdict['status']);
        self::assertTrue($verdict['passed']);
        self::assertSame([], $verdict['blockers']);
    }

    public function test_hold_when_index_is_stale(): void
    {
        $facts = $this->readyFacts();
        $facts['code_status']['is_stale'] = true;

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_HOLD, $verdict['status']);
        self::assertFalse($verdict['passed']);
        self::assertContains('rerun_engineering_knowledge_index_code_prune', $verdict['repair_actions']);
    }

    public function test_hold_when_index_is_empty(): void
    {
        $facts = $this->readyFacts();
        $facts['code_status']['indexed_symbols'] = 0;

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_HOLD, $verdict['status']);
        self::assertFalse($verdict['passed']);
        self::assertContains('run_engineering_knowledge_index_code', $verdict['repair_actions']);
    }

    public function test_blocked_when_schema_drift_facts_missing(): void
    {
        $facts = $this->readyFacts();
        unset($facts['schema_drift']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertFalse($verdict['passed']);
        self::assertContains('schema_drift_facts_missing', $verdict['blockers']);
    }

    public function test_blocked_when_schema_drift_audit_fails(): void
    {
        $facts = $this->readyFacts();
        $facts['schema_drift'] = ['status' => 'schema_drift', 'passed' => false];

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('schema_drift_failed:schema_drift', $verdict['blockers']);
    }

    public function test_blocked_when_automatic_gate_facts_missing(): void
    {
        $facts = $this->readyFacts();
        unset($facts['automatic_gate']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('automatic_gate_facts_missing', $verdict['blockers']);
    }

    public function test_blocked_when_readiness_blocking_findings_present(): void
    {
        $facts = $this->readyFacts();
        $facts['readiness']['blocking_findings'] = [['type' => 'drift']];

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('readiness_blocking_findings_present', $verdict['blockers']);
    }

    public function test_hold_when_code_status_is_absent_but_index_is_populated(): void
    {
        $facts = $this->readyFacts();
        unset($facts['code_status']['status']); // absent status coerces to empty string
        $facts['code_status']['indexed_symbols'] = 8700;
        $facts['code_status']['is_stale'] = false;

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_HOLD, $verdict['status']);
        self::assertFalse($verdict['passed']);
        self::assertContains('investigate_code_intelligence_pipeline', $verdict['repair_actions']);
    }

    public function test_blocked_when_workspace_id_is_missing(): void
    {
        $facts = $this->readyFacts();
        unset($facts['workspace_id']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('workspace_id_missing', $verdict['blockers']);
    }

    public function test_blocked_when_index_workspace_does_not_match_workspace_id(): void
    {
        $facts = $this->readyFacts();
        $facts['code_status']['index_workspace_id'] = 'different-workspace';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('index_workspace_mismatch', $verdict['blockers']);
    }

    public function test_blocked_when_indexed_at_unix_is_missing(): void
    {
        $facts = $this->readyFacts();
        unset($facts['code_status']['indexed_at_unix']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('indexed_at_missing', $verdict['blockers']);
    }

    public function test_blocked_when_changed_code_hash_not_represented_in_index(): void
    {
        $facts = $this->readyFacts();
        $facts['changed_code_hash'] = 'new-unindexed-hash';
        $facts['code_status']['last_changed_code_hash'] = 'old-hash';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('changed_code_hash_not_represented', $verdict['blockers']);
    }

    public function test_ready_when_workspace_and_changed_code_hash_facts_match(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_READY, $verdict['status']);
        self::assertTrue($verdict['passed']);
        self::assertSame([], $verdict['blockers']);
    }

    // ── Acceptance criterion 4: output structure ────────────────────────

    public function test_output_contains_final_autonomy_ready(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertArrayHasKey('final_autonomy_ready', $verdict);
        self::assertTrue($verdict['final_autonomy_ready']);
        self::assertSame($verdict['passed'], $verdict['final_autonomy_ready']);
    }

    public function test_output_contains_evidence_refs(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertArrayHasKey('evidence_refs', $verdict);
        self::assertIsArray($verdict['evidence_refs']);
        self::assertArrayHasKey('code_index_status', $verdict['evidence_refs']);
        self::assertArrayHasKey('schema_drift_passed', $verdict['evidence_refs']);
        self::assertArrayHasKey('automatic_gate_status', $verdict['evidence_refs']);
        self::assertArrayHasKey('readiness_status', $verdict['evidence_refs']);
        self::assertArrayHasKey('readiness_blockers', $verdict['evidence_refs']);
        self::assertArrayHasKey('workspace_bound', $verdict['evidence_refs']);
        self::assertArrayHasKey('indexed_at_present', $verdict['evidence_refs']);
        self::assertArrayHasKey('code_hash_represented', $verdict['evidence_refs']);
    }

    public function test_evidence_refs_deterministic(): void
    {
        $facts = $this->readyFacts();

        $v1 = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);
        $v2 = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame($v1['evidence_refs'], $v2['evidence_refs']);
    }

    public function test_evidence_refs_reflects_blocked_state(): void
    {
        $facts = $this->readyFacts();
        unset($facts['schema_drift']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertNull($verdict['evidence_refs']['schema_drift_passed']);
        self::assertFalse($verdict['final_autonomy_ready']);
        self::assertSame('blocked', $verdict['status']);
    }

    public function test_evidence_refs_reflects_stale_index(): void
    {
        $facts = $this->readyFacts();
        $facts['code_status']['is_stale'] = true;

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertFalse($verdict['final_autonomy_ready']);
        self::assertSame('hold', $verdict['status']);
    }

    public function test_repairs_and_repair_actions_are_synced(): void
    {
        $facts = $this->readyFacts();
        unset($facts['schema_drift']);

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertArrayHasKey('repairs', $verdict);
        self::assertArrayHasKey('repair_actions', $verdict);
        self::assertSame($verdict['repairs'], $verdict['repair_actions']);
        self::assertContains('supply_schema_drift_audit_facts', $verdict['repairs']);
    }

    // ── Coverage gaps: edge cases ───────────────────────────────────────

    public function test_blocked_when_automatic_gate_status_not_ready(): void
    {
        $facts = $this->readyFacts();
        $facts['automatic_gate'] = ['status' => 'failed'];

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('automatic_gate_not_ready:failed', $verdict['blockers']);
    }

    public function test_blocked_when_code_status_not_ready(): void
    {
        $facts = $this->readyFacts();
        $facts['code_status']['status'] = 'indexing';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('code_status_not_ready:indexing', $verdict['blockers']);
    }

    public function test_blocked_when_readiness_status_is_failing(): void
    {
        $facts = $this->readyFacts();
        $facts['readiness']['status'] = 'failed';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_BLOCKED, $verdict['status']);
        self::assertContains('readiness_not_ready:failed', $verdict['blockers']);
    }

    public function test_not_blocked_when_readiness_status_is_watch(): void
    {
        $facts = $this->readyFacts();
        $facts['readiness']['status'] = 'watch';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        // watch is acceptable — not a blocker
        self::assertSame(AtlasSelfConstructionCodeIndexReadinessBridge::STATUS_READY, $verdict['status']);
        self::assertTrue($verdict['passed']);
    }

    public function test_code_status_in_code_index_facts(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertArrayHasKey('code_status', $verdict['code_index_facts']);
        self::assertSame('ready', $verdict['code_index_facts']['code_status']);
    }

    public function test_proof_summary_format(): void
    {
        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($this->readyFacts());

        self::assertMatchesRegularExpression(
            '/^status=ready blockers=\d+ repairs=\d+$/',
            $verdict['proof_summary'],
        );
    }

    public function test_blocked_when_empty_changed_code_hash_does_not_match(): void
    {
        // Empty changed_code_hash should not produce a mismatch blocker
        $facts = $this->readyFacts();
        $facts['changed_code_hash'] = '';

        $verdict = (new AtlasSelfConstructionCodeIndexReadinessBridge)->verify($facts);

        // Empty changed_code_hash means "no change detected" — skip hash check
        self::assertNotContains('changed_code_hash_not_represented', $verdict['blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'workspace_id' => 'ws-atlas-server',
            'changed_code_hash' => 'deadbeef',
            'code_status' => [
                'status' => 'ready',
                'indexed_symbols' => 8700,
                'is_stale' => false,
                'index_workspace_id' => 'ws-atlas-server',
                'indexed_at_unix' => 1751287200,
                'last_changed_code_hash' => 'deadbeef',
            ],
            'readiness' => [
                'status' => 'ready',
                'blocking_findings' => [],
            ],
            'automatic_gate' => [
                'status' => 'ready',
            ],
            'schema_drift' => [
                'status' => 'healthy',
                'passed' => true,
            ],
        ];
    }
}
