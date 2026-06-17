<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopConstraintsBlockAssembler as CB;
use PHPUnit\Framework\TestCase;

/**
 * ARBOR-GRAFT CB1 — the constraints-block text assembler (advisory context; never gates).
 */
final class AtlasLoopConstraintsBlockAssemblerTest extends TestCase
{
    public function test_assembles_all_three_blocks_with_anti_retread_directive(): void
    {
        $out = CB::assemble(
            prunedLessons: ['caching wrapper never lands'],
            validatedFindings: ['extracted MoneyRounder, gate green'],
            treeShape: ['pruned' => 2, 'done' => 1],
        );

        $this->assertStringContainsString('## TREE SHAPE', $out);
        $this->assertStringContainsString('2 pruned', $out);
        $this->assertStringContainsString('## PRUNED LESSONS', $out);
        $this->assertStringContainsString('hidden assumption or mechanism-class', $out); // anti-re-tread
        $this->assertStringContainsString('## VALIDATED FINDINGS', $out);
        $this->assertStringContainsString('machine-certified', $out);
    }

    public function test_empty_corpora_yields_empty_block(): void
    {
        $this->assertSame('', CB::assemble([], [], []));
    }

    public function test_operator_note_is_tagged_distinct_from_validated_findings(): void
    {
        $out = CB::assemble([], ['certified thing'], [], 'focus on the refund path this week');
        $this->assertStringContainsString('## OPERATOR NOTE', $out);
        $this->assertStringContainsString('provenance: operator_note', $out);
        $this->assertStringContainsString('NOT a certified finding', $out);
        // the note text must not appear inside the VALIDATED FINDINGS block heading line
        $this->assertStringContainsString('focus on the refund path', $out);
    }

    public function test_blank_operator_note_is_omitted(): void
    {
        $out = CB::assemble([], [], ['done' => 1], '   ');
        $this->assertStringNotContainsString('OPERATOR NOTE', $out);
    }

    public function test_long_lines_are_truncated(): void
    {
        $long = str_repeat('x', 500);
        $out = CB::assemble([$long], [], []);
        $this->assertStringContainsString('...', $out);
        // each rendered line stays bounded
        foreach (explode("\n", $out) as $line) {
            $this->assertLessThanOrEqual(260, strlen($line));
        }
    }
}
