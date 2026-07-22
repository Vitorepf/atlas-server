<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context\Retrieval;

use App\Services\Ai\Context\Retrieval\DomainLexicalNormalizer;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MAXB-10 — deterministic PT/EN domain lexical normalization.
 */
final class Maxb10DomainLexicalNormalizerTest extends TestCase
{
    #[Test]
    public function portuguese_memory_query_matches_english_memory_candidate(): void
    {
        $score = DomainLexicalNormalizer::score('memoria recall', ['memory recall policy']);

        $this->assertGreaterThan(0.0, $score);
        $this->assertSame(['memoria', 'memory', 'recall'], DomainLexicalNormalizer::tokens('memoria recall'));
    }

    #[Test]
    public function domain_equivalences_are_versioned_and_bounded(): void
    {
        $payload = DomainLexicalNormalizer::contract();

        $this->assertSame('atlas.memory.domain_lexical_normalizer.v1', $payload['schema_version']);
        $this->assertSame('maxb10.domain_equivalence.v1', $payload['formula_version']);
        $this->assertContains('cerebro', array_keys($payload['equivalences']));
        $this->assertLessThanOrEqual(32, $payload['max_expanded_tokens']);
    }

    #[Test]
    public function unknown_terms_stay_deterministic_without_synthetic_similarity(): void
    {
        $this->assertSame(['foobar'], DomainLexicalNormalizer::tokens('foobar'));
        $this->assertSame(0.0, DomainLexicalNormalizer::score('foobar', ['memory recall']));
    }
}
