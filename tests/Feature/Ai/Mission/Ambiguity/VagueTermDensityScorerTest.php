<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Mission\Ambiguity;

use App\Services\Ai\Mission\Ambiguity\VagueTermDensityScorer;
use PHPUnit\Framework\TestCase;

final class VagueTermDensityScorerTest extends TestCase
{
    private VagueTermDensityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new VagueTermDensityScorer();
    }

    public function test_density_is_always_within_unit_range(): void
    {
        $prompts = [
            '',
            '   ',
            'melhore alguns',
            'alguns varios melhor otimo etc',
            'melhor melhor melhor',
            'deploy the billing service to prod',
            'maybe some various stuff something',
        ];

        foreach ($prompts as $prompt) {
            $score = $this->scorer->density($prompt);

            $this->assertGreaterThanOrEqual(0.0, $score, $prompt);
            $this->assertLessThanOrEqual(1.0, $score, $prompt);
        }
    }

    public function test_rule_one_empty_or_whitespace_prompt_is_exactly_zero(): void
    {
        $this->assertSame(0.0, $this->scorer->density(''));
        $this->assertSame(0.0, $this->scorer->density('   '));
    }

    public function test_rule_two_single_vague_term_is_one_quarter_and_between_zero_and_two_term(): void
    {
        $oneTerm = $this->scorer->density('melhore alguns');

        // 1 distinct vague term ('alguns'; 'melhore' is NOT 'melhor') / cap 4 = 0.25.
        $this->assertSame(0.25, $oneTerm);

        $twoTerm = $this->scorer->density('alguns varios concretos');

        $this->assertGreaterThan(0.0, $oneTerm);
        $this->assertLessThan($twoTerm, $oneTerm);
    }

    public function test_rule_three_four_or_more_distinct_terms_clamp_to_exactly_one(): void
    {
        $score = $this->scorer->density('alguns varios melhor otimo etc');

        // 5 distinct vague terms, saturation cap 4 => clamped to exactly 1.0, not >1.
        $this->assertSame(1.0, $score);
        $this->assertLessThanOrEqual(1.0, $score);
    }

    public function test_rule_four_strict_ordering_one_then_two_then_three_terms(): void
    {
        $oneTerm = $this->scorer->density('melhore alguns');
        $twoTerm = $this->scorer->density('alguns varios concretos');
        $threeTerm = $this->scorer->density('alguns varios melhor agora');

        $this->assertSame(0.25, $oneTerm);
        $this->assertSame(0.5, $twoTerm);
        $this->assertSame(0.75, $threeTerm);

        $this->assertGreaterThan($oneTerm, $twoTerm);
        $this->assertLessThan($threeTerm, $twoTerm);
    }

    public function test_rule_five_repeated_term_counts_once_and_concrete_prompt_is_zero(): void
    {
        // Same vague term repeated 3x counts as ONE distinct match => 0.25.
        $this->assertSame(0.25, $this->scorer->density('melhor melhor melhor'));

        // Fully concrete prompt with zero vague terms => 0.0.
        $this->assertSame(0.0, $this->scorer->density('deploy the billing service to prod'));
    }

    public function test_generalises_to_inputs_not_in_the_acceptance_examples(): void
    {
        // English vague terms, three distinct => 0.75.
        $this->assertSame(0.75, $this->scorer->density('do something better maybe'));
        $this->assertSame(0.75, $this->scorer->density('various several some'));

        // Distinct-not-raw: one EN term repeated four times still counts once => 0.25.
        $this->assertSame(0.25, $this->scorer->density('stuff stuff stuff stuff'));

        // Five distinct EN vague terms, saturated and clamped => 1.0.
        $this->assertSame(1.0, $this->scorer->density('maybe some various stuff something'));

        // Concrete EN prompt with no vague terms => 0.0.
        $this->assertSame(0.0, $this->scorer->density('restart the postgres container'));

        // Trailing punctuation must not break the whole-word match.
        $this->assertSame(0.25, $this->scorer->density('alguns!'));

        // Two distinct terms, regardless of language mix => 0.5.
        $this->assertSame(0.5, $this->scorer->density('some coisas here'));
    }
}
