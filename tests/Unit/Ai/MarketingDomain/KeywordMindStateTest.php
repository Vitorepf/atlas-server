<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\IntentLadderClassifier;
use App\Services\Ai\MarketingDomain\Campaign\KeywordMindState;
use PHPUnit\Framework\TestCase;

/**
 * Locks the keyword→MENTE projection: a keyword's intent tier/pain/polarity maps to the searcher's
 * Schwartz awareness, dominant emotional driver, and the page angle that fisga that mind. Niche-agnostic
 * by construction (reads only the structural intent, not the content) — the first link of the prompt's
 * central lever keyword→mente→página→venda.
 */
class KeywordMindStateTest extends TestCase
{
    private KeywordMindState $m;

    private IntentLadderClassifier $c;

    protected function setUp(): void
    {
        $this->m = new KeywordMindState;
        $this->c = new IntentLadderClassifier;
    }

    public function test_most_aware_keyword_gets_direct_offer_angle(): void
    {
        $r = $this->m->project(['tier' => 'T4', 'pain' => 0.0, 'polarity' => 'neutral']);
        $this->assertSame('most_aware', $r['awareness']);
        $this->assertSame('direct_offer_reminder', $r['page_angle']);
        $this->assertSame('hot', $r['heat']);
    }

    public function test_informational_is_unaware_and_cold(): void
    {
        $r = $this->m->project(['tier' => 'T0', 'pain' => 0.0, 'polarity' => 'neutral']);
        $this->assertSame('unaware', $r['awareness']);
        $this->assertSame('curiosity_hook_educate', $r['page_angle']);
        $this->assertSame('cold', $r['heat']);
    }

    public function test_pain_drives_urgency_relief(): void
    {
        $r = $this->m->project(['tier' => 'T3', 'pain' => 0.7, 'polarity' => 'neutral']);
        $this->assertSame('product_aware', $r['awareness']);
        $this->assertSame('urgency_relief', $r['emotional_driver']);
        $this->assertSame('hot', $r['heat']);
    }

    public function test_negative_polarity_is_distrust_and_cold(): void
    {
        $r = $this->m->project(['tier' => 'T4', 'pain' => 0.0, 'polarity' => 'negative']);
        $this->assertSame('distrust', $r['emotional_driver']);
        $this->assertSame('cold', $r['heat']);
    }

    public function test_buyer_trust_check_is_desire_confirmation(): void
    {
        $r = $this->m->project(['tier' => 'T4', 'pain' => 0.0, 'polarity' => 'positive']);
        $this->assertSame('desire_confirmation', $r['emotional_driver']);
    }

    public function test_problem_aware_gets_agitate_then_reveal(): void
    {
        $r = $this->m->project(['tier' => 'T1', 'pain' => 0.0, 'polarity' => 'neutral']);
        $this->assertSame('problem_aware', $r['awareness']);
        $this->assertSame('agitate_then_reveal', $r['page_angle']);
    }

    public function test_end_to_end_from_a_real_keyword_cross_niche(): void
    {
        // health, finance, relationship — same structure → coherent mind-state, no niche lexicon.
        foreach (['como acabar com disfunção erétil rápido', 'como sair das dívidas rápido', 'como reconquistar meu ex rápido'] as $kw) {
            $intent = $this->c->classify($kw);
            $mind = $this->m->project($intent);
            $this->assertSame('product_aware', $mind['awareness'], $kw); // T3 + urgency
            $this->assertSame('hot', $mind['heat'], $kw);
            foreach (['awareness', 'emotional_driver', 'page_angle', 'heat', 'note'] as $k) {
                $this->assertArrayHasKey($k, $mind);
            }
        }
    }
}
