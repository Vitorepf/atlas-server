<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos\Cores;

use App\Services\Ai\AgenticEngineeringOs\Scoring\SpecCompletenessScorer;
use PHPUnit\Framework\TestCase;

final class SpecCompletenessScorerTest extends TestCase
{
    private SpecCompletenessScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new SpecCompletenessScorer();
    }

    public function testSchemaVersionMatchesContract(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('atlas.aaeos.spec_completeness_score.v1', $result['schema_version']);
        $this->assertSame(12, $result['total_fields']);
    }

    public function testAllTwelveFieldsAdequateScoresOneHundredComplete(): void
    {
        $result = $this->scorer->score($this->fullSpec());

        $this->assertSame(100, $result['total_score']);
        $this->assertSame('complete', $result['verdict']);
        $this->assertSame(12, $result['present_count']);
        $this->assertSame([], $result['missing_or_weak']);

        foreach ($result['fields'] as $field) {
            $this->assertTrue($field['present']);
            $this->assertTrue($field['satisfied']);
            $this->assertSame('ok', $field['reason']);
            $this->assertSame((float) $field['weight'], $field['earned']);
        }
    }

    public function testEmptySpecIsInsufficientWithZeroScore(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame(0, $result['total_score']);
        $this->assertSame('insufficient', $result['verdict']);
        $this->assertSame(0, $result['present_count']);
        $this->assertCount(12, $result['missing_or_weak']);
    }

    public function testSpecEngineeredToSeventyNineIsPartial(): void
    {
        $result = $this->scorer->score($this->specScoringSeventyNine());

        $this->assertSame(79, $result['total_score']);
        $this->assertSame('partial', $result['verdict']);
    }

    public function testNudgingSeventyNineToExactlyEightyFlipsToComplete(): void
    {
        $spec = $this->specScoringSeventyNine();

        // Single-field nudge: satisfy blocking_questions (weight 1) -> 79 + 1 == 80.
        $spec['blocking_questions'] = ['Which environment is the target deploy?'];

        $result = $this->scorer->score($spec);

        $this->assertSame(80, $result['total_score']);
        $this->assertSame('complete', $result['verdict']);
    }

    public function testHeavierMissingFieldRanksFirstInGapList(): void
    {
        $spec = $this->fullSpec();
        unset($spec['acceptance_criteria']); // weight 14
        unset($spec['non_goals']);           // weight 5

        $result = $this->scorer->score($spec);

        $this->assertCount(2, $result['missing_or_weak']);
        $this->assertSame('acceptance_criteria', $result['missing_or_weak'][0]['field']);
        $this->assertSame(14, $result['missing_or_weak'][0]['weight']);
        $this->assertSame(14.0, $result['missing_or_weak'][0]['weight_loss']);
        $this->assertSame('non_goals', $result['missing_or_weak'][1]['field']);
        $this->assertSame(5, $result['missing_or_weak'][1]['weight']);
        $this->assertSame(5.0, $result['missing_or_weak'][1]['weight_loss']);
    }

    public function testTextFieldBelowMinLengthIsTooShortButPresent(): void
    {
        $spec = $this->fullSpec();
        $spec['product_area'] = 'ui'; // below TEXT_MIN_LENGTH (8)

        $result = $this->scorer->score($spec);

        $this->assertTrue($result['fields']['product_area']['present']);
        $this->assertFalse($result['fields']['product_area']['satisfied']);
        $this->assertSame('too_short', $result['fields']['product_area']['reason']);
        $this->assertSame(0.0, $result['fields']['product_area']['earned']);
    }

    public function testEmptyListFieldReportsEmptyListReason(): void
    {
        $spec = $this->fullSpec();
        $spec['requirements'] = [];

        $result = $this->scorer->score($spec);

        $this->assertFalse($result['fields']['requirements']['present']);
        $this->assertFalse($result['fields']['requirements']['satisfied']);
        $this->assertSame('empty_list', $result['fields']['requirements']['reason']);
    }

    public function testPresentCountCountsPresentEvenWhenNotSatisfied(): void
    {
        $spec = $this->fullSpec();
        // Present but too short -> counts as present, not satisfied.
        $spec['interpreted_goal'] = 'go';
        // Empty list -> not present at all.
        $spec['assumptions'] = [];

        $result = $this->scorer->score($spec);

        $this->assertTrue($result['fields']['interpreted_goal']['present']);
        $this->assertFalse($result['fields']['interpreted_goal']['satisfied']);
        $this->assertFalse($result['fields']['assumptions']['present']);
        // 12 fields, one text still present-but-weak, one list dropped -> 11 present.
        $this->assertSame(11, $result['present_count']);
    }

    public function testWhitespaceOnlyTextIsAbsent(): void
    {
        $spec = $this->fullSpec();
        $spec['security_constraints'] = '   ';

        $result = $this->scorer->score($spec);

        $this->assertFalse($result['fields']['security_constraints']['present']);
        $this->assertFalse($result['fields']['security_constraints']['satisfied']);
        $this->assertSame('absent', $result['fields']['security_constraints']['reason']);
    }

    public function testListWithOnlyBlankItemsIsEmptyList(): void
    {
        $spec = $this->fullSpec();
        $spec['non_goals'] = ['', '   '];

        $result = $this->scorer->score($spec);

        $this->assertFalse($result['fields']['non_goals']['present']);
        $this->assertSame('empty_list', $result['fields']['non_goals']['reason']);
    }

    /**
     * Every canonical field satisfied; total_score == 100, verdict complete.
     *
     * @return array<string,mixed>
     */
    private function fullSpec(): array
    {
        return [
            'raw_request' => 'Add a save button to the profile edit form.',
            'interpreted_goal' => 'Persist the active profile form on click.',
            'non_goals' => ['Do not change the navigation bar.'],
            'product_area' => 'profile-settings',
            'business_actor_object_action' => 'operator saves profile form',
            'requirements' => ['Button persists form', 'Show success toast'],
            'acceptance_criteria' => ['Saved profile is reloaded on refresh.'],
            'design_system_constraints' => 'Use the primary token button.',
            'security_constraints' => 'Validate operator session before save.',
            'assumptions' => ['Form maps to the active profile.'],
            'blocking_questions' => ['Which profile is active by default?'],
            'test_strategy' => 'Feature test for the save endpoint.',
        ];
    }

    /**
     * Engineered to score exactly 79 (partial).
     *
     * Drops interpreted_goal (12) + security_constraints (8) + blocking_questions (1)
     * = 21 lost out of 100 -> 79.
     *
     * @return array<string,mixed>
     */
    private function specScoringSeventyNine(): array
    {
        $spec = $this->fullSpec();
        unset($spec['interpreted_goal']);     // weight 12
        unset($spec['security_constraints']); // weight 8
        unset($spec['blocking_questions']);   // weight 1

        return $spec;
    }
}
