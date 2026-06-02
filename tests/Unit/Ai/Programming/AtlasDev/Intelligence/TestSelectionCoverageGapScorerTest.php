<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\TestSelectionCoverageGapScorer;
use PHPUnit\Framework\TestCase;

final class TestSelectionCoverageGapScorerTest extends TestCase
{
    private TestSelectionCoverageGapScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new TestSelectionCoverageGapScorer;
    }

    public function test_schema_version_is_pinned(): void
    {
        $result = $this->scorer->score(['app/A.php'], ['app/A.php'], 'R1');

        $this->assertSame('atlas.programming.test_coverage_gap.v1', $result['schema_version']);
        $this->assertSame('atlas.programming.test_coverage_gap.v1', TestSelectionCoverageGapScorer::SCHEMA_VERSION);
    }

    public function test_partial_coverage_yields_partial_band_and_medium_ceiling(): void
    {
        $result = $this->scorer->score(['app/A.php', 'app/B.php'], ['app/A.php'], 'R1');

        $this->assertSame(0.5, $result['coverage_ratio']);
        $this->assertSame('partial', $result['gap_band']);
        $this->assertSame(['app/B.php'], $result['uncovered_production_files']);
        $this->assertSame('medium', $result['confidence_ceiling']);
        $this->assertSame(2, $result['production_file_count']);
        $this->assertSame(1, $result['covered_count']);
    }

    public function test_zero_coverage_yields_severe_band_and_low_ceiling(): void
    {
        $result = $this->scorer->score(['app/A.php'], [], 'R1');

        $this->assertSame(0.0, $result['coverage_ratio']);
        $this->assertSame('severe', $result['gap_band']);
        $this->assertSame('low', $result['confidence_ceiling']);
        $this->assertSame(['app/A.php'], $result['uncovered_production_files']);
        $this->assertSame(1, $result['production_file_count']);
        $this->assertSame(0, $result['covered_count']);
    }

    public function test_full_coverage_low_risk_yields_none_band_and_high_ceiling(): void
    {
        $result = $this->scorer->score(['app/A.php'], ['app/A.php'], 'R2');

        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame('none', $result['gap_band']);
        $this->assertSame('high', $result['confidence_ceiling']);
        $this->assertSame([], $result['uncovered_production_files']);
        $this->assertSame(1, $result['covered_count']);
    }

    public function test_full_coverage_elevated_risk_keeps_none_band_but_caps_ceiling_at_medium(): void
    {
        $result = $this->scorer->score(['app/A.php'], ['app/A.php'], 'R5');

        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame('none', $result['gap_band']);
        $this->assertSame('medium', $result['confidence_ceiling']);
    }

    public function test_only_test_files_changed_count_as_zero_production_and_full_ratio(): void
    {
        $result = $this->scorer->score(['tests/FooTest.php'], [], 'R1');

        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame('none', $result['gap_band']);
        $this->assertSame([], $result['uncovered_production_files']);
        $this->assertSame(0, $result['production_file_count']);
        $this->assertSame(0, $result['covered_count']);
    }

    public function test_uncovered_production_files_are_sorted_ascending(): void
    {
        $result = $this->scorer->score(
            ['app/Zebra.php', 'app/Alpha.php', 'app/Mango.php'],
            [],
            'R1'
        );

        $this->assertSame(['app/Alpha.php', 'app/Mango.php', 'app/Zebra.php'], $result['uncovered_production_files']);
    }

    public function test_test_files_are_excluded_from_production_counts_and_coverage(): void
    {
        $result = $this->scorer->score(
            ['app/A.php', 'app/B.php', 'tests/Unit/SomethingTest.php'],
            ['app/A.php'],
            'R1'
        );

        $this->assertSame(2, $result['production_file_count']);
        $this->assertSame(1, $result['covered_count']);
        $this->assertSame(0.5, $result['coverage_ratio']);
        $this->assertSame('partial', $result['gap_band']);
        $this->assertSame(['app/B.php'], $result['uncovered_production_files']);
    }

    public function test_coverage_ratio_is_rounded_to_two_decimals(): void
    {
        $result = $this->scorer->score(
            ['app/A.php', 'app/B.php', 'app/C.php'],
            ['app/A.php'],
            'R1'
        );

        // 1/3 = 0.3333... -> round(…, 2) === 0.33
        $this->assertSame(0.33, $result['coverage_ratio']);
        $this->assertSame('partial', $result['gap_band']);
        $this->assertSame(1, $result['covered_count']);
        $this->assertSame(['app/B.php', 'app/C.php'], $result['uncovered_production_files']);
    }

    public function test_coverage_ratio_never_exceeds_one_when_covered_superset_of_changed(): void
    {
        $result = $this->scorer->score(
            ['app/A.php'],
            ['app/A.php', 'app/B.php', 'app/C.php'],
            'R1'
        );

        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame('none', $result['gap_band']);
        $this->assertSame(1, $result['production_file_count']);
        $this->assertSame(1, $result['covered_count']);
        $this->assertSame([], $result['uncovered_production_files']);
    }

    public function test_numeric_string_paths_stay_strings_in_uncovered_list(): void
    {
        // A pure-numeric path used as an array key would be coerced to int by PHP
        // and array_keys() would return an int, breaking the list<string> contract.
        $result = $this->scorer->score(['123', 'app/A.php'], [], 'R1');

        $this->assertSame(['123', 'app/A.php'], $result['uncovered_production_files']);
        $this->assertSame([0, 1], array_keys($result['uncovered_production_files']));
        foreach ($result['uncovered_production_files'] as $path) {
            $this->assertIsString($path);
        }
        $this->assertSame(2, $result['production_file_count']);
        $this->assertSame('severe', $result['gap_band']);
    }

    public function test_high_risk_string_caps_full_coverage_ceiling_at_medium(): void
    {
        $result = $this->scorer->score(['app/A.php'], ['app/A.php'], 'high');

        $this->assertSame('none', $result['gap_band']);
        $this->assertSame('medium', $result['confidence_ceiling']);
    }

    public function test_critical_risk_string_caps_full_coverage_ceiling_at_medium(): void
    {
        $result = $this->scorer->score(['app/A.php'], ['app/A.php'], 'critical');

        $this->assertSame('none', $result['gap_band']);
        $this->assertSame('medium', $result['confidence_ceiling']);
    }

    public function test_near_full_coverage_rounding_to_one_still_bands_partial_not_none(): void
    {
        // 199/200 = 0.995 -> round(…, 2) === 1.0. Banding off the rounded ratio
        // would mislabel this 'none' with a high ceiling while one production file
        // is provably uncovered. The band must follow the exact covered counts.
        $changed = [];
        for ($i = 0; $i < 200; $i++) {
            $changed[] = "app/f{$i}.php";
        }
        $covered = array_slice($changed, 0, 199);

        $result = $this->scorer->score($changed, $covered, 'R1');

        $this->assertSame(1.0, $result['coverage_ratio']);
        $this->assertSame(200, $result['production_file_count']);
        $this->assertSame(199, $result['covered_count']);
        // The gap band must not claim 'none' while an uncovered file remains.
        $this->assertSame('partial', $result['gap_band']);
        $this->assertSame('medium', $result['confidence_ceiling']);
        $this->assertSame(['app/f199.php'], $result['uncovered_production_files']);
        // Hard invariant: an empty uncovered list iff the band is 'none'.
        $this->assertNotSame([], $result['uncovered_production_files']);
    }

    public function test_near_zero_coverage_rounding_to_zero_still_bands_partial_not_severe(): void
    {
        // 1/300 -> round(…, 2) === 0.0. Banding off the rounded ratio would mislabel
        // this 'severe'/'low' even though one production file IS covered. The band
        // must follow the exact covered counts, so a covered file forbids 'severe'.
        $changed = [];
        for ($i = 0; $i < 300; $i++) {
            $changed[] = "app/g{$i}.php";
        }

        $result = $this->scorer->score($changed, ['app/g0.php'], 'R1');

        $this->assertSame(0.0, $result['coverage_ratio']);
        $this->assertSame(300, $result['production_file_count']);
        $this->assertSame(1, $result['covered_count']);
        // A covered production file means the gap is not 'severe'.
        $this->assertSame('partial', $result['gap_band']);
        $this->assertSame('medium', $result['confidence_ceiling']);
    }
}
