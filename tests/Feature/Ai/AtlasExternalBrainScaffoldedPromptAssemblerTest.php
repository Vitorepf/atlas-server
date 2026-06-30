<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldedPromptAssembler;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldedPromptAssemblerTest extends TestCase
{
    private AtlasExternalBrainScaffoldedPromptAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new AtlasExternalBrainScaffoldedPromptAssembler;
    }

    private function assemble(array $overrides = []): array
    {
        return $this->assembler->assemble(array_merge([
            'evidence_intake'  => [['text' => 'Organ AtlasFooService has zero test coverage.']],
            'queued_targets'   => [],
        ], $overrides));
    }

    // ── AC2: fail-closed guards ───────────────────────────────────────────────

    public function test_fails_closed_when_allow_direct_final_answer_true(): void
    {
        $r = $this->assemble(['allow_direct_final_answer' => true]);

        $this->assertFalse($r['assembled']);
        $this->assertStringContainsString('allow_direct_final_answer', $r['failure_reason']);
    }

    public function test_fails_closed_when_evidence_intake_empty(): void
    {
        $r = $this->assemble(['evidence_intake' => []]);

        $this->assertFalse($r['assembled']);
        $this->assertStringContainsString('evidence_intake_empty', $r['failure_reason']);
    }

    public function test_fails_closed_when_queued_targets_key_absent(): void
    {
        $r = $this->assembler->assemble([
            'evidence_intake' => [['text' => 'some evidence here']],
            // queued_targets intentionally absent
        ]);

        $this->assertFalse($r['assembled']);
        $this->assertStringContainsString('dedup', $r['failure_reason']);
    }

    public function test_failed_assembly_has_empty_sections(): void
    {
        $r = $this->assemble(['evidence_intake' => []]);

        $this->assertSame([], $r['prompt_sections']);
    }

    // ── AC3: valid scaffold emits sections in required order ──────────────────

    public function test_valid_input_assembles_successfully(): void
    {
        $r = $this->assemble();

        $this->assertTrue($r['assembled']);
        $this->assertNull($r['failure_reason']);
    }

    public function test_sections_contain_all_required_types(): void
    {
        $r = $this->assemble();

        $names = array_column($r['prompt_sections'], 'section');
        $this->assertContains('evidence_intake',        $names);
        $this->assertContains('live_queue_snapshot',    $names);
        $this->assertContains('ranking_order',          $names);
        $this->assertContains('reasoning_scaffold',     $names);
        $this->assertContains('anti_duplication_check', $names);
        $this->assertContains('forbidden_output_shapes', $names);
        $this->assertContains('acceptance_floor',       $names);
        $this->assertContains('budget_guard',           $names);
        $this->assertContains('output_contract',        $names);
    }

    public function test_sections_follow_required_order(): void
    {
        $expectedOrder = [
            'evidence_intake',
            'live_queue_snapshot',
            'ranking_order',
            'reasoning_scaffold',
            'anti_duplication_check',
            'forbidden_output_shapes',
            'acceptance_floor',
            'budget_guard',
            'output_contract',
        ];
        $r = $this->assemble();
        $actualOrder = array_column($r['prompt_sections'], 'section');

        $this->assertSame($expectedOrder, $actualOrder);
    }

    public function test_forbidden_output_shapes_section_is_present(): void
    {
        $r = $this->assemble();

        $byName = array_combine(
            array_column($r['prompt_sections'], 'section'),
            array_column($r['prompt_sections'], 'content'),
        );
        $this->assertArrayHasKey('forbidden_output_shapes', $byName);
        $this->assertStringContainsString('FORBIDDEN', $byName['forbidden_output_shapes']);
    }

    public function test_acceptance_floor_section_is_present(): void
    {
        $r = $this->assemble();

        $byName = array_combine(
            array_column($r['prompt_sections'], 'section'),
            array_column($r['prompt_sections'], 'content'),
        );
        $this->assertArrayHasKey('acceptance_floor', $byName);
        $this->assertStringContainsString('ACCEPTANCE FLOOR', $byName['acceptance_floor']);
    }

    public function test_queued_targets_appear_in_anti_duplication_section(): void
    {
        $r = $this->assemble(['queued_targets' => ['AtlasFooService', 'AtlasBarService']]);

        $byName = array_combine(
            array_column($r['prompt_sections'], 'section'),
            array_column($r['prompt_sections'], 'content'),
        );
        $this->assertStringContainsString('AtlasFooService', $byName['anti_duplication_check']);
        $this->assertStringContainsString('AtlasBarService', $byName['anti_duplication_check']);
    }

    // ── AC4: output contract requires required artifacts ──────────────────────

    public function test_required_artifacts_includes_task_spec_with_allowed_files(): void
    {
        $r = $this->assemble();

        $this->assertContains('task_spec_with_allowed_files', $r['required_artifacts']);
    }

    public function test_required_artifacts_includes_runnable_acceptance_criteria(): void
    {
        $r = $this->assemble();

        $this->assertContains('acceptance_criteria_runnable', $r['required_artifacts']);
    }

    public function test_required_artifacts_includes_implementation_notes(): void
    {
        $r = $this->assemble();

        $this->assertContains('implementation_notes', $r['required_artifacts']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['evidence_intake' => [['text' => 'Organ X has no consumers.']], 'queued_targets' => ['Y']];

        $this->assertSame(json_encode($this->assemble($input)), json_encode($this->assemble($input)));
    }

    public function test_schema_version_is_set(): void
    {
        $r = $this->assemble();

        $this->assertSame(AtlasExternalBrainScaffoldedPromptAssembler::SCHEMA_VERSION, $r['schema_version']);
    }
}
