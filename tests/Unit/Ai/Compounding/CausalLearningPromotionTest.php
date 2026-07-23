<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Compounding;

use App\Services\Ai\Compounding\CausalLearningCandidate;
use App\Services\Ai\Compounding\CausalLearningGate;
use App\Services\Ai\Compounding\CausalLearningPromotionService;
use App\Services\Ai\Compounding\CausalLearningRoutingPromotionOwner;
use App\Services\Ai\Compounding\CausalLearningVerdict;
use App\Services\Ai\AtlasDecide\AtlasConductorRoutingMemory;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainLateRegressionCoordinator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CausalLearningPromotionTest extends TestCase
{
    public function test_reversible_promotion_is_scoped_and_expiring(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $service = new CausalLearningPromotionService;
        $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));

        self::assertSame('promoted', $promotion->status);
        self::assertSame('atlas.route', $promotion->scope);
        self::assertSame('route-v1', $promotion->rollbackVersion);
        self::assertSame(['0h', '24h', '7d', '30d', '90d', '150d'], array_keys($promotion->observationSchedule));
        self::assertFalse($promotion->claimEligible);
    }

    public function test_promotion_and_revoke_apply_through_reversible_routing_owner_with_receipts(): void
    {
        $path = sys_get_temp_dir().'/atlas-causal-routing-'.bin2hex(random_bytes(5)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory;
        $routing->setLogPathForTesting($path);
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'task_category' => 'engineering', 'role' => 'worker', 'provider' => 'provider-a', 'model' => 'model-a',
        ]));
        $service = new CausalLearningPromotionService(null, new CausalLearningRoutingPromotionOwner($routing));

        try {
            $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));

            self::assertSame(CausalLearningRoutingPromotionOwner::OWNER, $promotion->owner);
            self::assertSame('route-v1', $promotion->beforeState['version']);
            self::assertSame('route-v2', $promotion->afterState['version']);
            self::assertSame('provider-a', $routing->preferredFor('engineering', 'worker')['provider']);
            self::assertSame(64, strlen($promotion->effectReceiptHash));

            $revoked = $service->revoke($promotion, 'late_regression');

            self::assertSame('route-v1', $revoked->activeVersion);
            self::assertSame('route-v2', $revoked->beforeState['version']);
            self::assertSame('route-v1', $revoked->afterState['version']);
            self::assertNull($routing->preferredFor('engineering', 'worker'));
            self::assertSame(64, strlen($revoked->effectReceiptHash));
        } finally {
            if (is_file($path)) @unlink($path);
            if (is_file($path.'.preferred.jsonl')) @unlink($path.'.preferred.jsonl');
        }
    }

    public function test_adverse_late_outcome_revokes_and_restores_previous_version(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $service = new CausalLearningPromotionService;
        $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));

        $revoked = $service->revoke($promotion, 'late_regression');

        self::assertSame('revoked', $revoked->status);
        self::assertSame('route-v1', $revoked->activeVersion);
    }

    public function test_late_regression_coordinator_uses_gate_then_rolls_back_owner_and_requests_claim_review(): void
    {
        $path = sys_get_temp_dir().'/atlas-causal-coordinator-'.bin2hex(random_bytes(5)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory;
        $routing->setLogPathForTesting($path);
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'task_category' => 'engineering', 'role' => 'worker', 'provider' => 'provider-a', 'model' => 'model-a',
        ]));
        $service = new CausalLearningPromotionService(null, new CausalLearningRoutingPromotionOwner($routing));

        try {
            $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));
            $result = (new AtlasExternalBrainLateRegressionCoordinator(promotions: $service))->handle($candidate, $promotion, [
                'observed_outcome' => 'failure', 'regression_threshold_crossed' => true, 'evidence_refs' => ['outcome:late-1'],
            ]);

            self::assertSame('revoked', $result['status']);
            self::assertSame('late_adverse_outcome', $result['decision']['reason']);
            self::assertSame('route-v1', $result['promotion']->activeVersion);
            self::assertNull($routing->preferredFor('engineering', 'worker'));
            self::assertTrue($result['response']['rivals_revocation_request']['required']);
        } finally {
            if (is_file($path)) @unlink($path);
            if (is_file($path.'.preferred.jsonl')) @unlink($path.'.preferred.jsonl');
        }
    }

    public function test_late_regression_without_evidence_is_held_without_rollback(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'task_category' => 'engineering', 'role' => 'worker', 'provider' => 'provider-a', 'model' => 'model-a',
        ]));
        $path = sys_get_temp_dir().'/atlas-causal-hold-'.bin2hex(random_bytes(5)).'.jsonl';
        $routing = new AtlasConductorRoutingMemory;
        $routing->setLogPathForTesting($path);
        $service = new CausalLearningPromotionService(null, new CausalLearningRoutingPromotionOwner($routing));

        try {
            $promotion = $service->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));
            $result = (new AtlasExternalBrainLateRegressionCoordinator(promotions: $service))->handle($candidate, $promotion, ['observed_outcome' => 'failure', 'regression_threshold_crossed' => true]);

            self::assertSame('hold', $result['status']);
            self::assertSame('provider-a', $routing->preferredFor('engineering', 'worker')['provider']);
        } finally {
            if (is_file($path)) @unlink($path);
            if (is_file($path.'.preferred.jsonl')) @unlink($path.'.preferred.jsonl');
        }
    }

    public function test_code_task_cannot_be_promoted_as_policy(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), ['change_class' => 'code_task']));

        $this->expectException(InvalidArgumentException::class);
        (new CausalLearningPromotionService)->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $this->artifacts($candidate));
    }

    public function test_verdict_from_another_candidate_cannot_be_reused(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $other = CausalLearningCandidate::fromArray(array_replace($this->candidate(), ['scope' => 'other.scope']));
        $verdict = (new CausalLearningGate)->adjudicate($other);

        $this->expectExceptionMessage('causal_verdict_stale_or_mismatched');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2', $this->artifacts($candidate));
    }

    public function test_claim_eligibility_cannot_be_escalated_by_learning_layer(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $gateVerdict = (new CausalLearningGate)->adjudicate($candidate);
        $verdict = new CausalLearningVerdict($gateVerdict->verdict, $gateVerdict->reason, $gateVerdict->decisionHash, true);

        $this->expectExceptionMessage('causal_claim_authority_escalation');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2', $this->artifacts($candidate));
    }

    public function test_expired_candidate_cannot_be_promoted_even_with_a_stale_green_verdict(): void
    {
        $candidate = CausalLearningCandidate::fromArray(array_replace($this->candidate(), [
            'expiry' => '2026-07-01T00:00:00Z',
        ]));
        $verdict = new CausalLearningVerdict(
            'promote_reversible',
            'causal_evidence_admitted',
            \App\Services\Ai\Compounding\CompoundingHash::make([
                'candidate' => $candidate->candidateHash,
                'verdict' => 'promote_reversible',
                'reason' => 'causal_evidence_admitted',
            ]),
        );

        $this->expectExceptionMessage('causal_policy_promotion_expired');
        (new CausalLearningPromotionService)->promote($candidate, $verdict, 'route-v2', $this->artifacts($candidate));
    }

    public function test_missing_or_invalid_live_artifact_blocks_promotion(): void
    {
        $candidate = CausalLearningCandidate::fromArray($this->candidate());
        $artifacts = $this->artifacts($candidate);
        $artifacts[0]['integrity_valid'] = false;

        $this->expectExceptionMessage('causal_evidence_binding_unresolved');
        (new CausalLearningPromotionService)->promote($candidate, (new CausalLearningGate)->adjudicate($candidate), 'route-v2', $artifacts);
    }

    /** @return array<string,mixed> */
    private function candidate(): array
    {
        return [
            'assignment_hash' => str_repeat('b', 64), 'experiment_hash' => str_repeat('c', 64), 'order_hash' => str_repeat('7', 64),
            'run_hash' => str_repeat('d', 64), 'release_hash' => str_repeat('e', 64), 'outcome_hash' => str_repeat('f', 64),
            'change_class' => 'routing', 'hypothesis' => 'route improves quality', 'baseline' => 'route-v1', 'metric' => 'quality',
            'window' => '7d', 'effect' => 0.18, 'ci_low' => 0.06, 'ci_high' => 0.3, 'confounders' => ['provider' => 'controlled'],
            'rollback' => 'route-v1', 'reversible' => true, 'assignment_precedes_run' => true, 'real_outcome' => true,
            'authority_hash' => str_repeat('1', 64), 'scope' => 'atlas.route', 'expiry' => '2026-08-01T00:00:00Z',
            'assignment_at' => '2026-07-12T00:00:00Z', 'release_at' => '2026-07-12T00:10:00Z',
            'run_at' => '2026-07-12T00:20:00Z', 'outcome_at' => '2026-07-12T01:00:00Z',
            'observation_schedule' => ['0h' => 'pending', '24h' => 'pending', '7d' => 'pending', '30d' => 'pending', '90d' => 'pending', '150d' => 'pending'],
            'binding_refs' => [
                'assignment' => ['hash' => str_repeat('b', 64), 'artifact_id' => 'assignment-1'],
                'experiment' => ['hash' => str_repeat('c', 64), 'artifact_id' => 'experiment-1'],
                'order' => ['hash' => str_repeat('7', 64), 'artifact_id' => 'order-1'],
                'run' => ['hash' => str_repeat('d', 64), 'artifact_id' => 'run-1'],
                'release' => ['hash' => str_repeat('e', 64), 'artifact_id' => 'release-1'],
                'outcome' => ['hash' => str_repeat('f', 64), 'artifact_id' => 'outcome-1'],
                'authority' => ['hash' => str_repeat('1', 64), 'artifact_id' => 'authority-1'],
            ],
        ];
    }

    /** @return list<array{artifact_id:string,hash:string,integrity_valid:bool}> */
    private function artifacts(CausalLearningCandidate $candidate): array
    {
        return array_values(array_map(
            static fn (array $ref): array => $ref + ['integrity_valid' => true],
            $candidate->data['binding_refs'],
        ));
    }
}
