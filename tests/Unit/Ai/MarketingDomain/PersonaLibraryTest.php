<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\PersonaLibrary;
use PHPUnit\Framework\TestCase;

/**
 * PersonaLibrary — the niche-keyed skeptic panels (finance / relationship / generic) + the one
 * generic evaluator. The health panel stays in PersonaSimulator; this proves the OTHER niches get
 * a psychologically-real read instead of the health-blind ~0 they used to collapse to.
 */
class PersonaLibraryTest extends TestCase
{
    // ── FINANCE: the core fear is LOSS, not "another diet". get-rich screams repel the buyer. ──────
    private string $strongFinance = 'For everyday investors who got burned before: this is a '
        .'rule-based, backtested strategy that protects your capital first. Unlike the hype-driven '
        .'crowds that wiped people out, the exact strategy is shown with full transparency, no hidden '
        .'steps. As seen on Bloomberg, with a verified, audited track record. Start with as little as '
        .'$100, no experience needed: a step-by-step blueprint you run in your spare time. Realistic, '
        .'consistent results. The full methodology, drawdown and risk-adjusted numbers are on the '
        .'table. Backed by a 60-day money-back guarantee, one-time, no subscription.';

    private string $weakFinance = 'Get rich overnight! Guaranteed profit. Double your money with our '
        .'VIP signals group. Financial freedom is one click away. Amazing, incredible, life-changing.';

    // ── RELATIONSHIP: the wounds are loneliness + betrayal + fear of being manipulated. ────────────
    private string $strongRelationship = "It's never too late, at any age. What he did was the "
        .'betrayal that broke you, and it was not your fault, you were never the problem. I was where '
        .'you are: divorced and starting over. This is real psychology and the science of attraction, '
        .'honest, ethical, authentic, with no games. The exact method is step-by-step: the words to '
        .'say, in a few minutes that fit real life. Backed by testimonials from real women and a case '
        .'study. This builds genuine, real connection through attachment and emotional communication.';

    private string $weakRelationship = 'One weird trick to manipulate him and make him obsessed '
        .'instantly. Magic mind games guaranteed to work on any woman. Pickup secrets revealed!';

    // ── GENERIC: niche-agnostic universal skeptics. ───────────────────────────────────────────────
    private string $strongGeneric = 'Unlike anything you tried, this is different, and it was never '
        .'your fault. Backed by a study, as seen on major media, with verified testimonials and real '
        .'numbers over 30 days. Worth far more than the price, less than a coffee. For the few in the '
        .'inner circle, ahead of the curve. In short: simple, here is how it works, in seconds. '
        .'Money-back guarantee.';

    private string $weakGeneric = 'Amazing incredible revolutionary miracle for everyone! Best ever!';

    public function test_finance_panel_separates_strong_from_weak(): void
    {
        $lib = new PersonaLibrary;
        $strong = $lib->simulate('finance', $this->strongFinance);
        $weak = $lib->simulate('finance', $this->weakFinance);

        $this->assertArrayHasKey('burned_retiree', $strong);
        $this->assertArrayHasKey('crypto_skeptic_lost_money', $strong);
        $this->assertArrayHasKey('ambitious_broke_hustler', $strong);

        foreach (array_keys($strong) as $p) {
            $this->assertGreaterThan($weak[$p]['will_watch'], $strong[$p]['will_watch'], "{$p}: strong should out-watch weak");
            $this->assertLessThan($weak[$p]['will_close'], $strong[$p]['will_close'], "{$p}: strong should out-close weak");
        }
    }

    public function test_finance_greed_hype_triggers_the_loss_averse_objection(): void
    {
        $lib = new PersonaLibrary;
        $weak = $lib->simulate('finance', $this->weakFinance);

        // The retiree and the burned crypto buyer must flag the scam/greed smell explicitly.
        $this->assertStringContainsString('enriquecimento rápido', $weak['burned_retiree']['first_objection']);
        $this->assertStringContainsString('golpe', $weak['crypto_skeptic_lost_money']['first_objection']);
    }

    public function test_relationship_panel_separates_strong_from_weak(): void
    {
        $lib = new PersonaLibrary;
        $strong = $lib->simulate('relationship', $this->strongRelationship);
        $weak = $lib->simulate('relationship', $this->weakRelationship);

        $this->assertArrayHasKey('divorced_woman_afraid_alone', $strong);
        $this->assertArrayHasKey('betrayed_cynic', $strong);

        foreach (array_keys($strong) as $p) {
            $this->assertGreaterThan($weak[$p]['will_watch'], $strong[$p]['will_watch'], "{$p}: strong should out-watch weak");
            $this->assertLessThan($weak[$p]['will_close'], $strong[$p]['will_close'], "{$p}: strong should out-close weak");
        }
    }

    public function test_relationship_manipulation_smell_repels_the_betrayed_cynic(): void
    {
        $lib = new PersonaLibrary;
        $weak = $lib->simulate('relationship', $this->weakRelationship);

        $this->assertGreaterThan(0.7, $weak['betrayed_cynic']['will_close']);
        $this->assertStringContainsString('manipulação', $weak['betrayed_cynic']['first_objection']);
    }

    public function test_generic_panel_separates_strong_from_weak(): void
    {
        $lib = new PersonaLibrary;
        $strong = $lib->simulate('generic', $this->strongGeneric);
        $weak = $lib->simulate('generic', $this->weakGeneric);

        $this->assertArrayHasKey('proof_demander', $strong);
        foreach (array_keys($strong) as $p) {
            $this->assertGreaterThanOrEqual($weak[$p]['will_watch'], $strong[$p]['will_watch'], "{$p}: strong should out-watch weak");
        }
    }

    public function test_unknown_niche_falls_back_to_generic_panel(): void
    {
        $lib = new PersonaLibrary;
        $this->assertSame('generic', $lib->resolveFamily('spirituality'));
        $this->assertSame('generic', $lib->resolveFamily('gardening'));
        $panel = $lib->simulate('spirituality', $this->strongGeneric);
        $this->assertArrayHasKey('bargain_skeptic', $panel);
    }

    public function test_niche_label_resolution(): void
    {
        $lib = new PersonaLibrary;
        $this->assertSame('health', $lib->resolveFamily(''));
        $this->assertSame('health', $lib->resolveFamily('weight_loss'));
        $this->assertSame('finance', $lib->resolveFamily('finance'));
        $this->assertSame('finance', $lib->resolveFamily('crypto trading'));
        $this->assertSame('relationship', $lib->resolveFamily('dating'));
        $this->assertSame('relationship', $lib->resolveFamily('save my marriage'));
    }

    public function test_every_persona_returns_the_full_contract(): void
    {
        $lib = new PersonaLibrary;
        foreach (['finance', 'relationship', 'generic'] as $family) {
            foreach ($lib->simulate($family, $this->strongGeneric) as $key => $p) {
                foreach (['will_watch', 'will_close', 'first_objection', 'what_she_needs_next', 'reason', 'slot'] as $field) {
                    $this->assertArrayHasKey($field, $p, "{$family}/{$key} missing {$field}");
                }
                $this->assertGreaterThanOrEqual(0.0, $p['will_watch']);
                $this->assertLessThanOrEqual(1.0, $p['will_watch']);
                $this->assertNotEmpty($p['first_objection'], "{$family}/{$key} must surface an objection");
                $this->assertNotEmpty($p['what_she_needs_next'], "{$family}/{$key} must surface a next-action hint");
            }
        }
    }
}
