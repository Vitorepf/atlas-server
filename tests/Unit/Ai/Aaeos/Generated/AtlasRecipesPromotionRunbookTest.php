<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasRecipesPromotionRunbookService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Cyber Recipes Promotion Runbook gate:
 * eight Promotion Steps + Required Gates + seven-item Definition Of Done,
 * with the "no provider/raw-MCP bypass" hard safety invariant.
 *
 * @see docs/engineering-knowledge-base/cyber-security/recipes-promotion-runbook.md
 */
class AtlasRecipesPromotionRunbookTest extends TestCase
{
    private function service(): AtlasRecipesPromotionRunbookService
    {
        return new AtlasRecipesPromotionRunbookService();
    }

    /**
     * A fully-green offensive (active exploitation) promotion attempt: every
     * Promotion Step done, every applicable gate green (incl. conditional sandbox
     * + approval), every Definition-of-Done item satisfied.
     *
     * @return array<string,mixed>
     */
    private function greenOffensiveAttempt(): array
    {
        return [
            'category' => 'active_exploit',
            'offensive' => true,
            'steps' => array_fill_keys(AtlasRecipesPromotionRunbookService::PROMOTION_STEPS, true),
            'gates' => [
                'scope_proof' => true,
                'refusal_matrix' => true,
                'decision_receipt' => true,
                'evidence' => true,
                'sandbox' => true,
                'approval' => true,
            ],
            'definition_of_done' => array_fill_keys(AtlasRecipesPromotionRunbookService::DEFINITION_OF_DONE, true),
        ];
    }

    /** All steps + applicable gates + DoD green => promote, with the active gate set surfaced. */
    public function test_fully_green_offensive_promotion_is_promoted(): void
    {
        $result = $this->service()->evaluatePromotion($this->greenOffensiveAttempt());

        $this->assertSame('promote', $result['verdict']);
        $this->assertTrue($result['promoted']);
        $this->assertSame([], $result['incomplete_steps']);
        $this->assertSame([], $result['failed_gates']);
        $this->assertSame([], $result['open_definition_of_done']);
        $this->assertSame([], $result['violations']);
        // Offensive active-exploit => sandbox + approval gates are both required.
        $this->assertTrue($result['sandbox_required']);
        $this->assertTrue($result['approval_required']);
        $this->assertSame(
            ['scope_proof', 'refusal_matrix', 'decision_receipt', 'evidence', 'sandbox', 'approval'],
            $result['required_gates'],
        );
    }

    /** Promotion Steps: dropping any step => hold + the step is named. */
    public function test_incomplete_promotion_step_holds_and_is_listed(): void
    {
        $attempt = $this->greenOffensiveAttempt();
        $attempt['steps']['sandbox_profile'] = false;
        unset($attempt['steps']['tests']); // missing key also counts as unmet

        $result = $this->service()->evaluatePromotion($attempt);

        $this->assertSame('hold', $result['verdict']);
        $this->assertEqualsCanonicalizing(['sandbox_profile', 'tests'], $result['incomplete_steps']);
        $this->assertContains('promotion_steps_incomplete', array_column($result['violations'], 'code'));
    }

    /** Required Gate: a red refusal-matrix gate => hold + the gate is named. */
    public function test_failed_required_gate_holds(): void
    {
        $attempt = $this->greenOffensiveAttempt();
        $attempt['gates']['refusal_matrix'] = false;

        $result = $this->service()->evaluatePromotion($attempt);

        $this->assertSame('hold', $result['verdict']);
        $this->assertContains('refusal_matrix', $result['failed_gates']);
        $this->assertContains('required_gates_not_green', array_column($result['violations'], 'code'));
    }

    /**
     * Conditional gates: an offensive recipe MUST satisfy the sandbox gate, and a
     * high-risk category (active exploit) MUST satisfy the approval gate. Missing
     * either => hold.
     */
    public function test_offensive_high_risk_requires_sandbox_and_approval_gates(): void
    {
        $service = $this->service();

        // sandbox + approval are part of the required set for active_exploit/offensive.
        $this->assertSame(
            ['scope_proof', 'refusal_matrix', 'decision_receipt', 'evidence', 'sandbox', 'approval'],
            $service->requiredGatesFor('active_exploit', true),
        );

        $attempt = $this->greenOffensiveAttempt();
        $attempt['gates']['sandbox'] = false;
        $attempt['gates']['approval'] = false;

        $result = $service->evaluatePromotion($attempt);

        $this->assertSame('hold', $result['verdict']);
        $this->assertContains('sandbox', $result['failed_gates']);
        $this->assertContains('approval', $result['failed_gates']);
    }

    /**
     * Definition Of Done hard invariant: even with EVERY step done, EVERY gate
     * green and every other DoD item satisfied, a provider/raw-MCP bypass forces
     * hold and emits its own dedicated violation.
     */
    public function test_provider_or_mcp_bypass_blocks_promotion_unconditionally(): void
    {
        $attempt = $this->greenOffensiveAttempt();
        $attempt['definition_of_done']['no_provider_or_mcp_bypass'] = false;

        $result = $this->service()->evaluatePromotion($attempt);

        $this->assertSame('hold', $result['verdict']);
        $this->assertFalse($result['promoted']);
        $this->assertContains('no_provider_or_mcp_bypass', $result['open_definition_of_done']);
        $this->assertContains('provider_or_mcp_bypass_present', array_column($result['violations'], 'code'));
    }

    /**
     * A defensive, low-risk recipe does NOT require the conditional sandbox/approval
     * gates, so a green defensive attempt promotes without them.
     */
    public function test_defensive_low_risk_promotes_without_conditional_gates(): void
    {
        $service = $this->service();

        $this->assertFalse($service->isHighRiskCategory('recon'));
        $this->assertSame(
            ['scope_proof', 'refusal_matrix', 'decision_receipt', 'evidence'],
            $service->requiredGatesFor('recon', false),
        );

        $attempt = [
            'category' => 'recon',
            'offensive' => false,
            'steps' => array_fill_keys(AtlasRecipesPromotionRunbookService::PROMOTION_STEPS, true),
            'gates' => [
                'scope_proof' => true,
                'refusal_matrix' => true,
                'decision_receipt' => true,
                'evidence' => true,
                // no sandbox / approval supplied — and none required here
            ],
            'definition_of_done' => array_fill_keys(AtlasRecipesPromotionRunbookService::DEFINITION_OF_DONE, true),
        ];

        $result = $service->evaluatePromotion($attempt);

        $this->assertSame('promote', $result['verdict']);
        $this->assertFalse($result['sandbox_required']);
        $this->assertFalse($result['approval_required']);
    }
}
