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
        $expected = ['schema_version', 'run_id', 'delivery_id', 'status', 'correlated_hashes', 'role_dispositions', 'evidence_bundle', 'provider_receipt', 'sandbox_receipt', 'release_receipt', 'canary_rollback_receipt', 'operator_effort', 'cost', 'tokens', 'elapsed_ms', 'uncertainties', 'observation_schedule', 'claim_eligible', 'outcome_hash'];
        if (array_diff(array_keys($data), $expected) !== []) {
            throw new InvalidArgumentException('engineering_outcome_unknown_fields');
        }
        $schema = CanonicalKernelPayload::requireString($data, 'schema_version');
        if ($schema !== 'atlas.engineering_outcome.v2') {
            throw new InvalidArgumentException('schema_version_invalid');
        }
        $hashes = CanonicalKernelPayload::requireArray($data, 'correlated_hashes');
        foreach (['order', 'intent', 'spec', 'baseline', 'diff', 'evidence', 'release'] as $name) {
            CanonicalKernelPayload::requireHash($hashes, $name);
        }
        if (($data['claim_eligible'] ?? false) !== false) {
            throw new InvalidArgumentException('claim_eligibility_reserved_for_rivals');
        }
        $schedule = CanonicalKernelPayload::requireArray($data, 'observation_schedule');
        if (array_diff(array_keys($schedule), self::WINDOWS) !== [] || array_diff(self::WINDOWS, array_keys($schedule)) !== []) {
            throw new InvalidArgumentException('observation_schedule_invalid');
        }
        $schedule = array_replace(array_fill_keys(self::WINDOWS, 'pending'), $schedule);
        $status = CanonicalKernelPayload::requireEnum($data, 'status', self::STATUSES);
        $dispositions = EngineeringRoleRoster::validateDispositions(CanonicalKernelPayload::requireArray($data, 'role_dispositions'));
        $evidenceBundle = CanonicalKernelPayload::requireArray($data, 'evidence_bundle');
        $releaseReceipt = CanonicalKernelPayload::requireArray($data, 'release_receipt');
        if (! hash_equals($hashes['evidence'], CanonicalKernelPayload::requireHash($evidenceBundle, 'hash'))
            || ! hash_equals($hashes['release'], CanonicalKernelPayload::requireHash($releaseReceipt, 'hash'))) {
            throw new InvalidArgumentException('outcome_receipt_hash_mismatch');
        }
        $uncertainties = $data['uncertainties'] ?? null;
        if (! is_array($uncertainties) || ! array_is_list($uncertainties)) {
            throw new InvalidArgumentException('uncertainties_invalid');
        }
        foreach ($uncertainties as $uncertainty) {
            if (! is_string($uncertainty) || trim($uncertainty) === '') {
                throw new InvalidArgumentException('uncertainties_invalid');
            }
        }
        if ($status === 'completed_read_only'
            && ($uncertainties !== [] || array_any($dispositions, static fn (array $entry): bool => $entry['status'] === 'block'))) {
            throw new InvalidArgumentException('completed_read_only_requires_unblocked_certain_dispositions');
        }
        if ($status === 'completed_read_only') {
            if (($evidenceBundle['status'] ?? null) !== 'accepted'
                || data_get($evidenceBundle, 'gate_verdict.status') !== CertVerdict::PROMOTE
                || trim((string) ($evidenceBundle['acceptance_authority_ref'] ?? '')) === ''
                || preg_match('/^[a-f0-9]{64}$/', (string) ($evidenceBundle['acceptance_authority_event_hash'] ?? '')) !== 1) {
                throw new InvalidArgumentException('completed_read_only_requires_authoritative_acceptance');
            }
            foreach ($dispositions as $disposition) {
                CanonicalKernelPayload::requireString($disposition, 'receipt_ref');
                CanonicalKernelPayload::requireHash($disposition, 'receipt_event_hash');
            }
            if (! app(KernelEvidenceAuthority::class)->verifyOutcome($data)) {
                throw new InvalidArgumentException('completed_outcome_authority_invalid');
            }
        }
        $elapsed = $data['elapsed_ms'] ?? null;
        if (! is_int($elapsed) || $elapsed < 0) {
            throw new InvalidArgumentException('elapsed_ms_invalid');
        }
        $normalized = [
            'schema_version' => $schema,
            'run_id' => CanonicalKernelPayload::requireString($data, 'run_id'),
            'delivery_id' => CanonicalKernelPayload::requireString($data, 'delivery_id'),
            'status' => $status,
            'correlated_hashes' => $hashes,
            'role_dispositions' => $dispositions,
            'evidence_bundle' => $evidenceBundle,
            'provider_receipt' => CanonicalKernelPayload::requireArray($data, 'provider_receipt'),
            'sandbox_receipt' => CanonicalKernelPayload::requireArray($data, 'sandbox_receipt'),
            'release_receipt' => $releaseReceipt,
            'canary_rollback_receipt' => CanonicalKernelPayload::requireArray($data, 'canary_rollback_receipt'),
            'operator_effort' => CanonicalKernelPayload::requireArray($data, 'operator_effort'),
            'cost' => CanonicalKernelPayload::requireArray($data, 'cost'),
            'tokens' => CanonicalKernelPayload::requireArray($data, 'tokens'),
            'elapsed_ms' => $elapsed,
            'uncertainties' => $uncertainties,
            'observation_schedule' => $schedule,
            'claim_eligible' => false,
        ];
        $computedHash = CanonicalKernelPayload::hash($normalized);
        if (isset($data['outcome_hash']) && ! hash_equals((string) $data['outcome_hash'], $computedHash)) {
            throw new InvalidArgumentException('outcome_hash_mismatch');
        }

        return new self(
            schemaVersion: $schema,
            runId: $normalized['run_id'],
            deliveryId: $normalized['delivery_id'],
            status: $status,
            correlatedHashes: $hashes,
            roleDispositions: $dispositions,
            evidenceBundle: $evidenceBundle,
            providerReceipt: $normalized['provider_receipt'],
            sandboxReceipt: $normalized['sandbox_receipt'],
            releaseReceipt: $releaseReceipt,
            canaryRollbackReceipt: $normalized['canary_rollback_receipt'],
            operatorEffort: $normalized['operator_effort'],
            cost: $normalized['cost'],
            tokens: $normalized['tokens'],
            elapsedMs: $elapsed,
            uncertainties: $uncertainties,
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
