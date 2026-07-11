<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\EngineeringKernel\Adapters\AgentExecutionProviderPortAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\RealExecution\AtlasRealEngineeringExecutionKernelService;
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
        private readonly ?AtlasSelfConstructionHermeticSandboxApplyService $mutativeSandbox = null,
        private readonly ?AtlasRealEngineeringExecutionKernelService $realExecution = null,
    ) {}

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
                );
        } catch (\Throwable $e) {
            return new VerifiedMutativeCandidate('blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', $allowed, $sandboxRoot, $provider, $sandbox, [], ['independent_verification_error:'.$e::class.':'.$e->getMessage()], false);
        }
        $appliedPaths = array_values(array_map(static fn (array $row): string => (string) ($row['path'] ?? ''), (array) data_get($sandbox, 'apply_receipt.applied_files', [])));
        sort($appliedPaths, SORT_STRING);
        $verificationFiles = array_values(array_map('strval', (array) ($verification['files'] ?? [])));
        sort($verificationFiles, SORT_STRING);
        $diffArtifact = (array) ($verification['diff_artifact'] ?? []);
        $junitArtifact = (array) ($verification['junit_artifact'] ?? []);
        $commands = (array) ($verification['commands'] ?? []);
        $artifactsValid = $this->artifactValid($diffArtifact, $sandboxRoot) && $this->artifactValid($junitArtifact, $sandboxRoot)
            && hash_equals((string) ($verification['diff_hash'] ?? ''), (string) ($diffArtifact['sha256'] ?? ''));
        $bindingValid = ($verification['order_hash'] ?? null) === $order->canonicalHash()
            && ($verification['base_commit'] ?? null) === $order->baseCommit
            && $appliedPaths !== []
            && $verificationFiles === $appliedPaths
            && array_diff($appliedPaths, $allowed) === []
            && array_intersect($appliedPaths, $order->forbiddenScope) === []
            && count($commands) >= 2
            && ! array_any($commands, static fn (mixed $result): bool => ! is_array($result) || ($result['passed'] ?? false) !== true);
        if (($verification['passed'] ?? false) !== true || ($verification['independent_from_provider'] ?? false) !== true
            || ! $artifactsValid || ! $bindingValid || ! $this->authority()->verifyMutativeVerificationReceipt($verification)) {
            return new VerifiedMutativeCandidate('blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', $appliedPaths, $sandboxRoot, $provider, $sandbox, $verification, ['independent_verification_refused'], false);
        }
        $behavioral = (array) ($verification['behavioral'] ?? []);
        if (($behavioral['status'] ?? null) === 'missing') {
            return new VerifiedMutativeCandidate('held', $order->canonicalHash(), '', $order->baseCommit, '', '', $appliedPaths, $sandboxRoot, $provider, $sandbox, $verification, ['behavioral_target_required'], false);
        }
        $behavioralArtifact = is_array($behavioral['junit_artifact'] ?? null) ? $behavioral['junit_artifact'] : [];
        $behavioralTarget = (string) ($behavioral['target'] ?? '');
        if (($behavioral['passed'] ?? false) !== true || ($behavioral['target'] ?? null) !== $behavioralTarget
            || ! $this->artifactValid($behavioralArtifact, $sandboxRoot)
            || ! hash_equals((string) ($behavioral['target_hash'] ?? ''), (string) hash_file('sha256', $sandboxRoot.'/'.$behavioralTarget))) {
            return new VerifiedMutativeCandidate('blocked', $order->canonicalHash(), '', $order->baseCommit, '', '', $appliedPaths, $sandboxRoot, $provider, $sandbox, $verification, ['behavioral_verification_refused'], false);
        }
        $candidateHash = CanonicalKernelPayload::hash([
            'order_hash' => $order->canonicalHash(), 'base_commit' => $order->baseCommit,
            'tree_hash' => $verification['tree_hash'], 'diff_hash' => $verification['diff_hash'],
            'files' => $appliedPaths, 'verification_hash' => $verification['hash'], 'behavioral_hash' => CanonicalKernelPayload::hash($behavioral),
        ]);

        // Slice B intentionally stops before the sovereign mutative 22-role court and Governor.
        return new VerifiedMutativeCandidate(
            'behaviorally_verified_pending_quality_court', $order->canonicalHash(), $candidateHash, $order->baseCommit,
            (string) $verification['tree_hash'], (string) $verification['diff_hash'], $appliedPaths, $sandboxRoot,
            $provider, $sandbox, $verification, ['mutative_22_role_court_receipt_absent'], false,
        );
    }

    /** @param array<string,mixed> $artifact */
    private function artifactValid(array $artifact, string $sandboxRoot): bool
    {
        $path = (string) ($artifact['path'] ?? '');
        $hash = (string) ($artifact['sha256'] ?? '');

        $real = $path !== '' ? realpath($path) : false;
        $artifactRoot = realpath($sandboxRoot.'/.atlas');

        return $real !== false && $artifactRoot !== false && str_starts_with($real, $artifactRoot.'/')
            && $hash !== '' && is_file($path) && ! is_link($path)
            && hash_equals($hash, (string) hash_file('sha256', $path));
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
