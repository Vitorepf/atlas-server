<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasDecideProviderKeyResolver;
use Tests\TestCase;

final class AtlasDecideProviderKeyResolverTest extends TestCase
{
    public function test_provider_aliases_and_models_are_canonical_and_unknown_keys_are_not_known(): void
    {
        $resolver = new AtlasDecideProviderKeyResolver;

        self::assertSame('claude_cli', $resolver->canonicalProviderKey('anthropic_claude'));
        self::assertSame('codex_cli', $resolver->canonicalProviderKey('openai_codex'));
        self::assertSame('minimax_m27_cli', $resolver->canonicalProviderKey('minimax-m3'));
        self::assertSame('MiniMax-M3', $resolver->canonicalModelForProvider('minimax_m27_cli', 'm3'));
        self::assertTrue($resolver->isKnownProviderKey('codex_cli'));
        self::assertFalse($resolver->isKnownProviderKey('provider-fork'));
    }

    public function test_cost_normalization_rejects_free_or_invalid_provider_costs(): void
    {
        $resolver = new AtlasDecideProviderKeyResolver;

        self::assertSame(1.5, $resolver->numericCost('1.5'));
        self::assertNull($resolver->numericCost(0));
        self::assertNull($resolver->numericCost('not-a-cost'));
    }
}
