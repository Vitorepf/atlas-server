<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Foundry\Rsi;

use App\Services\Ai\Foundry\Rsi\ImmutableInvariantRegistryService;
use App\Services\Ai\Foundry\Rsi\RsiInvariantGuardService;
use App\Services\Ai\Foundry\Rsi\RsiSelfImprovementProposalGate;
use Tests\TestCase;

/**
 * Governed RSI · Part A (Build-Safety) · Gating proof.
 *
 * Build-Safety MUST be green before Build-RSI begins. This test PROVES the RSI
 * path cannot weaken an invariant and cannot auto-canonize:
 *
 *   P1 — a proposal whose diff TOUCHES a sacred gate file is REJECTED with a
 *        precise gate id + path, and is NEVER routed to the human gate.
 *   P2 — a proposal that EDITS the invariant registry itself is REJECTED
 *        (the sacred set is self-protecting).
 *   P3 — a proposal that adds an eligibility / invariant override is REJECTED.
 *   P4 — a proposal that adds auto-canonize / auto-apply intent is REJECTED;
 *        a clean PASS is still proposal-only (never auto-applied).
 *   P5 — the guard FAILS CLOSED on a malformed / empty diff.
 *
 * Plus the positive control: a proposal touching ONLY a non-sacred component
 * (the decomposer heuristic) PASSES the guard and is routed to the human gate
 * — still proposal-only.
 */
final class RsiCannotWeakenInvariantTest extends TestCase
{
    private function guard(): RsiInvariantGuardService
    {
        return app(RsiInvariantGuardService::class);
    }

    private function gate(): RsiSelfImprovementProposalGate
    {
        return app(RsiSelfImprovementProposalGate::class);
    }

    private const SACRED_PROVIDER_PROOF = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/OwnerFlow/Ap786OwnerFlowExecutor.php';

    private const SACRED_REGISTRY = 'app/Services/Ai/Foundry/Rsi/ImmutableInvariantRegistryService.php';

    private const SACRED_GUARD = 'app/Services/Ai/Foundry/Rsi/RsiInvariantGuardService.php';

    private const NON_SACRED_DECOMPOSER = 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/PlanExecution/FindingDecomposerService.php';

    // ---- P1: cannot touch / weaken a sacred gate --------------------------

    public function test_p1_proposal_touching_sacred_gate_file_is_rejected_with_precise_reason(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::SACRED_PROVIDER_PROOF],
        ]);

        $this->assertTrue($screening['rejected']);
        $this->assertSame(RsiInvariantGuardService::VERDICT_REJECTED, $screening['verdict']);
        $this->assertContains(self::SACRED_PROVIDER_PROOF, $screening['touched_sacred_paths']);
        $this->assertContains(
            ImmutableInvariantRegistryService::GATE_PROVIDER_PROOF,
            $screening['touched_gates'],
        );

        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_SACRED_PATH_TOUCHED, $codes);
    }

    public function test_p1_proposal_removing_a_sacred_check_is_flagged_as_invariant_weakened(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::SACRED_PROVIDER_PROOF],
            'removed_lines' => [
                self::SACRED_PROVIDER_PROOF => ['if ($providerCalls > 0) { return true; }'],
            ],
        ]);

        $this->assertTrue($screening['rejected']);
        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_INVARIANT_WEAKENED, $codes);
    }

    public function test_p1_blocked_proposal_is_never_routed_to_the_human_gate(): void
    {
        $result = $this->gate()->admit(
            ['diff' => ['changed_paths' => [self::SACRED_PROVIDER_PROOF]]],
            ['rsi_mode_enabled' => true],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_BLOCKED_BY_INVARIANT, $result['status']);
        $this->assertFalse($result['routed_to_human_gate']);
        $this->assertFalse($result['auto_applied']);
        $this->assertFalse($result['auto_canonized']);
    }

    // ---- P2: registry is self-protecting ----------------------------------

    public function test_p2_proposal_editing_the_registry_itself_is_rejected(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::SACRED_REGISTRY],
        ]);

        $this->assertTrue($screening['rejected']);
        $this->assertContains(
            ImmutableInvariantRegistryService::GATE_REGISTRY_SELF,
            $screening['touched_gates'],
        );
    }

    public function test_p2_proposal_editing_the_guard_itself_is_rejected(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::SACRED_GUARD],
        ]);

        $this->assertTrue($screening['rejected']);
        $this->assertContains(
            ImmutableInvariantRegistryService::GATE_REGISTRY_SELF,
            $screening['touched_gates'],
        );
    }

    // ---- P3: cannot add an eligibility / invariant override ----------------

    public function test_p3_proposal_adding_an_eligibility_override_is_rejected(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::NON_SACRED_DECOMPOSER],
            'added_lines' => ["    'force_eligible' => true,"],
        ]);

        $this->assertTrue($screening['rejected']);
        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_ELIGIBILITY_OVERRIDE_ADDED, $codes);
    }

    public function test_p3_proposal_disabling_the_guard_is_rejected(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::NON_SACRED_DECOMPOSER],
            'added_lines' => ['$input["disable_invariant_guard"] = true;'],
        ]);

        $this->assertTrue($screening['rejected']);
        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_ELIGIBILITY_OVERRIDE_ADDED, $codes);
    }

    // ---- P4: cannot auto-canonize; PASS is still proposal-only -------------

    public function test_p4_proposal_adding_auto_canonize_intent_is_rejected(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::NON_SACRED_DECOMPOSER],
            'added_lines' => ['$this->autoApply($proposal); // auto_canonize'],
        ]);

        $this->assertTrue($screening['rejected']);
        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_AUTO_CANONIZE_ATTEMPTED, $codes);
    }

    public function test_p4_clean_non_sacred_proposal_passes_but_stays_proposal_only(): void
    {
        $screening = $this->guard()->screen([
            'changed_paths' => [self::NON_SACRED_DECOMPOSER],
            'added_lines' => ['    private function rankSlices(array $slices): array { return $slices; }'],
        ]);

        $this->assertFalse($screening['rejected']);
        $this->assertSame(RsiInvariantGuardService::VERDICT_PASSED, $screening['verdict']);
        $this->assertTrue($screening['proposal_only']);
        $this->assertTrue($screening['human_gate_required']);
        $this->assertFalse($screening['auto_applied']);

        // End-to-end through the live seam: passed => routed to the HUMAN gate,
        // never auto-applied / auto-canonized.
        $result = $this->gate()->admit(
            ['diff' => ['changed_paths' => [self::NON_SACRED_DECOMPOSER]]],
            ['rsi_mode_enabled' => true],
        );
        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_ROUTED_TO_HUMAN_GATE, $result['status']);
        $this->assertTrue($result['routed_to_human_gate']);
        $this->assertFalse($result['auto_applied']);
        $this->assertFalse($result['auto_canonized']);
        $this->assertTrue($result['proposal_only']);
    }

    // ---- P5: fails closed on a malformed / empty diff ----------------------

    public function test_p5_guard_fails_closed_on_empty_diff(): void
    {
        $screening = $this->guard()->screen([]);

        $this->assertTrue($screening['rejected']);
        $codes = array_column($screening['reasons'], 'code');
        $this->assertContains(RsiInvariantGuardService::REASON_DIFF_MALFORMED, $codes);
    }

    public function test_p5_default_off_flag_makes_the_rsi_gate_inert(): void
    {
        // No flag override and config defaults false => skipped, routes nothing,
        // even for a sacred-touching diff (the path is simply inert).
        $result = $this->gate()->admit(
            ['diff' => ['changed_paths' => [self::SACRED_PROVIDER_PROOF]]],
        );

        $this->assertSame(RsiSelfImprovementProposalGate::STATUS_SKIPPED, $result['status']);
        $this->assertFalse($result['routed_to_human_gate']);
    }

    // ---- Registry integrity ------------------------------------------------

    public function test_registry_is_self_protecting_and_lists_every_sacred_gate(): void
    {
        $registry = app(ImmutableInvariantRegistryService::class)->registry();

        $gateIds = array_column($registry['gates'], 'gate_id');
        $this->assertContains(ImmutableInvariantRegistryService::GATE_PROVIDER_PROOF, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_NO_SCAFFOLD, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_MEASURED_OR_REVERTED, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_ADVERSARIAL_PROOF_PANEL, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_ZERO_PROVIDER_PREFLIGHT, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_HONEST_STOP, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_PROPOSAL_ONLY_GATING, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_EXHAUSTION_RARITY, $gateIds);
        $this->assertContains(ImmutableInvariantRegistryService::GATE_REGISTRY_SELF, $gateIds);

        $this->assertTrue($registry['frozen']);
        $this->assertTrue($registry['read_only']);
        $this->assertFalse($registry['provider_invoked']);
    }
}
