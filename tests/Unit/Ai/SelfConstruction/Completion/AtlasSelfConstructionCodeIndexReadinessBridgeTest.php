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

    /**
     * @return array<string,mixed>
     */
    private function readyFacts(): array
    {
        return [
            'code_status' => [
                'status' => 'ready',
                'indexed_symbols' => 8700,
                'is_stale' => false,
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
