<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyRoleRun;
use App\Models\AiRealExecutionPatchRun;
use App\Models\AiRealExecutionTestRun;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringCompany\EngineeringCompanyHash;
use App\Services\Ai\Kernel\Decision\DecisionReceipt;
use App\Services\Ai\Kernel\Decision\DecisionReceiptRuntimeGuard;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\RealExecution\RealExecutionHash;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorAdmissionPolicy;
use App\Services\Ai\SelfConstruction\MergeGovernor\AtlasMergeGovernorReleaseDecisionLedger;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class KernelEvidenceAuthority
{
    public const EMITTER_STAGE = 'atlas.engineering_kernel.evidence_authority';

    public const SCHEMA = 'atlas.engineering_kernel.evidence_authority.v1';

    public function __construct(
        private readonly AtlasEvidenceLedger $ledger,
        private readonly DecisionReceiptRuntimeGuard $decisionGuard,
        private readonly ?AtlasMergeGovernorReleaseDecisionLedger $governorReleaseLedger = null,
    ) {}

    /** @param array<string,mixed> $context */
    public function issueDecision(DecisionReceipt $receipt, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        if ($this->decisionGuard->violationForReceipt(['receipt_v2' => $receipt->toArray()]) !== null) {
            throw new InvalidArgumentException('kernel_decision_receipt_guard_refused');
        }
        $expectedMetadata = [
            'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(),
            'spec_hash' => $order->specHash, 'roster_hash' => CanonicalKernelPayload::hash($order->roleRoster),
            'mode' => $order->mode,
        ];
        $flow = ['dev' => 'atlas.dev', 'forge' => 'atlas.forge', 'autonomos' => 'atlas.autonomos'][$order->mode];
        $risk = in_array($order->riskClass, ['R0', 'R1'], true) ? 'low' : (in_array($order->riskClass, ['R2', 'R3'], true) ? 'medium' : ($order->riskClass === 'R4' ? 'high' : 'critical'));
        if ($receipt->envelopeId !== $order->runId || $receipt->flow !== $flow || $receipt->risk !== $risk
            || array_intersect_key($receipt->metadata, $expectedMetadata) !== $expectedMetadata) {
            throw new InvalidArgumentException('kernel_decision_receipt_order_binding_invalid');
        }

        return $this->issue('decision', LedgerEventType::DecisionIssued, $this->bindingPayload($order) + [
            'event_name' => 'decision.issued',
            'authority_hash' => CanonicalKernelPayload::hash($order->authorityEnvelope),
            'role_roster' => $order->roleRoster,
            'decision_receipt_hash' => $receipt->receiptHash,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    /** @param list<AiEngineeringCompanyRoleRun> $roleRuns @param array<string,mixed> $context */
    public function issueEvidenceBundle(AiRealExecutionTestRun $testRun, array $roleRuns, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        $persisted = $this->persistedTestRun($testRun, $order);
        $derived = $this->bundleArray(app(EngineeringQualityCourt::class)->buildBundle($order, $persisted, $roleRuns));

        return $this->issue('evidence_bundle', LedgerEventType::EvidencePacked, $this->bindingPayload($order) + [
            'event_name' => 'evidence.bundle.recorded',
            'acceptance_bundle' => $derived,
            'test_run_id' => $persisted->test_run_id, 'test_hash' => $persisted->test_hash,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueRoleDisposition(AiEngineeringCompanyRoleRun $roleRun, ExecutionOrder $order, array $context): AtlasLedgerEvent
    {
        if (! $roleRun->exists || $roleRun->getKey() === null) {
            throw new InvalidArgumentException('kernel_role_run_not_persisted');
        }
        $roleRun = AiEngineeringCompanyRoleRun::query()->find($roleRun->getKey());
        if (! $roleRun instanceof AiEngineeringCompanyRoleRun) {
            throw new InvalidArgumentException('kernel_role_run_not_persisted');
        }
        $role = (string) $roleRun->getAttribute('role_id');
        $roleHash = (string) $roleRun->getAttribute('role_hash');
        $evidenceRefs = $roleRun->getAttribute('evidence_refs');
        $output = $roleRun->getAttribute('output');
        $receipt = $roleRun->getAttribute('receipt');
        $expectedBinding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash,
            'engagement_record_id' => (string) $roleRun->engagement_record_id,
            'cycle_record_id' => (string) $roleRun->cycle_record_id];
        if (! in_array($role, EngineeringRoleRoster::OFFICIAL_ROLES, true)
            || preg_match('/^[a-f0-9]{64}$/', $roleHash) !== 1 || ! is_array($evidenceRefs) || $evidenceRefs === []
            || ! is_array($output) || ! is_array($output['disposition'] ?? null) || ! is_array($receipt)) {
            throw new InvalidArgumentException('kernel_role_run_invalid');
        }
        $unsigned = $receipt;
        $receiptHash = (string) ($unsigned['hash'] ?? '');
        unset($unsigned['hash']);
        $verificationHash = is_string($evidenceRefs[0] ?? null) && str_starts_with($evidenceRefs[0], 'verification:') ? substr($evidenceRefs[0], 13) : '';
        $verification = AiRealExecutionTestRun::query()->where('test_hash', $verificationHash)->first();
        if (! hash_equals($roleHash, $receiptHash) || ! hash_equals($roleHash, EngineeringCompanyHash::make($unsigned))
            || ($unsigned['role_id'] ?? null) !== $role || ($unsigned['status'] ?? null) !== $roleRun->status
            || ($unsigned['evidence_refs'] ?? null) !== $evidenceRefs || ($unsigned['output'] ?? null) !== $output
            || ($unsigned['binding'] ?? null) !== $expectedBinding
            || ($unsigned['disposition'] ?? null) !== $output['disposition']
            || ! $verification instanceof AiRealExecutionTestRun
            || ! app(EngineeringQualityCourt::class)->dispositionValid($order, $verification, $role, $output['disposition'])
            || ! $this->producerSealValid($unsigned, AtlasRealEngineeringCompanyRuntimeService::QUALITY_ROLE_PRODUCER, false)) {
            throw new InvalidArgumentException('kernel_role_run_receipt_binding_invalid');
        }

        return $this->issue('role_disposition', LedgerEventType::GateEvaluated, $this->bindingPayload($order) + [
            'event_name' => 'role.disposition.recorded', 'role' => $role,
            'role_hash' => $roleHash, 'evidence_refs' => $evidenceRefs, 'disposition' => $output['disposition'],
        ], $context);
    }

    /** @param array<string,mixed> $context */
    public function issueMutativeRoleDisposition(AiEngineeringCompanyRoleRun $roleRun, CandidateQualityCase $case, array $context): AtlasLedgerEvent
    {
        $persisted = $roleRun->exists ? AiEngineeringCompanyRoleRun::query()->find($roleRun->getKey()) : null;
        if (! $persisted instanceof AiEngineeringCompanyRoleRun) {
            throw new InvalidArgumentException('kernel_mutative_role_receipt_binding_invalid');
        }
        $receipt = $persisted->getAttribute('receipt');
        $output = $persisted->getAttribute('output');
        if (! is_array($receipt) || ! is_array($output) || ! is_array($output['disposition'] ?? null)) {
            throw new InvalidArgumentException('kernel_mutative_role_receipt_binding_invalid');
        }
        $role = (string) $persisted->role_id;
        $receiptDomain = (string) data_get($receipt, 'owner_domain', '');
        // Scoped Dev matrix N/A persists frontend/mobile/performance under the
        // mutative absence domain (not the specialized owner domain). Prefer the
        // receipt's owner_domain so issueMutativeRoleDisposition does not reject
        // honest N/A rows as binding-invalid.
        $domain = match ($role) {
            'final_certification' => EngineeringFinalCertifier::MUTATIVE_DOMAIN,
            'qa_testing' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN,
            'architecture' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN,
            'data' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_DATA_OWNER_DOMAIN,
            'appsec_privacy' => AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN,
            'performance_resilience' => $receiptDomain === EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                ? EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                : AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN,
            'backend' => $receiptDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN
                ? AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN
                : EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN,
            'frontend', 'mobile' => $receiptDomain === EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                ? EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN
                : AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role),
            default => EngineeringQualityCourt::MUTATIVE_ABSENCE_DOMAIN,
        };
        if (! $this->mutativeRoleReceiptValid($persisted, $case, $domain, 'v1')) {
            // Sem o papel no erro é impossível saber qual dos 22 receipts recusou.
            throw new InvalidArgumentException('kernel_mutative_role_receipt_binding_invalid:'.$role);
        }
        $hash = (string) $persisted->role_hash;

        return $this->issue('role_disposition', LedgerEventType::GateEvaluated, $this->bindingPayload($case->order) + [
            'event_name' => 'role.mutative_disposition.recorded', 'case_hash' => $case->caseHash,
            'candidate_hash' => $case->candidate->candidateHash, 'diff_hash' => $case->candidate->diffHash,
            'tree_hash' => $case->candidate->treeHash, 'role' => $role,
            'role_hash' => $hash, 'disposition' => $output['disposition'],
        ], $context);
    }

    public function mutativeRoleReceiptValid(AiEngineeringCompanyRoleRun $persisted, CandidateQualityCase $case, string $expectedDomain, string $expectedVersion): bool
    {
        $receipt = $persisted->getAttribute('receipt');
        $output = $persisted->getAttribute('output');
        if (! is_array($receipt) || ! is_array($output) || ! is_array($output['disposition'] ?? null)) {
            return false;
        }
        $role = (string) $persisted->role_id;
        if ($role === 'qa_testing') {
            return $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_DOMAIN
                && $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_QA_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateQaOwnerReceiptValid($persisted, $case);
        }
        if ($role === 'architecture') {
            return $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_ARCHITECTURE_OWNER_DOMAIN
                && $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_ARCHITECTURE_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateArchitectureOwnerReceiptValid($persisted, $case);
        }
        if ($role === 'data') {
            return $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_DATA_OWNER_DOMAIN
                && $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_DATA_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateDataOwnerReceiptValid($persisted, $case);
        }
        if ($role === 'appsec_privacy') {
            return $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_DOMAIN
                && $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_APPSEC_PRIVACY_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateAppsecPrivacyOwnerReceiptValid($persisted, $case);
        }
        if ($role === 'performance_resilience'
            && $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_DOMAIN) {
            return $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_PERFORMANCE_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidatePerformanceOwnerReceiptValid($persisted, $case);
        }
        if ($role === 'backend' && $expectedDomain === AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_DOMAIN) {
            return $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_BACKEND_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateBackendOwnerReceiptValid($persisted, $case);
        }
        // Matrix N/A for frontend/mobile lands on mutative absence domain — do not
        // force the specialized surface owner validator on those rows.
        if (in_array($role, ['frontend', 'mobile'], true)
            && $expectedDomain === AtlasRealEngineeringExecutionKernelService::surfaceApplicabilityOwnerDomain($role)) {
            return $expectedVersion === AtlasRealEngineeringExecutionKernelService::CANDIDATE_SURFACE_APPLICABILITY_OWNER_VERSION
                && app(AtlasRealEngineeringExecutionKernelService::class)->candidateSurfaceApplicabilityOwnerReceiptValid($persisted, $case, $role);
        }
        $expectedBinding = [
            'run_id' => $case->order->runId, 'delivery_id' => $case->order->deliveryId,
            'order_hash' => $case->order->canonicalHash(), 'spec_hash' => $case->order->specHash,
            'case_hash' => $case->caseHash, 'candidate_hash' => $case->candidate->candidateHash,
            'diff_hash' => $case->candidate->diffHash, 'tree_hash' => $case->candidate->treeHash,
            'engagement_record_id' => $case->engagementRecordId,
            'cycle_record_id' => $case->cycleRecordId,
        ];
        $issuedAt = (string) ($receipt['issued_at'] ?? '');
        $expiresAt = (string) ($receipt['expires_at'] ?? '');
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, $issuedAt);
            $expires = CarbonImmutable::createFromFormat(DATE_ATOM, $expiresAt);
        } catch (\Throwable) {
            return false;
        }
        $disposition = $output['disposition'];
        $expectedTypedReceipt = RoleEvidenceReceipt::issue(
            $case,
            $role === 'final_certification'
                ? app(EngineeringFinalCertifier::class)->certifyCandidate($case)
                : app(EngineeringQualityCourt::class)->adjudicateMutativeRole($case, $role),
            $expectedDomain, $expectedVersion, $issuedAt, $expiresAt,
            ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash],
        )->toArray();
        $sealedReceipt = array_diff_key($receipt, ['hash' => true]);
        if (($receipt['purpose'] ?? null) !== 'mutative_candidate_quality_adjudication'
            || ($receipt['owner_domain'] ?? null) !== $expectedDomain || ($receipt['owner_version'] ?? null) !== $expectedVersion
            || $issued === null || $expires === null || CarbonImmutable::now()->lt($issued) || CarbonImmutable::now()->gte($expires)
            || ($receipt['role_id'] ?? null) !== $role || ($receipt['status'] ?? null) !== $persisted->status
            || ($disposition['role'] ?? null) !== $role
            || ($receipt['binding'] ?? null) !== $expectedBinding
            || ($receipt['evidence_refs'] ?? null) !== ['candidate:'.$case->candidate->candidateHash, 'verification:'.$case->verification->receiptHash]
            || ($receipt['output'] ?? null) !== $output || ($receipt['disposition'] ?? null) !== ($output['disposition'] ?? null)
            || ($output['role_evidence_receipt'] ?? null) !== $expectedTypedReceipt
            || ($role === 'final_certification'
                ? ! app(EngineeringFinalCertifier::class)->mutativeDispositionValid($case, $disposition)
                : ! app(EngineeringQualityCourt::class)->mutativeDispositionValid($case, $role, $disposition))
            || ! $this->producerSealValid($sealedReceipt, $expectedDomain, false)) {
            return false;
        }
        $unsigned = $receipt;
        $hash = (string) ($unsigned['hash'] ?? '');
        $unsigned = array_diff_key($unsigned, ['hash' => true]);
        if (! hash_equals((string) $persisted->role_hash, $hash) || ! hash_equals($hash, EngineeringCompanyHash::make($unsigned))) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $context */
    public function issueAcceptanceVerdict(CertVerdict $verdict, ExecutionOrder $order, AtlasLedgerEvent $evidenceEvent, array $context): AtlasLedgerEvent
    {
        return $this->issue('acceptance', LedgerEventType::GateEvaluated, $this->bindingPayload($order) + [
            'event_name' => 'acceptance.adjudicated', 'verdict' => $verdict->toArray(),
            'evidence_event_id' => $evidenceEvent->event_id,
            'evidence_event_hash' => (string) $evidenceEvent->getAttribute('event_hash'),
        ], $context);
    }

    public function issueReleaseAuthorization(CanonicalReleaseAuthorizationRequest $request): AtlasLedgerEvent
    {
        $row = null;
        foreach ($this->trustedGovernorReleaseLedger()->all() as $candidate) {
            if (hash_equals((string) ($candidate['decision_hash'] ?? ''), $request->decisionHash)) {
                $row = $candidate;
                break;
            }
        }
        $binding = is_array($row['prepare_binding'] ?? null) ? $row['prepare_binding'] : [];
        $expectedBinding = [
            'task_packet_id' => $request->taskPacketId,
            'action' => $request->action,
            'candidate_hash' => $request->candidateHash,
            'verification_hash' => $request->verificationHash,
            'rollback_hash' => $request->rollbackHash,
            'changed_files' => $request->files,
            'scope_hash' => $request->scopeHash,
            'base_commit' => $request->baseCommit,
            'tree_hash' => $request->treeHash,
            'lease_id' => $request->leaseId,
            'lease_owner' => $request->leaseOwner,
            'fencing_token' => $request->fencingToken,
        ];
        if ($request->requiresCanarySettlement) {
            $expectedBinding += [
                'order_hash' => $request->orderHash,
                'delivery_id' => $request->deliveryId,
                'evidence_hash' => $request->evidenceHash,
            ];
        }
        if (! is_array($row)
            || ($row['schema'] ?? null) !== AtlasMergeGovernorReleaseDecisionLedger::SCHEMA
            || ($row['decision'] ?? null) !== AtlasMergeGovernorAdmissionPolicy::DECISION_ADMITTED
            || ! hash_equals((string) ($row['decision_hash'] ?? ''), $request->decisionHash)
            || $binding !== $expectedBinding
            || ! hash_equals((string) ($row['candidate_hash'] ?? ''), $request->candidateHash)
            || ! hash_equals((string) ($row['verification_hash'] ?? ''), $request->verificationHash)
            || ! hash_equals((string) ($row['rollback_hash'] ?? ''), $request->rollbackHash)
            || ! str_starts_with((string) ($row['rollback_posture'] ?? ''), 'revertible:')) {
            throw new InvalidArgumentException('release_authorization_governor_decision_not_persisted_admitted');
        }

        $credentialScope = [
            'action' => $request->action,
            'files' => $request->files,
            'scope_hash' => $request->scopeHash,
            'nonce' => $request->nonce,
            'expires_at' => $request->expiresAt,
            'one_effect' => true,
        ];

        return $this->issue('release_authorization', LedgerEventType::ReleaseAuthorized, [
            'event_name' => 'release.authorized',
            'task_packet_id' => (string) ($row['task_packet_id'] ?? ''),
            'action' => $request->action,
            'candidate_hash' => (string) ($row['candidate_hash'] ?? ''),
            'decision_hash' => (string) ($row['decision_hash'] ?? ''),
            'verification_hash' => (string) ($row['verification_hash'] ?? ''),
            'rollback_hash' => (string) ($row['rollback_hash'] ?? ''),
            'rollback_posture' => (string) ($row['rollback_posture'] ?? ''),
            'changed_files' => $request->files,
            'scope_hash' => $request->scopeHash,
            'base_commit' => $request->baseCommit,
            'tree_hash' => $request->treeHash,
            'lease_id' => $request->leaseId,
            'lease_owner' => $request->leaseOwner,
            'fencing_token' => $request->fencingToken,
            'order_hash' => $request->orderHash,
            'delivery_id' => $request->deliveryId,
            'evidence_hash' => $request->evidenceHash,
            'nonce' => $request->nonce,
            'issued_at' => $request->issuedAt,
            'expires_at' => $request->expiresAt,
            'credential_scope' => $credentialScope,
            'credential_hash' => CanonicalKernelPayload::hash($credentialScope),
        ], $request->context, 300);
    }

    private function trustedGovernorReleaseLedger(): AtlasMergeGovernorReleaseDecisionLedger
    {
        return $this->governorReleaseLedger ?? new AtlasMergeGovernorReleaseDecisionLedger(
            storage_path('atlas/governance/merge-governor-release-decision-ledger.jsonl'),
        );
    }

    public function verifyReleaseAuthorization(AtlasLedgerEvent $event): bool
    {
        if (! $this->verifyEvent($event, 'release_authorization')) {
            return false;
        }

        $payload = is_array($event->payload) ? $event->payload : [];
        $scope = $payload['credential_scope'] ?? null;

        return is_array($scope)
            && ($scope['one_effect'] ?? false) === true
            && is_array($scope['files'] ?? null)
            && trim((string) ($scope['action'] ?? '')) !== ''
            && preg_match('/^[a-f0-9]{64}$/', (string) ($scope['scope_hash'] ?? '')) === 1
            && trim((string) ($scope['nonce'] ?? '')) !== ''
            && trim((string) ($scope['expires_at'] ?? '')) !== ''
            && hash_equals((string) ($payload['credential_hash'] ?? ''), CanonicalKernelPayload::hash($scope));
    }

    /** @param array<string,mixed> $receipt */
    public function verifyMutativeVerificationReceipt(array $receipt): bool
    {
        $hash = (string) ($receipt['hash'] ?? '');
        unset($receipt['hash']);

        return $hash !== '' && hash_equals($hash, RealExecutionHash::make($receipt))
            && $this->producerSealValid($receipt, AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER, true);
    }

    /** @param array<string,mixed> $observation */
    public function issueCanaryObservation(CanarySettlementRequest $request, array $observation, string $phase): AtlasLedgerEvent
    {
        if (! in_array($phase, ['observed', 'terminal'], true)
            || ! in_array((string) ($observation['verdict'] ?? ''), ['canary_pass', 'canary_fail_attributed', 'canary_inconclusive'], true)
            || ! in_array((string) ($observation['status'] ?? ''), ['pending', 'settled', 'reverted', 'release_uncertain'], true)) {
            throw new InvalidArgumentException('canonical_canary_observation_invalid');
        }

        return $this->issue($phase === 'terminal' ? 'terminal_outcome' : 'canary_observation', LedgerEventType::GateEvaluated, $request->binding() + [
            'event_name' => 'release.canary_'.$phase, 'canary_request_hash' => $request->idempotencyHash(),
            'phase' => $phase, 'observation' => $observation,
        ], ['event_id' => 'canary-'.substr(hash('sha256', $phase.':'.$request->idempotencyHash()), 0, 24),
            'correlation_id' => $request->action->nonce, 'causation_id' => $request->landedEventId,
            'scope_type' => 'task_packet', 'scope_id' => $request->action->taskPacketId], $phase === 'terminal' ? 0 : 3600);
    }

    /** @param list<string> $files */
    public function issueRevertSettlement(AuthorizedMergeAction $action, string $targetSha, string $revertSha, array $files): AtlasLedgerEvent
    {
        if ($action->action !== 'revert_task' || preg_match('/^[a-f0-9]{40}$/', $targetSha) !== 1
            || preg_match('/^[a-f0-9]{40}$/', $revertSha) !== 1 || $files !== $action->files) {
            throw new InvalidArgumentException('canonical_revert_settlement_invalid');
        }

        return $this->issue('revert_settlement', LedgerEventType::ReleaseReverted, [
            'event_name' => 'release.reverted', 'task_packet_id' => $action->taskPacketId,
            'authorization_event_id' => $action->canonicalEventId,
            'authorization_event_hash' => $action->canonicalEventHash, 'nonce' => $action->nonce,
            'target_sha' => $targetSha, 'revert_sha' => $revertSha, 'changed_files' => $files,
            'scope_hash' => $action->scopeHash, 'order_hash' => $action->orderHash,
            'delivery_id' => $action->deliveryId, 'evidence_hash' => $action->evidenceHash,
        ], ['correlation_id' => $action->nonce, 'causation_id' => $action->canonicalEventId,
            'scope_type' => 'task_packet', 'scope_id' => $action->taskPacketId]);
    }

    public function issueProvisionalOutcome(EngineeringOutcome $outcome, AuthorizedMergeAction $action): AtlasLedgerEvent
    {
        $data = $outcome->toArray();
        if (! $this->verifyOutcome($data) || $outcome->deliveryId !== $action->deliveryId
            || ! hash_equals((string) ($outcome->correlatedHashes['order'] ?? ''), $action->orderHash)
            || ! hash_equals((string) ($outcome->correlatedHashes['evidence'] ?? ''), $action->evidenceHash)) {
            throw new InvalidArgumentException('provisional_engineering_outcome_binding_invalid');
        }

        return $this->issue('provisional_outcome', LedgerEventType::OperationCompleted, [
            'event_name' => 'engineering.outcome.provisional', 'task_packet_id' => $action->taskPacketId,
            'candidate_hash' => $action->candidateHash, 'order_hash' => $action->orderHash,
            'delivery_id' => $action->deliveryId, 'evidence_hash' => $action->evidenceHash,
            'outcome_hash' => $outcome->outcomeHash, 'outcome' => $data,
        ], ['correlation_id' => $action->nonce, 'causation_id' => $action->canonicalEventId,
            'scope_type' => 'engineering_delivery', 'scope_id' => $action->deliveryId]);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $context */
    private function issue(string $kind, LedgerEventType $type, array $payload, array $context, int $validForSeconds = 3600): AtlasLedgerEvent
    {
        $this->assertAllowed($kind, $type);
        $now = CarbonImmutable::now()->startOfSecond();
        $payload['_authority'] = $this->seal($kind, $payload, $now,
            $validForSeconds > 0 ? $now->addSeconds($validForSeconds) : null);
        $event = $this->ledger->record($type, $payload, array_replace($context, [
            'emitter_stage' => self::EMITTER_STAGE,
            'emitter_version' => self::SCHEMA,
        ]));
        if ($event === null) {
            throw new \RuntimeException('kernel_evidence_authority_ledger_unavailable');
        }

        return $event;
    }

    public function verifyEvent(AtlasLedgerEvent $event, string $kind): bool
    {
        $expectedType = match ($kind) {
            'decision' => LedgerEventType::DecisionIssued,
            'evidence_bundle' => LedgerEventType::EvidencePacked,
            'acceptance', 'role_disposition', 'canary_observation', 'terminal_outcome' => LedgerEventType::GateEvaluated,
            'release_authorization' => LedgerEventType::ReleaseAuthorized,
            'revert_settlement' => LedgerEventType::ReleaseReverted,
            'provisional_outcome' => LedgerEventType::OperationCompleted,
            default => null,
        };
        if ($expectedType === null || $event->event_type !== $expectedType->value
            || $event->emitter_stage !== self::EMITTER_STAGE
            || $event->emitter_version !== self::SCHEMA
            || ! $this->ledger->eventIntegrityValid($event)) {
            return false;
        }
        $payload = $event->getAttribute('payload');
        if (! is_array($payload) || ! is_array($payload['_authority'] ?? null)) {
            return false;
        }
        $authority = $payload['_authority'];
        unset($payload['_authority']);

        return $this->verifySeal($authority, $kind, $payload);
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    public function sealOutcome(array $outcome): array
    {
        $now = CarbonImmutable::now()->startOfSecond();

        return $this->seal('engineering_outcome', $this->outcomePayload($outcome), $now, null);
    }

    /** @param array<string,mixed> $outcome */
    public function verifyOutcome(array $outcome): bool
    {
        $authority = data_get($outcome, 'evidence_bundle.authority');

        return is_array($authority) && $this->verifySeal($authority, 'engineering_outcome', $this->outcomePayload($outcome));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function seal(string $kind, array $payload, CarbonImmutable $issuedAt, ?CarbonImmutable $expiresAt): array
    {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'kind' => $kind,
            'issued_at' => $issuedAt->format(DATE_ATOM),
            'expires_at' => $expiresAt?->format(DATE_ATOM),
            'provenance' => 'kernel_evidence_authority',
            'key_id' => $this->currentKeyId(),
            'payload_hash' => CanonicalKernelPayload::hash($payload),
        ];
        $envelope['signature'] = hash_hmac('sha256', CanonicalKernelPayload::hash($envelope), $this->keyring()[$this->currentKeyId()]);

        return $envelope;
    }

    /** @param array<string,mixed> $authority @param array<string,mixed> $payload */
    private function verifySeal(array $authority, string $kind, array $payload): bool
    {
        try {
            $issued = CarbonImmutable::createFromFormat(DATE_ATOM, CanonicalKernelPayload::requireString($authority, 'issued_at'));
            $expiresRaw = $authority['expires_at'] ?? null;
            $expires = is_string($expiresRaw) ? CarbonImmutable::createFromFormat(DATE_ATOM, $expiresRaw) : null;
            $signature = CanonicalKernelPayload::requireHash($authority, 'signature');
        } catch (InvalidArgumentException) {
            return false;
        }
        if ($issued === null || CarbonImmutable::now()->lt($issued)
            || (! in_array($kind, ['engineering_outcome', 'terminal_outcome'], true)
                && ($expires === null || CarbonImmutable::now()->gte($expires)))
            || (in_array($kind, ['engineering_outcome', 'terminal_outcome'], true) && $expiresRaw !== null)
            || ($authority['schema_version'] ?? null) !== self::SCHEMA || ($authority['kind'] ?? null) !== $kind
            || ($authority['provenance'] ?? null) !== 'kernel_evidence_authority'
            || ! hash_equals((string) ($authority['payload_hash'] ?? ''), CanonicalKernelPayload::hash($payload))) {
            return false;
        }
        $unsigned = $authority;
        unset($unsigned['signature']);

        $keyId = $authority['key_id'] ?? null;
        $key = is_string($keyId) ? $this->keyring()[$keyId] ?? null : null;

        return is_string($key) && hash_equals($signature, hash_hmac('sha256', CanonicalKernelPayload::hash($unsigned), $key));
    }

    /** @return array<string,string> */
    private function keyring(): array
    {
        $appKey = $this->applicationKey((string) config('app.key'));
        if ($appKey === '') {
            throw new \RuntimeException('kernel_evidence_authority_app_key_missing');
        }

        $keys = [$this->currentKeyId() => hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $appKey, true)];
        foreach ((array) config('atlas.engineering_kernel.evidence_authority.previous_keys', []) as $id => $key) {
            if (is_string($id) && $id !== '' && is_string($key) && $key !== '') {
                $keys[$id] = hash_hmac('sha256', 'atlas.engineering_kernel.evidence_authority.v1', $this->applicationKey($key), true);
            }
        }

        return $keys;
    }

    private function currentKeyId(): string
    {
        return 'app-key-'.substr(hash('sha256', $this->applicationKey((string) config('app.key'))), 0, 16);
    }

    private function applicationKey(string $key): string
    {
        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);

            return $decoded === false ? '' : $decoded;
        }

        return $key;
    }

    private function assertAllowed(string $kind, LedgerEventType $type): void
    {
        $allowed = ($kind === 'decision' && $type === LedgerEventType::DecisionIssued)
            || ($kind === 'evidence_bundle' && $type === LedgerEventType::EvidencePacked)
            || (in_array($kind, ['acceptance', 'role_disposition', 'canary_observation', 'terminal_outcome'], true)
                && $type === LedgerEventType::GateEvaluated);
        $allowed = $allowed || ($kind === 'release_authorization' && $type === LedgerEventType::ReleaseAuthorized);
        $allowed = $allowed || ($kind === 'revert_settlement' && $type === LedgerEventType::ReleaseReverted);
        $allowed = $allowed || ($kind === 'provisional_outcome' && $type === LedgerEventType::OperationCompleted);
        if (! $allowed) {
            throw new InvalidArgumentException('kernel_evidence_authority_kind_type_forbidden');
        }
    }

    /** @param array<string,mixed> $outcome @return array<string,mixed> */
    private function outcomePayload(array $outcome): array
    {
        unset($outcome['outcome_hash'], $outcome['evidence_bundle']['authority']);

        return $outcome;
    }

    /** @return array<string,mixed> */
    private function bindingPayload(ExecutionOrder $order): array
    {
        return [
            'delivery_id' => $order->deliveryId, 'order_hash' => $order->canonicalHash(),
            'spec_hash' => $order->specHash,
            'role_roster_catalog_hash' => CanonicalKernelPayload::hash($order->roleRoster),
        ];
    }

    /** @return array<string,mixed> */
    private function bundleArray(AcceptanceBundle $bundle): array
    {
        return [
            'criteria_hash' => $bundle->criteriaHash, 'frozen_hash' => $bundle->frozenHash,
            'changed_files' => $bundle->changedFiles, 'changed_public_symbols' => $bundle->changedPublicSymbols,
            'execution' => ['commands' => $bundle->execution->commands, 'claimed_status' => $bundle->execution->claimedStatus, 'tests_run' => $bundle->execution->testsRun, 'assertions_executed' => $bundle->execution->assertionsExecuted, 'selected_tests' => $bundle->execution->selectedTests, 'artifacts' => $bundle->execution->artifacts],
            'mutation_report' => $bundle->mutationReport, 'security_scan' => $bundle->securityScan,
            'judges' => $bundle->judges, 'context_sufficiency' => $bundle->contextSufficiency,
            'non_functional' => $bundle->nonFunctional, 'criteria' => $bundle->criteria, 'repair' => $bundle->repair,
        ];
    }

    private function persistedTestRun(AiRealExecutionTestRun $testRun, ExecutionOrder $order): AiRealExecutionTestRun
    {
        if (! $testRun->exists || $testRun->getKey() === null) {
            throw new InvalidArgumentException('kernel_test_run_not_persisted');
        }
        $persisted = AiRealExecutionTestRun::query()->find($testRun->getKey());
        $receipt = $persisted?->receipt;
        if (! $persisted instanceof AiRealExecutionTestRun || ! is_array($receipt)) {
            throw new InvalidArgumentException('kernel_test_run_not_persisted');
        }
        $unsigned = $receipt;
        $hash = (string) ($unsigned['hash'] ?? '');
        unset($unsigned['hash']);
        $binding = ['run_id' => $order->runId, 'delivery_id' => $order->deliveryId,
            'order_hash' => $order->canonicalHash(), 'spec_hash' => $order->specHash];
        $patch = AiRealExecutionPatchRun::query()->find($persisted->patch_run_record_id);
        $junitPath = data_get($unsigned, 'junit_artifact.path');
        $junitHash = data_get($unsigned, 'junit_artifact.sha256');
        if ($persisted->status !== 'passed' || $persisted->exit_code !== 0
            || ! hash_equals((string) $persisted->test_hash, $hash)
            || ! hash_equals($hash, RealExecutionHash::make($unsigned))
            || ($unsigned['binding'] ?? null) !== $binding
            || ($unsigned['test_run_id'] ?? null) !== $persisted->test_run_id
            || ($unsigned['status'] ?? null) !== $persisted->status
            || ($unsigned['selected_tests'] ?? null) !== $persisted->selected_tests
            || ($unsigned['evidence_refs'] ?? null) !== $persisted->evidence_refs
            || ($unsigned['goal_record_id'] ?? null) !== (string) $persisted->goal_record_id
            || ($unsigned['patch_run_record_id'] ?? null) !== (string) $persisted->patch_run_record_id
            || $persisted->goal_record_id === null || $persisted->patch_run_record_id === null
            || ! $patch instanceof AiRealExecutionPatchRun
            || ($unsigned['target'] ?? null) !== 'typed_contract_smoke'
            || ($unsigned['base_commit'] ?? null) !== $order->baseCommit
            || ($unsigned['patch_hash'] ?? null) !== $patch->patch_hash
            || ! is_string($junitPath) || ! is_file($junitPath) || ! is_string($junitHash)
            || ! hash_equals($junitHash, (string) hash_file('sha256', $junitPath))
            || ! is_array($unsigned['acceptance_bundle'] ?? null)
            || ! $this->producerSealValid($unsigned, AtlasRealEngineeringExecutionKernelService::KERNEL_VERIFICATION_PRODUCER, true)) {
            throw new InvalidArgumentException('kernel_test_run_receipt_binding_invalid');
        }
        $execution = $unsigned['acceptance_bundle']['execution'] ?? null;
        if (! is_array($execution) || ($execution['claimed_status'] ?? null) !== 'passed'
            || ($execution['selected_tests'] ?? null) !== $persisted->selected_tests
            || ! is_array($execution['commands'] ?? null) || $execution['commands'] === []
            || ! is_int($execution['tests_run'] ?? null) || $execution['tests_run'] < count((array) $persisted->selected_tests)
            || ! is_int($execution['assertions_executed'] ?? null) || $execution['assertions_executed'] < 1) {
            throw new InvalidArgumentException('kernel_test_run_execution_evidence_invalid');
        }

        return $persisted;
    }

    /** @param array<string,mixed> $receipt */
    private function producerSealValid(array $receipt, string $domain, bool $realExecution): bool
    {
        $producer = $receipt['producer'] ?? null;
        if (! is_array($producer) || ($producer['domain'] ?? null) !== $domain) {
            return false;
        }
        $unsigned = $receipt;
        unset($unsigned['producer']);
        $payloadHash = $realExecution ? RealExecutionHash::make($unsigned) : EngineeringCompanyHash::make($unsigned);
        if (! hash_equals((string) ($producer['payload_hash'] ?? ''), $payloadHash)) {
            return false;
        }
        $unsignedProducer = $producer;
        $signature = (string) ($unsignedProducer['signature'] ?? '');
        unset($unsignedProducer['signature']);
        $producerHash = $realExecution ? RealExecutionHash::make($unsignedProducer) : EngineeringCompanyHash::make($unsignedProducer);
        $key = $this->keyring()[(string) ($producer['key_id'] ?? '')] ?? null;

        return is_string($key) && hash_equals($signature, hash_hmac('sha256', $producerHash, hash_hmac('sha256', $domain, $key, true)));
    }
}
