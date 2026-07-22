<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognitive;

use App\Models\AiLearningProposal;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessFrozenSuite;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessInstructionSurface;
use App\Services\Ai\Cognitive\Harness\AtlasHarnessProposalBridge;
use App\Services\Ai\Compounding\AtlasLearningProposalApplier;
use Tests\TestCase;

/**
 * AP-819 Surface v2 — seções de instrução evoluíveis. As certezas provadas aqui:
 *   - espaço de busca FINITO: texto fora da biblioteca declarada é rejeitado;
 *   - o fio é REAL: o texto vivo entra no prompt que o AiPromptBuilder monta;
 *   - apply+reverse perfeitos (1 passo, byte-igual);
 *   - a ponte propõe a próxima variante AINDA NÃO ATIVA (busca discreta);
 *   - nunca auto-aplica pelo caminho genérico (só o autopilot, com gates).
 */
class AtlasHarnessInstructionSurfaceTest extends TestCase
{
    private AtlasHarnessInstructionSurface $instructions;

    private string $overridesPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->overridesPath = sys_get_temp_dir().'/atlas-instr-overrides-'.bin2hex(random_bytes(4)).'.json';
        $this->instructions = new AtlasHarnessInstructionSurface;
        $this->instructions->setOverridesPathForTesting($this->overridesPath);
        $this->app->instance(AtlasHarnessInstructionSurface::class, $this->instructions);
    }

    protected function tearDown(): void
    {
        @unlink($this->overridesPath);

        parent::tearDown();
    }

    public function test_search_space_is_finite_and_freeform_text_is_rejected(): void
    {
        $space = $this->instructions->searchSpace('worker.tool_error_recovery');
        $this->assertCount(3, $space, 'default + 2 variantes declaradas');

        $this->assertTrue($this->instructions->validate('worker.tool_error_recovery', $space[1])['valid']);
        $this->assertFalse($this->instructions->validate('worker.tool_error_recovery', 'texto livre que nenhum modelo declarou')['valid']);
        $this->assertFalse($this->instructions->validate('secao.inexistente', $space[0])['valid']);
        $this->assertFalse($this->instructions->validate('worker.tool_error_recovery', '')['valid']);
    }

    public function test_apply_and_reverse_roundtrip_changes_and_restores_live_text(): void
    {
        $default = (string) $this->instructions->text('worker.output_discipline');
        $variant = $this->instructions->searchSpace('worker.output_discipline')[1];

        $applied = $this->instructions->applyOverride('worker.output_discipline', $variant, 'proposal-x');
        $this->assertTrue($applied['applied']);
        $this->assertSame($variant, $this->instructions->text('worker.output_discipline'));

        $reversed = $this->instructions->reverseOverride('worker.output_discipline');
        $this->assertTrue($reversed['reversed']);
        $this->assertSame($default, $this->instructions->text('worker.output_discipline'));
    }

    public function test_real_wire_prompt_builder_carries_the_live_instruction_text(): void
    {
        $variant = $this->instructions->searchSpace('worker.tool_error_recovery')[1];
        $this->instructions->applyOverride('worker.tool_error_recovery', $variant, 'proposal-wire');

        $prompt = app(\App\Services\Ai\AiPromptBuilder::class)
            ->build('teste simples do fio de instrucoes', [])
            ->prompt;

        $this->assertStringContainsString('Disciplina operacional (Harness Surface v2', $prompt);
        $this->assertStringContainsString($variant, $prompt, 'o OVERRIDE vivo entra no prompt real');
        $this->assertStringContainsString(
            (string) $this->instructions->text('worker.output_discipline'),
            $prompt,
            'seções sem override entram com a default',
        );
    }

    public function test_applier_applies_and_reverses_instruction_proposals_and_rejects_freeform(): void
    {
        $applier = app(AtlasLearningProposalApplier::class);
        $variant = $this->instructions->searchSpace('worker.verification_guidance')[1];

        $proposal = (new AiLearningProposal)->forceFill([
            'status' => 'approved',
            'kind' => 'harness_instruction',
            'proposed_state' => ['key' => 'worker.verification_guidance', 'text' => $variant],
        ]);
        $applied = $applier->apply($proposal, 'vitor');
        $this->assertTrue($applied['applied'], 'reason: '.(string) $applied['reason']);
        $this->assertSame($variant, $this->instructions->text('worker.verification_guidance'));

        $reversed = $applier->reverse($proposal, 'vitor');
        $this->assertTrue($reversed['reversed']);
        $this->assertNotSame($variant, $this->instructions->text('worker.verification_guidance'));

        $freeform = (new AiLearningProposal)->forceFill([
            'status' => 'approved',
            'kind' => 'harness_instruction',
            'proposed_state' => ['key' => 'worker.verification_guidance', 'text' => 'prompt injetado fora da biblioteca'],
        ]);
        $refused = $applier->apply($freeform, 'vitor');
        $this->assertFalse($refused['applied']);
        $this->assertSame('harness_instruction_rejected_by_surface', $refused['reason']);

        $this->assertFalse($applier->supportsAutoApply('harness_instruction'));
    }

    public function test_bridge_maps_behavioral_cluster_to_next_untried_variant(): void
    {
        $bridge = new AtlasHarnessProposalBridge(
            $this->createStub(\App\Services\Ai\Cognitive\Failure\FailureSignatureRepository::class),
            app(\App\Services\Ai\Cognitive\Harness\AtlasHarnessSurface::class),
            $this->instructions,
        );
        $map = new \ReflectionMethod($bridge, 'mapClusterToInstruction');

        $mapping = $map->invoke($bridge, ['signature_key' => 'fsig_tool_error_loop_detected', 'domain' => 'engineering']);
        $this->assertSame('harness_instruction', $mapping['kind']);
        $this->assertSame('worker.tool_error_recovery', $mapping['key']);
        $this->assertContains($mapping['proposed'], $this->instructions->searchSpace('worker.tool_error_recovery'));
        $this->assertNotSame($mapping['current'], $mapping['proposed'], 'propõe variante DIFERENTE da ativa');

        $this->assertNull($map->invoke($bridge, ['signature_key' => 'fsig_sem_padrao_comportamental', 'domain' => 'engineering']));
    }

    public function test_frozen_suite_v2_probes_are_green_on_the_real_harness(): void
    {
        $suite = new AtlasHarnessFrozenSuite;
        $suite->setBaselinePathForTesting(sys_get_temp_dir().'/atlas-instr-baseline-'.bin2hex(random_bytes(4)).'.json');

        $evaluation = $suite->evaluate();

        $this->assertSame(1.0, $evaluation['held_in']['pass_rate'], json_encode($evaluation['held_in']['results']));
        $this->assertSame(1.0, $evaluation['held_out']['pass_rate'], json_encode($evaluation['held_out']['results']));
        $this->assertArrayHasKey(
            'instruction_surface_rejects_freeform_text',
            $evaluation['held_in']['results'] + $evaluation['held_out']['results'],
        );
    }
}
