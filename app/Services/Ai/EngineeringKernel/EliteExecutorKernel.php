<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AiEngineeringCompanyCycle;
use App\Models\AiEngineeringCompanyEngagement;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\EngineeringCompany\AtlasRealEngineeringCompanyRuntimeService;
use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskPostLandCanarySentinel;
use App\Services\Ai\SelfConstruction\Governance\AtlasTaskCommitGovernanceChain;
use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionHermeticSandboxApplyService;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shared elite kernel surface for Dev · Forge · Autônomos.
 *
 * Same quality bar; difference is operator presence + scale/duration (adapters).
 */
final class EliteExecutorKernel
{
    public const SCHEMA_VERSION = 'atlas.elite_executor_kernel.v1';

    public function __construct(
        private readonly OutcomeProofGate $outcomeProof,
        private readonly FalseClaimInvariant $falseClaim,
        private readonly SovereignHonestyFloor $honestyFloor,
        private readonly RepairDiagnosisStage $repairDiagnosis,
        private readonly AtlasDevGateAdapter $devAdapter,
        private readonly AtlasForgeGateAdapter $forgeAdapter,
        private readonly AtlasAutonomosGateAdapter $autonomosAdapter,
        private readonly ?AtlasEvidenceLedger $evidenceLedger = null,
        private readonly ?KernelEvidenceAuthority $evidenceAuthority = null,
        private readonly ?ProviderPort $providerPort = null,
        private readonly ?HermeticSandboxPort $mutativeSandbox = null,
        private readonly ?AtlasRealEngineeringExecutionKernelService $realExecution = null,
    ) {}

    /** @return array<string,mixed> */
    public function settleLandedRelease(CanarySettlementRequest $request, MergeActuator $actuator, AtlasTaskPostLandCanarySentinel $sentinel): array
    {
        $ledger = $this->evidenceLedger ?? app(AtlasEvidenceLedger::class);
        $authority = $this->evidenceAuthority ?? app(KernelEvidenceAuthority::class);
        $terminalId = 'canary-'.substr(hash('sha256', 'terminal:'.$request->idempotencyHash()), 0, 24);
        $terminal = $ledger->eventById($terminalId);
        if ($terminal instanceof AtlasLedgerEvent && $authority->verifyEvent($terminal, 'terminal_outcome')) {
            $observation = (array) data_get($terminal->payload, 'observation', []);
            $terminalOutcome = $observation['engineering_outcome'] ?? null;
            if (is_array($terminalOutcome) && $authority->verifyOutcome($terminalOutcome)) {
                return $observation + ['replayed' => true];
            }
        }
        $landed = $ledger->eventById($request->landedEventId);
        if (! $landed instanceof AtlasLedgerEvent || ! $ledger->eventIntegrityValid($landed)
            || $landed->event_type !== LedgerEventType::ReleaseLanded->value
            || ! $request->matchesCanonicalLandedEvent($landed)
            || data_get($landed->payload, 'commit_sha') !== $request->landedSha
            || data_get($landed->payload, 'task_packet_id') !== $request->action->taskPacketId
            || data_get($landed->payload, 'scope_hash') !== $request->action->scopeHash
            || data_get($landed->payload, 'order_hash') !== $request->orderHash
            || data_get($landed->payload, 'delivery_id') !== $request->deliveryId
            || data_get($landed->payload, 'evidence_hash') !== $request->evidenceHash
            || ! $this->canonicalReleaseBindingValid($ledger, $authority, $request, $landed)) {
            return $this->uncertainCanary($request, 'landed_release_binding_invalid');
        }
        $provisionalOutcome = $this->provisionalOutcome($ledger, $authority, $request);
        if (! $provisionalOutcome instanceof EngineeringOutcome) {
            return $this->uncertainCanary($request, 'provisional_engineering_outcome_invalid');
        }
        $observedId = 'canary-'.substr(hash('sha256', 'observed:'.$request->idempotencyHash()), 0, 24);
        $observed = $ledger->eventById($observedId);
        if ($observed instanceof AtlasLedgerEvent && $authority->verifyEvent($observed, 'canary_observation')) {
            return $this->persistUncertainCanary($authority, $request,
                'reconciliation_required_after_observed_effect', provisional: $provisionalOutcome) + ['replayed' => true];
        }
        try {
            $canary = $sentinel->observe($request->action->taskPacketId, $request->landedSha, $request->action->files);
        } catch (\Throwable $exception) {
            return $this->persistUncertainCanary($authority, $request, 'canary_observer_failed:'.$exception::class,
                provisional: $provisionalOutcome);
        }
        $verdict = (string) ($canary['verdict'] ?? AtlasTaskPostLandCanarySentinel::VERDICT_INCONCLUSIVE);
        try {
            $authority->issueCanaryObservation($request, ['verdict' => $verdict, 'status' => 'pending'], 'observed');
        } catch (\Throwable) {
            return $this->uncertainCanary($request, 'canary_observed_ledger_failed');
        }
        if (($canary['ledger_status'] ?? 'error') === 'error' || $verdict === AtlasTaskPostLandCanarySentinel::VERDICT_INCONCLUSIVE) {
            return $this->persistUncertainCanary($authority, $request, 'canary_inconclusive_or_diagnostic_ledger_down', $verdict,
                $provisionalOutcome);
        }
        if ($verdict === AtlasTaskPostLandCanarySentinel::VERDICT_PASS) {
            $result = ['verdict' => $verdict, 'status' => 'settled', 'resolved' => true, 'release_uncertain' => false,
                'landed_sha' => $request->landedSha, 'canary_before_terminal' => true];
            $result['engineering_outcome'] = $this->terminalEngineeringOutcome(
                $provisionalOutcome, 'released', $request, $request->landedEventHash, $result,
            )->toArray();
            try {
                $authority->issueCanaryObservation($request, $result, 'terminal');
            } catch (\Throwable) {
                return $this->uncertainCanary($request, 'canary_terminal_ledger_failed');
            }

            return $result;
        }
        try {
            $authorizedRevert = $actuator->prepareRevert($request);
            if (! $authorizedRevert instanceof AuthorizedRevertAction
                || ! $this->canonicalRevertAuthorizationValid($ledger, $authority, $request, $authorizedRevert)) {
                return $this->persistUncertainCanary($authority, $request, 'revert_authority_invalid', $verdict, $provisionalOutcome);
            }
            $revert = $actuator->act($authorizedRevert->action);
        } catch (\Throwable $exception) {
            return $this->persistUncertainCanary($authority, $request, 'revert_failed:'.$exception::class, $verdict, $provisionalOutcome);
        }
        $reverted = $this->canonicalRevertSettlementValid($ledger, $authority, $request, $authorizedRevert, $revert);
        $result = ['verdict' => $verdict, 'status' => $reverted ? 'reverted' : 'release_uncertain',
            'resolved' => $reverted, 'release_uncertain' => ! $reverted, 'revert_receipt' => $revert,
            'landed_sha' => $request->landedSha, 'canary_before_terminal' => true];
        $releaseHash = $request->landedEventHash;
        if ($reverted) {
            $settlement = $ledger->eventById((string) ($revert['settlement_event_id'] ?? ''));
            $releaseHash = (string) ($settlement?->getAttribute('event_hash') ?: $settlement?->getAttribute('payload_hash'));
        }
        $result['engineering_outcome'] = $this->terminalEngineeringOutcome(
            $provisionalOutcome, $reverted ? 'reverted' : 'release_uncertain', $request, $releaseHash, $result,
        )->toArray();
        try {
            $authority->issueCanaryObservation($request, $result, 'terminal');
        } catch (\Throwable) {
            return $this->uncertainCanary($request, 'revert_effect_terminal_ledger_failed');
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function persistUncertainCanary(KernelEvidenceAuthority $authority, CanarySettlementRequest $request, string $reason,
        string $verdict = AtlasTaskPostLandCanarySentinel::VERDICT_INCONCLUSIVE,
        ?EngineeringOutcome $provisional = null): array
    {
        $result = $this->uncertainCanary($request, $reason) + ['verdict' => $verdict];
        if ($provisional instanceof EngineeringOutcome) {
            $result['engineering_outcome'] = $this->terminalEngineeringOutcome(
                $provisional, 'release_uncertain', $request, $request->landedEventHash, $result,
            )->toArray();
        }
        try {
            $authority->issueCanaryObservation($request, $result, 'terminal');
        } catch (\Throwable) {
            // The returned quarantine remains fail-closed; no terminal claim is fabricated.
        }

        return $result;
    }

    /** @return array<string,mixed> */
    private function uncertainCanary(CanarySettlementRequest $request, string $reason): array
    {
        return ['verdict' => AtlasTaskPostLandCanarySentinel::VERDICT_INCONCLUSIVE, 'status' => 'release_uncertain',
            'resolved' => false, 'release_uncertain' => true, 'quarantined' => true, 'reason' => $reason,
            'landed_sha' => $request->landedSha, 'canary_before_terminal' => true];
    }

    private function provisionalOutcome(AtlasEvidenceLedger $ledger, KernelEvidenceAuthority $authority,
        CanarySettlementRequest $request): ?EngineeringOutcome
    {
        $event = $ledger->eventById($request->provisionalOutcomeEventId);
        $payload = $event?->getAttribute('payload');
        if (! $event instanceof AtlasLedgerEvent || ! $request->matchesProvisionalOutcomeEvent($event)
            || ! $authority->verifyEvent($event, 'provisional_outcome') || ! is_array($payload)
            || ($payload['event_name'] ?? null) !== 'engineering.outcome.provisional'
            || ($payload['task_packet_id'] ?? null) !== $request->action->taskPacketId
            || ($payload['candidate_hash'] ?? null) !== $request->action->candidateHash
            || ($payload['order_hash'] ?? null) !== $request->orderHash
            || ($payload['delivery_id'] ?? null) !== $request->deliveryId
            || ($payload['evidence_hash'] ?? null) !== $request->evidenceHash
            || ($payload['outcome_hash'] ?? null) !== $request->provisionalOutcomeHash
            || ! is_array($payload['outcome'] ?? null)) {
            return null;
        }
        try {
            $outcome = EngineeringOutcome::fromArray($payload['outcome']);
        } catch (\Throwable) {
            return null;
        }

        return $outcome->outcomeHash === $request->provisionalOutcomeHash
            && $outcome->deliveryId === $request->deliveryId
            && ($outcome->correlatedHashes['order'] ?? null) === $request->orderHash
            && ($outcome->correlatedHashes['evidence'] ?? null) === $request->evidenceHash
            && $authority->verifyOutcome($outcome->toArray()) ? $outcome : null;
    }

    /** @param array<string,mixed> $canary */
    private function terminalEngineeringOutcome(EngineeringOutcome $provisional, string $status,
        CanarySettlementRequest $request, string $releaseHash, array $canary): EngineeringOutcome
    {
        $data = $provisional->toArray();
        unset($data['outcome_hash']);
        $data['status'] = $status;
        $data['correlated_hashes']['release'] = $releaseHash;
        $data['release_receipt'] = ['status' => $status, 'hash' => $releaseHash,
            'landed_event_id' => $request->landedEventId, 'landed_sha' => $request->landedSha];
        $data['canary_rollback_receipt'] = $canary;
        $remainingUncertainties = array_values(array_filter($provisional->uncertainties,
            static fn (mixed $uncertainty): bool => $uncertainty !== 'release_pending_canary'));
        $data['uncertainties'] = $status === 'release_uncertain'
            ? array_values(array_unique([...$remainingUncertainties, (string) ($canary['reason'] ?? 'release_uncertain')]))
            : $remainingUncertainties;
        $data['evidence_bundle']['authority'] = ($this->evidenceAuthority ?? app(KernelEvidenceAuthority::class))->sealOutcome($data);

        return EngineeringOutcome::fromArray($data);
    }

    private function canonicalReleaseBindingValid(AtlasEvidenceLedger $ledger, KernelEvidenceAuthority $authority,
        CanarySettlementRequest $request, AtlasLedgerEvent $landed): bool
    {
        $action = $request->action;
        if (! hash_equals($action->orderHash, $request->orderHash)
            || $action->deliveryId !== $request->deliveryId
            || ! hash_equals($action->evidenceHash, $request->evidenceHash)) {
            return false;
        }
        $authorization = $ledger->eventById($action->canonicalEventId);
        if (! $authorization instanceof AtlasLedgerEvent
            || ! $authority->verifyReleaseAuthorization($authorization)
            || data_get($landed->payload, 'authorization_event_id') !== $action->canonicalEventId
            || data_get($landed->payload, 'authorization_event_hash') !== $action->canonicalEventHash
            || data_get($landed->payload, 'changed_files') !== $action->files) {
            return false;
        }
        $payload = $authorization->getAttribute('payload');
        if (! is_array($payload)) {
            return false;
        }
        $bindings = [
            'task_packet_id' => $action->taskPacketId,
            'action' => 'commit',
            'candidate_hash' => $action->candidateHash,
            'decision_hash' => $action->decisionHash,
            'verification_hash' => $action->verificationHash,
            'rollback_hash' => $action->rollbackHash,
            'changed_files' => $action->files,
            'scope_hash' => $action->scopeHash,
            'base_commit' => $action->baseCommit,
            'tree_hash' => $action->treeHash,
            'lease_id' => $action->leaseId,
            'lease_owner' => (string) ($action->metadata['lease_owner'] ?? ''),
            'fencing_token' => $action->fencingToken,
            'nonce' => $action->nonce,
            'issued_at' => $action->issuedAt,
            'expires_at' => $action->expiresAt,
            'order_hash' => $action->orderHash,
            'delivery_id' => $action->deliveryId,
            'evidence_hash' => $action->evidenceHash,
        ];
        foreach ($bindings as $key => $expected) {
            if (($payload[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return str_starts_with((string) ($payload['rollback_posture'] ?? ''), 'revertible:');
    }

    private function canonicalRevertAuthorizationValid(AtlasEvidenceLedger $ledger, KernelEvidenceAuthority $authority,
        CanarySettlementRequest $request, AuthorizedRevertAction $authorized): bool
    {
        $action = $authorized->action;
        $event = $ledger->eventById($action->canonicalEventId);
        $payload = $event?->getAttribute('payload');
        if (! $event instanceof AtlasLedgerEvent || ! $authority->verifyReleaseAuthorization($event) || ! is_array($payload)
            || $authorized->canaryRequestHash !== $request->idempotencyHash()
            || $authorized->originatingLandedEventId !== $request->landedEventId
            || $action->action !== 'revert_task' || $action->taskPacketId !== $request->action->taskPacketId
            || $action->targetSha !== $request->landedSha || $action->files !== $request->action->files
            || $action->scopeHash !== $request->action->scopeHash || $action->leaseId !== $request->action->leaseId
            || $action->fencingToken !== $request->action->fencingToken || $action->orderHash !== $request->orderHash
            || $action->deliveryId !== $request->deliveryId || $action->evidenceHash !== $request->evidenceHash) {
            return false;
        }
        foreach (['task_packet_id' => $action->taskPacketId, 'action' => 'revert_task',
            'candidate_hash' => $action->candidateHash, 'decision_hash' => $action->decisionHash,
            'verification_hash' => $action->verificationHash, 'rollback_hash' => $action->rollbackHash,
            'changed_files' => $action->files, 'scope_hash' => $action->scopeHash, 'base_commit' => $action->baseCommit,
            'tree_hash' => $action->treeHash, 'lease_id' => $action->leaseId,
            'lease_owner' => (string) ($action->metadata['lease_owner'] ?? ''), 'fencing_token' => $action->fencingToken,
            'nonce' => $action->nonce, 'order_hash' => $action->orderHash, 'delivery_id' => $action->deliveryId,
            'evidence_hash' => $action->evidenceHash] as $key => $expected) {
            if (($payload[$key] ?? null) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string,mixed> $result */
    private function canonicalRevertSettlementValid(AtlasEvidenceLedger $ledger, KernelEvidenceAuthority $authority,
        CanarySettlementRequest $request, AuthorizedRevertAction $authorized, array $result): bool
    {
        if (($result['reverted'] ?? false) !== true || ($result['resolved'] ?? false) !== true
            || ($result['status'] ?? null) !== 'settled') {
            return false;
        }
        $event = $ledger->eventById((string) ($result['settlement_event_id'] ?? ''));
        $payload = $event?->getAttribute('payload');

        return $event instanceof AtlasLedgerEvent && $authority->verifyEvent($event, 'revert_settlement')
            && $event->event_type === LedgerEventType::ReleaseReverted->value && is_array($payload)
            && ($payload['event_name'] ?? null) === 'release.reverted'
            && ($payload['authorization_event_id'] ?? null) === $authorized->action->canonicalEventId
            && ($payload['authorization_event_hash'] ?? null) === $authorized->action->canonicalEventHash
            && ($payload['task_packet_id'] ?? null) === $request->action->taskPacketId
            && ($payload['target_sha'] ?? null) === $request->landedSha
            && ($payload['scope_hash'] ?? null) === $request->action->scopeHash
            && ($payload['order_hash'] ?? null) === $request->orderHash
            && ($payload['delivery_id'] ?? null) === $request->deliveryId
            && ($payload['evidence_hash'] ?? null) === $request->evidenceHash;
    }

    public function prepareMutativeCandidate(ExecutionOrder $order): VerifiedMutativeCandidate
    {
        if (($order->toolPermissions['mutate'] ?? false) !== true) {
            return VerifiedMutativeCandidate::blocked($order, ['mutative_permission_required']);
        }
        $guard = new AtlasLoopHarnessGuard;
        foreach ($order->allowedScope as $file) {
            if ($guard->isForbiddenSelfTarget($file)) {
                return VerifiedMutativeCandidate::blocked($order, ['forbidden_self_target:'.$file]);
            }
        }
        try {
            $provider = ($this->providerPort ?? app(AgentExecutionProviderPortAdapter::class))->invoke([
                'execute_provider' => true,
                'provider' => (string) ($order->providerRoute['provider'] ?? ''),
                'model' => (string) ($order->providerRoute['model'] ?? ''),
                'prompt' => 'Produce the structured patch_plan for frozen spec '.$order->specHash.'. Do not claim verification.',
                'claim' => ['allowed_files' => $order->allowedScope],
            ]);
        } catch (\Throwable $e) {
            return VerifiedMutativeCandidate::blocked($order, ['provider_exception:'.$e::class]);
        }
        if (($provider['status'] ?? null) !== 'ok') {
            return VerifiedMutativeCandidate::blocked($order, ['provider_'.(string) ($provider['status'] ?? 'refused')], $provider);
        }

        $providerFiles = array_values(array_map('strval', (array) data_get($provider, 'patch_plan.allowed_files', [])));
        sort($providerFiles, SORT_STRING);
        $allowed = $order->allowedScope;
        sort($allowed, SORT_STRING);
        if ($providerFiles !== $allowed || array_intersect($providerFiles, $order->forbiddenScope) !== []) {
            return VerifiedMutativeCandidate::blocked($order, ['provider_scope_mismatch'], $provider);
        }
        try {
            $sandbox = ($this->mutativeSandbox ?? app(AtlasSelfConstructionHermeticSandboxApplyService::class))->execute([
            'idempotency_key' => $order->idempotencyKey,
            'source_repo' => $order->workspace,
            'base_commit' => $order->baseCommit,
            'task_packet_id' => $order->deliveryId,
            'lease_id' => (string) ($order->authorityEnvelope['lease_id'] ?? ''),
            'allowed_files' => $allowed,
            'patch_plan' => (array) ($provider['patch_plan'] ?? []),
            'provider_receipt' => $provider,
            ]);
        } catch (\Throwable $e) {
            return VerifiedMutativeCandidate::blocked($order, ['sandbox_exception:'.$e::class], $provider);
        }
        if (($sandbox['applied'] ?? false) !== true || ($sandbox['replayed'] ?? false) === true) {
            return VerifiedMutativeCandidate::blocked($order, ['sandbox_'.(string) ($sandbox['reason'] ?? 'refused')], $provider);
        }
        $sandboxRoot = (string) ($sandbox['sandbox_root'] ?? '');
        try {
            $verification = ($this->realExecution ?? app(AtlasRealEngineeringExecutionKernelService::class))
                ->verifyHermeticCandidate(
                    $order,
                    $sandboxRoot,
                    $allowed,
                    array_values(array_map(static fn (array $row): string => (string) ($row['path'] ?? ''), (array) data_get($sandbox, 'apply_receipt.applied_files', []))),
                    $provider,
                );
        } catch (\Throwable $e) {
            return new VerifiedMutativeCandidate('blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', $allowed, $sandboxRoot, $provider, $sandbox, '', '', '', '', '', ['independent_verification_error:'.$e::class.':'.$e->getMessage()], false);
        }
        $appliedPaths = array_values(array_map(static fn (array $row): string => (string) ($row['path'] ?? ''), (array) data_get($sandbox, 'apply_receipt.applied_files', [])));
        sort($appliedPaths, SORT_STRING);
        try {
            $row = ($this->realExecution ?? app(AtlasRealEngineeringExecutionKernelService::class))->verifiedMutativeVerification($verification, $order);
        } catch (\Throwable $e) {
            return new VerifiedMutativeCandidate(
                'blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', $appliedPaths, $sandboxRoot,
                $provider, $sandbox, $verification->runId, $verification->receiptHash,
                $verification->providerIdentity, $verification->authorIdentity, $verification->verifierIdentity,
                ['independent_verification_refused:'.$e->getMessage()], false,
            );
        }
        $receipt = (array) $row->receipt;
        $binding = (array) ($receipt['binding'] ?? []);

        // Slice B intentionally stops before the sovereign mutative 22-role court and Governor.
        return new VerifiedMutativeCandidate(
            'behaviorally_verified_pending_quality_court', $order->canonicalHash(), $verification->candidateHash, $order->baseCommit,
            (string) ($binding['tree_hash'] ?? ''), (string) ($binding['diff_hash'] ?? ''), $appliedPaths, $sandboxRoot,
            $provider, $sandbox, $verification->runId, $verification->receiptHash,
            $verification->providerIdentity, $verification->authorIdentity, $verification->verifierIdentity,
            ['mutative_22_role_court_receipt_absent'], false,
        );
    }

    public function adjudicateMutativeCandidate(
        ExecutionOrder $order,
        VerifiedMutativeCandidate $candidate,
        AiEngineeringCompanyEngagement $engagement,
        AiEngineeringCompanyCycle $cycle,
    ): QualityCourtVerdict {
        $case = CandidateQualityCase::fromCandidate($order, $candidate, $engagement, $cycle);

        return app(AtlasRealEngineeringCompanyRuntimeService::class)
            ->adjudicateMutativeCandidate($engagement, $cycle, $case);
    }

    /** @return array{verdict:QualityCourtVerdict,governance:array<string,mixed>} */
    public function governMutativeCandidate(
        ExecutionOrder $order,
        VerifiedMutativeCandidate $candidate,
        AiEngineeringCompanyEngagement $engagement,
        AiEngineeringCompanyCycle $cycle,
    ): array {
        $verdict = $this->adjudicateMutativeCandidate($order, $candidate, $engagement, $cycle);
        $hasBlockingRole = array_any($verdict->dispositions, static fn (RoleDisposition $disposition): bool => $disposition->status === 'block');
        $passed = $candidate->status === 'behaviorally_verified_pending_quality_court'
            && ! $hasBlockingRole
            && $verdict->authorityEligible;
        $evidenceHash = CanonicalKernelPayload::hash([
            'candidate_hash' => $candidate->candidateHash,
            'verification_hash' => $candidate->verificationHash,
            'case_hash' => $verdict->caseHash,
            'authority_eligible' => $verdict->authorityEligible,
        ]);
        $governance = app(AtlasTaskCommitGovernanceChain::class)->govern([
            'task_packet_id' => $order->deliveryId,
            'project_id' => $order->workspace,
            'changed_files' => $candidate->files,
            'verification' => [
                'passed' => $passed,
                'evidence_hash' => $evidenceHash,
                'checks' => ['quality_court' => $passed ? 'pass' : 'fail'],
                'planned_commands' => [],
                'replay_results' => [],
            ],
            'budget_posture' => $order->budgetPosture,
            'base_commit' => $candidate->baseCommit,
            'tree_hash' => $candidate->treeHash,
            'lease_id' => (string) ($order->authorityEnvelope['lease_id'] ?? ''),
            'lease_owner' => (string) ($order->authorityEnvelope['lease_owner'] ?? ''),
            'fencing_token' => (int) ($order->authorityEnvelope['fencing_token'] ?? 0),
            'candidate_hash' => $candidate->candidateHash,
            'verification_hash' => $candidate->verificationHash,
        ]);

        return ['verdict' => $verdict, 'governance' => $governance];
    }

    /** @param array<string,mixed> $governance @return array<string,mixed> */
    public function actAuthorizedMutativeCandidate(array $governance, MergeActuator $actuator): array
    {
        $rawAction = $governance['authorized_merge_action'] ?? null;
        if (! is_array($rawAction)) {
            return [
                'status' => 'blocked',
                'acted' => false,
                'release_uncertain' => false,
                'reason' => 'governor_authority_absent',
            ];
        }
        try {
            $action = AuthorizedMergeAction::fromArray($rawAction);
        } catch (\Throwable $exception) {
            return [
                'status' => 'blocked',
                'acted' => false,
                'release_uncertain' => false,
                'reason' => 'governor_authority_invalid:'.$exception::class,
            ];
        }
        try {
            $result = $actuator->act($action);
        } catch (\Throwable $exception) {
            return [
                'status' => 'release_uncertain',
                'acted' => false,
                'release_uncertain' => true,
                'reason' => 'merge_actuator_exception:'.$exception::class,
                'authorized_merge_action' => $action->toArray(),
            ];
        }

        return array_merge($result, [
            'authorized_merge_action' => $action->toArray(),
            'acted' => true,
            'release_uncertain' => (bool) ($result['release_uncertain'] ?? false),
        ]);
    }

    /** @return array<string,mixed> */
    public function executeMutativeCandidate(
        ExecutionOrder $order,
        AiEngineeringCompanyEngagement $engagement,
        AiEngineeringCompanyCycle $cycle,
        MergeActuator $actuator,
        AtlasTaskPostLandCanarySentinel $sentinel,
    ): array {
        $candidate = $this->prepareMutativeCandidate($order);
        if ($candidate->status === 'blocked') {
            return ['status' => 'blocked', 'candidate' => $candidate, 'governance' => null, 'actuation' => [
                'status' => 'blocked', 'acted' => false, 'release_uncertain' => false,
                'reason' => 'candidate_preparation_blocked',
            ]];
        }

        $governed = $this->governMutativeCandidate($order, $candidate, $engagement, $cycle);
        $rawAction = $governed['governance']['authorized_merge_action'] ?? null;
        if (! is_array($rawAction)) {
            $actuation = $this->actAuthorizedMutativeCandidate($governed['governance'], $actuator);

            return [
                'status' => (string) ($actuation['status'] ?? 'blocked'),
                'candidate' => $candidate,
                'verdict' => $governed['verdict'],
                'governance' => $governed['governance'],
                'actuation' => $actuation,
            ];
        }
        try {
            $action = AuthorizedMergeAction::fromArray($rawAction);
            $provisional = $this->provisionalMutativeOutcome($order, $candidate, $governed['verdict'], $action);
            $provisionalEvent = $this->authority()->issueProvisionalOutcome($provisional, $action);
        } catch (\Throwable $exception) {
            return [
                'status' => 'held',
                'candidate' => $candidate,
                'verdict' => $governed['verdict'],
                'governance' => $governed['governance'],
                'actuation' => [
                    'status' => 'held', 'acted' => false, 'release_uncertain' => false,
                    'reason' => 'provisional_outcome_unavailable:'.$exception::class,
                ],
            ];
        }
        $actuation = $this->actAuthorizedMutativeCandidate($governed['governance'], $actuator);
        $settlement = null;
        if (($actuation['status'] ?? null) === 'landed_pending_canary'
            && is_string($actuation['settlement_event_id'] ?? null)
            && $actuation['settlement_event_id'] !== '') {
            try {
                $landed = $this->ledger()->eventById($actuation['settlement_event_id']);
                if (! $landed instanceof AtlasLedgerEvent) {
                    throw new \RuntimeException('release_landed_event_missing');
                }
                $request = CanarySettlementRequest::fromCanonicalLanded(
                    $action, $landed, $provisionalEvent, $order->canonicalHash(), $order->deliveryId,
                    $action->evidenceHash, 'atlas.engineering_kernel.canary',
                );
                $settlement = $this->settleLandedRelease($request, $actuator, $sentinel);
            } catch (\Throwable $exception) {
                $settlement = [
                    'status' => 'release_uncertain', 'resolved' => false, 'release_uncertain' => true,
                    'quarantined' => true, 'reason' => 'canary_settlement_exception:'.$exception::class,
                ];
            }
        }

        return [
            'status' => (string) ($settlement['status'] ?? ($actuation['status'] ?? 'blocked')),
            'candidate' => $candidate,
            'verdict' => $governed['verdict'],
            'governance' => $governed['governance'],
            'actuation' => $actuation,
            'provisional_outcome' => $provisional->toArray(),
            'provisional_event' => $provisionalEvent,
            'settlement' => $settlement,
        ];
    }

    private function provisionalMutativeOutcome(
        ExecutionOrder $order,
        VerifiedMutativeCandidate $candidate,
        QualityCourtVerdict $verdict,
        AuthorizedMergeAction $action,
    ): EngineeringOutcome {
        $dispositions = [];
        foreach (EngineeringRoleRoster::OFFICIAL_ROLES as $role) {
            $disposition = $verdict->dispositions[$role]->toArray();
            if ($disposition['status'] === 'not_applicable') {
                $disposition['applicability_rule'] = 'mutative_scope_excludes_'.$role;
                $disposition['justification'] = $disposition['reason'];
            }
            $disposition['evidence_hash'] = CanonicalKernelPayload::hash($disposition);
            $dispositions[$role] = $disposition;
        }
        $releaseHash = hash('sha256', 'release_pending:'.$action->nonce);
        $data = [
            'schema_version' => 'atlas.engineering_outcome.v2',
            'run_id' => $order->runId,
            'delivery_id' => $order->deliveryId,
            'status' => 'held',
            'correlated_hashes' => [
                'order' => $order->canonicalHash(), 'intent' => $order->productIntentVerdictHash,
                'spec' => $order->specHash, 'baseline' => hash('sha256', $candidate->baseCommit),
                'diff' => $candidate->diffHash, 'evidence' => $action->evidenceHash, 'release' => $releaseHash,
            ],
            'role_dispositions' => $dispositions,
            'evidence_bundle' => [
                'hash' => $action->evidenceHash, 'status' => 'accepted',
                'court_case_hash' => $verdict->caseHash, 'candidate_hash' => $candidate->candidateHash,
                'verification_hash' => $candidate->verificationHash,
            ],
            'provider_receipt' => $candidate->providerReceipt,
            'sandbox_receipt' => $candidate->sandboxReceipt,
            'release_receipt' => ['status' => 'pending_canary', 'hash' => $releaseHash],
            'canary_rollback_receipt' => ['status' => 'pending'],
            'operator_effort' => ['active_seconds' => 0], 'cost' => ['amount' => 0, 'currency' => 'USD'],
            'tokens' => ['input' => 0, 'output' => 0], 'elapsed_ms' => 0,
            'uncertainties' => ['release_pending_canary'],
            'observation_schedule' => array_fill_keys(EngineeringOutcome::WINDOWS, 'pending'),
            'claim_eligible' => false,
        ];
        $data['evidence_bundle']['authority'] = $this->authority()->sealOutcome($data);

        return EngineeringOutcome::fromArray($data);
    }

    public function devGate(): AtlasDevGateAdapter
    {
        return $this->devAdapter;
    }

    public function forgeGate(): AtlasForgeGateAdapter
    {
        return $this->forgeAdapter;
    }

    public function autonomosGate(): AtlasAutonomosGateAdapter
    {
        return $this->autonomosAdapter;
    }

    public function outcomeProof(): OutcomeProofGate
    {
        return $this->outcomeProof;
    }

    public function honestyFloor(): SovereignHonestyFloor
    {
        return $this->honestyFloor;
    }

    /**
     * Obra 2 / DEV-02: single honesty contract — OutcomeProofGate and SovereignHonestyFloor
     * share FalseClaimInvariant so fake-green cannot drift between thin assert and full certify.
     *
     * @return array<string,mixed>
     */
    public function unifiedHonestyContract(): array
    {
        return [
            'schema_version' => 'atlas.elite_kernel.unified_honesty.v1',
            'outcome_proof' => OutcomeProofGate::class,
            'sovereign_floor' => SovereignHonestyFloor::class,
            'shared_invariant' => FalseClaimInvariant::class,
            'rule' => 'fake_green_blocked_identically',
            'assert_path' => 'assertHonestOutcome',
            'certify_path' => 'devGate|forgeGate|autonomosGate -> SovereignHonestyFloor::certify',
        ];
    }

    public function repairDiagnosis(): RepairDiagnosisStage
    {
        return $this->repairDiagnosis;
    }

    public function execute(ExecutionOrder $order): EngineeringOutcome
    {
        if (self::idempotencyLockStrategy((string) DB::connection()->getDriverName()) === 'postgres_advisory_xact_lock') {
            return DB::transaction(function () use ($order): EngineeringOutcome {
                DB::select('SELECT pg_advisory_xact_lock(?)', [(int) hexdec(substr(hash('sha256', $order->idempotencyKey), 0, 15))]);

                return $this->executeLocked($order);
            });
        }
        try {
            return Cache::lock('atlas:engineering-kernel:idempotency:'.hash('sha256', $order->idempotencyKey), 30)
                ->block(1, fn (): EngineeringOutcome => $this->executeLocked($order));
        } catch (LockTimeoutException) {
            throw new \RuntimeException('engineering_execution_idempotency_lock_unavailable');
        }
    }

    public static function idempotencyLockStrategy(string $driver): string
    {
        return $driver === 'pgsql' ? 'postgres_advisory_xact_lock' : 'test_cache_lock';
    }

    private function executeLocked(ExecutionOrder $order): EngineeringOutcome
    {
        $orderHash = $order->canonicalHash();
        $existing = $this->durableReplay($order);
        if ($existing !== null) {
            return $existing;
        }

        if (($order->toolPermissions['mutate'] ?? null) !== false) {
            throw new \LogicException('mutating_execution_not_available_in_packet_3');
        }

        $started = $this->recordEvent(LedgerEventType::ExecutionStarted, $order, [
            'event_name' => 'execution.started',
            'order_hash' => $orderHash,
            'idempotency_key' => $order->idempotencyKey,
            'role_roster_catalog_hash' => CanonicalKernelPayload::hash($order->roleRoster),
        ]);
        if ($started === null) {
            throw new \RuntimeException('canonical_engineering_ledger_unavailable');
        }

        [$acceptanceInput, $dispositions, $evidenceEvent] = $this->resolveAuthoritativeEvidence($order, $orderHash);
        $acceptanceHash = CanonicalKernelPayload::hash($acceptanceInput);
        $bundle = AcceptanceBundle::fromArray($acceptanceInput);
        $execution = (array) ($acceptanceInput['execution'] ?? []);
        $proof = $this->outcomeProof->assess('success', $execution);
        $falseClaimVerdict = $this->falseClaim->evaluate($bundle->execution);
        $gateVerdict = match ($order->mode) {
            'dev' => $this->devAdapter->certify($bundle, TrustLevel::Dev),
            'forge' => $this->forgeAdapter->certify($bundle, TrustLevel::Forge),
            'autonomos' => $this->autonomosAdapter->certify($bundle, TrustLevel::Autonomos),
            default => throw new \InvalidArgumentException('execution_order_mode_unreachable'),
        };
        $freshAndVerified = $proof['proven_real'] && $falseClaimVerdict['status'] === 'pass' && $gateVerdict->promoted();
        $acceptanceAuthorityEvent = $this->authority()->issueAcceptanceVerdict($gateVerdict, $order, $evidenceEvent, $this->authorityContext($order));
        $uncertainties = $freshAndVerified ? [] : ['authoritative_acceptance_refused'];
        if (! $freshAndVerified) {
            $dispositions = $this->blockingDispositions($order, 'authoritative_acceptance_refused');
        }

        $hasBlock = array_any($dispositions, static fn (array $entry): bool => $entry['status'] === 'block');
        $status = $uncertainties !== [] ? 'held' : ($hasBlock ? 'blocked' : 'completed_read_only');
        $evidenceHash = $acceptanceHash;
        $releaseHash = hash('sha256', 'read-only:no-release:'.$orderHash);
        $outcomeData = [
            'schema_version' => 'atlas.engineering_outcome.v2',
            'run_id' => $order->runId,
            'delivery_id' => $order->deliveryId,
            'status' => $status,
            'correlated_hashes' => [
                'order' => $orderHash,
                'intent' => $order->productIntentVerdictHash,
                'spec' => $order->specHash,
                'baseline' => hash('sha256', $order->baseCommit),
                'diff' => hash('sha256', 'read-only:no-diff:'.$orderHash),
                'evidence' => $evidenceHash,
                'release' => $releaseHash,
            ],
            'role_dispositions' => $dispositions,
            'evidence_bundle' => ['hash' => $evidenceHash, 'status' => $freshAndVerified ? 'accepted' : 'unknown_or_refused', 'gate_verdict' => $gateVerdict->toArray(), 'acceptance_authority_ref' => $acceptanceAuthorityEvent->event_id, 'acceptance_authority_event_hash' => (string) $acceptanceAuthorityEvent->getAttribute('event_hash')],
            'provider_receipt' => ['status' => 'not_applicable_read_only'],
            'sandbox_receipt' => ['status' => 'not_applicable_read_only'],
            'release_receipt' => ['status' => 'not_applicable_read_only', 'hash' => $releaseHash],
            'canary_rollback_receipt' => ['status' => 'not_applicable_read_only'],
            'operator_effort' => ['active_seconds' => 0],
            'cost' => ['amount' => 0, 'currency' => 'USD'],
            'tokens' => ['input' => 0, 'output' => 0],
            'elapsed_ms' => 0,
            'uncertainties' => $uncertainties,
            'observation_schedule' => array_fill_keys(EngineeringOutcome::WINDOWS, 'pending'),
            'claim_eligible' => false,
        ];
        $outcomeData['evidence_bundle']['authority'] = $this->authority()->sealOutcome($outcomeData);
        $outcome = EngineeringOutcome::fromArray($outcomeData);

        $this->recordEvent(LedgerEventType::GateEvaluated, $order, [
            'event_name' => 'acceptance.adjudicated', 'order_hash' => $orderHash,
            'evidence_hash' => $evidenceHash, 'status' => $status, 'gate_verdict' => $gateVerdict->toArray(),
        ]);
        $this->recordEvent(LedgerEventType::EvidencePacked, $order, [
            'event_name' => 'evidence.packed', 'order_hash' => $orderHash, 'evidence_hash' => $evidenceHash,
        ]);
        $completed = $this->recordEvent(LedgerEventType::OperationCompleted, $order, [
            'schema_version' => 'atlas.engineering_kernel.execution_receipt.v2',
            'event_name' => 'engineering.outcome.recorded',
            'idempotency_key' => $order->idempotencyKey,
            'order_hash' => $orderHash,
            'outcome' => $outcome->toArray(),
        ]);
        if ($completed === null) {
            throw new \RuntimeException('engineering_outcome_ledger_append_failed');
        }

        return $outcome;
    }

    public function observeOutcome(OutcomeObservation $observation): OutcomeLearningReceipt
    {
        $known = $this->ledger()->engineeringOutcomeEvent($observation->deliveryId, $observation->orderHash, $observation->outcomeHash);
        $payload = $known === null ? [] : (array) $known->payload;
        $outcome = (array) ($payload['outcome'] ?? []);
        if ($known === null || ! $this->ledger()->eventIntegrityValid($known)
            || ! hash_equals((string) ($payload['order_hash'] ?? ''), $observation->orderHash)
            || ! hash_equals((string) ($outcome['outcome_hash'] ?? ''), $observation->outcomeHash)
            || ! hash_equals((string) data_get($outcome, 'correlated_hashes.release', ''), $observation->releaseHash)) {
            throw new \InvalidArgumentException('outcome_observation_unknown_correlation');
        }
        $event = $this->ledger()->record(LedgerEventType::SloObserved, [
            'schema_version' => 'atlas.outcome_observed.v1',
            'event_name' => 'outcome.observed',
            'observation' => $observation->toArray(),
            'observation_hash' => $observation->canonicalHash(),
        ], [
            'envelope_id' => $observation->runId,
            'correlation_id' => $observation->deliveryId,
            'scope_type' => 'engineering_delivery',
            'scope_id' => $observation->deliveryId,
            'emitter_stage' => 'atlas.engineering_kernel.outcome',
        ]);
        if ($event === null) {
            throw new \RuntimeException('outcome_observation_ledger_write_failed');
        }

        return OutcomeLearningReceipt::fromObservation($observation, (string) ($event->event_hash ?? $event->event_id));
    }

    private function durableReplay(ExecutionOrder $order): ?EngineeringOutcome
    {
        $event = $this->ledger()->latestForCorrelation($order->idempotencyKey, 'engineering.outcome.recorded');
        if ($event === null) {
            return null;
        }
        if (! $this->ledger()->eventIntegrityValid($event)) {
            throw new \InvalidArgumentException('idempotency_receipt_integrity_invalid');
        }
        $payload = (array) $event->payload;
        if (! hash_equals((string) ($payload['order_hash'] ?? ''), $order->canonicalHash())) {
            throw new \InvalidArgumentException('idempotency_key_reused_with_changed_order');
        }

        return EngineeringOutcome::fromArray((array) ($payload['outcome'] ?? []));
    }

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>,2:AtlasLedgerEvent} */
    private function resolveAuthoritativeEvidence(ExecutionOrder $order, string $orderHash): array
    {
        $rosterHash = CanonicalKernelPayload::hash($order->roleRoster);
        $decision = $this->boundEvent((string) $order->decisionReceipt['decision_event_id'], 'decision.issued', $order, $orderHash, $rosterHash);
        if (! hash_equals((string) ($decision['authority_hash'] ?? ''), CanonicalKernelPayload::hash($order->authorityEnvelope))
            || ! hash_equals((string) ($decision['role_roster_catalog_hash'] ?? ''), $rosterHash)
            || CanonicalKernelPayload::hash((array) ($decision['role_roster'] ?? [])) !== $rosterHash) {
            throw new \InvalidArgumentException('decision_event_roster_mismatch');
        }

        $acceptanceEvent = $this->ledger()->eventById((string) $order->evidencePolicy['acceptance_event_id']);
        $acceptance = $this->boundEvent((string) $order->evidencePolicy['acceptance_event_id'], 'evidence.bundle.recorded', $order, $orderHash, $rosterHash);
        $bundle = $acceptance['acceptance_bundle'] ?? null;
        if (! is_array($bundle) || $bundle === []) {
            throw new \InvalidArgumentException('acceptance_event_bundle_missing');
        }
        if ($acceptanceEvent === null) {
            throw new \InvalidArgumentException('acceptance_event_missing');
        }

        $dispositions = [];
        foreach ($order->evidencePolicy['role_disposition_event_ids'] as $role => $eventId) {
            $payload = $this->boundEvent((string) $eventId, 'role.disposition.recorded', $order, $orderHash, $rosterHash);
            if (($payload['role'] ?? null) !== $role || ! is_array($payload['disposition'] ?? null)) {
                throw new \InvalidArgumentException('role_disposition_event_mismatch');
            }
            $event = $this->ledger()->eventById((string) $eventId);
            $dispositions[$role] = $payload['disposition'] + [
                'receipt_ref' => (string) $eventId,
                'receipt_event_hash' => (string) ($event?->getAttribute('event_hash') ?? ''),
            ];
        }

        return [$bundle, EngineeringRoleRoster::validateDispositions($dispositions), $acceptanceEvent];
    }

    /** @return array<string,mixed> */
    private function boundEvent(string $eventId, string $eventName, ExecutionOrder $order, string $orderHash, string $rosterHash): array
    {
        $event = $this->ledger()->eventById($eventId);
        if ($event === null || ! $this->ledger()->eventIntegrityValid($event)) {
            throw new \InvalidArgumentException('canonical_evidence_event_missing_or_invalid');
        }
        $kind = match ($eventName) {
            'decision.issued' => 'decision',
            'evidence.bundle.recorded' => 'evidence_bundle',
            'role.disposition.recorded' => 'role_disposition',
            default => throw new \InvalidArgumentException('canonical_evidence_event_kind_invalid'),
        };
        if (! $this->authority()->verifyEvent($event, $kind)) {
            throw new \InvalidArgumentException('canonical_evidence_authority_invalid');
        }
        $payload = $this->eventPayload($event);
        if (($payload['event_name'] ?? null) !== $eventName
            || $event->getAttribute('scope_type') !== 'engineering_delivery'
            || $event->getAttribute('scope_id') !== $order->deliveryId
            || ($payload['delivery_id'] ?? null) !== $order->deliveryId
            || ! hash_equals((string) ($payload['order_hash'] ?? ''), $orderHash)
            || ! hash_equals((string) ($payload['spec_hash'] ?? ''), $order->specHash)
            || ! hash_equals((string) ($payload['role_roster_catalog_hash'] ?? ''), $rosterHash)) {
            throw new \InvalidArgumentException('canonical_evidence_event_binding_invalid');
        }

        return $payload;
    }

    /** @return array<string,mixed> */
    private function eventPayload(AtlasLedgerEvent $event): array
    {
        $raw = $event->getAttribute('payload');
        if (! is_array($raw)) {
            throw new \InvalidArgumentException('canonical_evidence_event_payload_invalid');
        }
        $payload = [];
        foreach ($raw as $key => $value) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException('canonical_evidence_event_payload_invalid');
            }
            $payload[$key] = $value;
        }

        return $payload;
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(LedgerEventType $type, ExecutionOrder $order, array $payload): ?AtlasLedgerEvent
    {
        return $this->ledger()->record($type, $payload, [
            'envelope_id' => $order->runId,
            'correlation_id' => $order->idempotencyKey,
            'receipt_id' => $order->idempotencyKey,
            'scope_type' => 'engineering_delivery',
            'scope_id' => $order->deliveryId,
            'emitter_stage' => 'atlas.engineering_kernel',
            'emitter_version' => 'v2',
        ]);
    }

    private function ledger(): AtlasEvidenceLedger
    {
        return $this->evidenceLedger ?? app(AtlasEvidenceLedger::class);
    }

    private function authority(): KernelEvidenceAuthority
    {
        return $this->evidenceAuthority ?? app(KernelEvidenceAuthority::class);
    }

    /** @return array<string,mixed> */
    private function authorityContext(ExecutionOrder $order): array
    {
        return [
            'envelope_id' => $order->runId, 'correlation_id' => $order->idempotencyKey,
            'scope_type' => 'engineering_delivery', 'scope_id' => $order->deliveryId,
        ];
    }

    /** @return array<string,array<string,mixed>> */
    private function blockingDispositions(ExecutionOrder $order, string $reason): array
    {
        $dispositions = [];
        foreach (array_keys($order->roleRoster) as $role) {
            $dispositions[$role] = [
                'status' => 'block',
                'evidence_hash' => hash('sha256', 'missing:'.$role.':'.$reason),
                'signature' => hash('sha256', 'kernel-fail-closed:'.$role.':'.$reason),
            ];
        }

        return $dispositions;
    }

    /**
     * @param  array<string, mixed>  $evidence
     */
    public function assertHonestOutcome(array $evidence, string $executor = 'dev'): void
    {
        $status = (string) ($evidence['status'] ?? 'failed');
        $proof = $this->outcomeProof->assess($status, (array) ($evidence['execution'] ?? []));
        if (($proof['fake_green'] ?? false) === true) {
            throw new \RuntimeException('elite_kernel_fake_green: '.(string) ($proof['reason'] ?? 'unknown').' executor='.$executor);
        }
    }

    public function intentEnvelope(string $objective, string $executor = 'dev'): IntentEnvelope
    {
        return new IntentEnvelope(rawGoal: $objective.' ['.$executor.']');
    }

    /**
     * @return array<string, mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'executors' => ['dev', 'forge', 'autonomos'],
            'shared' => ['context_intake', 'evidence_grammar', 'repair', 'completion_governor', 'quality_bar'],
            'difference' => 'operator_presence_and_scale_only',
        ];
    }
}
