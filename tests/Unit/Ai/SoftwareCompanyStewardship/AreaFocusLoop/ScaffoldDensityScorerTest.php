<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ScaffoldDensityScorer;
use PHPUnit\Framework\TestCase;

final class ScaffoldDensityScorerTest extends TestCase
{
    private ScaffoldDensityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new ScaffoldDensityScorer();
    }

    public function testSchemaVersionIsCanonical(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('atlas.software_company_stewardship.scaffold_density.v1', $result['schema_version']);
        $this->assertSame(ScaffoldDensityScorer::SCHEMA_VERSION, $result['schema_version']);
    }

    /**
     * Assertion (1): all-comments-plus-one-docblock diff, zero real logic, no
     * banned marker -> filler_only===true, real_logic_line_count===0,
     * band==='hollow', scaffold_density===1.0, marker_hits===[].
     */
    public function testAllCommentaryWithDocblockIsFillerOnlyHollow(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Sample.php' => [
                '/**',
                ' * Computes the steward score for an area.',
                ' */',
                '// prepare the working set',
                '// nothing executable here, only narration',
                '# legacy shell-style note kept for context',
            ],
        ]);

        $this->assertTrue($result['filler_only']);
        $this->assertSame(0, $result['real_logic_line_count']);
        $this->assertSame('hollow', $result['band']);
        $this->assertSame(1.0, $result['scaffold_density']);
        $this->assertSame([], $result['marker_hits']);
        $this->assertSame(6, $result['commentary_line_count']);
        $this->assertSame(6, $result['total_added_lines']);
        $this->assertSame(1, $result['scanned_product_files']);
    }

    /**
     * Assertion (2), low side: 9 commentary + 11 behavioural -> round(9/20)=0.45
     * -> band==='clean'.
     */
    public function testNineCommentaryElevenBehaviouralScoresClean(): void
    {
        $lines = [];
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = '// commentary line number '.$i;
        }
        for ($i = 1; $i <= 11; $i++) {
            $lines[] = '$total = $total + $value['.$i.'];';
        }

        $result = $this->scorer->score([
            'app/Services/Ai/Mixed.php' => $lines,
        ]);

        $this->assertSame(0.45, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
        $this->assertSame(9, $result['commentary_line_count']);
        $this->assertSame(11, $result['real_logic_line_count']);
        $this->assertSame(20, $result['total_added_lines']);
        $this->assertFalse($result['filler_only']);
    }

    /**
     * Assertion (2), high side: 17 commentary + 3 behavioural -> 0.85 ->
     * band==='hollow' (>=0.85 boundary is inclusive).
     */
    public function testSeventeenCommentaryThreeBehaviouralHitsHollowBoundary(): void
    {
        $lines = [];
        for ($i = 1; $i <= 17; $i++) {
            $lines[] = '// narration line '.$i;
        }
        for ($i = 1; $i <= 3; $i++) {
            $lines[] = 'return $value * '.$i.';';
        }

        $result = $this->scorer->score([
            'app/Services/Ai/Thin.php' => $lines,
        ]);

        $this->assertSame(0.85, $result['scaffold_density']);
        $this->assertSame('hollow', $result['band']);
        $this->assertSame(17, $result['commentary_line_count']);
        $this->assertSame(3, $result['real_logic_line_count']);
        $this->assertSame(20, $result['total_added_lines']);
        $this->assertFalse($result['filler_only']);
    }

    /**
     * Just below the hollow threshold lands in the thin band (boundary check
     * between thin and hollow): 16 commentary + 4 behavioural -> 0.80.
     */
    public function testJustBelowHollowBoundaryIsThin(): void
    {
        $lines = [];
        for ($i = 1; $i <= 16; $i++) {
            $lines[] = '// narration line '.$i;
        }
        for ($i = 1; $i <= 4; $i++) {
            $lines[] = 'return $value * '.$i.';';
        }

        $result = $this->scorer->score([
            'app/Services/Ai/ThinBand.php' => $lines,
        ]);

        $this->assertSame(0.8, $result['scaffold_density']);
        $this->assertSame('thin', $result['band']);
        $this->assertSame(16, $result['commentary_line_count']);
        $this->assertSame(4, $result['real_logic_line_count']);
    }

    /**
     * Just below the thin threshold stays clean (boundary check between clean
     * and thin): 9 commentary + 12 behavioural -> round(9/21)=0.43.
     */
    public function testJustBelowThinBoundaryIsClean(): void
    {
        $lines = [];
        for ($i = 1; $i <= 9; $i++) {
            $lines[] = '// narration line '.$i;
        }
        for ($i = 1; $i <= 12; $i++) {
            $lines[] = '$acc = $acc + '.$i.';';
        }

        $result = $this->scorer->score([
            'app/Services/Ai/CleanBand.php' => $lines,
        ]);

        $this->assertSame(0.43, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
    }

    /**
     * Assertion (3): '// TODO: wire later' classifies as a marker (label 'todo'
     * in marker_hits); '// returns the clamped score' classifies as commentary
     * and is NOT a marker hit.
     */
    public function testTodoCommentIsMarkerButPlainCommentIsCommentary(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Marked.php' => [
                '// TODO: wire later',
                '// returns the clamped score',
                'return min(max($score, 0), 100);',
            ],
        ]);

        $this->assertSame(1, $result['marker_line_count']);
        $this->assertSame(1, $result['commentary_line_count']);
        $this->assertSame(1, $result['real_logic_line_count']);
        $this->assertCount(1, $result['marker_hits']);
        $this->assertSame('todo', $result['marker_hits'][0]['marker']);
        $this->assertSame('app/Services/Ai/Marked.php', $result['marker_hits'][0]['file']);
        $this->assertSame('// TODO: wire later', $result['marker_hits'][0]['line']);

        $hitLines = array_column($result['marker_hits'], 'line');
        $this->assertNotContains('// returns the clamped score', $hitLines);
    }

    /**
     * Assertion (4): added lines under a test path do not change
     * scanned_product_files, total_added_lines or scaffold_density.
     */
    public function testTestFileLinesAreExcludedFromScoring(): void
    {
        $productOnly = $this->scorer->score([
            'app/Services/Ai/Real.php' => [
                '$result = $this->compute($input);',
                'return $result;',
            ],
        ]);

        $withTestFile = $this->scorer->score([
            'app/Services/Ai/Real.php' => [
                '$result = $this->compute($input);',
                'return $result;',
            ],
            'tests/Unit/Ai/RealTest.php' => [
                '// TODO: this scaffold lives only in the test',
                '$mock = Mockery::mock(Real::class);',
                '$this->assertSame(1, $result);',
                '',
            ],
        ]);

        $this->assertSame(1, $productOnly['scanned_product_files']);
        $this->assertSame(1, $withTestFile['scanned_product_files']);
        $this->assertSame($productOnly['scanned_product_files'], $withTestFile['scanned_product_files']);
        $this->assertSame($productOnly['total_added_lines'], $withTestFile['total_added_lines']);
        $this->assertSame($productOnly['scaffold_density'], $withTestFile['scaffold_density']);
        $this->assertSame([], $withTestFile['marker_hits']);
    }

    /**
     * Assertion (5): an empty diff and a blank-only diff both yield
     * filler_only===false, scaffold_density===0.0, band==='clean'.
     */
    public function testEmptyDiffIsCleanAndNotFiller(): void
    {
        $result = $this->scorer->score([]);

        $this->assertFalse($result['filler_only']);
        $this->assertSame(0.0, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
        $this->assertSame(0, $result['total_added_lines']);
        $this->assertSame(0, $result['scanned_product_files']);
    }

    public function testBlankOnlyDiffIsCleanAndNotFiller(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Blank.php' => [
                '',
                '   ',
                "\t",
            ],
        ]);

        $this->assertFalse($result['filler_only']);
        $this->assertSame(0.0, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
        $this->assertSame(0, $result['total_added_lines']);
        $this->assertSame(0, $result['real_logic_line_count']);
        $this->assertSame(1, $result['scanned_product_files']);
    }

    /**
     * Markers spelled with a hyphen still classify, and every recognised marker
     * line is counted (generalisation beyond a single canned input).
     */
    public function testHyphenatedAndMixedMarkersAreDetected(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Markers.php' => [
                '$x = 1; // shape-only stub',
                'throw new RuntimeException("not-implemented");',
                '// step 2 of 5 will follow',
                '$builder = $this->getMockBuilder(Foo::class);',
                'return $x;',
            ],
        ]);

        $this->assertSame(4, $result['marker_line_count']);
        $this->assertSame(1, $result['real_logic_line_count']);
        $this->assertSame(0, $result['commentary_line_count']);
        $this->assertCount(4, $result['marker_hits']);

        $labels = array_column($result['marker_hits'], 'marker');
        $this->assertContains('shape_only', $labels);
        $this->assertContains('not_implemented', $labels);
        $this->assertContains('step_n_of_m', $labels);
        $this->assertContains('mock_in_product', $labels);
    }

    /**
     * Density is bounded to [0,1] even when blank lines vastly outnumber the
     * scored lines. Blank lines must be excluded from BOTH sides of the ratio:
     * with 2 behavioural + 2 commentary + 6 blank, the scored surface is
     * behavioural+commentary+marker=4 and the numerator is commentary+marker=2,
     * so the density is 2/4=0.5 (thin) — never above 1.0. This is the guard the
     * original suite lacked; counting blank in the numerator while excluding it
     * from the denominator would have produced 8/4=2.0, outside the spec bound.
     */
    public function testBlankLinesNeverPushDensityAboveOne(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/BlankHeavy.php' => [
                '$a = compute($x);',
                '$b = compute($y);',
                '// note one',
                '// note two',
                '',
                '',
                '',
                '',
                '   ',
                "\t",
            ],
        ]);

        $this->assertLessThanOrEqual(1.0, $result['scaffold_density']);
        $this->assertGreaterThanOrEqual(0.0, $result['scaffold_density']);
        $this->assertSame(0.5, $result['scaffold_density']);
        $this->assertSame('thin', $result['band']);
        $this->assertSame(2, $result['real_logic_line_count']);
        $this->assertSame(2, $result['commentary_line_count']);
        $this->assertSame(4, $result['total_added_lines']);
        $this->assertFalse($result['filler_only']);
    }

    /**
     * A purely behavioural file padded with trailing blank lines stays clean at
     * density 0.0: blank lines do not feign hollowness over real logic.
     */
    public function testBehaviouralWithTrailingBlanksStaysCleanZeroDensity(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Padded.php' => [
                '$sum = 0;',
                '$sum += $a;',
                '$sum += $b;',
                '',
                '',
            ],
        ]);

        $this->assertSame(0.0, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
        $this->assertSame(3, $result['real_logic_line_count']);
        $this->assertSame(3, $result['total_added_lines']);
        $this->assertFalse($result['filler_only']);
    }

    /**
     * Commentary-plus-blank with zero behavioural is filler at density exactly
     * 1.0 (not above): numerator commentary+marker equals the scored surface
     * when no behavioural line exists, and the blanks are ignored.
     */
    public function testCommentaryAndBlankNoBehaviouralIsFillerDensityOne(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/CommentBlank.php' => [
                '// alpha',
                '// beta',
                '// gamma',
                '',
                '',
                '',
                '',
            ],
        ]);

        $this->assertSame(1.0, $result['scaffold_density']);
        $this->assertSame('hollow', $result['band']);
        $this->assertTrue($result['filler_only']);
        $this->assertSame(0, $result['real_logic_line_count']);
        $this->assertSame(3, $result['commentary_line_count']);
        $this->assertSame(3, $result['total_added_lines']);
    }

    /**
     * Density and band move correctly across a wholly behavioural diff (clean,
     * not filler) — a generalising sanity check over real logic only.
     */
    public function testAllBehaviouralDiffIsCleanAndNotFiller(): void
    {
        $result = $this->scorer->score([
            'app/Services/Ai/Pure.php' => [
                '$sum = 0;',
                'foreach ($items as $item) {',
                '    $sum += $item;',
                '}',
                'return $sum;',
            ],
        ]);

        $this->assertSame(0.0, $result['scaffold_density']);
        $this->assertSame('clean', $result['band']);
        $this->assertSame(5, $result['real_logic_line_count']);
        $this->assertSame(5, $result['total_added_lines']);
        $this->assertFalse($result['filler_only']);
        $this->assertSame([], $result['marker_hits']);
    }
}
