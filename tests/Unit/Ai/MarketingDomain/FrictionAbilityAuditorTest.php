<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\FrictionAbilityAuditor;
use PHPUnit\Framework\TestCase;

/**
 * FrictionAbilityAuditor — the Fogg Ability axis. Does the page LOWER the cost of acting, or pile on
 * action friction? Structural fact (presence of ability signals / friction-raisers), not a score.
 */
class FrictionAbilityAuditorTest extends TestCase
{
    public function test_detects_when_friction_is_addressed(): void
    {
        $r = (new FrictionAbilityAuditor)->audit('Get instant access — no credit card, cancel anytime, sign up in 2 minutes.');
        $this->assertTrue($r['addresses_friction']);
        $this->assertContains('instant access', $r['ability_signals']);
        $this->assertContains('no credit card', $r['ability_signals']);
    }

    public function test_flags_high_friction_with_no_relief(): void
    {
        $r = (new FrictionAbilityAuditor)->audit('To get started, create an account, fill out the form and schedule a call. Then wait for approval.');
        $this->assertFalse($r['addresses_friction']);
        $this->assertNotEmpty($r['friction_flags']);
        $this->assertStringContainsString('morre no esforço', $r['note']);
    }

    public function test_a_page_that_never_lowers_effort_is_flagged(): void
    {
        $r = (new FrictionAbilityAuditor)->audit('This protocol resets your hormones and melts the fat. Order now.');
        $this->assertFalse($r['addresses_friction']);
        $this->assertSame([], $r['friction_flags']);
        $this->assertStringContainsString('não REDUZ a fricção', $r['note']);
    }

    public function test_cross_niche_portuguese(): void
    {
        $r = (new FrictionAbilityAuditor)->audit('Acesso imediato, sem cartão, cancele quando quiser. Frete grátis.');
        $this->assertTrue($r['addresses_friction']);
    }
}
