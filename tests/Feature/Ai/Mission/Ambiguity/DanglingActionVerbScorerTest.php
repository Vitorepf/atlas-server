<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Mission\Ambiguity;

use App\Services\Ai\Mission\Ambiguity\DanglingActionVerbScorer;
use PHPUnit\Framework\TestCase;

final class DanglingActionVerbScorerTest extends TestCase
{
    private DanglingActionVerbScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new DanglingActionVerbScorer();
    }

    public function test_score_is_always_within_unit_range(): void
    {
        $prompts = [
            'crie',
            'analise isso',
            'crie a automacao de instagram',
            'o sistema esta lento',
            'quero que voce crie',
            'faca',
            'faca algo',
            'faca o relatorio mensal',
        ];

        foreach ($prompts as $prompt) {
            $score = $this->scorer->danglingScore($prompt);

            $this->assertGreaterThanOrEqual(0.0, $score, $prompt);
            $this->assertLessThanOrEqual(1.0, $score, $prompt);
        }
    }

    public function test_rule_one_bare_verb_only_scores_exactly_one(): void
    {
        $this->assertSame(1.0, $this->scorer->danglingScore('crie'));
    }

    public function test_rule_two_verb_plus_single_filler_is_high_but_below_bare_verb(): void
    {
        $bareScore = $this->scorer->danglingScore('crie');
        $singleFillerScore = $this->scorer->danglingScore('analise isso');

        $this->assertSame(0.5, $singleFillerScore);
        $this->assertGreaterThan(0.0, $singleFillerScore);
        $this->assertLessThan($bareScore, $singleFillerScore);
    }

    public function test_rule_three_verb_with_concrete_object_phrase_scores_zero(): void
    {
        $this->assertSame(0.0, $this->scorer->danglingScore('crie a automacao de instagram'));
    }

    public function test_rule_four_strict_ordering_by_trailing_content_tokens(): void
    {
        $zeroTrailing = $this->scorer->danglingScore('faca');
        $oneTrailing = $this->scorer->danglingScore('faca algo');
        $twoOrMoreTrailing = $this->scorer->danglingScore('faca o relatorio mensal');

        $this->assertSame(1.0, $zeroTrailing);
        $this->assertSame(0.5, $oneTrailing);
        $this->assertSame(0.0, $twoOrMoreTrailing);

        $this->assertGreaterThan($oneTrailing, $zeroTrailing);
        $this->assertGreaterThan($twoOrMoreTrailing, $oneTrailing);
    }

    public function test_rule_five_non_leading_verb_prompts_score_zero(): void
    {
        // Prompt that does not start with an action verb, regardless of length.
        $this->assertSame(0.0, $this->scorer->danglingScore('o sistema esta lento'));

        // Action verb appears mid-sentence only -> not a leading imperative.
        $this->assertSame(0.0, $this->scorer->danglingScore('quero que voce crie'));
    }

    public function test_generalises_to_inputs_not_in_the_acceptance_examples(): void
    {
        // English bare verb -> 1.0
        $this->assertSame(1.0, $this->scorer->danglingScore('build'));

        // English verb + single trailing token -> 0.5
        $this->assertSame(0.5, $this->scorer->danglingScore('improve performance'));

        // PT verb + many trailing tokens -> 0.0
        $this->assertSame(0.0, $this->scorer->danglingScore('corrija o bug do checkout agora'));

        // Mid-sentence English verb -> 0.0
        $this->assertSame(0.0, $this->scorer->danglingScore('please fix the login bug'));

        // Empty / whitespace-only prompt -> 0.0
        $this->assertSame(0.0, $this->scorer->danglingScore(''));
        $this->assertSame(0.0, $this->scorer->danglingScore('   '));

        // Trailing punctuation must not inflate the trailing-token count.
        $this->assertSame(1.0, $this->scorer->danglingScore('otimize!'));
        $this->assertSame(0.5, $this->scorer->danglingScore('melhore   tudo'));
    }
}
