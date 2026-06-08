<?php

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphExtractorScaffoldGenerator;
use PHPUnit\Framework\TestCase;

class CodeGraphExtractorScaffoldGeneratorTest extends TestCase
{
    public function test_proposal_has_self_construction_safety_rails(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('rs', 800, ['fn', 'struct', 'impl', 'use']);

        $this->assertSame(CodeGraphExtractorScaffoldGenerator::SCHEMA, $proposal['schema_version']);
        $this->assertSame(CodeGraphExtractorScaffoldGenerator::TYPE_EXTRACTOR_SCAFFOLD, $proposal['type']);

        // The M-7 hard rails: a proposal is never a green light, never auto-runs,
        // and always defers to a human. These are explicit, not implied.
        $this->assertFalse($proposal['promotion_allowed'], 'promotion must be blocked');
        $this->assertFalse($proposal['auto_execute'], 'nothing here may auto-execute');
        $this->assertTrue($proposal['requires_human_review'], 'a human must gate the next step');

        // Strict false/true identity (not just falsy/truthy).
        $this->assertSame(false, $proposal['promotion_allowed']);
        $this->assertSame(false, $proposal['auto_execute']);
        $this->assertSame(true, $proposal['requires_human_review']);
    }

    public function test_outline_is_non_empty_text(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('go', 300, ['func', 'package']);

        $this->assertIsString($proposal['scaffold_outline']);
        $this->assertNotSame('', trim($proposal['scaffold_outline']));
        $this->assertStringContainsString('.go', $proposal['scaffold_outline'], 'outline references the target extension');
        $this->assertStringContainsString('NOT executable code', $proposal['scaffold_outline']);
    }

    public function test_outline_describes_steps_but_emits_no_runnable_code(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('rb', 50, ['def', 'class', 'require']);

        $outline = $proposal['scaffold_outline'];

        // It is a prose description, not source: none of these code/exec markers
        // may appear. This is the core M-7 safety property.
        foreach (['<?php', 'function ', 'def ', 'class ', 'exec(', 'shell_exec', 'eval(', 'system(', 'fn ', '#!/'] as $codeMarker) {
            $this->assertStringNotContainsString($codeMarker, $outline, "outline must not contain code marker: {$codeMarker}");
        }

        // But it IS a real, numbered plan a human could follow.
        $this->assertStringContainsString('1.', $outline);
        $this->assertStringContainsString('human review', $outline);
    }

    public function test_node_types_are_suggested_from_keywords_with_baseline(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('rs', 800, ['fn', 'struct', 'use', 'const']);

        $types = $proposal['suggested_node_types'];

        // Baseline always present and first.
        $this->assertSame('module', $types[0]);
        $this->assertSame('symbol', $types[1]);

        // Keyword-derived types present (fn->function, struct->struct, use->import, const->constant).
        $this->assertContains('function', $types);
        $this->assertContains('struct', $types);
        $this->assertContains('import', $types);
        $this->assertContains('constant', $types);

        // No duplicates.
        $this->assertSame(array_values(array_unique($types)), $types);
    }

    public function test_baseline_node_types_present_even_without_keywords(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('zig', 40, []);

        $this->assertSame(['module', 'symbol'], $proposal['suggested_node_types']);
        $this->assertSame([], $proposal['observed_keywords']);
    }

    public function test_extension_is_normalized_and_blank_falls_back(): void
    {
        $a = (new CodeGraphExtractorScaffoldGenerator)->propose('*.RS', 10, []);
        $this->assertSame('rs', $a['extension']);

        $b = (new CodeGraphExtractorScaffoldGenerator)->propose('.Go', 10, []);
        $this->assertSame('go', $b['extension']);

        $blank = (new CodeGraphExtractorScaffoldGenerator)->propose('   ', 10, []);
        $this->assertSame('unknown', $blank['extension']);
    }

    public function test_file_count_is_clamped_to_non_negative(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('rs', -7, []);

        $this->assertSame(0, $proposal['file_count']);
    }

    public function test_invalid_keywords_are_ignored(): void
    {
        $proposal = (new CodeGraphExtractorScaffoldGenerator)->propose('rs', 5, ['fn', '', '   ', 123, ['nested'], null]);

        // Only the valid 'fn' survives.
        $this->assertSame(['fn'], $proposal['observed_keywords']);
        $this->assertContains('function', $proposal['suggested_node_types']);
    }

    public function test_propose_is_deterministic_across_keyword_orderings(): void
    {
        $generator = new CodeGraphExtractorScaffoldGenerator;

        $a = $generator->propose('rs', 800, ['use', 'fn', 'struct', 'impl', 'fn']);
        $b = $generator->propose('rs', 800, ['impl', 'struct', 'fn', 'use']);

        // Identical input (modulo order/dupes) => byte-identical proposal.
        $this->assertSame($a, $b, 'proposal independent of keyword ordering and duplicates');
        $this->assertSame($a['scaffold_outline'], $b['scaffold_outline']);
    }
}
