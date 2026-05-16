<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CompletionSummary;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class FastPathTelemetry implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.fast_path_telemetry.v1';

    public const ALLOWED_TASK_KINDS = ['question', 'patch', 'repair', 'review', 'frontend', 'risky'];
    public const ALLOWED_RISK_LEVELS = ['R0', 'R1', 'R2', 'R3', 'R4', 'R5'];
    public const ALLOWED_CONTRACT_STATUSES = ['passed', 'failed', 'needs_review'];

    public const COMPLETION_STATES_REQUIRING_ERROR_LEDGER = [
        CompletionSummary::STATUS_FAILED,
        CompletionSummary::STATUS_NEEDS_REVIEW,
        CompletionSummary::STATUS_ESCALATE_FORGE,
    ];

    public const POST_HOC_FIELDS = ['escalation_was_correct'];

    private const HASH_FIELD = 'telemetry_hash';

    /**
     * @param  list<string>  $docTiersSelected
     * @param  list<string>  $gatesActivated
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $workspaceHash,
        public readonly string $taskKind,
        public readonly string $riskLevel,
        public readonly string $promptProjectionHash,
        public readonly string $contractCompletenessStatus,
        public readonly array $docTiersSelected,
        public readonly array $gatesActivated,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $providerCalls,
        public readonly int $repairAttempts,
        public readonly ?float $costEstimateUsd,
        public readonly ?int $wallTimeMs,
        public readonly string $completionState,
        public readonly bool $escalationTriggered,
        public readonly ?bool $escalationWasCorrect,
        public readonly bool $receiptPersisted,
        public readonly bool $errorLedgerWritten,
        public readonly string $telemetryHash,
    ) {
        if (! in_array($this->taskKind, self::ALLOWED_TASK_KINDS, true)) {
            throw new InvalidArgumentException(
                "FastPathTelemetry.task_kind invalid: '{$this->taskKind}'."
            );
        }
        if (! in_array($this->riskLevel, self::ALLOWED_RISK_LEVELS, true)) {
            throw new InvalidArgumentException(
                "FastPathTelemetry.risk_level invalid: '{$this->riskLevel}'."
            );
        }
        if (! in_array($this->contractCompletenessStatus, self::ALLOWED_CONTRACT_STATUSES, true)) {
            throw new InvalidArgumentException(
                "FastPathTelemetry.contract_completeness_status invalid: '{$this->contractCompletenessStatus}'."
            );
        }
        if (! in_array($this->completionState, CompletionSummary::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                "FastPathTelemetry.completion_state invalid: '{$this->completionState}'."
            );
        }
        if ($this->providerCalls < 0 || $this->repairAttempts < 0) {
            throw new InvalidArgumentException('FastPathTelemetry counters must be non-negative.');
        }

        // Invariant 3: error_ledger_written=true when completion in (failed,needs_review,escalate_forge)
        if (in_array($this->completionState, self::COMPLETION_STATES_REQUIRING_ERROR_LEDGER, true)
            && ! $this->errorLedgerWritten) {
            throw new InvalidArgumentException(
                "FastPathTelemetry invariant: completion_state='{$this->completionState}' requires error_ledger_written=true."
            );
        }
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'completion_state' => $this->completionState,
            'contract_completeness_status' => $this->contractCompletenessStatus,
            'cost_estimate_usd' => $this->costEstimateUsd,
            'doc_tiers_selected' => array_values($this->docTiersSelected),
            'error_ledger_written' => $this->errorLedgerWritten,
            'escalation_triggered' => $this->escalationTriggered,
            'escalation_was_correct' => $this->escalationWasCorrect,
            'gates_activated' => array_values($this->gatesActivated),
            'model' => $this->model,
            'prompt_projection_hash' => $this->promptProjectionHash,
            'provider' => $this->provider,
            'provider_calls' => $this->providerCalls,
            'provider_safe' => true,
            'receipt_persisted' => $this->receiptPersisted,
            'repair_attempts' => $this->repairAttempts,
            'risk_level' => $this->riskLevel,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'task_kind' => $this->taskKind,
            'telemetry_hash' => $this->telemetryHash,
            'wall_time_ms' => $this->wallTimeMs,
            'workspace_hash' => $this->workspaceHash,
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    /**
     * Invariant 5: hash excludes escalation_was_correct (post-hoc) and itself.
     */
    public function hash(): string
    {
        $payload = $this->toCanonicalArray();
        unset($payload[self::HASH_FIELD]);
        foreach (self::POST_HOC_FIELDS as $f) {
            unset($payload[$f]);
        }

        return CanonicalHasher::hash($payload);
    }

    public function isProviderSafe(): bool
    {
        return true;
    }

    /**
     * @param  list<string>  $docTiersSelected
     * @param  list<string>  $gatesActivated
     */
    public static function issue(
        string $runId,
        string $workspaceHash,
        string $taskKind,
        string $riskLevel,
        string $promptProjectionHash,
        string $contractCompletenessStatus,
        array $docTiersSelected,
        array $gatesActivated,
        string $provider,
        string $model,
        int $providerCalls,
        int $repairAttempts,
        ?float $costEstimateUsd,
        ?int $wallTimeMs,
        string $completionState,
        bool $escalationTriggered,
        bool $receiptPersisted,
        bool $errorLedgerWritten,
    ): self {
        $skeleton = new self(
            runId: $runId,
            workspaceHash: $workspaceHash,
            taskKind: $taskKind,
            riskLevel: $riskLevel,
            promptProjectionHash: $promptProjectionHash,
            contractCompletenessStatus: $contractCompletenessStatus,
            docTiersSelected: $docTiersSelected,
            gatesActivated: $gatesActivated,
            provider: $provider,
            model: $model,
            providerCalls: $providerCalls,
            repairAttempts: $repairAttempts,
            costEstimateUsd: $costEstimateUsd,
            wallTimeMs: $wallTimeMs,
            completionState: $completionState,
            escalationTriggered: $escalationTriggered,
            escalationWasCorrect: null,
            receiptPersisted: $receiptPersisted,
            errorLedgerWritten: $errorLedgerWritten,
            telemetryHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            workspaceHash: $workspaceHash,
            taskKind: $taskKind,
            riskLevel: $riskLevel,
            promptProjectionHash: $promptProjectionHash,
            contractCompletenessStatus: $contractCompletenessStatus,
            docTiersSelected: $docTiersSelected,
            gatesActivated: $gatesActivated,
            provider: $provider,
            model: $model,
            providerCalls: $providerCalls,
            repairAttempts: $repairAttempts,
            costEstimateUsd: $costEstimateUsd,
            wallTimeMs: $wallTimeMs,
            completionState: $completionState,
            escalationTriggered: $escalationTriggered,
            escalationWasCorrect: null,
            receiptPersisted: $receiptPersisted,
            errorLedgerWritten: $errorLedgerWritten,
            telemetryHash: $hash,
        );
    }

    public function withPostHocEscalationReview(bool $wasCorrect): self
    {
        return new self(
            runId: $this->runId,
            workspaceHash: $this->workspaceHash,
            taskKind: $this->taskKind,
            riskLevel: $this->riskLevel,
            promptProjectionHash: $this->promptProjectionHash,
            contractCompletenessStatus: $this->contractCompletenessStatus,
            docTiersSelected: $this->docTiersSelected,
            gatesActivated: $this->gatesActivated,
            provider: $this->provider,
            model: $this->model,
            providerCalls: $this->providerCalls,
            repairAttempts: $this->repairAttempts,
            costEstimateUsd: $this->costEstimateUsd,
            wallTimeMs: $this->wallTimeMs,
            completionState: $this->completionState,
            escalationTriggered: $this->escalationTriggered,
            escalationWasCorrect: $wasCorrect,
            receiptPersisted: $this->receiptPersisted,
            errorLedgerWritten: $this->errorLedgerWritten,
            telemetryHash: $this->telemetryHash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $cost = $payload['cost_estimate_usd'] ?? null;
        if ($cost !== null && ! is_float($cost) && ! is_int($cost)) {
            throw new InvalidArgumentException('FastPathTelemetry.cost_estimate_usd must be number or null.');
        }

        return new self(
            runId: AtlasDevSchemaArray::string($payload, 'run_id'),
            workspaceHash: AtlasDevSchemaArray::string($payload, 'workspace_hash'),
            taskKind: AtlasDevSchemaArray::string($payload, 'task_kind'),
            riskLevel: AtlasDevSchemaArray::string($payload, 'risk_level'),
            promptProjectionHash: AtlasDevSchemaArray::string($payload, 'prompt_projection_hash'),
            contractCompletenessStatus: AtlasDevSchemaArray::string($payload, 'contract_completeness_status'),
            docTiersSelected: AtlasDevSchemaArray::stringList($payload, 'doc_tiers_selected'),
            gatesActivated: AtlasDevSchemaArray::stringList($payload, 'gates_activated'),
            provider: AtlasDevSchemaArray::string($payload, 'provider'),
            model: AtlasDevSchemaArray::string($payload, 'model'),
            providerCalls: AtlasDevSchemaArray::int($payload, 'provider_calls'),
            repairAttempts: AtlasDevSchemaArray::int($payload, 'repair_attempts'),
            costEstimateUsd: $cost === null ? null : (float) $cost,
            wallTimeMs: AtlasDevSchemaArray::nullableInt($payload, 'wall_time_ms'),
            completionState: AtlasDevSchemaArray::string($payload, 'completion_state'),
            escalationTriggered: AtlasDevSchemaArray::bool($payload, 'escalation_triggered'),
            escalationWasCorrect: AtlasDevSchemaArray::nullableBool($payload, 'escalation_was_correct'),
            receiptPersisted: AtlasDevSchemaArray::bool($payload, 'receipt_persisted'),
            errorLedgerWritten: AtlasDevSchemaArray::bool($payload, 'error_ledger_written'),
            telemetryHash: AtlasDevSchemaArray::string($payload, 'telemetry_hash'),
        );
    }
}
