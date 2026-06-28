<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopComprehensionOriginator;
use PHPUnit\Framework\TestCase;

/**
 * CONTRACT-GAP origination (automated path) — the writer prompt gains a declared-but-unimplemented-contract
 * line. contractGapPromptLine is the byte-identical-OFF seam: EMPTY list (the flag-OFF default path) MUST yield
 * '' so the prompt is unchanged; a non-empty list injects the grounded FQCNs.
 */
final class AtlasLoopComprehensionOriginatorPromptTest extends TestCase
{
    public function test_empty_gap_list_yields_empty_line_byte_identical_off(): void
    {
        self::assertSame('', AtlasLoopComprehensionOriginator::contractGapPromptLine([]));
        // whitespace-only entries are filtered → still empty (OFF path can never leak a stray line).
        self::assertSame('', AtlasLoopComprehensionOriginator::contractGapPromptLine(['', '   ']));
    }

    public function test_non_empty_list_injects_grounded_fqcns(): void
    {
        $line = AtlasLoopComprehensionOriginator::contractGapPromptLine([
            'App\\Services\\Ai\\AutonomousEvolution\\Quaternity\\LoopIntentDrift\\AtlasLoopAmbitionFacultyStore',
            'App\\X\\AtlasLoopOperatorIntentSource',
        ]);
        self::assertStringContainsString('AtlasLoopAmbitionFacultyStore', $line);
        self::assertStringContainsString('AtlasLoopOperatorIntentSource', $line);
        self::assertStringContainsString('ZERO implementer', $line);
        self::assertStringStartsWith("\n\n", $line, 'appends as a fact line, never mid-sentence');
    }

    public function test_caps_at_eight_to_keep_the_prompt_bounded(): void
    {
        $many = array_map(static fn (int $i): string => "App\\Iface{$i}", range(1, 20));
        $line = AtlasLoopComprehensionOriginator::contractGapPromptLine($many);
        self::assertStringContainsString('App\\Iface1', $line);
        self::assertStringNotContainsString('App\\Iface9', $line, 'only the first 8 are listed');
    }
}
