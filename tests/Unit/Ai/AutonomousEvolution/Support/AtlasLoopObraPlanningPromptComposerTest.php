<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Support;

use App\Services\Ai\AutonomousEvolution\Support\AtlasLoopObraPlanningPromptComposer;
use PHPUnit\Framework\TestCase;

/**
 * ITEM8 — proves the cohesive prompt/JSON helper extracted from AtlasLoopObraExecutionAdapter.
 *
 * The composer is the only place the adapter turns the goal + prior-gaps into a deterministic JSON-only
 * prompt (specPrompt / dagPrompt) and parses the provider's response back into an object (decodeJsonObject).
 * Its contract is byte-identical to the previous private methods on the god-class, so these tests must:
 *  (a) prove specPrompt embeds the goal + the canonical shape + the prior-gaps fix line (when any);
 *  (b) prove dagPrompt embeds the goal + hint + new-files + canonical shape + the prior-gaps fix line,
 *  (c) prove decodeJsonObject handles clean JSON, prose-wrapped JSON, ```json fenced JSON, non-objects,
 *      empty input, and missing braces — returning null for the rejection cases.
 *
 * No DB, no Laravel container — pure core, hang-free.
 */
final class AtlasLoopObraPlanningPromptComposerTest extends TestCase
{
    private AtlasLoopObraPlanningPromptComposer $composer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->composer = new AtlasLoopObraPlanningPromptComposer;
    }

    public function test_spec_prompt_embeds_goal_and_canonical_shape(): void
    {
        $prompt = $this->composer->specPrompt('reduce complexity of X', []);

        $this->assertStringContainsString('Goal: reduce complexity of X', $prompt);
        $this->assertStringContainsString('"summary"', $prompt);
        $this->assertStringContainsString('"acceptance_criteria"', $prompt);
        $this->assertStringContainsString('"suggested_files"', $prompt);
        $this->assertStringContainsString('"decomposition_hint"', $prompt);
        $this->assertStringContainsString('Produce ONLY a single deterministic JSON object', $prompt);
    }

    public function test_spec_prompt_omits_prior_gaps_line_when_empty(): void
    {
        $prompt = $this->composer->specPrompt('g', []);

        $this->assertStringNotContainsString('The previous spec had these gaps', $prompt);
    }

    public function test_spec_prompt_includes_prior_gaps_fix_line_when_non_empty(): void
    {
        $prompt = $this->composer->specPrompt('g', ['no summary', 'missing required criterion']);

        $this->assertStringContainsString('The previous spec had these gaps; FIX them:', $prompt);
        $this->assertStringContainsString('no summary', $prompt);
        $this->assertStringContainsString('missing required criterion', $prompt);
    }

    public function test_dag_prompt_embeds_goal_hint_and_new_files_when_present(): void
    {
        $prompt = $this->composer->dagPrompt('ship Y', [
            'decomposition_hint' => 'split GodClass into a Probe + a Composer',
            'suggested_files' => ['app/Support/Y/Probe.php', 'app/Support/Y/Composer.php'],
        ], []);

        $this->assertStringContainsString('Goal: ship Y', $prompt);
        $this->assertStringContainsString('Decomposition hint: split GodClass into a Probe + a Composer', $prompt);
        $this->assertStringContainsString('New files to create: app/Support/Y/Probe.php, app/Support/Y/Composer.php', $prompt);
        $this->assertStringContainsString('"plan_id"', $prompt);
        $this->assertStringContainsString('"nodes"', $prompt);
        $this->assertStringContainsString('Do NOT include create-class nodes for the new files', $prompt);
    }

    public function test_dag_prompt_omits_hint_and_new_files_lines_when_absent(): void
    {
        $prompt = $this->composer->dagPrompt('g', [], []);

        $this->assertStringNotContainsString('Decomposition hint:', $prompt);
        $this->assertStringNotContainsString('New files to create:', $prompt);
    }

    public function test_dag_prompt_includes_prior_gaps_fix_line_when_non_empty(): void
    {
        $prompt = $this->composer->dagPrompt('g', [], ['node missing target_area', 'plan_id not unique']);

        $this->assertStringContainsString('The previous DAG had these gaps; FIX them:', $prompt);
        $this->assertStringContainsString('node missing target_area', $prompt);
        $this->assertStringContainsString('plan_id not unique', $prompt);
    }

    public function test_decode_json_object_handles_clean_object(): void
    {
        $obj = $this->composer->decodeJsonObject('{"summary":"s","acceptance_criteria":[]}');

        $this->assertIsArray($obj);
        $this->assertSame('s', $obj['summary']);
        $this->assertSame([], $obj['acceptance_criteria']);
    }

    public function test_decode_json_object_handles_prose_wrapped_object(): void
    {
        $obj = $this->composer->decodeJsonObject("Sure! Here you go:\n{\"summary\":\"s\",\"ok\":true}\nDone.");

        $this->assertIsArray($obj);
        $this->assertSame('s', $obj['summary']);
        $this->assertTrue($obj['ok']);
    }

    public function test_decode_json_object_handles_markdown_json_fence(): void
    {
        $obj = $this->composer->decodeJsonObject("```json\n{\"plan_id\":\"p1\",\"nodes\":[{\"id\":\"n1\"}]}\n```");

        $this->assertIsArray($obj);
        $this->assertSame('p1', $obj['plan_id']);
        $this->assertCount(1, $obj['nodes']);
    }

    public function test_decode_json_object_returns_null_on_empty_input(): void
    {
        $this->assertNull($this->composer->decodeJsonObject(''));
        $this->assertNull($this->composer->decodeJsonObject("   \n\t  "));
    }

    public function test_decode_json_object_returns_null_on_non_object_payload(): void
    {
        // String scalar — must reject (json_decode returns a string, not an array).
        $this->assertNull($this->composer->decodeJsonObject('"just a string"'));
        // Bare scalar — must reject (json_decode returns an int, not an array).
        $this->assertNull($this->composer->decodeJsonObject('42'));
        // No braces at all — must reject (no extractable outermost object).
        $this->assertNull($this->composer->decodeJsonObject('not json at all'));
    }

    public function test_decode_json_object_returns_null_on_object_with_trailing_garbage_but_no_close_brace(): void
    {
        // Starts with `{` but has no matching `}` — extraction must fail cleanly.
        $this->assertNull($this->composer->decodeJsonObject('{ "summary": "s" '));
    }
}
