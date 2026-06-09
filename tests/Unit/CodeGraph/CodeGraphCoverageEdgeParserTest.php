<?php

declare(strict_types=1);

namespace Tests\Unit\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphCoverageEdgeParser;
use Tests\TestCase;

/**
 * AP-815 · P-9 — contract for the test→code coverage edge parser.
 *
 * Pure (no DB): the parser is a deterministic report→edges transform. We prove the
 * runtime-grade invariants — Clover XML and LCOV both parse to correct file
 * ratios + `covered_by` edges, malformed/empty input degrades to the safe empty
 * result without throwing, and the numeric edges (duplicate merge, covered>total,
 * zero-total) are sanitized rather than allowed to over-claim coverage.
 */
class CodeGraphCoverageEdgeParserTest extends TestCase
{
    private function parser(): CodeGraphCoverageEdgeParser
    {
        return new CodeGraphCoverageEdgeParser;
    }

    /**
     * Happy path — Clover XML. Two files with known statement counts must produce
     * exact covered/total/ratio rows and one `covered_by` edge per file, scored by
     * the coverage ratio and pointing at the resolved suite node.
     */
    public function test_parses_clover_into_files_ratios_and_edges(): void
    {
        $clover = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<coverage clover="3.2.0">
  <project name="atlas">
    <package name="App">
      <file name="app/Services/Foo.php">
        <metrics statements="10" coveredstatements="8"/>
      </file>
      <file name="app/Services/Bar.php">
        <metrics statements="4" coveredstatements="1"/>
      </file>
    </package>
  </project>
</coverage>
XML;

        $result = $this->parser()->parse($clover, 'clover', 'test:suite');

        $this->assertCount(2, $result['files']);

        $this->assertSame('app/Services/Foo.php', $result['files'][0]['path']);
        $this->assertSame(8, $result['files'][0]['covered']);
        $this->assertSame(10, $result['files'][0]['total']);
        $this->assertSame(0.8, $result['files'][0]['ratio']);

        $this->assertSame('app/Services/Bar.php', $result['files'][1]['path']);
        $this->assertSame(1, $result['files'][1]['covered']);
        $this->assertSame(4, $result['files'][1]['total']);
        $this->assertSame(0.25, $result['files'][1]['ratio']);

        // One covered_by edge per file, aligned, scored by ratio, EXTRACTED-grade.
        $this->assertCount(2, $result['edges']);
        $this->assertSame([
            'from_node_id' => 'node:app/Services/Foo.php',
            'to_node_id' => 'test:suite',
            'edge_type' => 'covered_by',
            'score' => 0.8,
        ], $result['edges'][0]);
        $this->assertSame('node:app/Services/Bar.php', $result['edges'][1]['from_node_id']);
        $this->assertSame(0.25, $result['edges'][1]['score']);
        $this->assertSame('covered_by', $result['edges'][1]['edge_type']);

        // avg of 0.8 and 0.25 = 0.525
        $this->assertSame(2, $result['stats']['files']);
        $this->assertSame(0.525, $result['stats']['avg_ratio']);
    }

    /**
     * Happy path — LCOV. SF/DA/end_of_record records become files; covered is the
     * count of DA lines with hits > 0; the optional checksum 3rd column is ignored.
     */
    public function test_parses_lcov_into_files_ratios_and_edges(): void
    {
        $lcov = <<<'LCOV'
TN:
SF:src/alpha.js
DA:1,5
DA:2,0
DA:3,2
DA:4,0
end_of_record
SF:src/beta.js
DA:10,1,abc123checksum
DA:11,3
end_of_record
LCOV;

        $result = $this->parser()->parse($lcov, 'lcov', 'test:suite');

        $this->assertCount(2, $result['files']);

        // alpha: 4 statements, 2 with hits > 0 → 0.5
        $this->assertSame('src/alpha.js', $result['files'][0]['path']);
        $this->assertSame(2, $result['files'][0]['covered']);
        $this->assertSame(4, $result['files'][0]['total']);
        $this->assertSame(0.5, $result['files'][0]['ratio']);

        // beta: 2 statements, both hit (checksum column ignored) → 1.0
        $this->assertSame('src/beta.js', $result['files'][1]['path']);
        $this->assertSame(2, $result['files'][1]['covered']);
        $this->assertSame(2, $result['files'][1]['total']);
        $this->assertSame(1.0, $result['files'][1]['ratio']);

        $this->assertSame('node:src/alpha.js', $result['edges'][0]['from_node_id']);
        $this->assertSame(0.5, $result['edges'][0]['score']);
        $this->assertSame('node:src/beta.js', $result['edges'][1]['from_node_id']);
        $this->assertSame(1.0, $result['edges'][1]['score']);

        $this->assertSame(2, $result['stats']['files']);
        $this->assertSame(0.75, $result['stats']['avg_ratio']); // (0.5 + 1.0) / 2
    }

    /**
     * Edge case — malformed input never throws and returns the safe empty result,
     * for BOTH the XML path (broken markup) and a garbage blob.
     */
    public function test_malformed_input_returns_empty_result_without_throwing(): void
    {
        $empty = [
            'files' => [],
            'edges' => [],
            'stats' => ['files' => 0, 'avg_ratio' => 0.0],
        ];

        // Broken XML declared as clover → libxml fails safe.
        $this->assertSame($empty, $this->parser()->parse('<coverage><file name="x"><metrics', 'clover'));

        // Total garbage, auto-sniffed → unknown format → empty.
        $this->assertSame($empty, $this->parser()->parse("\x00\x01 not a report at all %%%", 'auto'));

        // Empty / whitespace-only content.
        $this->assertSame($empty, $this->parser()->parse('', 'clover'));
        $this->assertSame($empty, $this->parser()->parse("   \n\t ", 'lcov'));

        // Well-formed XML but with NO <file> elements → nothing to score → empty.
        $this->assertSame($empty, $this->parser()->parse('<coverage><project/></coverage>', 'clover'));
    }

    /**
     * Edge case — numeric sanitization. A duplicate file is MERGED (summed), a
     * report claiming covered > total is clamped (never over-claims coverage), and
     * a file with zero statements yields ratio 0.0 (no division by zero).
     */
    public function test_merges_duplicates_and_clamps_overclaim_and_zero_total(): void
    {
        $clover = <<<'XML'
<?xml version="1.0"?>
<coverage>
  <file name="dup.php"><metrics statements="2" coveredstatements="1"/></file>
  <file name="dup.php"><metrics statements="3" coveredstatements="3"/></file>
  <file name="liar.php"><metrics statements="4" coveredstatements="99"/></file>
  <file name="empty.php"><metrics statements="0" coveredstatements="0"/></file>
</coverage>
XML;

        $result = $this->parser()->parse($clover, 'clover');

        // dup.php merged once: covered 1+3=4, total 2+3=5 → 0.8
        $this->assertCount(3, $result['files']);
        $this->assertSame('dup.php', $result['files'][0]['path']);
        $this->assertSame(4, $result['files'][0]['covered']);
        $this->assertSame(5, $result['files'][0]['total']);
        $this->assertSame(0.8, $result['files'][0]['ratio']);

        // liar.php: covered clamped to total (4), ratio capped at 1.0 — no over-claim.
        $this->assertSame('liar.php', $result['files'][1]['path']);
        $this->assertSame(4, $result['files'][1]['covered']);
        $this->assertSame(4, $result['files'][1]['total']);
        $this->assertSame(1.0, $result['files'][1]['ratio']);

        // empty.php: zero total → ratio 0.0, no division by zero, no NaN.
        $this->assertSame('empty.php', $result['files'][2]['path']);
        $this->assertSame(0, $result['files'][2]['total']);
        $this->assertSame(0.0, $result['files'][2]['ratio']);
        $this->assertFalse(is_nan($result['files'][2]['ratio']));

        // Edges stay aligned 1:1 with the merged file list.
        $this->assertCount(3, $result['edges']);
        $this->assertSame('node:dup.php', $result['edges'][0]['from_node_id']);
        $this->assertSame(1.0, $result['edges'][1]['score']);
        $this->assertSame(0.0, $result['edges'][2]['score']);
    }

    /**
     * Edge case — robustness extras: auto-sniff routes a mislabelled report to the
     * right parser, a custom suite node flows into every edge's to_node_id, Windows
     * backslash paths + leading "./" normalize to '/', and a DA before any SF (no
     * open record) is ignored rather than crashing.
     */
    public function test_auto_sniff_custom_suite_and_path_normalization(): void
    {
        // LCOV body but format LABELLED 'clover' → sniff overrides to lcov.
        // Path uses Windows separators and a leading "./" to prove normalization.
        $lcov = <<<'LCOV'
DA:1,1
SF:.\app\Win\Path.php
DA:1,1
DA:2,0
end_of_record
LCOV;

        $result = $this->parser()->parse($lcov, 'auto', 'test:phpunit-suite');

        $this->assertCount(1, $result['files']);
        // ".\app\Win\Path.php" → "app/Win/Path.php"
        $this->assertSame('app/Win/Path.php', $result['files'][0]['path']);
        $this->assertSame(1, $result['files'][0]['covered']);
        $this->assertSame(2, $result['files'][0]['total']);
        $this->assertSame(0.5, $result['files'][0]['ratio']);

        // Custom suite node propagated to the edge target.
        $this->assertSame('node:app/Win/Path.php', $result['edges'][0]['from_node_id']);
        $this->assertSame('test:phpunit-suite', $result['edges'][0]['to_node_id']);
        $this->assertSame('covered_by', $result['edges'][0]['edge_type']);
    }

    /**
     * Determinism — the same report parsed twice yields byte-identical output.
     * (No clock/random/DB; first-seen ordering is stable.)
     */
    public function test_output_is_deterministic(): void
    {
        $clover = '<coverage><file name="a.php"><metrics statements="3" coveredstatements="2"/></file>'
            .'<file name="b.php"><metrics statements="5" coveredstatements="5"/></file></coverage>';

        $first = $this->parser()->parse($clover, 'clover');
        $second = $this->parser()->parse($clover, 'clover');

        $this->assertSame($first, $second);
    }
}
