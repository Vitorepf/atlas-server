<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PersonaSimulator;
use PHPUnit\Framework\TestCase;

/**
 * Locks the persona-level simulation: instead of measuring markers (what the page HAS), the
 * simulator models the reaction of 3 skeptical personas (woman 40+ fatigued of dieting, ex-Ozempic
 * buyer, busy mom) — will_watch + will_close + first_objection + what_she_needs_next per persona.
 * It's the "I judge as the brutal panel" brief from the loop directive, cristalizado em código.
 */
class PersonaSimulatorTest extends TestCase
{
    private string $strong = 'For women over 40 who tried everything: it was never your willpower. '
        .'Three hormones quietly fall out of sync. Big Pharma hides it. As seen on CBS. Dr. Attia at Harvard. '
        .'Unlike Ozempic at $1,000 a month, no needle. 3 seconds in the morning, no diet, no gym.';

    private string $weak = 'Our amazing revolutionary supplement. Just take it. So easy. Transform your life!';

    public function test_strong_copy_wins_all_three_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->strong);

        foreach (['woman_40_diet_fatigue', 'ex_ozempic_buyer', 'busy_mom_no_time'] as $p) {
            $this->assertArrayHasKey($p, $sim);
            $this->assertGreaterThan(0.3, $sim[$p]['will_watch'], "{$p} should want to watch");
            $this->assertLessThan(0.4, $sim[$p]['will_close'], "{$p} should NOT close fast");
        }
    }

    public function test_weak_copy_loses_all_three_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->weak);

        foreach (['woman_40_diet_fatigue', 'ex_ozempic_buyer', 'busy_mom_no_time'] as $p) {
            $this->assertLessThan(0.3, $sim[$p]['will_watch'], "{$p} should NOT want to watch flat copy");
            $this->assertGreaterThan(0.4, $sim[$p]['will_close'], "{$p} should close flat copy fast");
        }
    }

    public function test_audience_score_separates_strong_from_weak(): void
    {
        $sim = new PersonaSimulator;
        $this->assertGreaterThan($sim->audienceScore($this->weak), $sim->audienceScore($this->strong));
    }

    public function test_each_persona_returns_concrete_next_action_hint(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->weak);
        foreach ($sim as $p) {
            $this->assertNotEmpty($p['first_objection']);
            $this->assertNotEmpty($p['what_she_needs_next']);
        }
    }

    public function test_panel_includes_all_five_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate($this->strong);
        foreach (['woman_40_diet_fatigue', 'ex_ozempic_buyer', 'busy_mom_no_time', 'skeptic_husband', 'early_adopter'] as $p) {
            $this->assertArrayHasKey($p, $sim, "Persona panel must include {$p}.");
        }
    }

    public function test_skeptic_husband_demands_guarantee_and_proof(): void
    {
        $noGuarantee = 'Get amazing results fast. Change your life today.';
        $sim = (new PersonaSimulator)->simulate($noGuarantee)['skeptic_husband'];
        $this->assertGreaterThan(0.4, $sim['will_close']);
        $this->assertStringContainsString('garantia', $sim['first_objection']);

        $solid = 'Backed by 60-day money-back guarantee. As seen on CBS. Dr. Attia. One-time purchase, no subscription.';
        $sim2 = (new PersonaSimulator)->simulate($solid)['skeptic_husband'];
        $this->assertGreaterThan($sim['will_watch'], $sim2['will_watch']);
    }

    public function test_early_adopter_rejects_cheesy_pitch(): void
    {
        $cheesy = 'A magic miracle that will transform your life forever. Just take it.';
        $sim = (new PersonaSimulator)->simulate($cheesy)['early_adopter'];
        $this->assertGreaterThan(0.5, $sim['will_close']);
        $this->assertStringContainsString('piegas', $sim['first_objection']);

        $deep = 'Unlike Ozempic, this targets all three hormones: GLP-1, GIP, and glucagon. The mechanism is in the protocol.';
        $sim2 = (new PersonaSimulator)->simulate($deep)['early_adopter'];
        $this->assertGreaterThan($sim['will_watch'], $sim2['will_watch']);
    }

    // ── Niche dispatch: the simulator picks the right skeptic panel for the niche. ─────────────────

    public function test_finance_niche_swaps_in_finance_personas(): void
    {
        $sim = (new PersonaSimulator)->simulate('a rule-based strategy with an audited track record', '', 'finance');
        $this->assertArrayHasKey('burned_retiree', $sim);
        $this->assertArrayHasKey('crypto_skeptic_lost_money', $sim);
        $this->assertArrayNotHasKey('woman_40_diet_fatigue', $sim, 'finance niche must NOT use health personas');
        $this->assertArrayNotHasKey('ex_ozempic_buyer', $sim);
    }

    public function test_relationship_niche_swaps_in_relationship_personas(): void
    {
        $rel = 'this is real psychology and the science of attraction, honest and authentic';
        $sim = (new PersonaSimulator)->simulate($rel, '', 'relationship');
        $this->assertArrayHasKey('divorced_woman_afraid_alone', $sim);
        $this->assertArrayNotHasKey('woman_40_diet_fatigue', $sim);
    }

    public function test_unknown_niche_uses_generic_panel_and_weight_loss_stays_health(): void
    {
        $sim = new PersonaSimulator;
        $generic = $sim->simulate('Backed by a study. Money-back guarantee. In short, here is how.', '', 'astrology');
        $this->assertArrayHasKey('proof_demander', $generic);
        $this->assertArrayNotHasKey('woman_40_diet_fatigue', $generic);

        // weight_loss is a health alias → keeps the hand-tuned health panel (backward compatible).
        $health = $sim->simulate($this->strong, '', 'weight_loss');
        $this->assertArrayHasKey('woman_40_diet_fatigue', $health);
    }

    // ── THE anti-Goodhart proof. The brutal cross-niche panel broke v1 by showing the audience score
    //    measured substring DENSITY, not persuasion: keyword-salad out-scored real elite copy, and
    //    every non-stuffed page collapsed to exactly 0. These fixtures are the EXACT adversarial copy
    //    the panel used — NOT reverse-engineered from the marker dictionary — so passing proves real
    //    discrimination, not tautology. GOOD = real elite (must rank high); BAD = scam/salad (low).

    /** @return array<string,string> real elite finance copy that a human would actually write */
    private function goodFinance(): array
    {
        return [
            'loss_framed' => 'Você passou quarenta anos construindo isso. Não vou te insultar com promessa '
                .'de iate. Vou te ensinar a perder menos quando todo mundo entra em pânico. Quem mantém o '
                .'dinheiro não é o mais ousado — é o mais paciente. Leia os dezessete anos de extratos.',
            'retention' => 'Se você tem mais de 60 anos, não vou prometer que você fica rico — quem promete '
                .'tá mentindo. É um método chato, baseado em regras, que protegeu o capital dos meus clientes '
                .'no crash de 2008 e no tombo de 2022. Track record auditado de 17 anos. O pior drawdown foi 11%.',
            'story_lead' => 'My father lost the house in 2008. I swore that would never be me. So I spent '
                .'eleven years building one boring rule I follow every Monday. It has never had a losing year. '
                .'You can read the brokerage statements yourself.',
        ];
    }

    /** @return array<string,string> scam / keyword-salad finance copy */
    private function badFinance(): array
    {
        return [
            'dressed_scam' => 'Our verified, audited track record speaks for itself. As seen on Forbes. '
                .'Protect your capital with our proven low-risk system. Backed by a 60-day money-back '
                .'guarantee. The exact strategy, fully transparent, rule-based and backtested. This is not '
                .'another signals group.',
            'keyword_salad' => 'track record verified audited as seen on bloomberg protect your capital low '
                .'risk realistic money-back backtested rule-based start with $100 step-by-step one-time no subscription',
            'naked_lambo' => 'Get rich overnight! Double your money guaranteed. To the moon, buy the Lambo, '
                .'100% win rate, VIP signals group!',
        ];
    }

    public function test_real_elite_finance_copy_outscores_scam_and_salad(): void
    {
        $sim = new PersonaSimulator;
        $good = array_map(fn ($c) => $sim->audienceScore($c, 'finance'), $this->goodFinance());
        $bad = array_map(fn ($c) => $sim->audienceScore($c, 'finance'), $this->badFinance());

        // Every real elite page must out-score every scam/salad — the discrimination the panel demanded.
        $this->assertGreaterThan(
            max($bad),
            min($good),
            sprintf('elite finance copy (min %.2f) must beat scam/salad (max %.2f)', min($good), max($bad))
        );
        // And the keyword salad — which used to WIN — must now be near the floor.
        $this->assertLessThan(0.2, $sim->audienceScore($this->badFinance()['keyword_salad'], 'finance'));
    }

    public function test_real_elite_relationship_copy_outscores_scam_and_salad(): void
    {
        $sim = new PersonaSimulator;
        $good = [
            'elite' => 'He stopped texting back three weeks ago. You have read the last conversation forty '
                .'times. I read mine four hundred. Then a therapist told me one sentence that changed how I '
                .'saw the whole thing, and it had nothing to do with playing games.',
            'negation' => 'This is real psychology, not manipulation and no mind games. It is about '
                .'understanding why people pull away, and what genuinely rebuilds trust after it breaks.',
        ];
        $bad = [
            'salad' => 'never too late not your fault real psychology not tricks the betrayal testimonial '
                .'step-by-step attachment emotional any age you were never the problem divorced starting over',
            'pickup' => 'One weird trick to manipulate him and make him obsessed instantly. Magic mind games guaranteed.',
        ];
        $goodScores = array_map(fn ($c) => $sim->audienceScore($c, 'relationship'), $good);
        $badScores = array_map(fn ($c) => $sim->audienceScore($c, 'relationship'), $bad);

        $this->assertGreaterThan(max($badScores), min($goodScores));
    }

    /**
     * Negation must not be punished. v1's str_contains was polarity-blind: "not manipulation / no mind
     * games" FIRED the manipulation penalty, and the ladder's own prescribed fix ("not get rich quick")
     * LOWERED the score because "get rich" matched inside it. Both are now handled.
     */
    public function test_negation_is_rewarded_not_punished(): void
    {
        $sim = new PersonaSimulator;

        // "not manipulation" reads as ethical reassurance, not as manipulation.
        $ethical = $sim->audienceScore('real psychology, not manipulation and no mind games, just honest understanding', 'relationship');
        $manipulative = $sim->audienceScore('mind games and tricks to manipulate him and make him obsessed', 'relationship');
        $this->assertGreaterThan($manipulative, $ethical, 'negated bad-frame must beat the actual bad-frame');

        // Take-away selling: applying the ladder's own anti-greed fix must NOT drop the score.
        $plain = 'a rule-based strategy that protects your capital, with an audited track record since 2009';
        $withTakeaway = $plain.'. This is not get rich quick — realistic, consistent results only.';
        $this->assertGreaterThanOrEqual(
            $sim->audienceScore($plain, 'finance') - 0.001,
            $sim->audienceScore($withTakeaway, 'finance'),
            'adding the anti-greed take-away must not lower the score (negation bug)'
        );
    }
}
