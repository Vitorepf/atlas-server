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
            'role_roster_catalog_hash' => $order->roleRosterCatalogHash,
        ]);
        if ($started === null) {
            throw new \RuntimeException('canonical_engineering_ledger_unavailable');
        }

        $policy = $order->evidencePolicy;
        $acceptanceInput = $policy['acceptance_bundle'] ?? null;
        $freshAndVerified = false;
        $acceptanceHash = null;
        $gateVerdict = null;
        if (is_array($acceptanceInput) && $acceptanceInput !== []) {
            $acceptanceHash = CanonicalKernelPayload::hash($acceptanceInput);
            $freshAndVerified = ($policy['required'] ?? false) === true
                && ($policy['fresh'] ?? false) === true
                && ($policy['status'] ?? null) === 'verified'
                && is_string($policy['evidence_hash'] ?? null)
                && hash_equals($acceptanceHash, $policy['evidence_hash']);
            if ($freshAndVerified) {
                $bundle = AcceptanceBundle::fromArray($acceptanceInput);
                $execution = (array) ($acceptanceInput['execution'] ?? []);
                $proof = $this->outcomeProof->assess('success', $execution);
                $falseClaimVerdict = $this->falseClaim->evaluate($bundle->execution);
                try {
                    $this->assertHonestOutcome(['status' => 'success', 'execution' => $execution], $order->mode);
                    $gateVerdict = match ($order->mode) {
                        'dev' => $this->devAdapter->certify($bundle, TrustLevel::Dev),
                        'forge' => $this->forgeAdapter->certify($bundle, TrustLevel::Forge),
                        'autonomos' => $this->autonomosAdapter->certify($bundle, TrustLevel::Autonomos),
                        default => throw new \InvalidArgumentException('execution_order_mode_unreachable'),
                    };
                    $freshAndVerified = $proof['proven_real']
                        && $falseClaimVerdict['status'] === 'pass'
                        && $gateVerdict->promoted();
                } catch (\RuntimeException) {
                    $freshAndVerified = false;
                }
            }
        }

        $uncertainties = [];
        $dispositions = $policy['role_dispositions'] ?? null;
        if (! $freshAndVerified || ! is_array($dispositions)) {
            $uncertainties[] = 'read_only_evidence_missing_stale_or_unknown';
            $dispositions = $this->blockingDispositions($order, 'read_only_evidence_missing_stale_or_unknown');
        } else {
            try {
                $dispositions = EngineeringRoleRoster::validateDispositions($dispositions);
                if (array_keys($dispositions) !== array_keys($order->roleRoster)) {
                    throw new \InvalidArgumentException('role_dispositions_do_not_match_order_roster');
                }
            } catch (\InvalidArgumentException $exception) {
                $uncertainties[] = $exception->getMessage();
                $dispositions = $this->blockingDispositions($order, $exception->getMessage());
            }
        }

        $hasBlock = array_any($dispositions, static fn (array $entry): bool => $entry['status'] === 'block');
        $status = $uncertainties !== [] ? 'held' : ($hasBlock ? 'blocked' : 'completed_read_only');
        $evidenceHash = $acceptanceHash ?? hash('sha256', 'unknown-evidence:'.$orderHash);
        $releaseHash = hash('sha256', 'read-only:no-release:'.$orderHash);
        $outcome = EngineeringOutcome::fromArray([
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
            'evidence_bundle' => ['hash' => $evidenceHash, 'status' => $freshAndVerified ? 'accepted' : 'unknown_or_refused', 'gate_verdict' => $gateVerdict?->toArray()],
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
        ]);

        $this->recordEvent(LedgerEventType::GateEvaluated, $order, [
            'event_name' => 'acceptance.adjudicated', 'order_hash' => $orderHash,
            'evidence_hash' => $evidenceHash, 'status' => $status, 'gate_verdict' => $gateVerdict?->toArray(),
        ]);
        $this->recordEvent(LedgerEventType::EvidencePacked, $order, [
            'event_name' => 'evidence.packed', 'order_hash' => $orderHash, 'evidence_hash' => $evidenceHash,
        ]);
        $this->recordEvent(LedgerEventType::OperationCompleted, $order, [
            'schema_version' => 'atlas.engineering_kernel.execution_receipt.v2',
            'event_name' => 'engineering.outcome.recorded',
            'idempotency_key' => $order->idempotencyKey,
            'order_hash' => $orderHash,
            'outcome' => $outcome->toArray(),
        ]);

        return $outcome;
    }

    public function observeOutcome(OutcomeObservation $observation): OutcomeLearningReceipt
    {
        $known = collect($this->ledger()->eventsForScope('engineering_delivery', $observation->deliveryId, 500))
            ->contains(function (array $event) use ($observation): bool {
                $payload = (array) ($event['payload'] ?? []);
                $outcome = (array) ($payload['outcome'] ?? []);

                return ($payload['event_name'] ?? null) === 'engineering.outcome.recorded'
                    && hash_equals((string) ($payload['order_hash'] ?? ''), $observation->orderHash)
                    && hash_equals((string) ($outcome['outcome_hash'] ?? ''), $observation->outcomeHash)
                    && hash_equals((string) data_get($outcome, 'correlated_hashes.release', ''), $observation->releaseHash);
            });
        if (! $known) {
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
        foreach ($this->ledger()->eventsForScope('engineering_delivery', $order->deliveryId, 500) as $event) {
            $payload = (array) ($event['payload'] ?? []);
            if (($payload['event_name'] ?? null) !== 'engineering.outcome.recorded'
                || ($payload['idempotency_key'] ?? null) !== $order->idempotencyKey) {
                continue;
            }
            if (! hash_equals((string) ($payload['order_hash'] ?? ''), $order->canonicalHash())) {
                throw new \InvalidArgumentException('idempotency_key_reused_with_changed_order');
            }

            return EngineeringOutcome::fromArray((array) ($payload['outcome'] ?? []));
        }

        return null;
    }

    /** @param array<string,mixed> $payload */
    private function recordEvent(LedgerEventType $type, ExecutionOrder $order, array $payload): ?AtlasLedgerEvent
    {
        return $this->ledger()->record($type, $payload, [
            'envelope_id' => $order->runId,
            'correlation_id' => $order->deliveryId,
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
