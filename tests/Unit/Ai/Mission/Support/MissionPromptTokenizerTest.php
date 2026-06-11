<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Mission\Support;

use App\Services\Ai\Mission\Support\MissionPromptTokenizer;
use PHPUnit\Framework\TestCase;

final class MissionPromptTokenizerTest extends TestCase
{
    public function test_prompt_words_preserves_ambiguity_scorer_contract(): void
    {
        $this->assertSame(
            ['melhore', 'tudo', 'agora'],
            MissionPromptTokenizer::promptWords("  Melhore   tudo, agora!  "),
        );
    }

    public function test_prompt_words_returns_empty_for_blank_prompt(): void
    {
        $this->assertSame([], MissionPromptTokenizer::promptWords(" \t\n "));
    }

    public function test_whitespace_tokens_preserves_case_and_punctuation(): void
    {
        $this->assertSame(
            ['Deploy...', 'agora!'],
            MissionPromptTokenizer::whitespaceTokens("  Deploy... \t agora!  "),
        );
    }

    public function test_semantic_words_split_on_punctuation_and_keep_unicode_letters_and_numbers(): void
    {
        $this->assertSame(
            ['criar', 'api', 'v2', 'integração'],
            MissionPromptTokenizer::semanticWords('Criar API-v2: integração!'),
        );
    }
}
