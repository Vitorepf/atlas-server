<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowContractsPart02Service;
use Tests\TestCase;

/**
 * Pins the numbered invariants from Atlas Dev Efficient Programming Flow
 * Contracts v1 · Parte 2 (sections 4.1 OperationEnvelope and 4.2 CompactSDD),
 * including the doc's own "Exemplos Invalidos" rows. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-02.md
 */
class AtlasDevEfficientProgrammingFlowContractsPart02Test extends TestCase
{
    private function service(): AtlasDevEfficientProgrammingFlowContractsPart02Service
    {
        return new AtlasDevEfficientProgrammingFlowContractsPart02Service;
    }

    /**
     * The doc's "#### Exemplo Valido" envelope (4.1) must pass every invariant.
     *
     * @return array<string,mixed>
     */
    private function validEnvelope(): array
    {
        return [
            'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'surface_id' => 'atlas_desktop_ai',
            'surface_context' => ['product_surface' => 'atlas_ai_desktop_mac'],
            'flow_id' => 'atlas_dev',
            'flow_origin' => 'atlas_ai_router',
            'command_intent' => 'fix',
            'routed_slash_command' => true,
            'workspace' => '/Users/op/code/atlas-server',
            'intent_clarity_level' => 'high',
            'preflight' => [
                'permission_mode' => 'write',
                'write_allowed' => true,
                'operator_explicit' => false,
            ],
        ];
    }

    /**
     * The doc's "#### Exemplo Valido (Patch R2)" CompactSDD (4.2) must pass.
     *
     * @return array<string,mixed>
     */
    private function validCompactSdd(): array
    {
        return [
            'run_id' => '0192b5d2-2fe0-7c4f-9d2a-1f3b8e9a4c2d',
            'task_kind' => 'repair',
            'risk_level' => 'R2',
            'mode' => 'repair',
            'verification_profile' => 'php_laravel',
        ];
    }

    public function test_doc_valid_examples_pass_both_contracts(): void
    {
        $envelope = $this->service()->validateEnvelope($this->validEnvelope());
        $this->assertTrue($envelope['valid']);
        $this->assertTrue($envelope['can_advance']);
        $this->assertSame([], $envelope['violations']);
        $this->assertSame('atlas.dev.operation_envelope.v1', $envelope['contract']);

        $sdd = $this->service()->validateCompactSdd($this->validCompactSdd());
        $this->assertTrue($sdd['valid']);
        $this->assertSame([], $sdd['violations']);
        $this->assertSame('atlas.dev.compact_sdd.v1', $sdd['contract']);
        // Doc 4.1 anti-duplication boundary: this is NOT the Kernel envelope.
        $this->assertNotSame('atlas.envelope.v1', $envelope['contract']);
    }

    public function test_envelope_invariants_1_2_9_12_reject_doc_invalid_rows(): void
    {
        $service = $this->service();

        // Row: run_id "123" -> not a UUID v7 (OE-1). Also a plain v4 must fail.
        $badRunId = $this->validEnvelope();
        $badRunId['run_id'] = '123';
        $this->assertContains('OE-1', $service->validateEnvelope($badRunId)['violated_invariants']);

        $v4 = $this->validEnvelope();
        $v4['run_id'] = '550e8400-e29b-41d4-a716-446655440000'; // version nibble 4
        $this->assertContains('OE-1', $service->validateEnvelope($v4)['violated_invariants']);

        // Row: workspace "./atlas-server" -> not absolute (OE-2).
        $rel = $this->validEnvelope();
        $rel['workspace'] = './atlas-server';
        $relResult = $service->validateEnvelope($rel);
        $this->assertContains('OE-2', $relResult['violated_invariants']);
        $this->assertFalse($relResult['can_advance']);

        // Row: flow_id atlas_research -> this contract governs only atlas_dev (OE-9).
        $foreignFlow = $this->validEnvelope();
        $foreignFlow['flow_id'] = 'atlas_research';
        $this->assertContains('OE-9', $service->validateEnvelope($foreignFlow)['violated_invariants']);

        // Row: command_intent brainstorm -> outside the canonical set (OE-12).
        $badIntent = $this->validEnvelope();
        $badIntent['command_intent'] = 'brainstorm';
        $this->assertContains('OE-12', $service->validateEnvelope($badIntent)['violated_invariants']);
    }

    public function test_envelope_invariant_5_blocking_clarity_cannot_advance(): void
    {
        $env = $this->validEnvelope();
        $env['intent_clarity_level'] = 'blocking';

        $result = $this->service()->validateEnvelope($env);

        $this->assertContains('OE-5', $result['violated_invariants']);
        $this->assertTrue($result['blocked']);
        $this->assertFalse($result['can_advance']);
    }

    public function test_envelope_invariant_6_danger_write_requires_operator_explicit(): void
    {
        // Doc row: permission_mode danger + operator_explicit false + write_allowed true.
        $env = $this->validEnvelope();
        $env['preflight'] = [
            'permission_mode' => 'danger',
            'write_allowed' => true,
            'operator_explicit' => false,
        ];

        $result = $this->service()->validateEnvelope($env);
        $this->assertContains('OE-6', $result['violated_invariants']);
        $this->assertFalse($result['valid']);

        // Flipping operator_explicit true clears OE-6 (danger write now sanctioned).
        $env['preflight']['operator_explicit'] = true;
        $this->assertNotContains('OE-6', $this->service()->validateEnvelope($env)['violated_invariants']);

        // read permission with write_allowed=true also violates OE-6.
        $readWrite = $this->validEnvelope();
        $readWrite['preflight'] = ['permission_mode' => 'read', 'write_allowed' => true, 'operator_explicit' => false];
        $this->assertContains('OE-6', $this->service()->validateEnvelope($readWrite)['violated_invariants']);
    }

    public function test_envelope_invariant_7_surface_product_surface_coupling(): void
    {
        // Doc row: surface_id atlas_desktop_ai + product_surface atlas_app.
        $env = $this->validEnvelope();
        $env['surface_context']['product_surface'] = 'atlas_app';

        $this->assertContains('OE-7', $this->service()->validateEnvelope($env)['violated_invariants']);
    }

    public function test_compact_sdd_invariants_1_3_5_reject_doc_invalid_rows(): void
    {
        $service = $this->service();

        // Row: task_kind risky + risk_level R2 -> invariant 3.
        $risky = $this->validCompactSdd();
        $risky['task_kind'] = 'risky';
        $risky['risk_level'] = 'R2';
        $risky['mode'] = 'plan_only';
        $this->assertContains('SDD-3', $service->validateCompactSdd($risky)['violated_invariants']);

        // Row: risk_level R4 + mode patch -> invariant 1 (must be escalate_preview).
        $r4 = $this->validCompactSdd();
        $r4['risk_level'] = 'R4';
        $r4['mode'] = 'patch';
        $r4Result = $service->validateCompactSdd($r4);
        $this->assertContains('SDD-1', $r4Result['violated_invariants']);
        $this->assertSame('escalate_preview', $r4Result['forced_mode']);

        // Row: task_kind patch + verification_profile null -> invariant 5.
        $patch = $this->validCompactSdd();
        $patch['task_kind'] = 'patch';
        $patch['verification_profile'] = null;
        $this->assertContains('SDD-5', $service->validateCompactSdd($patch)['violated_invariants']);
    }

    public function test_compact_sdd_invariant_2_question_forces_read_only(): void
    {
        $service = $this->service();

        // question + non-read_only mode -> SDD-2; forced_mode is read_only.
        $bad = $this->validCompactSdd();
        $bad['task_kind'] = 'question';
        $bad['risk_level'] = 'R0';
        $bad['mode'] = 'patch';
        $badResult = $service->validateCompactSdd($bad);
        $this->assertContains('SDD-2', $badResult['violated_invariants']);
        $this->assertSame('read_only', $badResult['forced_mode']);

        // question + read_only is legal (question has no verification_profile need).
        $ok = ['task_kind' => 'question', 'risk_level' => 'R0', 'mode' => 'read_only'];
        $okResult = $service->validateCompactSdd($ok);
        $this->assertTrue($okResult['valid']);
        $this->assertSame('read_only', $okResult['forced_mode']);

        // High risk dominates: question at R5 is forced to escalate_preview, not read_only.
        $this->assertSame('escalate_preview', $service->forcedMode('question', 'R5'));
    }

    public function test_invariant_7_hash_excludes_monotonic_fill_fields(): void
    {
        $excluded = $this->service()->hashExcludedFields();

        // Doc 4.2 invariant 7: compact_sdd_hash excludes mini_spec_hash and
        // task_contract_hash (filled later), plus the hash field itself.
        $this->assertContains('mini_spec_hash', $excluded);
        $this->assertContains('task_contract_hash', $excluded);
        $this->assertContains('compact_sdd_hash', $excluded);
    }

    public function test_manifest_pins_canonical_command_intent_set(): void
    {
        $manifest = $this->service()->manifest();

        $this->assertSame('atlas_dev', $manifest['envelope_flow_id']);
        $this->assertSame(
            ['fix', 'explain', 'research', 'test', 'refactor', 'review', 'debug', 'plan', 'conversation'],
            $manifest['command_intent_set'],
        );
        $this->assertSame(['patch', 'repair', 'frontend'], $manifest['profile_required_task_kinds']);
    }
}
