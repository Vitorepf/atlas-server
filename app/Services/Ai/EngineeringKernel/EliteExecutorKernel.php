<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasAutonomosGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasDevGateAdapter;
use App\Services\Ai\EngineeringKernel\Adapters\AtlasForgeGateAdapter;
use App\Services\Ai\EngineeringKernel\Repair\RepairDiagnosisStage;
use App\Services\Ai\EngineeringKernel\Spec\IntentEnvelope;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
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
    ) {}

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

        [$acceptanceInput, $dispositions] = $this->resolveAuthoritativeEvidence($order, $orderHash);
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
            'evidence_bundle' => ['hash' => $evidenceHash, 'status' => $freshAndVerified ? 'accepted' : 'unknown_or_refused', 'gate_verdict' => $gateVerdict->toArray()],
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

    /** @return array{0:array<string,mixed>,1:array<string,array<string,mixed>>} */
    private function resolveAuthoritativeEvidence(ExecutionOrder $order, string $orderHash): array
    {
        $rosterHash = CanonicalKernelPayload::hash($order->roleRoster);
        $decision = $this->boundEvent((string) $order->decisionReceipt['decision_event_id'], 'decision.issued', $order, $orderHash, $rosterHash);
        if (! hash_equals((string) ($decision['authority_hash'] ?? ''), CanonicalKernelPayload::hash($order->authorityEnvelope))
            || ! hash_equals((string) ($decision['role_roster_catalog_hash'] ?? ''), $rosterHash)
            || CanonicalKernelPayload::hash((array) ($decision['role_roster'] ?? [])) !== $rosterHash) {
            throw new \InvalidArgumentException('decision_event_roster_mismatch');
        }

        $acceptance = $this->boundEvent((string) $order->evidencePolicy['acceptance_event_id'], 'acceptance.evidence.recorded', $order, $orderHash, $rosterHash);
        $bundle = $acceptance['acceptance_bundle'] ?? null;
        if (! is_array($bundle) || $bundle === []) {
            throw new \InvalidArgumentException('acceptance_event_bundle_missing');
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

        return [$bundle, EngineeringRoleRoster::validateDispositions($dispositions)];
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
            'acceptance.evidence.recorded' => 'acceptance',
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
