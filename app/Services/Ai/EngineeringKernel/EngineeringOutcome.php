<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class EngineeringOutcome
{
    public const STATUSES = ['released', 'completed_read_only', 'held', 'blocked', 'refused', 'reverted', 'release_uncertain'];

    public const WINDOWS = ['0h', '24h', '7d', '30d', '90d', '150d'];

    /** @param array<string,mixed> $correlatedHashes @param array<string,array<string,mixed>> $roleDispositions */
    private function __construct(
        public string $schemaVersion,
        public string $runId,
        public string $deliveryId,
        public string $status,
        public array $correlatedHashes,
        public array $roleDispositions,
        public array $evidenceBundle,
        public array $providerReceipt,
        public array $sandboxReceipt,
        public array $releaseReceipt,
        public array $canaryRollbackReceipt,
        public array $operatorEffort,
        public array $cost,
        public array $tokens,
        public int $elapsedMs,
        public array $uncertainties,
        public array $observationSchedule,
        public bool $claimEligible,
        public string $outcomeHash,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        $schema = CanonicalKernelPayload::requireString($data, 'schema_version');
        if ($schema !== 'atlas.engineering_outcome.v2') {
            throw new InvalidArgumentException('schema_version_invalid');
        }
        $hashes = CanonicalKernelPayload::requireArray($data, 'correlated_hashes');
        foreach (['intent', 'spec', 'baseline', 'diff', 'evidence', 'release'] as $name) {
            CanonicalKernelPayload::requireHash($hashes, $name);
        }
        if (($data['claim_eligible'] ?? false) !== false) {
            throw new InvalidArgumentException('claim_eligibility_reserved_for_rivals');
        }
        $schedule = CanonicalKernelPayload::requireArray($data, 'observation_schedule');
        if (array_keys($schedule) !== self::WINDOWS) {
            throw new InvalidArgumentException('observation_schedule_invalid');
        }
        $payload = $data;
        unset($payload['outcome_hash']);
        $computedHash = CanonicalKernelPayload::hash($payload);
        if (isset($data['outcome_hash']) && ! hash_equals((string) $data['outcome_hash'], $computedHash)) {
            throw new InvalidArgumentException('outcome_hash_mismatch');
        }

        return new self(
            schemaVersion: $schema,
            runId: CanonicalKernelPayload::requireString($data, 'run_id'),
            deliveryId: CanonicalKernelPayload::requireString($data, 'delivery_id'),
            status: CanonicalKernelPayload::requireEnum($data, 'status', self::STATUSES),
            correlatedHashes: $hashes,
            roleDispositions: EngineeringRoleRoster::validateDispositions(CanonicalKernelPayload::requireArray($data, 'role_dispositions')),
            evidenceBundle: CanonicalKernelPayload::requireArray($data, 'evidence_bundle'),
            providerReceipt: CanonicalKernelPayload::requireArray($data, 'provider_receipt'),
            sandboxReceipt: CanonicalKernelPayload::requireArray($data, 'sandbox_receipt'),
            releaseReceipt: CanonicalKernelPayload::requireArray($data, 'release_receipt'),
            canaryRollbackReceipt: CanonicalKernelPayload::requireArray($data, 'canary_rollback_receipt'),
            operatorEffort: CanonicalKernelPayload::requireArray($data, 'operator_effort'),
            cost: CanonicalKernelPayload::requireArray($data, 'cost'),
            tokens: CanonicalKernelPayload::requireArray($data, 'tokens'),
            elapsedMs: max(0, (int) ($data['elapsed_ms'] ?? 0)),
            uncertainties: array_values((array) ($data['uncertainties'] ?? [])),
            observationSchedule: $schedule,
            claimEligible: false,
            outcomeHash: $computedHash,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'run_id' => $this->runId,
            'delivery_id' => $this->deliveryId,
            'status' => $this->status,
            'correlated_hashes' => $this->correlatedHashes,
            'role_dispositions' => $this->roleDispositions,
            'evidence_bundle' => $this->evidenceBundle,
            'provider_receipt' => $this->providerReceipt,
            'sandbox_receipt' => $this->sandboxReceipt,
            'release_receipt' => $this->releaseReceipt,
            'canary_rollback_receipt' => $this->canaryRollbackReceipt,
            'operator_effort' => $this->operatorEffort,
            'cost' => $this->cost,
            'tokens' => $this->tokens,
            'elapsed_ms' => $this->elapsedMs,
            'uncertainties' => $this->uncertainties,
            'observation_schedule' => $this->observationSchedule,
            'claim_eligible' => false,
            'outcome_hash' => $this->outcomeHash,
        ];
    }
}
