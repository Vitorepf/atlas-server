<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\AutonomousEvolution\SelfMod\AtlasLoopSelfModInvariantExtractor;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasLoopSelfModInvariantExtractor::recordsFromDocComment does not
 * emit a negative source_line for a large docblock.
 */
final class AtlasLoopSelfModInvariantExtractorHardeningTest extends TestCase
{
    private function recordsFromDocComment(
        AtlasLoopSelfModInvariantExtractor $extractor,
        string $docComment,
        int $startLine
    ): array {
        $reflection = new \ReflectionClass($extractor);
        $method = $reflection->getMethod('recordsFromDocComment');
        return $method->invokeArgs($extractor, [
            'Test\Class',
            $docComment,
            $startLine,
            'class',
            '/fake/path.php',
        ]);
    }

    public function test_large_docblock_with_small_start_line_does_not_produce_negative_source_line(): void
    {
        $extractor = new AtlasLoopSelfModInvariantExtractor();

        // Create a docblock with 100 lines
        $lines = [];
        for ($i = 0; $i < 100; $i++) {
            $lines[] = " * line $i";
        }
        $lines[] = " * @invariant something";
        $docComment = "/**
" . implode("
", $lines) . "
 */";

        // startLine = 5, but docblock has 102 lines => docStartLine would be 5 - 102 = -97
        $records = $this->recordsFromDocComment($extractor, $docComment, 5);

        // All records should have positive source_line
        foreach ($records as $record) {
            $this->assertGreaterThan(0, $record['source_line'], "source_line must be positive, got {$record['source_line']}");
        }
    }

    public function test_normal_docblock_produces_correct_source_lines(): void
    {
        $extractor = new AtlasLoopSelfModInvariantExtractor();

        $docComment = "/**
 * @invariant test
 */";

        $records = $this->recordsFromDocComment($extractor, $docComment, 10);

        $this->assertCount(1, $records);
        $this->assertGreaterThan(0, $records[0]['source_line']);
    }

    public function test_docblock_longer_than_start_line_floors_at_one(): void
    {
        $extractor = new AtlasLoopSelfModInvariantExtractor();

        // 50-line docblock, startLine = 1
        $lines = [];
        for ($i = 0; $i < 49; $i++) {
            $lines[] = " * line $i";
        }
        $lines[] = " * @invariant floor-test";
        $docComment = "/**
" . implode("
", $lines) . "
 */";

        $records = $this->recordsFromDocComment($extractor, $docComment, 1);

        foreach ($records as $record) {
            $this->assertGreaterThanOrEqual(1, $record['source_line'], "source_line must be >= 1");
        }
    }
}
