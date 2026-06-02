<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Kernel\Decision\Reversibility;

use App\Services\Ai\Kernel\Decision\Reversibility\ReceiptReversibilityConsentGate;
use Tests\TestCase;

final class ReceiptReversibilityConsentGateTest extends TestCase
{
    public function test_unrecoverable_without_confirmation_is_blocked_and_requires_consent(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('unrecoverable', 0, 0, false);

        $this->assertFalse($result['committable']);
        $this->assertTrue($result['requires_operator_confirmation']);
        $this->assertSame('blocked_irreversible_without_consent', $result['verdict']);
    }

    public function test_unrecoverable_with_confirmation_is_committable_but_still_requires_consent(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('unrecoverable', 0, 0, true);

        $this->assertTrue($result['committable']);
        $this->assertTrue($result['requires_operator_confirmation']);
        $this->assertSame('committable_with_explicit_consent', $result['verdict']);
    }

    public function test_expensive_with_remaining_hold_holds_for_cooldown(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('expensive', 10, 60, false);

        $this->assertFalse($result['committable']);
        $this->assertSame(50, $result['remaining_hold_seconds']);
        $this->assertSame('holding_for_cooldown', $result['verdict']);
    }

    public function test_expensive_after_hold_elapsed_is_committable(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('expensive', 60, 60, false);

        $this->assertTrue($result['committable']);
        $this->assertSame(0, $result['remaining_hold_seconds']);
        $this->assertSame('committable_after_hold', $result['verdict']);
    }

    public function test_cheap_tier_is_committable_immediately_without_confirmation(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('cheap', 0, 9999, false);

        $this->assertTrue($result['committable']);
        $this->assertFalse($result['requires_operator_confirmation']);
        $this->assertSame('committable_immediately', $result['verdict']);
    }

    public function test_unknown_tier_fails_closed_to_unrecoverable(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('banana', 0, 0, false);

        $this->assertFalse($result['committable']);
        $this->assertTrue($result['requires_operator_confirmation']);
        $this->assertSame('blocked_irreversible_without_consent', $result['verdict']);
    }

    public function test_schema_version_literal_is_present(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('cheap', 0, 0, false);

        $this->assertSame('atlas.decide.receipt_reversibility_consent.v1', $result['schema_version']);
    }

    public function test_empty_and_uppercase_tiers_normalize_then_fail_closed_or_match(): void
    {
        $gate = new ReceiptReversibilityConsentGate();

        // Empty fails closed to unrecoverable.
        $empty = $gate->evaluate('   ', 0, 0, false);
        $this->assertSame('blocked_irreversible_without_consent', $empty['verdict']);

        // Uppercase + padding normalizes to the known cheap tier.
        $cheap = $gate->evaluate('  CHEAP  ', 0, 0, false);
        $this->assertSame('committable_immediately', $cheap['verdict']);
        $this->assertTrue($cheap['committable']);
    }

    public function test_moderate_tier_holds_then_commits_and_confirmation_bypasses_hold(): void
    {
        $gate = new ReceiptReversibilityConsentGate();

        // R5: moderate with remaining hold and no confirmation holds.
        $holding = $gate->evaluate('moderate', 5, 30, false);
        $this->assertFalse($holding['committable']);
        $this->assertSame(25, $holding['remaining_hold_seconds']);
        $this->assertSame('holding_for_cooldown', $holding['verdict']);
        $this->assertFalse($holding['requires_operator_confirmation']);

        // R6: moderate with operator confirmation commits despite remaining hold.
        $confirmed = $gate->evaluate('moderate', 5, 30, true);
        $this->assertTrue($confirmed['committable']);
        $this->assertSame('committable_after_hold', $confirmed['verdict']);
    }

    public function test_negative_hold_seconds_clamp_to_zero_remaining(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('expensive', -100, -50, false);

        $this->assertSame(0, $result['remaining_hold_seconds']);
        $this->assertTrue($result['committable']);
        $this->assertSame('committable_after_hold', $result['verdict']);
    }

    public function test_expensive_with_confirmation_bypasses_remaining_hold(): void
    {
        $result = (new ReceiptReversibilityConsentGate())->evaluate('expensive', 0, 120, true);

        $this->assertTrue($result['committable']);
        $this->assertSame(120, $result['remaining_hold_seconds']);
        $this->assertSame('committable_after_hold', $result['verdict']);
    }
}
