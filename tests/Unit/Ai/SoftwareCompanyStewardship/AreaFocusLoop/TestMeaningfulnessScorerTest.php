<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TestMeaningfulnessScorer;
use PHPUnit\Framework\TestCase;

final class TestMeaningfulnessScorerTest extends TestCase
{
    private TestMeaningfulnessScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new TestMeaningfulnessScorer();
    }

    /**
     * @return array{path: string, added_lines: list<string>}
     */
    private function theaterFile(): array
    {
        return [
            'path' => 'tests/Unit/Ai/CoverageTheaterTest.php',
            'added_lines' => [
                '    public function testAlpha(): void',
                '    {',
                '        $this->assertTrue(true);',
                '    }',
                '    public function testBeta(): void',
                '    {',
                '        $this->expectNotToPerformAssertions();',
                '    }',
            ],
        ];
    }

    /**
     * @return array{path: string, added_lines: list<string>}
     */
    private function genuineFile(): array
    {
        return [
            'path' => 'tests/Unit/Ai/GenuineCoverageTest.php',
            'added_lines' => [
                '    public function testScoreComputesValue(): void',
                '    {',
                '        $this->assertSame(2, Scorer::score($input)[\'value\']);',
                '        $this->assertSame(\'high\', Scorer::score($other)[\'band\']);',
                '    }',
                '    public function testScoreComputesFloor(): void',
                '    {',
                '        $this->assertGreaterThan(0.5, Scorer::score($third)[\'floor\']);',
                '    }',
            ],
        ];
    }

    public function testTheaterFileIsFlaggedLowAndDegenerate(): void
    {
        $result = $this->scorer->score([$this->theaterFile()], []);

        $this->assertSame(TestMeaningfulnessScorer::SCHEMA_VERSION, $result['schema_version']);
        $this->assertTrue($result['theater']);
        $this->assertSame('low', $result['band']);
        $this->assertSame(2, $result['degenerate_assertion_count']);
        $this->assertSame(0, $result['real_assertion_count']);
        $this->assertSame(0, $result['production_referencing_assertion_count']);
        $this->assertSame(2, $result['test_method_count']);
        $this->assertLessThan(0.4, $result['meaningfulness_score']);
    }

    public function testGenuineFileIsHighAndNotTheater(): void
    {
        $result = $this->scorer->score([$this->genuineFile()], ['Scorer::score']);

        $this->assertSame(TestMeaningfulnessScorer::SCHEMA_VERSION, $result['schema_version']);
        $this->assertFalse($result['theater']);
        $this->assertSame('high', $result['band']);
        $this->assertSame(2, $result['test_method_count']);
        $this->assertSame(3, $result['real_assertion_count']);
        $this->assertSame(0, $result['degenerate_assertion_count']);
        $this->assertSame(3, $result['production_referencing_assertion_count']);
        $this->assertGreaterThanOrEqual(0.66, $result['meaningfulness_score']);
    }

    public function testGenuineFileScoreStrictlyExceedsTheaterFileScore(): void
    {
        $theater = $this->scorer->score([$this->theaterFile()], []);
        $genuine = $this->scorer->score([$this->genuineFile()], ['Scorer::score']);

        $this->assertGreaterThan($theater['meaningfulness_score'], $genuine['meaningfulness_score']);
    }

    public function testEqualRealAndDegenerateTipsTheaterTrue(): void
    {
        $boundaryFile = [
            'path' => 'tests/Unit/Ai/BoundaryTest.php',
            'added_lines' => [
                '    public function testBoundary(): void',
                '    {',
                '        $this->assertSame(1, Scorer::score($a));',
                '        $this->assertSame(2, Scorer::score($b));',
                '        $this->assertTrue(true);',
                '        $this->expectNotToPerformAssertions();',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$boundaryFile], ['Scorer::score']);

        $this->assertSame(2, $result['real_assertion_count']);
        $this->assertSame(2, $result['degenerate_assertion_count']);
        $this->assertSame(2, $result['production_referencing_assertion_count']);
        $this->assertTrue($result['theater']);
    }

    public function testEmptyBodyMethodCountsButAddsNoRealAssertionAndTipsTheater(): void
    {
        $edgeFile = [
            'path' => 'tests/Unit/Ai/EmptyBodyTest.php',
            'added_lines' => [
                '    public function testEmptyBody(): void',
                '    {',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$edgeFile], ['Scorer::score']);

        $this->assertSame(1, $result['test_method_count']);
        $this->assertSame(0, $result['real_assertion_count']);
        $this->assertSame(0, $result['degenerate_assertion_count']);
        $this->assertTrue($result['theater']);
    }

    public function testAssertSameWithIdenticalOperandsIsDegenerate(): void
    {
        $identicalFile = [
            'path' => 'tests/Unit/Ai/IdenticalTest.php',
            'added_lines' => [
                '    public function testIdenticalOperands(): void',
                '    {',
                '        $this->assertSame($value, $value);',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$identicalFile], []);

        $this->assertSame(1, $result['degenerate_assertion_count']);
        $this->assertSame(0, $result['real_assertion_count']);
    }

    public function testAssertSameWithDistinctOperandsIsRealNotDegenerate(): void
    {
        $distinctFile = [
            'path' => 'tests/Unit/Ai/DistinctTest.php',
            'added_lines' => [
                '    public function testDistinctOperands(): void',
                '    {',
                '        $this->assertSame($expected, $actual);',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$distinctFile], []);

        $this->assertSame(0, $result['degenerate_assertion_count']);
        $this->assertSame(1, $result['real_assertion_count']);
    }

    public function testAttributeStyleTestMethodIsCountedAndReferencesProduction(): void
    {
        $attributeFile = [
            'path' => 'tests/Unit/Ai/AttributeStyleTest.php',
            'added_lines' => [
                '    #[Test]',
                '    public function itComputesTheScore(): void',
                '    {',
                '        $this->assertSame(3, Scorer::score($payload));',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$attributeFile], ['Scorer::score']);

        $this->assertSame(1, $result['test_method_count']);
        $this->assertSame(1, $result['real_assertion_count']);
        $this->assertSame(1, $result['production_referencing_assertion_count']);
        $this->assertFalse($result['theater']);
    }

    public function testAssertionDensityIsRealAssertionsOverMethodCount(): void
    {
        $result = $this->scorer->score([$this->genuineFile()], ['Scorer::score']);

        // 3 real assertions across 2 test methods => 1.5.
        $this->assertEqualsWithDelta(1.5, $result['assertion_density'], 0.0001);
    }

    public function testAssertionDensityUsesOneWhenNoMethodsToAvoidDivisionByZero(): void
    {
        $result = $this->scorer->score([], []);

        $this->assertSame(0, $result['test_method_count']);
        $this->assertSame(0.0, $result['assertion_density']);
    }

    public function testTheaterFileSurfacesNoProductionReferenceReason(): void
    {
        $result = $this->scorer->score([$this->theaterFile()], []);

        $this->assertTrue(in_array('no_production_reference', $result['reasons'], true));
        $this->assertTrue(in_array('degenerate_outnumber_real', $result['reasons'], true));
    }

    public function testGenuineFileSurfacesMeaningfulAssertionsReason(): void
    {
        $result = $this->scorer->score([$this->genuineFile()], ['Scorer::score']);

        $this->assertTrue(in_array('meaningful_assertions_present', $result['reasons'], true));
        $this->assertFalse(in_array('no_production_reference', $result['reasons'], true));
    }

    public function testEmptyInputIsNotTheaterAndScoresZero(): void
    {
        $result = $this->scorer->score([], []);

        $this->assertFalse($result['theater']);
        $this->assertSame(0.0, $result['meaningfulness_score']);
        $this->assertSame('low', $result['band']);
        $this->assertTrue(in_array('no_test_methods', $result['reasons'], true));
    }

    public function testProductionSymbolMatchingIsCaseInsensitiveAndAcrossFiles(): void
    {
        $fileOne = [
            'path' => 'tests/Unit/Ai/FileOneTest.php',
            'added_lines' => [
                '    public function testOne(): void',
                '    {',
                '        $this->assertSame(1, scorer::SCORE($a));',
                '    }',
            ],
        ];
        $fileTwo = [
            'path' => 'tests/Unit/Ai/FileTwoTest.php',
            'added_lines' => [
                '    public function testTwo(): void',
                '    {',
                '        $this->assertSame(2, Scorer::score($b));',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$fileOne, $fileTwo], ['Scorer::score']);

        $this->assertSame(2, $result['test_method_count']);
        $this->assertSame(2, $result['real_assertion_count']);
        $this->assertSame(2, $result['production_referencing_assertion_count']);
        $this->assertFalse($result['theater']);
    }

    public function testMediumBandIsReachedBetweenThresholds(): void
    {
        // 3 real assertions (1 production-referencing) + 1 degenerate across 3
        // methods is not theater (real outnumber degenerate, production reference
        // present) yet the diluted production ratio lands the score strictly
        // between the medium (0.4) and high (0.66) thresholds.
        $mediumFile = [
            'path' => 'tests/Unit/Ai/MediumTest.php',
            'added_lines' => [
                '    public function testReferenced(): void',
                '    {',
                '        $this->assertSame(1, Scorer::score($a));',
                '    }',
                '    public function testPlainGreaterThan(): void',
                '    {',
                '        $this->assertGreaterThan(0, $plainValue);',
                '    }',
                '    public function testPlainNotNull(): void',
                '    {',
                '        $this->assertNotNull($thing);',
                '        $this->assertTrue(true);',
                '    }',
            ],
        ];

        $result = $this->scorer->score([$mediumFile], ['Scorer::score']);

        $this->assertFalse($result['theater']);
        $this->assertSame(3, $result['real_assertion_count']);
        $this->assertSame(1, $result['degenerate_assertion_count']);
        $this->assertSame(1, $result['production_referencing_assertion_count']);
        $this->assertSame('medium', $result['band']);
        $this->assertGreaterThanOrEqual(0.4, $result['meaningfulness_score']);
        $this->assertLessThan(0.66, $result['meaningfulness_score']);
    }
}
