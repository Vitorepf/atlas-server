<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev;

use App\Services\Ai\Programming\AtlasDev\Pipeline\DevWorkcellInstructionAssembler;
use PHPUnit\Framework\TestCase;

final class DevWorkcellInstructionAssemblerTest extends TestCase
{
    private function svc(): DevWorkcellInstructionAssembler
    {
        return new DevWorkcellInstructionAssembler;
    }

    private function workcell(array $overrides = []): array
    {
        return array_merge([
            'workcell_id' => 'wc-1',
            'objective_slice' => 'add caching to FooService',
            'allowed_files' => ['app/Services/Foo/FooService.php', 'app/Services/Foo/FooCache.php'],
            'acceptance_criteria' => [
                ['id' => 'ac1', 'description' => 'cache hits avoid recompute', 'verification' => 'php artisan test --filter=FooServiceTest'],
            ],
            'risk_level' => 'low',
            'verification_command' => 'php artisan test --filter=FooServiceTest',
            'depends_on' => [],
        ], $overrides);
    }

    private function distilledContext(array $overrides = []): array
    {
        return array_merge([
            'schema' => 'atlas.dev.context_budget_distiller.v1',
            'sections' => [
                'callers' => 'FooController::index() calls FooService::get().',
                'tests' => 'tests/Unit/Services/Foo/FooServiceTest.php covers get().',
            ],
            'included_labels' => ['callers', 'tests'],
            'dropped_report' => [],
            'total_chars' => 90,
            'budget_chars' => 4000,
        ], $overrides);
    }

    private function spec(array $overrides = []): array
    {
        return array_merge([
            'goal' => 'add caching to FooService',
            'canonical_context' => [
                ['kind' => 'design_path', 'ref' => 'extend_existing_class', 'reason' => 'FooService already owns the get() method'],
            ],
        ], $overrides);
    }

    private function exemplars(): array
    {
        return [
            ['run_id' => 'run-1', 'objective_digest' => 'abc123', 'objective_excerpt' => 'Adicionar cache ao FooService::get()', 'design_path' => 'extend_existing_class', 'files_touched' => ['app/Services/Foo/FooService.php'], 'verification_command' => 'php artisan test --filter=FooServiceTest', 'outcome' => 'passed'],
        ];
    }

    public function test_exemplar_renders_objective_excerpt_and_files_touched(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertStringContainsString(
            'did "Adicionar cache ao FooService::get()"',
            $r['instruction_text'],
            'an exemplar without its objective is an opaque run id — the goal is what a model can imitate',
        );
        $this->assertStringContainsString('files=app/Services/Foo/FooService.php', $r['instruction_text']);
    }

    public function test_exemplar_without_excerpt_renders_without_did_clause(): void
    {
        $exemplars = [
            ['run_id' => 'run-2', 'objective_digest' => 'def456', 'design_path' => 'extend_existing_class', 'files_touched' => [], 'verification_command' => '', 'outcome' => 'passed'],
        ];
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $exemplars);

        $this->assertStringContainsString('run run-2', $r['instruction_text']);
        $this->assertStringNotContainsString('did ""', $r['instruction_text']);
    }

    // ── AC: rendering order ───────────────────────────────────────────────────

    public function test_sections_render_in_fixed_order(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertSame([
            'objective',
            'allowed_files',
            'design_path',
            'acceptance_criteria',
            'context:callers',
            'context:tests',
            'exemplars',
        ], $r['sections']);
    }

    public function test_objective_precedes_allowed_files_in_instruction_text(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $objectivePos = strpos($r['instruction_text'], 'OBJECTIVE');
        $filesPos = strpos($r['instruction_text'], 'ALLOWED FILES');
        $designPos = strpos($r['instruction_text'], 'DESIGN PATH');
        $acceptancePos = strpos($r['instruction_text'], 'ACCEPTANCE CRITERIA');
        $exemplarsPos = strpos($r['instruction_text'], 'GREEN-RUN EXEMPLARS');

        $this->assertLessThan($filesPos, $objectivePos);
        $this->assertLessThan($designPos, $filesPos);
        $this->assertLessThan($acceptancePos, $designPos);
        $this->assertLessThan($exemplarsPos, $acceptancePos);
    }

    public function test_objective_slice_is_rendered(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertStringContainsString('add caching to FooService', $r['instruction_text']);
    }

    public function test_allowed_files_are_rendered(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertStringContainsString('app/Services/Foo/FooService.php', $r['instruction_text']);
        $this->assertStringContainsString('app/Services/Foo/FooCache.php', $r['instruction_text']);
    }

    public function test_design_path_and_reason_are_rendered(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertStringContainsString('extend_existing_class', $r['instruction_text']);
        $this->assertStringContainsString('FooService already owns the get() method', $r['instruction_text']);
    }

    public function test_acceptance_criteria_render_as_runnable_commands(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertStringContainsString('php artisan test --filter=FooServiceTest', $r['instruction_text']);
    }

    public function test_acceptance_criteria_falls_back_to_verification_command_when_criteria_carry_none(): void
    {
        $workcell = $this->workcell(['acceptance_criteria' => [['id' => 'ac1', 'description' => 'no command here']]]);
        $r = $this->svc()->assemble($workcell, $this->distilledContext(), $this->spec(), []);

        $this->assertStringContainsString('ACCEPTANCE CRITERIA', $r['instruction_text']);
        $this->assertStringContainsString('php artisan test --filter=FooServiceTest', $r['instruction_text']);
    }

    // ── AC: budget-respecting char_count ──────────────────────────────────────

    public function test_char_count_matches_instruction_text_length(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), $this->exemplars());

        $this->assertSame(mb_strlen($r['instruction_text']), $r['char_count']);
    }

    public function test_char_count_reflects_the_distilled_budget_context_not_the_full_undistilled_content(): void
    {
        // The distiller already trimmed to budget_chars=4000; the assembler must render exactly
        // what it was given, not exceed it by re-inflating dropped sections.
        $distilled = $this->distilledContext(['sections' => ['callers' => str_repeat('x', 50)], 'included_labels' => ['callers']]);
        $r = $this->svc()->assemble($this->workcell(), $distilled, $this->spec(), []);

        $this->assertLessThanOrEqual($distilled['budget_chars'], $r['char_count']);
    }

    // ── AC: byte-identical output for repeated inputs ─────────────────────────

    public function test_assemble_is_byte_identical_for_repeated_identical_input(): void
    {
        $workcell = $this->workcell();
        $distilled = $this->distilledContext();
        $spec = $this->spec();
        $exemplars = $this->exemplars();

        $a = $this->svc()->assemble($workcell, $distilled, $spec, $exemplars);
        $b = $this->svc()->assemble($workcell, $distilled, $spec, $exemplars);

        $this->assertSame($a, $b);
    }

    // ── AC: valid instruction when the exemplar list is empty ────────────────

    public function test_empty_exemplar_list_still_produces_a_valid_instruction(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), $this->spec(), []);

        $this->assertNotSame('', $r['instruction_text']);
        $this->assertNotContains('exemplars', $r['sections']);
        $this->assertStringNotContainsString('GREEN-RUN EXEMPLARS', $r['instruction_text']);
        $this->assertContains('objective', $r['sections']);
    }

    // ── graceful degradation on missing facts ─────────────────────────────────

    public function test_missing_design_path_entry_omits_that_section(): void
    {
        $r = $this->svc()->assemble($this->workcell(), $this->distilledContext(), ['canonical_context' => []], []);

        $this->assertNotContains('design_path', $r['sections']);
    }

    public function test_missing_allowed_files_omits_that_section(): void
    {
        $r = $this->svc()->assemble($this->workcell(['allowed_files' => []]), $this->distilledContext(), $this->spec(), []);

        $this->assertNotContains('allowed_files', $r['sections']);
    }

    public function test_empty_distilled_context_omits_context_sections(): void
    {
        $r = $this->svc()->assemble($this->workcell(), ['sections' => [], 'included_labels' => []], $this->spec(), []);

        $contextSections = array_filter($r['sections'], static fn (string $s): bool => str_starts_with($s, 'context:'));
        $this->assertSame([], array_values($contextSections));
    }

    public function test_all_missing_produces_empty_sections_list(): void
    {
        $r = $this->svc()->assemble([], [], [], []);

        $this->assertSame([], $r['sections']);
        $this->assertSame('', $r['instruction_text']);
        $this->assertSame(0, $r['char_count']);
    }
}
