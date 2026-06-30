<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldedPromptAssembler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldedPromptAssemblerTest extends TestCase
{
    private AtlasExternalBrainScaffoldedPromptAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new AtlasExternalBrainScaffoldedPromptAssembler;
    }

    private function validInput(array $overrides = []): array
    {
        return array_merge([
            'model_tier'               => 'scaffolded_small_model',
            'evidence_intake'          => [
                ['text' => 'AtlasFooService is missing its wiring.'],
                ['text' => 'No tests exist for BarService.'],
            ],
            'queued_targets'           => ['app/Services/BazService.php'],
            'allow_direct_final_answer' => false,
            'context_budget_chars'     => 8000,
        ], $overrides);
    }

    // ── Schema / AC2 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->assembler->assemble($this->validInput());

        foreach (['schema_version', 'assembled', 'failure_reason', 'prompt_sections', 'required_artifacts', 'anti_duplication_checks', 'max_context_budget_chars'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainScaffoldedPromptAssembler::SCHEMA_VERSION, $result['schema_version']);
    }

    // ── AC2: successful assembly ──────────────────────────────────────────────

    public function test_valid_input_produces_assembled_prompt(): void
    {
        $result = $this->assembler->assemble($this->validInput());

        $this->assertTrue($result['assembled']);
        $this->assertNull($result['failure_reason']);
    }

    public function test_prompt_sections_are_ordered_correctly(): void
    {
        $result = $this->assembler->assemble($this->validInput());

        $sectionNames = array_column($result['prompt_sections'], 'section');

        $this->assertSame(
            ['evidence_intake', 'reasoning_scaffold', 'anti_duplication_check', 'output_contract'],
            $sectionNames,
        );
    }

    public function test_prompt_sections_each_have_content(): void
    {
        $result = $this->assembler->assemble($this->validInput());

        foreach ($result['prompt_sections'] as $section) {
            $this->assertArrayHasKey('section', $section);
            $this->assertArrayHasKey('content', $section);
            $this->assertNotEmpty($section['content']);
        }
    }

    // ── AC2: required_artifacts ───────────────────────────────────────────────

    public function test_required_artifacts_are_non_empty(): void
    {
        $result = $this->assembler->assemble($this->validInput());

        $this->assertNotEmpty($result['required_artifacts']);
        $this->assertContains('acceptance_criteria_runnable', $result['required_artifacts']);
    }

    // ── AC2: anti_duplication_checks ─────────────────────────────────────────

    public function test_anti_duplication_checks_echo_queued_targets(): void
    {
        $result = $this->assembler->assemble($this->validInput([
            'queued_targets' => ['app/Services/FooService.php', 'app/Services/BarService.php'],
        ]));

        $this->assertContains('app/Services/FooService.php', $result['anti_duplication_checks']);
        $this->assertContains('app/Services/BarService.php', $result['anti_duplication_checks']);
    }

    // ── AC2: max_context_budget_chars ─────────────────────────────────────────

    public function test_context_budget_echoed_in_result(): void
    {
        $result = $this->assembler->assemble($this->validInput(['context_budget_chars' => 12000]));

        $this->assertSame(12000, $result['max_context_budget_chars']);
    }

    // ── AC3: fail-closed on allow_direct_final_answer=true ───────────────────

    public function test_allow_direct_final_answer_true_fails_closed(): void
    {
        $result = $this->assembler->assemble($this->validInput([
            'allow_direct_final_answer' => true,
        ]));

        $this->assertFalse($result['assembled']);
        $this->assertNotNull($result['failure_reason']);
        $this->assertStringContainsString('allow_direct_final_answer', $result['failure_reason']);
        $this->assertSame([], $result['prompt_sections']);
    }

    // ── AC3: fail-closed on empty evidence_intake ────────────────────────────

    public function test_empty_evidence_intake_fails_closed(): void
    {
        $result = $this->assembler->assemble($this->validInput([
            'evidence_intake' => [],
        ]));

        $this->assertFalse($result['assembled']);
        $this->assertStringContainsString('evidence_intake_empty', $result['failure_reason']);
    }

    public function test_missing_evidence_intake_fails_closed(): void
    {
        $input = $this->validInput();
        unset($input['evidence_intake']);

        $result = $this->assembler->assemble($input);

        $this->assertFalse($result['assembled']);
    }

    // ── AC3: fail-closed output is still schema-complete ─────────────────────

    public function test_failed_assembly_still_has_schema_and_budget(): void
    {
        $result = $this->assembler->assemble($this->validInput(['evidence_intake' => []]));

        $this->assertSame(AtlasExternalBrainScaffoldedPromptAssembler::SCHEMA_VERSION, $result['schema_version']);
        $this->assertIsInt($result['max_context_budget_chars']);
    }

    // ── Evidence content appears in evidence_intake section ───────────────────

    public function test_evidence_text_appears_in_evidence_section(): void
    {
        $result = $this->assembler->assemble($this->validInput([
            'evidence_intake' => [
                ['text' => 'AtlasMegaService gap detected here'],
            ],
        ]));

        $evidenceSection = $this->findSection($result, 'evidence_intake');
        $this->assertStringContainsString('AtlasMegaService gap detected', $evidenceSection['content']);
    }

    // ── Queued targets appear in anti-duplication section ────────────────────

    public function test_queued_targets_appear_in_dedup_section(): void
    {
        $result = $this->assembler->assemble($this->validInput([
            'queued_targets' => ['app/Services/UniqueTarget.php'],
        ]));

        $dedupSection = $this->findSection($result, 'anti_duplication_check');
        $this->assertStringContainsString('UniqueTarget.php', $dedupSection['content']);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function findSection(array $result, string $name): array
    {
        foreach ($result['prompt_sections'] as $s) {
            if ($s['section'] === $name) {
                return $s;
            }
        }
        $this->fail("Section '{$name}' not found in prompt_sections.");
    }
}
