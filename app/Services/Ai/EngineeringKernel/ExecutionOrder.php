<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

use InvalidArgumentException;

final readonly class ExecutionOrder
{
    /**
     * @param  list<string>  $allowedScope
     * @param  list<string>  $forbiddenScope
     * @param  array<string,mixed>  $authorityEnvelope
     * @param  array<string,mixed>  $decisionReceipt
     * @param  array<string,mixed>  $operatorContract
     * @param  array<string,array<string,mixed>>  $roleRoster
     * @param  array<string,mixed>  $providerRoute
     * @param  array<string,mixed>  $toolPermissions
     * @param  array<string,mixed>  $evidencePolicy
     * @param  array<string,mixed>  $releasePolicy
     * @param  array<string,mixed>  $rollbackPolicy
     * @param  array<string,mixed>  $outcomePolicy
     */
    private function __construct(
        public string $schemaVersion,
        public string $runId,
        public string $deliveryId,
        public string $mode,
        public string $riskClass,
        public string $complexityBand,
        public string $durationRegime,
        public string $workTopology,
        public string $productIntentVerdictHash,
        public string $specHash,
        public string $worldModelSnapshotHash,
        public string $workspace,
        public string $baseCommit,
        public array $allowedScope,
        public array $forbiddenScope,
        public array $authorityEnvelope,
        public array $decisionReceipt,
        public array $operatorContract,
        public array $roleRoster,
        public array $providerRoute,
        public array $toolPermissions,
        public array $evidencePolicy,
        public array $releasePolicy,
        public array $rollbackPolicy,
        public array $outcomePolicy,
        public string $experimentRef,
        public string $idempotencyKey,
        public string $budgetPosture,
    ) {}

    /** @param array<string,mixed> $data */
    public static function fromArray(array $data): self
    {
        self::assertExactFields($data);
        $schema = CanonicalKernelPayload::requireString($data, 'schema_version');
        if ($schema !== 'atlas.execution_order.v2') {
            throw new InvalidArgumentException('schema_version_invalid');
        }
        $baseCommit = CanonicalKernelPayload::requireString($data, 'base_commit');
        if (preg_match('/^[a-f0-9]{40,64}$/', $baseCommit) !== 1) {
            throw new InvalidArgumentException('base_commit_invalid_hash');
        }
        $budget = CanonicalKernelPayload::requireString($data, 'budget_posture');
        if ($budget !== 'unbounded_quality_first') {
            throw new InvalidArgumentException('budget_posture_invalid');
        }

        $allowedScope = self::stringList($data, 'allowed_scope', false);
        $forbiddenScope = self::stringList($data, 'forbidden_scope', false);
        if (array_intersect($allowedScope, $forbiddenScope) !== []) {
            throw new InvalidArgumentException('allowed_forbidden_scope_overlap');
        }
        $authority = CanonicalKernelPayload::requireArray($data, 'authority_envelope');
        CanonicalKernelPayload::requireString($authority, 'kind');
        $roster = EngineeringRoleRoster::validateRoster(CanonicalKernelPayload::requireArray($data, 'role_roster'));
        $decisionReceipt = self::decisionReceipt($data);

        return new self(
            schemaVersion: $schema,
            runId: CanonicalKernelPayload::requireString($data, 'run_id'),
            deliveryId: CanonicalKernelPayload::requireString($data, 'delivery_id'),
            mode: CanonicalKernelPayload::requireEnum($data, 'mode', ['dev', 'forge', 'autonomos']),
            riskClass: CanonicalKernelPayload::requireEnum($data, 'risk_class', ['R0', 'R1', 'R2', 'R3', 'R4', 'R5']),
            complexityBand: CanonicalKernelPayload::requireEnum($data, 'complexity_band', ['C0', 'C1', 'C2', 'C3', 'C4', 'C5']),
            durationRegime: CanonicalKernelPayload::requireEnum($data, 'duration_regime', ['interactive', 'durable_task', 'obra', 'continuous']),
            workTopology: CanonicalKernelPayload::requireEnum($data, 'work_topology', ['single', 'candidate_set', 'workcell', 'DAG', 'portfolio']),
            productIntentVerdictHash: CanonicalKernelPayload::requireHash($data, 'product_intent_verdict_hash'),
            specHash: CanonicalKernelPayload::requireHash($data, 'spec_hash'),
            worldModelSnapshotHash: CanonicalKernelPayload::requireHash($data, 'world_model_snapshot_hash'),
            workspace: CanonicalKernelPayload::requireString($data, 'workspace'),
            baseCommit: $baseCommit,
            allowedScope: $allowedScope,
            forbiddenScope: $forbiddenScope,
            authorityEnvelope: $authority,
            decisionReceipt: $decisionReceipt,
            operatorContract: self::requiredNested($data, 'operator_contract', ['presence']),
            roleRoster: $roster,
            providerRoute: self::requiredNested($data, 'provider_route', ['provider', 'model']),
            toolPermissions: self::toolPermissions($data),
            evidencePolicy: self::evidencePolicy($data),
            releasePolicy: self::requiredNested($data, 'release_policy', ['kind']),
            rollbackPolicy: self::requiredNested($data, 'rollback_policy', ['kind']),
            outcomePolicy: self::outcomePolicy($data),
            experimentRef: CanonicalKernelPayload::requireString($data, 'experiment_ref'),
            idempotencyKey: CanonicalKernelPayload::requireString($data, 'idempotency_key'),
            budgetPosture: $budget,
        );
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => $this->schemaVersion,
            'run_id' => $this->runId,
            'delivery_id' => $this->deliveryId,
            'mode' => $this->mode,
            'risk_class' => $this->riskClass,
            'complexity_band' => $this->complexityBand,
            'duration_regime' => $this->durationRegime,
            'work_topology' => $this->workTopology,
            'product_intent_verdict_hash' => $this->productIntentVerdictHash,
            'spec_hash' => $this->specHash,
            'world_model_snapshot_hash' => $this->worldModelSnapshotHash,
            'workspace' => $this->workspace,
            'base_commit' => $this->baseCommit,
            'allowed_scope' => $this->allowedScope,
            'forbidden_scope' => $this->forbiddenScope,
            'authority_envelope' => $this->authorityEnvelope,
            'decision_receipt' => $this->decisionReceipt,
            'operator_contract' => $this->operatorContract,
            'role_roster' => $this->roleRoster,
            'provider_route' => $this->providerRoute,
            'tool_permissions' => $this->toolPermissions,
            'evidence_policy' => $this->evidencePolicy,
            'release_policy' => $this->releasePolicy,
            'rollback_policy' => $this->rollbackPolicy,
            'outcome_policy' => $this->outcomePolicy,
            'experiment_ref' => $this->experimentRef,
            'idempotency_key' => $this->idempotencyKey,
            'budget_posture' => $this->budgetPosture,
        ];
    }

    public function canonicalHash(): string
    {
        return CanonicalKernelPayload::hash($this->toArray());
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function decisionReceipt(array $data): array
    {
        $receipt = CanonicalKernelPayload::requireArray($data, 'decision_receipt');
        CanonicalKernelPayload::requireString($receipt, 'decision_event_id');

        return $receipt;
    }

    /** @param array<string,mixed> $data */
    private static function assertExactFields(array $data): void
    {
        $expected = ['schema_version', 'run_id', 'delivery_id', 'mode', 'risk_class', 'complexity_band', 'duration_regime', 'work_topology', 'product_intent_verdict_hash', 'spec_hash', 'world_model_snapshot_hash', 'workspace', 'base_commit', 'allowed_scope', 'forbidden_scope', 'authority_envelope', 'decision_receipt', 'operator_contract', 'role_roster', 'provider_route', 'tool_permissions', 'evidence_policy', 'release_policy', 'rollback_policy', 'outcome_policy', 'experiment_ref', 'idempotency_key', 'budget_posture'];
        $extra = array_diff(array_keys($data), $expected);
        if ($extra !== []) {
            throw new InvalidArgumentException('execution_order_unknown_fields');
        }
    }

    /** @param array<string,mixed> $data @return list<string> */
    private static function stringList(array $data, string $key, bool $allowEmpty): array
    {
        $value = $data[$key] ?? null;
        if (! is_array($value) || (! $allowEmpty && $value === [])) {
            throw new InvalidArgumentException("{$key}_required");
        }
        foreach ($value as $item) {
            if (! is_string($item) || trim($item) === '') {
                throw new InvalidArgumentException("{$key}_invalid_string");
            }
            if (str_starts_with($item, '/') || str_contains($item, '\\') || str_contains('/'.$item.'/', '/../') || str_contains('/'.$item.'/', '/./') || $item !== trim($item, '/')) {
                throw new InvalidArgumentException("{$key}_must_be_normalized_relative_paths");
            }
        }

        if (count(array_unique($value)) !== count($value)) {
            throw new InvalidArgumentException("{$key}_duplicate_path");
        }

        return array_values($value);
    }

    /** @param array<string,mixed> $data @param list<string> $keys @return array<string,mixed> */
    private static function requiredNested(array $data, string $field, array $keys): array
    {
        $value = CanonicalKernelPayload::requireArray($data, $field);
        foreach ($keys as $key) {
            CanonicalKernelPayload::requireString($value, $key);
        }

        return $value;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function toolPermissions(array $data): array
    {
        $permissions = CanonicalKernelPayload::requireArray($data, 'tool_permissions');
        if (! is_bool($permissions['read'] ?? null) || ! is_bool($permissions['mutate'] ?? null)) {
            throw new InvalidArgumentException('tool_permissions_invalid');
        }

        return $permissions;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function outcomePolicy(array $data): array
    {
        $policy = CanonicalKernelPayload::requireArray($data, 'outcome_policy');
        if (($policy['windows'] ?? null) !== EngineeringOutcome::WINDOWS) {
            throw new InvalidArgumentException('outcome_policy_windows_invalid');
        }

        return $policy;
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    private static function evidencePolicy(array $data): array
    {
        $policy = CanonicalKernelPayload::requireArray($data, 'evidence_policy');
        if (array_diff(array_keys($policy), ['acceptance_event_id', 'role_disposition_event_ids', 'behavioral_profile']) !== []) {
            throw new InvalidArgumentException('evidence_policy_caller_narrative_forbidden');
        }
        CanonicalKernelPayload::requireString($policy, 'acceptance_event_id');
        $roleEvents = CanonicalKernelPayload::requireArray($policy, 'role_disposition_event_ids');
        if (count($roleEvents) !== 22 || array_keys($roleEvents) !== array_keys(CanonicalKernelPayload::requireArray($data, 'role_roster'))) {
            throw new InvalidArgumentException('role_disposition_event_ids_mismatch');
        }
        foreach ($roleEvents as $eventId) {
            if (! is_string($eventId) || trim($eventId) === '') {
                throw new InvalidArgumentException('role_disposition_event_id_invalid');
            }
        }
        if (array_key_exists('behavioral_profile', $policy)) {
            CanonicalKernelPayload::requireEnum($policy, 'behavioral_profile', ['kernel_candidate_fixture_v1']);
        }

        return $policy;
    }
}
