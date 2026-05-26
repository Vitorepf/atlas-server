<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use App\Services\Ai\AgenticEngineeringOs\RunbookOrchestrator;
use Tests\TestCase;

final class RunbookOrchestratorTest extends TestCase
{
    private RunbookOrchestrator $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new RunbookOrchestrator(new DepartmentContractRuntime);
    }

    public function test_trivial_intent_produces_2_stage_flow(): void
    {
        $r = $this->svc->plan(['intent' => 'oi']);
        $this->assertSame('trivial', $r['intent_class']);
        $this->assertSame(2, $r['stage_count']);
        $this->assertSame('executive_intake', $r['stages'][0]['department']);
        $this->assertSame('memory', $r['stages'][1]['department']);
    }

    public function test_task_intent_produces_default_flow(): void
    {
        $r = $this->svc->plan(['intent' => 'implementa o endpoint de billing']);
        $this->assertSame('task', $r['intent_class']);
        $this->assertSame(9, $r['stage_count']);
        $this->assertSame('dev', $r['stages'][3]['department']);
    }

    public function test_obra_intent_replaces_dev_with_forge(): void
    {
        $r = $this->svc->plan(['intent' => 'refactor enterprise do billing engine inteiro']);
        $this->assertSame('obra', $r['intent_class']);
        $depts = array_column($r['stages'], 'department');
        $this->assertContains('forge', $depts);
        $this->assertNotContains('dev', $depts);
    }

    public function test_needs_research_inserts_research_after_architecture(): void
    {
        $r = $this->svc->plan([
            'intent' => 'implementa nova area de automacao',
            'needs_research' => true,
        ]);
        $depts = array_column($r['stages'], 'department');
        $archIdx = array_search('architecture', $depts, true);
        $resIdx = array_search('research', $depts, true);
        $this->assertSame($archIdx + 1, $resIdx);
    }

    public function test_needs_debug_inserts_debug_after_dev(): void
    {
        $r = $this->svc->plan([
            'intent' => 'conserta bug do billing',
            'needs_debug' => true,
        ]);
        $depts = array_column($r['stages'], 'department');
        $devIdx = array_search('dev', $depts, true);
        $debIdx = array_search('debug', $depts, true);
        $this->assertSame($devIdx + 1, $debIdx);
    }

    public function test_intent_hash_is_deterministic(): void
    {
        $r1 = $this->svc->plan(['intent' => 'mesma intenção']);
        $r2 = $this->svc->plan(['intent' => 'mesma intenção']);
        $this->assertSame($r1['intent_hash'], $r2['intent_hash']);
    }

    public function test_envelope_carries_schema_version(): void
    {
        $r = $this->svc->plan(['intent' => 'qualquer coisa']);
        $this->assertSame('atlas.agentic_engineering_os.runbook.v1', $r['schema_version']);
    }
}
