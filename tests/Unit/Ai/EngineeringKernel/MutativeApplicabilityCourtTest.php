<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\EngineeringKernel;

use App\Services\Ai\EngineeringKernel\CandidateQualityCase;
use App\Services\Ai\EngineeringKernel\CanonicalKernelPayload;
use App\Services\Ai\EngineeringKernel\EngineeringQualityCourt;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
use App\Services\Ai\EngineeringKernel\MutativeVerificationReference;
use App\Services\Ai\EngineeringKernel\VerifiedMutativeCandidate;
use Tests\TestCase;

final class MutativeApplicabilityCourtTest extends TestCase
{
    public function test_court_accepts_only_frozen_explicit_mutative_na_and_binds_the_case(): void
    {
        $matrixBase = [
            'documentation_dx' => [
                'status' => 'not_applicable',
                'rule' => 'no_public_contract_change',
                'justification' => 'candidate does not touch public documentation or API contract',
            ],
        ];
        $matrixHash = CanonicalKernelPayload::hash($matrixBase);
        $matrix = $matrixBase;
        $matrix['documentation_dx']['evidence_hash'] = CanonicalKernelPayload::hash([
            'role' => 'documentation_dx', 'rule' => $matrixBase['documentation_dx']['rule'],
            'justification' => $matrixBase['documentation_dx']['justification'], 'matrix_hash' => $matrixHash,
        ]);
        $order = ExecutionOrder::fromArray($this->orderData($matrix, $matrixHash));
        $candidateHash = hash('sha256', 'candidate');
        $candidate = new VerifiedMutativeCandidate(
            'behaviorally_verified_pending_quality_court', $order->canonicalHash(), $candidateHash,
            $order->baseCommit, hash('sha256', 'tree'), hash('sha256', 'diff'), ['app/Candidate.php'],
            '/tmp/atlas-mutative-court', ['status' => 'ok'], ['status' => 'applied'], 'verification-run',
            hash('sha256', 'verification'), 'provider', 'author', 'verifier', ['mutative_22_role_court_receipt_absent'], false,
        );
        $verification = new MutativeVerificationReference(
            $candidate->verificationRunId, $candidate->verificationHash, $candidate->candidateHash,
            $candidate->providerIdentity, $candidate->authorIdentity, $candidate->verifierIdentity,
        );
        $factory = \Closure::bind(
            static fn (...$arguments): CandidateQualityCase => new CandidateQualityCase(...$arguments),
            null,
            CandidateQualityCase::class,
        );
        $case = $factory($order, $candidate, $verification, 'engagement', 'cycle', hash('sha256', 'case'));

        $disposition = app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, 'documentation_dx');

        $this->assertSame('not_applicable', $disposition->status);
        $this->assertSame('no_public_contract_change', $disposition->applicabilityRule);
        $this->assertSame('candidate does not touch public documentation or API contract', $disposition->justification);
        $this->assertSame($case->candidate->candidateHash, $disposition->candidateHash);
    }

    /** @param array<string,array<string,string>> $matrix @return array<string,mixed> */
    private function orderData(array $matrix, string $matrixHash): array
    {
        $roles = array_fill_keys(EngineeringRoleRoster::OFFICIAL_ROLES, ['depth' => 'standard', 'independent' => true]);
        return [
            'schema_version' => 'atlas.execution_order.v2', 'run_id' => 'run-court-na', 'delivery_id' => 'delivery-court-na',
            'mode' => 'dev', 'risk_class' => 'R2', 'complexity_band' => 'C2', 'duration_regime' => 'interactive',
            'work_topology' => 'single', 'product_intent_verdict_hash' => hash('sha256', 'intent'),
            'spec_hash' => hash('sha256', 'spec'), 'world_model_snapshot_hash' => hash('sha256', 'world'),
            'workspace' => '/tmp/atlas-court-na', 'base_commit' => str_repeat('a', 40),
            'allowed_scope' => ['app/Candidate.php'], 'forbidden_scope' => ['.env'],
            'authority_envelope' => ['kind' => 'mutative'], 'decision_receipt' => ['decision_event_id' => 'decision-court-na'],
            'operator_contract' => ['presence' => 'intent_and_authority'], 'role_roster' => $roles,
            'provider_route' => ['provider' => 'fixture', 'model' => 'fixture'], 'tool_permissions' => ['read' => true, 'mutate' => true],
            'evidence_policy' => [
                'acceptance_event_id' => 'acceptance-court-na',
                'role_disposition_event_ids' => array_fill_keys(EngineeringRoleRoster::OFFICIAL_ROLES, 'role-court-na'),
                'mutative_applicability' => $matrix, 'mutative_applicability_hash' => $matrixHash,
            ],
            'release_policy' => ['kind' => 'git_scoped_commit_canary'],
            'rollback_policy' => ['kind' => 'git_revert_scoped_commit', 'restore_strategy' => 'git revert', 'verification_command' => 'php artisan test'],
            'outcome_policy' => ['windows' => ['0h', '24h', '7d', '30d', '90d', '150d']],
            'experiment_ref' => 'experiment-court-na', 'idempotency_key' => 'idempotency-court-na',
            'budget_posture' => 'unbounded_quality_first',
        ];
    }
}
