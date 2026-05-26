<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs;

use App\Services\Ai\AgenticEngineeringOs\AaeosPhaseHandoffService;
use InvalidArgumentException;
use Tests\TestCase;

final class AaeosPhaseHandoffServiceTest extends TestCase
{
    private AaeosPhaseHandoffService $svc;

    protected function setUp(): void
    {
        parent::setUp();
        $this->svc = new AaeosPhaseHandoffService;
    }

    public function test_17_canonical_phases_in_order(): void
    {
        $this->assertCount(17, AaeosPhaseHandoffService::PHASES);
        $this->assertSame('intent_capture', AaeosPhaseHandoffService::PHASES[0]);
        $this->assertSame('learning', AaeosPhaseHandoffService::PHASES[16]);
    }

    public function test_canonical_next_phase(): void
    {
        $this->assertSame('disambiguation', $this->svc->canonicalNextPhase('intent_capture'));
        $this->assertSame('learning', $this->svc->canonicalNextPhase('certification'));
        $this->assertNull($this->svc->canonicalNextPhase('learning'));
    }

    public function test_emit_produces_canonical_envelope(): void
    {
        $env = $this->svc->emit(
            intentId: 'i-1',
            phaseIn: 'intent_capture',
            phaseOut: 'disambiguation',
            actor: ['kind' => 'agent', 'id' => 'mission_foundation', 'provider' => null],
            inputs: ['intent_raw_hash' => 'sha256:abc'],
            outputs: ['clarified_hash' => 'sha256:def'],
            evidenceHashes: ['sha256:111'],
        );
        $this->assertSame('atlas.aaeos.phase.v1', $env['schema']);
        $this->assertSame('intent_capture', $env['phase_in']);
        $this->assertSame('disambiguation', $env['phase_out']);
        $this->assertSame('placement', $env['next_phase']);
        $this->assertContains('intent_clarity_score_min_0_8', $env['gates']['required']);
    }

    public function test_unknown_phase_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->emit('i-1', 'wibble', 'placement',
            ['kind' => 'agent', 'id' => 'x'], [], []);
    }

    public function test_raw_payload_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->emit('i-1', 'intent_capture', 'disambiguation',
            ['kind' => 'agent', 'id' => 'x'],
            ['raw' => str_repeat('a', 300)], []);
    }

    public function test_evidence_hash_must_be_sha256(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->emit('i-1', 'intent_capture', 'disambiguation',
            ['kind' => 'agent', 'id' => 'x'], [], [], ['not-a-hash']);
    }

    public function test_signature_required_at_l4_human_review(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->svc->emit('i-1', 'delivery', 'human_review',
            ['kind' => 'agent', 'id' => 'x'], [], [],
            autonomyLevel: 'L4'
        );
    }

    public function test_signature_accepted_at_l4_human_review(): void
    {
        $env = $this->svc->emit('i-1', 'delivery', 'human_review',
            ['kind' => 'agent', 'id' => 'x'], [], [],
            operatorSignature: 'sig:abc',
            autonomyLevel: 'L4'
        );
        $this->assertSame('sig:abc', $env['operator_signature']);
    }

    public function test_skip_requires_receipt_and_reason(): void
    {
        $env = $this->svc->skip('i-1', 'topology', 'rcpt:42', 'single provider only');
        $this->assertStringContainsString('rcpt:42', $env['skip_reason']);
        $this->assertSame('routing', $env['next_phase']);
    }

    public function test_validate_flags_bad_envelope(): void
    {
        $reasons = $this->svc->validate(['schema' => 'wrong']);
        $this->assertNotEmpty($reasons);
        $this->assertStringContainsString('schema', $reasons[0]);
    }

    public function test_validate_accepts_well_formed_envelope(): void
    {
        $env = $this->svc->emit('i-1', 'spec', 'tasks',
            ['kind' => 'agent', 'id' => 'dev'], [], []);
        $this->assertSame([], $this->svc->validate($env));
    }

    public function test_phase_index_canon(): void
    {
        $this->assertSame(0, $this->svc->phaseIndex('intent_capture'));
        $this->assertSame(11, $this->svc->phaseIndex('gates'));
        $this->assertSame(16, $this->svc->phaseIndex('learning'));
    }

    public function test_gates_for_phase(): void
    {
        $this->assertSame(['universal_15_gates_green_or_exception'], $this->svc->gatesForPhase('gates'));
    }
}
