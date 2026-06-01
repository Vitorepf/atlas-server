<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Mission\Ambiguity;

use App\Services\Ai\Mission\Ambiguity\PromptStructuralSparsityScorer;
use PHPUnit\Framework\TestCase;

final class PromptStructuralSparsityScorerTest extends TestCase
{
    public function test_rich_prompt_fires_no_penalty(): void
    {
        $score = (new PromptStructuralSparsityScorer())
            ->sparsity('implemente o modulo de cobranca completo com testes');

        $this->assertSame(0.0, $score);
    }

    public function test_single_word_command_dominates_short(): void
    {
        $score = (new PromptStructuralSparsityScorer())->sparsity('deploy');

        $this->assertSame(0.6, $score);
    }

    public function test_trailing_ellipsis_at_moderate_token_count(): void
    {
        $score = (new PromptStructuralSparsityScorer())
            ->sparsity('preciso que voce faca aquilo...');

        $this->assertSame(0.5, $score);
    }

    public function test_max_not_sum_when_single_word_with_trailing_ellipsis(): void
    {
        $score = (new PromptStructuralSparsityScorer())->sparsity('deploy...');

        $this->assertSame(0.6, $score);
        $this->assertNotSame(1.1, $score);
    }

    public function test_boundary_three_tokens_is_short(): void
    {
        $score = (new PromptStructuralSparsityScorer())->sparsity('faca o deploy');

        $this->assertSame(0.3, $score);
    }

    public function test_boundary_four_tokens_fires_no_penalty(): void
    {
        $score = (new PromptStructuralSparsityScorer())->sparsity('faca o deploy agora');

        $this->assertSame(0.0, $score);
    }

    public function test_ordering_single_word_above_ellipsis_above_short_above_rich(): void
    {
        $scorer = new PromptStructuralSparsityScorer();

        $singleWord = $scorer->sparsity('deploy');
        $ellipsis = $scorer->sparsity('preciso que voce faca aquilo...');
        $short = $scorer->sparsity('faca o deploy');
        $rich = $scorer->sparsity('implemente o modulo de cobranca completo com testes');

        $this->assertSame(0.6, $singleWord);
        $this->assertSame(0.5, $ellipsis);
        $this->assertSame(0.3, $short);
        $this->assertSame(0.0, $rich);

        $this->assertGreaterThan($ellipsis, $singleWord);
        $this->assertGreaterThan($short, $ellipsis);
        $this->assertGreaterThan($rich, $short);
    }

    public function test_unicode_ellipsis_at_end_fires_ellipsis_penalty(): void
    {
        $score = (new PromptStructuralSparsityScorer())
            ->sparsity("preciso que voce faca aquilo\u{2026}");

        $this->assertSame(0.5, $score);
    }

    public function test_score_is_always_within_unit_interval(): void
    {
        $scorer = new PromptStructuralSparsityScorer();

        foreach (['deploy', 'deploy...', 'faca o deploy', 'faca o deploy agora', '', 'implemente o modulo de cobranca completo com testes'] as $prompt) {
            $score = $scorer->sparsity($prompt);

            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
        }
    }
}
