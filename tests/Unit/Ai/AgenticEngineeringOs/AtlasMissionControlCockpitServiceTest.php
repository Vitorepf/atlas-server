<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use App\Services\Ai\AgenticEngineeringOs\AtlasMissionControlCockpitService;
use App\Services\Ai\AgenticEngineeringOs\AtlasUniversalGatesEvaluator;
use App\Services\Ai\AgenticEngineeringOs\DepartmentContractRuntime;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasMissionControlCockpitServiceTest extends TestCase
{
    private AtlasMissionControlCockpitService $svc;

    private AaeosPhaseHandoffService $phases;

    protected function setUp(): void
    {
        parent::setUp();
        $this->phases = new AaeosPhaseHandoffService;
        $this->svc = new AtlasMissionControlCockpitService(
            $this->phases,
            new AtlasUniversalGatesEvaluator,
            new DepartmentContractRuntime,
        );
    }

    public function test_empty_intent_id_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->snapshot('', []);
    }

    public function test_empty_journey_marks_all_phases_pending(): void
    {
        $r = $this->svc->snapshot('i-1', []);
        $this->assertSame('atlas.aaeos.mission_control_cockpit.v1', $r['schema']);
        $this->assertSame(17, $r['phase_count']);
        $this->assertCount(17, $r['phases']);
        $this->assertSame('pending', $r['phases'][0]['status']);
        $this->assertNull($r['current_phase']);
        $this->assertSame('intent_capture', $r['next_phase']);
    }

    public function test_completed_phase_advances_current_phase(): void
    {
        $env = $this->phases->emit(
            'i-1', 'intent_capture', 'disambiguation',
            ['kind' => 'agent', 'id' => 'mf'],
            [], [],
            endedAt: gmdate('c'),
        );
        $env['gates']['passed'] = ['intent_clarity_score_min_0_8'];
        $r = $this->svc->snapshot('i-1', [$env]);
        $this->assertSame('disambiguation', $r['current_phase']);
        $this->assertSame('placement', $r['next_phase']);
        $this->assertSame('complete', $r['phases'][1]['status']);
    }

    public function test_blockers_aggregated(): void
    {
        $env = $this->phases->emit(
            'i-1', 'intent_capture', 'disambiguation',
            ['kind' => 'agent', 'id' => 'mf'],
            [], [],
            blockers: [['id' => 'b1', 'severity' => 'high', 'owner' => 'security']],
        );
        $r = $this->svc->snapshot('i-1', [$env]);
        $this->assertCount(1, $r['blockers']);
        $this->assertSame('b1', $r['blockers'][0]['id']);
    }

    public function test_signature_required_at_l4_human_review(): void
    {
        // Bring journey to delivery completed; human_review will be in_progress (pending).
        $delivery = $this->phases->emit(
            'i-1', 'evidence', 'delivery',
            ['kind' => 'agent', 'id' => 'forge'],
            [], [],
            endedAt: gmdate('c'),
            operatorSignature: 'sig:1',
            autonomyLevel: 'L4',
        );
        $delivery['gates']['passed'] = ['delivery_pack_hash_signed'];
        $r = $this->svc->snapshot('i-1', [$delivery], autonomyLevel: 'L4');
        // current_phase becomes 'delivery' because human_review has not been emitted.
        // Signature required is true when current phase is in PHASES_REQUIRING_SIGNATURE_AT_L4.
        $this->assertSame('delivery', $r['current_phase']);
        $this->assertTrue($r['operator_signature_required']);
    }

    public function test_gate_report_embedded(): void
    {
        $signals = array_fill_keys(array_keys(AtlasUniversalGatesEvaluator::UNIVERSAL_GATES), true);
        $r = $this->svc->snapshot('i-1', [], $signals);
        $this->assertSame('green', $r['gate_report']['outcome']);
    }

    public function test_snapshot_hash_deterministic(): void
    {
        $a = $this->svc->snapshot('i-1', []);
        $b = $this->svc->snapshot('i-1', []);
        $this->assertSame($a['snapshot_hash'], $b['snapshot_hash']);
    }

    public function test_skipped_phase_marked(): void
    {
        $skip = $this->phases->skip('i-1', 'topology', 'rcpt:42', 'single provider only');
        $r = $this->svc->snapshot('i-1', [$skip]);
        $topology = collect($r['phases'])->firstWhere('phase', 'topology');
        $this->assertSame('skipped', $topology['status']);
    }
}
