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
            allowedScope: array_values(array_map('strval', CanonicalKernelPayload::requireArray($data, 'allowed_scope'))),
            forbiddenScope: array_values(array_map('strval', CanonicalKernelPayload::requireArray($data, 'forbidden_scope'))),
            authorityEnvelope: CanonicalKernelPayload::requireArray($data, 'authority_envelope'),
            decisionReceipt: self::decisionReceipt($data),
            operatorContract: CanonicalKernelPayload::requireArray($data, 'operator_contract'),
            roleRoster: EngineeringRoleRoster::validateRoster(CanonicalKernelPayload::requireArray($data, 'role_roster')),
            providerRoute: CanonicalKernelPayload::requireArray($data, 'provider_route'),
            toolPermissions: CanonicalKernelPayload::requireArray($data, 'tool_permissions'),
            evidencePolicy: CanonicalKernelPayload::requireArray($data, 'evidence_policy'),
            releasePolicy: CanonicalKernelPayload::requireArray($data, 'release_policy'),
            rollbackPolicy: CanonicalKernelPayload::requireArray($data, 'rollback_policy'),
            outcomePolicy: CanonicalKernelPayload::requireArray($data, 'outcome_policy'),
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
        CanonicalKernelPayload::requireHash($receipt, 'hash');

        return $receipt;
    }
}
