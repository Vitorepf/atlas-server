<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

/**
 * Deterministic adapter: turns an Atlas task-serving claim/queue entry into the normalized packet
 * shape consumed by {@see AtlasNativeWorkerExecutionEnvelopeBuilder}. Pure, facts-only.
 *
 * Fails closed (returns ok=false + reason) when the packet is not worker-executable, requires
 * operator handoff, lacks a valid lease, lacks scoped files/evidence, or encodes operator/human/
 * external-provider dependency.
 *
 * NO providers, NO commands, NO file/queue mutation, NO completion reporting.
 */
final class AtlasNativeWorkerClaimEnvelopeAdapter
{
    public const SCHEMA = 'atlas.native_worker.claim_envelope_adapter.v1';

    public const REASON_OK = 'ok';

    public const REASON_LEASE_MISSING = 'lease_id_missing';

    public const REASON_PACKET_MISSING = 'task_packet_missing';

    public const REASON_TASK_ID_MISSING = 'task_packet_id_missing';

    public const REASON_OBJECTIVE_MISSING = 'objective_missing';

    public const REASON_ALLOWED_FILES_EMPTY = 'allowed_files_empty';

    public const REASON_SCOPE_IN_EMPTY = 'scope_in_empty';

    public const REASON_ACCEPTANCE_EMPTY = 'acceptance_criteria_empty';

    public const REASON_EVIDENCE_EMPTY = 'required_evidence_empty';

    public const REASON_NOT_WORKER_EXECUTABLE = 'worker_executable_false';

    public const REASON_OPERATOR_HANDOFF = 'operator_handoff_required';

    public const REASON_NON_NATIVE_OWNER = 'final_runtime_owner_not_atlas_native';

    public const REASON_HUMAN_DEPENDENCY = 'human_dependency_present';

    public const REASON_OPERATOR_DEPENDENCY = 'operator_dependency_present';

    public const REASON_EXTERNAL_PROVIDER_DEPENDENCY = 'external_provider_dependency_present';

    public const REASON_TEST_ONLY_SCOPE = 'test_only_scope_no_impl';

    public const REASON_NO_RUNNABLE_PROOF = 'no_runnable_proof_in_acceptance';

    /**
     * @param  array<string,mixed>  $claim
     * @return array<string,mixed>
     */
    public function adapt(array $claim): array
    {
        $leaseId = (string) ($claim['lease_id'] ?? '');
        $packet = is_array($claim['task_packet'] ?? null) ? $claim['task_packet'] : null;
        if ($packet === null && is_array($claim['task'] ?? null) && is_array($claim['task']['task_packet'] ?? null)) {
            // Allow the task-serving envelope shape: {task: {task_packet: {...}, lease_id: ...}}
            $packet = $claim['task']['task_packet'];
            if ($leaseId === '') {
                $leaseId = (string) ($claim['task']['lease_id'] ?? '');
            }
        }

        if ($leaseId === '') {
            return $this->refuse(self::REASON_LEASE_MISSING);
        }
        if ($packet === null) {
            return $this->refuse(self::REASON_PACKET_MISSING);
        }

        $taskPacketId = (string) ($packet['task_packet_id'] ?? '');
        $objective = (string) ($packet['objective'] ?? '');
        $allowedFiles = array_values((array) ($packet['allowed_files'] ?? []));
        $scopeIn = array_values((array) ($packet['scope_in'] ?? []));
        $acceptance = array_values((array) ($packet['acceptance_criteria'] ?? []));
        $evidence = array_values((array) ($packet['required_evidence'] ?? []));

        if ($taskPacketId === '') {
            return $this->refuse(self::REASON_TASK_ID_MISSING);
        }
        if ($objective === '') {
            return $this->refuse(self::REASON_OBJECTIVE_MISSING);
        }
        if ($allowedFiles === []) {
            return $this->refuse(self::REASON_ALLOWED_FILES_EMPTY);
        }
        $hasTestFile = $this->anyTestFile($allowedFiles);
        $hasImplFile = $this->anyImplFile($allowedFiles);
        if ($hasTestFile && ! $hasImplFile) {
            return $this->refuse(self::REASON_TEST_ONLY_SCOPE);
        }
        if ($scopeIn === []) {
            // Default scope_in to allowed_files if absent.
            $scopeIn = $allowedFiles;
        }
        if ($acceptance === []) {
            return $this->refuse(self::REASON_ACCEPTANCE_EMPTY);
        }
        if ($evidence === []) {
            return $this->refuse(self::REASON_EVIDENCE_EMPTY);
        }
        if (! $hasTestFile && ! $this->hasRunnableProof($acceptance)) {
            return $this->refuse(self::REASON_NO_RUNNABLE_PROOF);
        }

        if (array_key_exists('worker_executable', $packet) && ! (bool) $packet['worker_executable']) {
            return $this->refuse(self::REASON_NOT_WORKER_EXECUTABLE);
        }
        if ((bool) ($packet['operator_handoff_required'] ?? false)) {
            return $this->refuse(self::REASON_OPERATOR_HANDOFF);
        }

        $contract = is_array($packet['simplicity_contract'] ?? null) ? $packet['simplicity_contract'] : [];
        $finalOwner = (string) ($contract['final_runtime_owner'] ?? ($packet['final_runtime_owner'] ?? ''));
        $steady = (string) ($contract['steady_state_runtime_owner'] ?? ($packet['steady_state_runtime_owner'] ?? ''));

        if ($finalOwner !== 'atlas_native') {
            return $this->refuse(self::REASON_NON_NATIVE_OWNER);
        }
        if ($steady !== '' && ! in_array($steady, ['atlas_native', 'atlas_server'], true)) {
            return $this->refuse(self::REASON_NON_NATIVE_OWNER);
        }

        // Dependency checks (cover both simplicity_contract layout and flat layout).
        $operatorAllowed = $this->flag($contract, $packet, 'operator_dependency_allowed', false);
        $humanAllowed = $this->flag($contract, $packet, 'human_dependency_allowed', false);
        $providerAllowed = $this->flag($contract, $packet, 'external_provider_dependency_allowed', false);
        $humanRequired = (bool) ($packet['requires_human'] ?? false) || (bool) ($contract['requires_human'] ?? false);
        $operatorRequired = (bool) ($packet['requires_operator'] ?? false) || (bool) ($contract['requires_operator'] ?? false);
        $providerRequired = (bool) ($packet['requires_external_provider'] ?? false) || (bool) ($contract['requires_external_provider'] ?? false);

        if ($operatorRequired || $operatorAllowed) {
            return $this->refuse(self::REASON_OPERATOR_DEPENDENCY);
        }
        if ($humanRequired || $humanAllowed) {
            return $this->refuse(self::REASON_HUMAN_DEPENDENCY);
        }
        if ($providerRequired || $providerAllowed) {
            return $this->refuse(self::REASON_EXTERNAL_PROVIDER_DEPENDENCY);
        }

        $gates = array_values((array) ($packet['gates'] ?? []));
        $rollbackPlan = is_array($packet['rollback_plan'] ?? null) ? $packet['rollback_plan'] : [];

        $normalized = [
            'task_packet_id' => $taskPacketId,
            'lease_id' => $leaseId,
            'objective' => $objective,
            'allowed_files' => $allowedFiles,
            'scope_in' => $scopeIn,
            'acceptance_criteria' => $acceptance,
            'required_evidence' => $evidence,
            'gates' => $gates,
            'rollback_plan' => $rollbackPlan,
            'simplicity_contract' => 'atlas_native',
            'final_runtime_owner' => 'atlas_native',
            'steady_state_runtime_owner' => $steady !== '' ? $steady : 'atlas_server',
        ];

        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'ok' => true,
            'reason' => self::REASON_OK,
            'normalized_packet' => $normalized,
            'adapter_hash' => $this->adapterHash($normalized),
        ];
    }

    /** @param list<mixed> $files */
    private function anyTestFile(array $files): bool
    {
        foreach ($files as $f) {
            $f = (string) $f;
            if (str_starts_with($f, 'tests/') || str_ends_with($f, 'Test.php')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $files */
    private function anyImplFile(array $files): bool
    {
        foreach ($files as $f) {
            $f = (string) $f;
            if (! str_starts_with($f, 'tests/') && ! str_ends_with($f, 'Test.php')) {
                return true;
            }
        }

        return false;
    }

    /** @param list<mixed> $acceptance */
    private function hasRunnableProof(array $acceptance): bool
    {
        foreach ($acceptance as $criterion) {
            if (str_contains((string) $criterion, 'php artisan')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $contract
     * @param  array<string,mixed>  $packet
     */
    private function flag(array $contract, array $packet, string $key, bool $default): bool
    {
        if (array_key_exists($key, $contract)) {
            return (bool) $contract[$key];
        }
        if (array_key_exists($key, $packet)) {
            return (bool) $packet[$key];
        }

        return $default;
    }

    /**
     * @return array<string,mixed>
     */
    private function refuse(string $reason): array
    {
        return [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'ok' => false,
            'reason' => $reason,
            'normalized_packet' => null,
            'adapter_hash' => '',
        ];
    }

    /**
     * @param  array<string,mixed>  $normalized
     */
    private function adapterHash(array $normalized): string
    {
        ksort($normalized);
        $canonical = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'adapter_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
