<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\PlanVisible;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\Schemas\PlanVisible;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevRiskNormalizer;
use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;

/**
 * Atlas Dev A2 — Plan Visible projection.
 *
 * Builds an `atlas.dev.plan_visible.v1` plan from a work item BEFORE the
 * provider is invoked. The plan is persisted in
 * `atlas_programming_work_items.plan_json` and indexed by `plan_hash`.
 *
 * This service is intentionally narrow:
 *
 *  - It does NOT call the provider.
 *  - It does NOT decide whether to ship the plan; that's the approval gate.
 *  - It does NOT mutate the work item beyond `plan_json` and `plan_hash`.
 *  - It MAY be called multiple times for the same work item; each call
 *    produces a new pending plan (idempotent for identical input).
 *
 * The projection accepts either an Eloquent work item OR a primitive payload,
 * so it can be exercised from CLI, HTTP and tests without coupling to the
 * database when callers don't need persistence.
 */
final class AtlasDevPlanProjectionService
{
    /**
     * Project a plan from an existing work item and persist it back.
     *
     * @param  array{
     *   target_files: list<string>,
     *   tests_to_run: list<string>,
     *   proposed_diff_summary: string,
     *   risk_band?: string,
     * }  $proposal
     */
    public function projectAndPersist(AtlasProgrammingWorkItem $workItem, array $proposal): PlanVisible
    {
        $plan = $this->project($workItem->id, $workItem->spec_hash ?? '', $workItem->risk_level ?? 'medium', $proposal);

        $workItem->plan_json = $plan->toCanonicalArray();
        $workItem->plan_hash = $plan->hash();
        $workItem->save();

        return $plan;
    }

    /**
     * Project a plan from primitive inputs without touching the database.
     *
     * @param  array{
     *   target_files: list<string>,
     *   tests_to_run: list<string>,
     *   proposed_diff_summary: string,
     *   risk_band?: string,
     * }  $proposal
     */
    public function project(
        string $runId,
        string $taskContractHash,
        string $defaultRiskBand,
        array $proposal,
    ): PlanVisible {
        if ($runId === '') {
            throw new InvalidArgumentException('AtlasDevPlanProjectionService: run_id must not be empty.');
        }
        if ($taskContractHash === '') {
            throw new InvalidArgumentException(
                'AtlasDevPlanProjectionService: task_contract_hash must not be empty. '
                .'Atlas Dev A2 requires the originating spec hash so the plan is auditable.'
            );
        }

        $targetFiles = $this->validateStringList($proposal['target_files'] ?? [], 'target_files');
        $testsToRun = $this->validateStringList($proposal['tests_to_run'] ?? [], 'tests_to_run');
        $summary = (string) ($proposal['proposed_diff_summary'] ?? '');
        $riskBand = AtlasDevRiskNormalizer::planVisibleRiskBand($proposal['risk_band'] ?? $defaultRiskBand);

        return PlanVisible::issue(
            runId: $runId,
            taskContractHash: $taskContractHash,
            targetFiles: $targetFiles,
            testsToRun: $testsToRun,
            riskBand: $riskBand,
            proposedDiffSummary: $summary,
            approvalStatus: PlanVisible::APPROVAL_STATUS_PENDING,
        );
    }

    /**
     * Load a plan back from a work item's persisted plan_json column.
     *
     * Returns null when the work item has no plan persisted yet — this is
     * a normal state, not an error. Callers MUST handle null explicitly
     * (the gate that fires the provider should refuse to fire when null).
     */
    public function loadPersisted(AtlasProgrammingWorkItem $workItem): ?PlanVisible
    {
        $payload = (array) ($workItem->plan_json ?? []);
        if ($payload === []) {
            return null;
        }
        if (! isset($payload['schema_version']) || $payload['schema_version'] !== PlanVisible::SCHEMA_VERSION) {
            return null;
        }

        return PlanVisible::fromArray($payload);
    }

    /**
     * @param  mixed  $list
     * @return list<string>
     */
    private function validateStringList($list, string $fieldName): array
    {
        if (! is_array($list)) {
            throw new InvalidArgumentException("AtlasDevPlanProjectionService: {$fieldName} must be an array.");
        }

        return AtlasDevStringListNormalizer::requireNonEmptyStrings(array_values($list), $fieldName);
    }

}
