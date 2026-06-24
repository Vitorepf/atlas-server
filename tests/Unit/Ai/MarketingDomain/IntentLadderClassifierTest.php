<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Campaign\IntentLadderClassifier;
use PHPUnit\Framework\TestCase;

/**
 * Locks the compositional intent ladder: journey × pain × specificity + negative-polarity, PT-BR + EN,
 * graded to the report's tier table (T0~10 .. T4~92). Proves the two verified bug-fixes (funciona=buyer
 * not scam; "how to [action]" is not informational) and cross-vertical generalization (health / finance /
 * relationship) from STRUCTURE, not a health lexicon.
 */
class IntentLadderClassifierTest extends TestCase
{
    private IntentLadderClassifier $c;

    protected function setUp(): void
    {
        $this->c = new IntentLadderClassifier;
    }

    public function test_informational_is_T0_and_excluded(): void
    {
        $r = $this->c->classify('o que é disfunção erétil');
        $this->assertSame('T0', $r['tier']);
        $this->assertSame('exclude', $r['action']);
        $this->assertLessThan(20, $r['intent_score']);

        $en = $this->c->classify('what is erectile dysfunction');
        $this->assertSame('T0', $en['tier']);

        // "como funciona X" is informational, NOT solution-seeking.
        $this->assertSame('T0', $this->c->classify('como funciona a dieta cetogênica')['tier']);
    }

    public function test_solution_keyword_lands_in_buy_or_test_even_without_urgency(): void
    {
        // The operator's own example of HIGH intent — must not be undervalued.
        $r = $this->c->classify('tratamento para disfunção erétil');
        $this->assertSame('T2', $r['tier']);
        $this->assertGreaterThanOrEqual(50, $r['intent_score']);
        $this->assertNotSame('exclude', $r['action']);
    }

    public function test_treatment_plus_urgency_is_T3_buy(): void
    {
        $r = $this->c->classify('como acabar com disfunção erétil rápido');
        $this->assertSame('T3', $r['tier']);
        $this->assertGreaterThanOrEqual(70, $r['intent_score']);
        $this->assertSame('buy', $r['action']);
    }

    public function test_pain_pushes_intent_up(): void
    {
        $calm = $this->c->classify('como emagrecer');
        $desperate = $this->c->classify('como emagrecer rápido de vez');
        $this->assertGreaterThan($calm['intent_score'], $desperate['intent_score']);
        $this->assertGreaterThan(0, $desperate['pain']);
        $this->assertSame(0.0, $calm['pain']);
    }

    public function test_funciona_is_a_buyer_trust_check_not_a_complaint(): void
    {
        // The headline fix: legacy KeywordIntentMapper flagged "funciona" as scam_complaint.
        $buyer = $this->c->classify('lipo bliss funciona');
        $this->assertSame('positive', $buyer['polarity']);
        $this->assertGreaterThanOrEqual(70, $buyer['intent_score']);

        $complaint = $this->c->classify('lipo bliss scam');
        $this->assertSame('negative', $complaint['polarity']);
    }

    public function test_negative_polarity_is_near_excluded(): void
    {
        foreach (['lipo bliss cancelar', 'lipo bliss reembolso', 'ozempic side effects', 'produto golpe'] as $kw) {
            $r = $this->c->classify($kw);
            $this->assertSame('negative', $r['polarity'], $kw);
            $this->assertLessThanOrEqual(12, $r['intent_score'], $kw);
            $this->assertSame('exclude', $r['action'], $kw);
        }
    }

    public function test_mechanism_via_lexicon_is_T4(): void
    {
        $r = $this->c->classify('blue salt trick', ['mechanism_lexicon' => ['blue salt trick']]);
        $this->assertSame('T4', $r['tier']);
        $this->assertSame(1.0, $r['journey']);
        $this->assertGreaterThanOrEqual(80, $r['intent_score']);
    }

    public function test_coined_mechanism_detected_without_lexicon(): void
    {
        // a device-noun tail proves VSL exposure even with no per-offer lexicon supplied
        $this->assertSame('T4', $this->c->classify('japanese salt trick')['tier']);
        $this->assertSame('T4', $this->c->classify('protocolo do sal azul')['tier']);
    }

    public function test_how_to_buy_is_not_informational(): void
    {
        // "how to" alone must NOT be killed as informational — the object decides.
        $r = $this->c->classify('how to get rid of belly fat fast');
        $this->assertContains($r['tier'], ['T2', 'T3']);
        $this->assertNotSame('exclude', $r['action']);
    }

    public function test_generalizes_cross_niche_from_structure(): void
    {
        // Finance — no health lexicon, climbs the ladder via "como [ação]" + urgency.
        $finance = $this->c->classify('como sair das dívidas rápido');
        $this->assertSame('T3', $finance['tier']);
        $this->assertGreaterThanOrEqual(70, $finance['intent_score']);
        $this->assertSame('pt', $finance['lang']);

        // Relationship — solution-seeking without urgency.
        $rel = $this->c->classify('como reconquistar meu ex');
        $this->assertSame('T2', $rel['tier']);
        $this->assertGreaterThanOrEqual(50, $rel['intent_score']);
    }

    public function test_every_result_has_the_full_shape(): void
    {
        $r = $this->c->classify('tratamento natural para ansiedade');
        foreach (['tier', 'journey', 'pain', 'specificity', 'polarity', 'intent_score', 'wtp_multiplier', 'action', 'confidence', 'lang', 'markers'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertGreaterThanOrEqual(1.0, $r['wtp_multiplier']);
        $this->assertGreaterThan(0.0, $r['confidence']);
    }

    // --- panel fix_then_commit hardening (cycle 1) ---

    public function test_product_review_is_proof_seeking_buyer_not_complaint(): void
    {
        // "[produto] review" is the safest high-intent window (report §6) — bottom-funnel proof, not defection.
        $r = $this->c->classify('lipo bliss review');
        $this->assertNotSame('negative', $r['polarity']);
        $this->assertSame('T4', $r['tier']);
        $this->assertNotSame('exclude', $r['action']);
    }

    public function test_safety_check_is_not_hard_negative(): void
    {
        // "é seguro / is it safe" near a product is a buyer trust-check, not a defensive complaint.
        $this->assertNotSame('negative', $this->c->classify('lipo bliss é seguro')['polarity']);
        // ...but a real defection stays negative.
        $this->assertSame('negative', $this->c->classify('lipo bliss reembolso')['polarity']);
    }

    public function test_qualifier_makes_solution_aware(): void
    {
        // "emagrecer sem exercício" names a solution constraint even without "como" → T2, not excluded.
        $r = $this->c->classify('emagrecer sem exercício');
        $this->assertSame('T2', $r['tier']);
        $this->assertNotSame('exclude', $r['action']);
    }

    public function test_deadline_counts_as_urgency(): void
    {
        // a timeframe ("em 7 dias") is implicit urgency → pain > 0, rescued from a flat exclude.
        $r = $this->c->classify('emagrecer em 7 dias');
        $this->assertGreaterThan(0.0, $r['pain']);
        $this->assertNotSame('exclude', $r['action']);
    }

    public function test_informational_beats_coined_mechanism_substring(): void
    {
        // "o que é o protocolo do sal" is asking WHAT it is (T0), not buying it (T4).
        $this->assertSame('T0', $this->c->classify('o que é o protocolo do sal azul')['tier']);
    }

    public function test_generic_recipe_word_is_not_a_mechanism(): void
    {
        // cross-vertical false-positive guard: "receita de bolo" must NOT be read as a coined mechanism.
        $this->assertNotSame('T4', $this->c->classify('receita de bolo de chocolate')['tier']);
    }
}
