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
