<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\KeywordSuffixGate;
use PHPUnit\Framework\TestCase;

/**
 * Locks the #2 rule from the real-sales dissection: the last noun re-classifies the CVR regime 14-30×.
 * POSSESSION (diet/pen/supplement) promotes the buyer; INFORMATION (recipe/trick/treatment) demotes the
 * freebie-seeker — UNLESS the suffix is part of a coined owned-root (anti-champion). Provado: jello diet
 * 31.95% vs jello diet recipe 2.20%.
 */
class KeywordSuffixGateTest extends TestCase
{
    private KeywordSuffixGate $g;

    protected function setUp(): void
    {
        $this->g = new KeywordSuffixGate;
    }

    public function test_possession_suffix_promotes(): void
    {
        $this->assertSame('possession', $this->g->classify('jello diet')['regime']);
        $this->assertGreaterThan(1.0, $this->g->classify('orivelle fungus pen')['multiplier']);
        $this->assertGreaterThan(1.0, $this->g->classify('sanjay gupta brain health supplement')['multiplier']);
    }

    public function test_information_suffix_demotes(): void
    {
        $this->assertSame('information', $this->g->classify('jello diet recipe')['regime']);
        $this->assertLessThan(1.0, $this->g->classify('jello diet recipe')['multiplier']);
        $this->assertLessThan(1.0, $this->g->classify('dr oz jello recipe')['multiplier']);
    }

    public function test_anti_champion_owned_root_is_not_demoted(): void
    {
        // "gelatin trick" É o nome coined do mecanismo → 'trick' não rebaixa
        $this->assertSame('owned', $this->g->classify('gelatin trick', ['gelatin trick'])['regime']);
        $this->assertSame(1.0, $this->g->classify('gelatin trick', ['gelatin trick'])['multiplier']);

        // removido o sufixo, o resto é a raiz coined → protegido (bariatric gelatin recipe lifta na vida real)
        $this->assertSame('owned', $this->g->classify('gelatin trick recipe', ['gelatin trick'])['regime']);
    }

    public function test_generic_recipe_without_owned_root_is_still_demoted(): void
    {
        // "gelatin recipe" sem o root coined "gelatin trick" → caça-grátis, rebaixa
        $this->assertSame('information', $this->g->classify('gelatin recipe', ['gelatin trick'])['regime']);
    }

    public function test_neutral_suffix_is_unchanged(): void
    {
        $this->assertSame(1.0, $this->g->classify('memory loss')['multiplier']);
    }
}
