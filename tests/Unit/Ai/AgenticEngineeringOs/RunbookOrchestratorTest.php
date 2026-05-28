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

    public function test_propose_structural_redesign_emits_bounded_review_packet(): void
    {
        $r = $this->svc->proposeStructuralRedesign([
            'title' => 'Consolidate QA and Security review gates',
            'limitation' => 'Duplicate veto cycles between QA and Security departments slow obra promotion',
            'structural_changes' => [
                [
                    'target' => 'department',
                    'current' => 'qa',
                    'proposed' => 'quality_security',
                ],
                [
                    'target' => 'gate',
                    'current' => 'qa-signoff',
                    'proposed' => 'quality-security-signoff',
                ],
            ],
        ]);

        $this->assertSame(RunbookOrchestrator::ARCHITECTURE_REDESIGN_PROPOSAL_SCHEMA, $r['schema']);
        $this->assertStringStartsWith('arp-', $r['proposal_id']);
        $this->assertCount(2, $r['structural_changes']);
        $this->assertSame('department', $r['structural_changes'][0]['target']);
        $this->assertSame('gate', $r['structural_changes'][1]['target']);
        $this->assertSame(RunbookOrchestrator::DEFAULT_FLOW, $r['runtime_baseline']['default_flow']);
        $this->assertGreaterThan(0, $r['runtime_baseline']['default_flow_gates_total']);
        $this->assertTrue($r['requires_replay_before_promotion']);
        $this->assertSame('pending_replay', $r['review_status']);
        $this->assertSame(RunbookOrchestrator::REPLAY_OBRAS_COUNT_MIN, $r['promotion_gates']['replay_obras_count_min']);
        $this->assertSame(0, $r['promotion_gates']['replay_regression_observed_count_max']);
        $this->assertTrue($r['promotion_gates']['dual_signature_required']);
        $this->assertNotEmpty($r['proposal_hash']);
    }

    public function test_propose_structural_redesign_applies_sovereignty_block(): void
    {
        $r = $this->svc->proposeStructuralRedesign([
            'title' => 'Restructure evidence certification runtime',
            'limitation' => 'Evidence gate topology no longer matches AAEOS phase count',
            'structural_changes' => [
                ['target' => 'phase', 'current' => 'certify', 'proposed' => 'certify_v2'],
            ],
            'touches_sovereignty_layer' => true,
        ]);

        $this->assertTrue($r['touches_sovereignty_layer']);
        $this->assertTrue($r['safety_sovereignty_block_applied']);
    }
}
