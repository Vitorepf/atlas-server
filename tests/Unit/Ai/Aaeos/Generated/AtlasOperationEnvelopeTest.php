<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasOperationEnvelopeService;
use Tests\TestCase;

/**
 * Pins the Operation Envelope invariant from the doc: sem envelope nao ha
 * execucao; trace is a HARD block (not an optional detail); an admitted envelope
 * unlocks exactly intent-routing; plan/execute are refused before admission;
 * data carried outside the envelope is forbidden. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/system-graph/operation-envelope.md
 */
class AtlasOperationEnvelopeTest extends TestCase
{
    private function service(): AtlasOperationEnvelopeService
    {
        return new AtlasOperationEnvelopeService;
    }

    /** A complete envelope is admitted and unlocks exactly the next governed step. */
    public function test_complete_envelope_is_admitted_and_unlocks_intent_routing(): void
    {
        $verdict = $this->service()->admit([
            'trace' => 't-1',
            'origin' => 'atlas_cli',
            'identity' => 'vitor/vitor',
            'input' => 'do the thing',
            'limits' => ['autonomy' => 'normal'],
            'attachments' => [],
        ]);

        $this->assertTrue($verdict['admitted']);
        $this->assertFalse($verdict['blocked']);
        $this->assertSame([], $verdict['missing']);
        $this->assertSame([], $verdict['hard_blockers']);
        // Fluxo: envelope -> intent-routing (never straight to plan/execution).
        $this->assertSame('intent-routing', $verdict['next_step']);
    }

    /** Regras para IA: "Falta de trace e bloqueio, nao detalhe opcional." */
    public function test_missing_trace_is_a_hard_block_not_an_optional_detail(): void
    {
        $verdict = $this->service()->admit([
            // trace intentionally omitted
            'origin' => 'atlas_cli',
            'identity' => 'vitor/vitor',
            'input' => 'do the thing',
            'limits' => ['autonomy' => 'normal'],
            'attachments' => [],
        ]);

        $this->assertFalse($verdict['admitted']);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('trace', $verdict['hard_blockers']);
        $this->assertContains('trace_missing', $verdict['reasons']);
        $this->assertNull($verdict['next_step']);
    }

    /** Invariante: sem envelope nao ha execucao — an empty context is the strongest block. */
    public function test_absent_envelope_is_blocked(): void
    {
        $verdict = $this->service()->admit([]);

        $this->assertFalse($verdict['envelope_present']);
        $this->assertFalse($verdict['admitted']);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('envelope_missing', $verdict['reasons']);
    }

    /** Regras para IA: envelope antes de plano ou execucao — execute is refused before admission. */
    public function test_execute_phase_is_blocked_until_envelope_admitted(): void
    {
        $svc = $this->service();

        // No trace -> not admitted -> execute must be refused.
        $blocked = $svc->guardPhase([
            'origin' => 'atlas_cli',
            'identity' => 'vitor/vitor',
            'input' => 'go',
            'limits' => [],
            'attachments' => [],
        ], 'execute');

        $this->assertFalse($blocked['allowed']);
        $this->assertTrue($blocked['blocked']);
        $this->assertContains('envelope_not_admitted', $blocked['reasons']);
        $this->assertContains('phase_requires_envelope_before_execute', $blocked['reasons']);

        // Full envelope -> execute may proceed.
        $allowed = $svc->guardPhase([
            'trace' => 't-9',
            'origin' => 'atlas_cli',
            'identity' => 'vitor/vitor',
            'input' => 'go',
            'limits' => [],
            'attachments' => [],
        ], 'execute');

        $this->assertTrue($allowed['allowed']);
        $this->assertFalse($allowed['blocked']);
    }

    /** Escopo proibido: "esconder dados fora do envelope" is a hard blocker even when fields are present. */
    public function test_data_outside_envelope_is_forbidden(): void
    {
        $verdict = $this->service()->admit([
            'trace' => 't-1',
            'origin' => 'atlas_cli',
            'identity' => 'vitor/vitor',
            'input' => 'do the thing',
            'limits' => [],
            'attachments' => [],
            'data_outside_envelope' => true,
        ]);

        $this->assertFalse($verdict['admitted']);
        $this->assertTrue($verdict['blocked']);
        $this->assertContains('data_outside_envelope', $verdict['hard_blockers']);
        $this->assertContains('data_hidden_outside_envelope', $verdict['reasons']);
    }

    /** Manifest exposes the canonical contract id and trace among the hard fields. */
    public function test_manifest_declares_canonical_contract_and_hard_fields(): void
    {
        $manifest = $this->service()->manifest();

        $this->assertSame('atlas.envelope.v1', $manifest['envelope_contract']);
        $this->assertSame('intent-routing', $manifest['next_step']);
        $this->assertContains('trace', $manifest['hard_fields']);
        $this->assertContains('origin', $manifest['hard_fields']);
        // attachments/limits are required but soft (not execution-blocking on their own).
        $this->assertContains('attachments', $manifest['soft_fields']);
        $this->assertContains('limits', $manifest['soft_fields']);
    }
}
