<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\AcosProgram;

use App\Services\Ai\Operator\AttentionBudgetClassifier;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * MULTN15-05 — Attention Budget classifier acceptance (§2753).
 *
 *   - Low-risk + reversible + far expiry ⇒ batch_to_digest (with the ask
 *     visible in the digest — that's the caller layer).
 *   - High-risk ⇒ interrupt_now (contrast).
 *   - Batched ask X minutes from expiry ⇒ PROMOTED to interrupt_now
 *     (anti-silence rule).
 *   - deny_rate arm requires n>=10 or is silently disarmed
 *     (insufficient_signal never fabricates a routing signal).
 *   - charter test: this classifier NEVER creates a new ask
 *     (source.creates_new_asks=false stamped).
 */
final class Multn1505AttentionBudgetClassifierTest extends TestCase
{
    #[Test]
    public function schema_version_is_pinned(): void
    {
        $this->assertSame(
            'atlas.operator.attention_budget.v1',
            AttentionBudgetClassifier::SCHEMA_VERSION,
        );
    }

    #[Test]
    public function low_risk_reversible_far_expiry_batches_to_digest(): void
    {
        $verdict = (new AttentionBudgetClassifier)->classify([
            'risk_band' => 'low',
            'reversible' => true,
            'expiry_minutes' => 24 * 60,
            'deny_rate' => 0.1,
            'n_deny_rate' => 20,
        ]);
        $this->assertSame('batch_to_digest', $verdict['verdict']);
        $this->assertSame([], $verdict['basis']);
    }

    #[Test]
    public function high_risk_forces_interrupt_now(): void
    {
        $verdict = (new AttentionBudgetClassifier)->classify([
            'risk_band' => 'high',
            'reversible' => true,
            'expiry_minutes' => 24 * 60,
            'deny_rate' => 0.1,
            'n_deny_rate' => 20,
        ]);
        $this->assertSame('interrupt_now', $verdict['verdict']);
        $this->assertContains('risk_band:high', $verdict['basis']);
    }

    #[Test]
    public function irreversible_forces_interrupt_now(): void
    {
        $verdict = (new AttentionBudgetClassifier)->classify([
            'risk_band' => 'low',
            'reversible' => false,
            'expiry_minutes' => 24 * 60,
            'deny_rate' => 0.1,
            'n_deny_rate' => 20,
        ]);
        $this->assertSame('interrupt_now', $verdict['verdict']);
        $this->assertContains('irreversible', $verdict['basis']);
    }

    #[Test]
    public function batched_ask_close_to_expiry_is_promoted_to_interrupt_now(): void
    {
        $classifier = new AttentionBudgetClassifier;
        $promoted = $classifier->promoteBatchedNearExpiry([
            'risk_band' => 'low',
            'reversible' => true,
            'expiry_minutes' => 15,
            'deny_rate' => 0.1,
            'n_deny_rate' => 20,
        ], currentRouting: 'batch_to_digest');
        $this->assertTrue($promoted['promoted']);
        $this->assertSame('interrupt_now', $promoted['verdict']);
        $this->assertContains('anti_silence_promotion', $promoted['basis']);
    }

    #[Test]
    public function batched_ask_far_from_expiry_is_not_promoted(): void
    {
        $promoted = (new AttentionBudgetClassifier)->promoteBatchedNearExpiry([
            'risk_band' => 'low',
            'reversible' => true,
            'expiry_minutes' => 12 * 60,
            'deny_rate' => 0.1,
            'n_deny_rate' => 20,
        ], currentRouting: 'batch_to_digest');
        $this->assertFalse($promoted['promoted']);
        $this->assertSame('batch_to_digest', $promoted['verdict']);
    }

    #[Test]
    public function deny_rate_arm_requires_n_10_or_is_silently_disarmed(): void
    {
        $verdict = (new AttentionBudgetClassifier)->classify([
            'risk_band' => 'low',
            'reversible' => true,
            'expiry_minutes' => 24 * 60,
            'deny_rate' => 0.9,
            'n_deny_rate' => 3,
        ]);
        $this->assertSame('batch_to_digest', $verdict['verdict'], 'small-n deny_rate must NOT trigger interrupt');
        $this->assertContains('deny_rate', $verdict['unmeasured']);
    }

    #[Test]
    public function classifier_never_creates_a_new_ask(): void
    {
        $verdict = (new AttentionBudgetClassifier)->classify([
            'risk_band' => 'high',
            'reversible' => false,
        ]);
        $this->assertFalse($verdict['source']['creates_new_asks']);
        $this->assertTrue($verdict['source']['reroutes_only']);
        $this->assertFalse($verdict['source']['promotes_ceiling']);
    }
}
