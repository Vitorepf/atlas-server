<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Compounding;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Compounding\CausalLearningPromotionService;
use App\Services\Ai\Rivals\Core\RivalsClaimAuthority;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityDriftWorkProposer;
use PHPUnit\Framework\TestCase;

/**
 * Longitudinal Quality Foundry proof: evidence -> causal decision -> scoped
 * reversible change/task -> later outcome -> retain/revoke, with no claim leak.
 */
final class AtlasQualityFoundryLongitudinalCompoundingProofTest extends TestCase
{
    public function test_positive_routing_promotes_and_retains_when_later_outcome_is_non_adverse(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $gate = new CausalLearningGate;
        $verdict = $gate->adjudicate($candidate);
        $promotion = (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2');

        self::assertSame('promote_reversible', $verdict->verdict);
        self::assertSame('promoted', $promotion->status);
        self::assertSame('atlas.route', $promotion->scope);
        self::assertFalse($promotion->claimEligible);
    }

    public function test_confounded_evidence_holds_and_applies_zero_change(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'assignment_hash' => str_repeat('0', 64),
        ]));
        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('hold', $verdict->verdict);
    }

    public function test_code_candidate_emits_governed_task_and_never_promotes_directly(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'change_class' => 'code_task',
        ]));
        $verdict = (new CausalLearningGate)->adjudicate($candidate);

        self::assertSame('emit_code_task', $verdict->verdict);
        $this->expectExceptionMessage('causal_policy_promotion_refused');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'code-v2');
    }

    public function test_late_regression_rolls_back_and_requests_rivals_revocation_and_repair(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $promotionService = new CausalLearningPromotionService;
        $promotion = $promotionService->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2');
        $revoked = $promotionService->revoke($promotion, 'late_adverse_outcome');

        $claim = (new RivalsClaimAuthority)->issue($this->claimEvidence());
        $rivalsRevocation = [
            'claim_id' => $claim['claim_id'],
            'requested' => true,
            'reason' => 'late_adverse_outcome',
            'evidence_refs' => ['outcome://late-regression', 'causal://'.$candidate->candidateHash],
        ];
        $repair = (new AtlasExternalBrainCapabilityDriftWorkProposer)->propose([
            'findings' => [[
                'area_id' => 'atlas.route',
                'drift_type' => 'late_outcome_regression',
                'impact_level' => 'high',
                'evidence_needed' => ['late_outcome', 'rollback_receipt'],
            ]],
            'frozen_facts' => ['route_version' => 'route-v2', 'world_hash' => 'w1', 'claim_version' => 'c1'],
            'current_facts' => ['route_version' => 'route-v2', 'world_hash' => 'w2', 'claim_version' => 'c1', 'outcome' => 'failure'],
            'target_paths' => ['atlas.route' => 'app/Services/Ai/AtlasRoutingService.php'],
        ]);

        self::assertSame('revoked', $revoked->status);
        self::assertSame('route-v1', $revoked->activeVersion);
        self::assertTrue($rivalsRevocation['requested']);
        self::assertCount(1, $repair['proposals']);
        self::assertFalse($claim['status'] === 'revoked', 'observer requests Rivals review; it does not mutate the claim');
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'assignment_hash' => str_repeat('b', 64), 'experiment_hash' => str_repeat('c', 64), 'order_hash' => str_repeat('7', 64),
            'run_hash' => str_repeat('d', 64), 'release_hash' => str_repeat('e', 64), 'outcome_hash' => str_repeat('f', 64),
            'change_class' => 'routing', 'hypothesis' => 'route improves verified quality', 'baseline' => 'route-v1',
            'metric' => 'verified_quality', 'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.30,
            'confounders' => ['provider_drift' => 'controlled'], 'rollback' => 'route-v1', 'reversible' => true,
            'assignment_precedes_run' => true, 'real_outcome' => true, 'authority_hash' => str_repeat('1', 64),
            'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
        ];
    }

    /** @return array<string,mixed> */
    private function claimEvidence(): array
    {
        return [
            'adjudication_status' => 'passed', 'claim_level' => 'world_10x_quality_proven',
            'scope' => ['suite' => 'atlas-bench', 'mode' => 'atlas', 'risk' => 'R3', 'duration' => 'durable_task'],
            'baseline_hash' => str_repeat('a', 64), 'evidence_pack_hash' => str_repeat('b', 64), 'experiment_hash' => str_repeat('c', 64),
            'effect' => 0.20, 'ci_low' => 0.08, 'ci_high' => 0.32, 'exposure' => ['campaigns' => 3, 'attempts' => 300],
            'issued_at' => '2026-07-12T00:00:00Z', 'expires_at' => '2026-10-10T00:00:00Z',
            'invalidators' => ['frontier_change', 'late_adverse_outcome'], 'evidence_refs' => ['rivals://pack/b'],
        ];
    }
}
