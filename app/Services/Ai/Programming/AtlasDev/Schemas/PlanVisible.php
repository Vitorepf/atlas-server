<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Schemas;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

/**
 * Canonical `atlas.dev.plan_visible.v1` contract.
 *
 * Atlas Dev A2 (Plan Visible) emits ONE plan per task BEFORE the provider is
 * invoked. The operator (or downstream gate) reads the plan and approves or
 * rejects it. Rejection cancels execution; approval persists the plan into
 * `atlas_programming_work_items.plan_payload` and unblocks provider call.
 *
 * The plan is intentionally narrow and editorial — the operator must be
 * able to read it in seconds, not minutes. Fields:
 *
 *  - `target_files[]`           — files the patch is expected to touch
 *  - `tests_to_run[]`           — focused tests to run for verification
 *  - `risk_band`                — `low|medium|high` (drives autonomy cap)
 *  - `proposed_diff_summary`    — human-readable summary of what changes
 *  - `run_id`                   — links plan to AtlasDev run
 *  - `task_contract_hash`       — links plan to the originating MiniProgrammingSpec
 *  - `plan_hash`                — canonical hash of the plan (computed)
 *
 * The plan is provider-safe by default: file paths and test paths are
 * shape-tokens, not contents. If a caller needs to embed sensitive content
 * in `proposed_diff_summary`, they MUST set `providerSafe=false` explicitly
 * — this contract never silently strips a field.
 */
final class PlanVisible implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.plan_visible.v1';

    public const RISK_BAND_LOW = 'low';

    public const RISK_BAND_MEDIUM = 'medium';

    public const RISK_BAND_HIGH = 'high';

    public const ALLOWED_RISK_BANDS = [
        self::RISK_BAND_LOW,
        self::RISK_BAND_MEDIUM,
        self::RISK_BAND_HIGH,
    ];

    public const APPROVAL_STATUS_PENDING = 'pending';

    public const APPROVAL_STATUS_APPROVED = 'approved';

    public const APPROVAL_STATUS_REJECTED = 'rejected';

    public const ALLOWED_APPROVAL_STATUSES = [
        self::APPROVAL_STATUS_PENDING,
        self::APPROVAL_STATUS_APPROVED,
        self::APPROVAL_STATUS_REJECTED,
    ];

    private const HASH_FIELD = 'plan_hash';

    private const SUMMARY_MAX_CHARS = 4000;

    /**
     * @param  list<string>  $targetFiles
     * @param  list<string>  $testsToRun
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $taskContractHash,
        public readonly array $targetFiles,
        public readonly array $testsToRun,
        public readonly string $riskBand,
        public readonly string $proposedDiffSummary,
        public readonly string $approvalStatus,
        public readonly string $planHash,
        public readonly bool $providerSafe = true,
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('PlanVisible.run_id must not be empty.');
        }
        if ($this->taskContractHash === '') {
            throw new InvalidArgumentException('PlanVisible.task_contract_hash must not be empty.');
        }
        if (! in_array($this->riskBand, self::ALLOWED_RISK_BANDS, true)) {
            throw new InvalidArgumentException(
                'PlanVisible.risk_band must be one of ['.implode(',', self::ALLOWED_RISK_BANDS)."], got '{$this->riskBand}'."
            );
        }
        if (! in_array($this->approvalStatus, self::ALLOWED_APPROVAL_STATUSES, true)) {
            throw new InvalidArgumentException(
                'PlanVisible.approval_status must be one of ['.implode(',', self::ALLOWED_APPROVAL_STATUSES)."], got '{$this->approvalStatus}'."
            );
        }
        if ($this->targetFiles === []) {
            throw new InvalidArgumentException(
                'PlanVisible.target_files must contain at least one file. An empty plan defeats Plan Visible.'
            );
        }
        foreach ($this->targetFiles as $i => $file) {
            if (! is_string($file) || $file === '') {
                throw new InvalidArgumentException("target_files[{$i}] must be a non-empty string.");
            }
        }
        foreach ($this->testsToRun as $i => $test) {
            if (! is_string($test) || $test === '') {
                throw new InvalidArgumentException("tests_to_run[{$i}] must be a non-empty string.");
            }
        }
        if (trim($this->proposedDiffSummary) === '') {
            throw new InvalidArgumentException(
                'PlanVisible.proposed_diff_summary must not be empty. An empty summary defeats operator review.'
            );
        }
        if (mb_strlen($this->proposedDiffSummary) > self::SUMMARY_MAX_CHARS) {
            throw new InvalidArgumentException(
                'PlanVisible.proposed_diff_summary exceeds '.self::SUMMARY_MAX_CHARS.' chars; split into multiple plans or summarize further.'
            );
        }
        if ($this->testsToRun === [] && $this->riskBand !== self::RISK_BAND_LOW) {
            throw new InvalidArgumentException(
                'PlanVisible.tests_to_run must contain at least one test when risk_band is medium or high.'
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
            'approval_status' => $this->approvalStatus,
            'plan_hash' => $this->planHash,
            'proposed_diff_summary' => $this->proposedDiffSummary,
            'risk_band' => $this->riskBand,
            'run_id' => $this->runId,
            'schema_version' => self::SCHEMA_VERSION,
            'target_files' => array_values($this->targetFiles),
            'task_contract_hash' => $this->taskContractHash,
            'tests_to_run' => array_values($this->testsToRun),
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

    public function hash(): string
    {
        return CanonicalHasher::hashWithout($this->toCanonicalArray(), self::HASH_FIELD);
    }

    public function isProviderSafe(): bool
    {
        return $this->providerSafe;
    }

    public function isApproved(): bool
    {
        return $this->approvalStatus === self::APPROVAL_STATUS_APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->approvalStatus === self::APPROVAL_STATUS_REJECTED;
    }

    public function isPending(): bool
    {
        return $this->approvalStatus === self::APPROVAL_STATUS_PENDING;
    }

    /**
     * Returns a new instance with `approval_status=approved` and a fresh hash.
     *
     * Approval is intentionally a state transition, not a mutation: callers
     * receive a new value object and must persist it explicitly. The plan
     * hash changes because approval is a meaningful canonical field, and
     * downstream gates that pinned an earlier hash should see drift.
     */
    public function approve(): self
    {
        return self::issue(
            runId: $this->runId,
            taskContractHash: $this->taskContractHash,
            targetFiles: $this->targetFiles,
            testsToRun: $this->testsToRun,
            riskBand: $this->riskBand,
            proposedDiffSummary: $this->proposedDiffSummary,
            approvalStatus: self::APPROVAL_STATUS_APPROVED,
        );
    }

    /**
     * Returns a new instance with `approval_status=rejected` and a fresh hash.
     */
    public function reject(): self
    {
        return self::issue(
            runId: $this->runId,
            taskContractHash: $this->taskContractHash,
            targetFiles: $this->targetFiles,
            testsToRun: $this->testsToRun,
            riskBand: $this->riskBand,
            proposedDiffSummary: $this->proposedDiffSummary,
            approvalStatus: self::APPROVAL_STATUS_REJECTED,
        );
    }

    /**
     * @param  list<string>  $targetFiles
     * @param  list<string>  $testsToRun
     */
    public static function issue(
        string $runId,
        string $taskContractHash,
        array $targetFiles,
        array $testsToRun,
        string $riskBand,
        string $proposedDiffSummary,
        string $approvalStatus = self::APPROVAL_STATUS_PENDING,
    ): self {
        $skeleton = new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            targetFiles: $targetFiles,
            testsToRun: $testsToRun,
            riskBand: $riskBand,
            proposedDiffSummary: $proposedDiffSummary,
            approvalStatus: $approvalStatus,
            planHash: 'pending',
        );
        $hash = $skeleton->hash();

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            targetFiles: $targetFiles,
            testsToRun: $testsToRun,
            riskBand: $riskBand,
            proposedDiffSummary: $proposedDiffSummary,
            approvalStatus: $approvalStatus,
            planHash: $hash,
        );
    }

    public static function fromArray(array $payload): self
    {
        $targetFiles = [];
        foreach (array_values((array) ($payload['target_files'] ?? [])) as $i => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("target_files[{$i}] must be a string.");
            }
            $targetFiles[] = $value;
        }

        $testsToRun = [];
        foreach (array_values((array) ($payload['tests_to_run'] ?? [])) as $i => $value) {
            if (! is_string($value)) {
                throw new InvalidArgumentException("tests_to_run[{$i}] must be a string.");
            }
            $testsToRun[] = $value;
        }

        $runId = (string) ($payload['run_id'] ?? '');
        $taskContractHash = (string) ($payload['task_contract_hash'] ?? '');
        $riskBand = (string) ($payload['risk_band'] ?? '');
        $summary = (string) ($payload['proposed_diff_summary'] ?? '');
        $approvalStatus = (string) ($payload['approval_status'] ?? self::APPROVAL_STATUS_PENDING);
        $providedHash = (string) ($payload['plan_hash'] ?? '');

        if ($providedHash === '') {
            return self::issue(
                runId: $runId,
                taskContractHash: $taskContractHash,
                targetFiles: $targetFiles,
                testsToRun: $testsToRun,
                riskBand: $riskBand,
                proposedDiffSummary: $summary,
                approvalStatus: $approvalStatus,
            );
        }

        return new self(
            runId: $runId,
            taskContractHash: $taskContractHash,
            targetFiles: $targetFiles,
            testsToRun: $testsToRun,
            riskBand: $riskBand,
            proposedDiffSummary: $summary,
            approvalStatus: $approvalStatus,
            planHash: $providedHash,
        );
    }
}
