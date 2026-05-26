<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanApprovalGate;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanProjectionService;
use App\Services\Ai\Programming\AtlasDev\PlanVisible\AtlasDevPlanTelemetry;
use Illuminate\Console\Command;

/**
 * Atlas CLI — Atlas Dev A2 (Plan Visible) entry.
 *
 * Definition of Done for Gap 2 requires:
 *
 *   "atlas-cli dev plan --task=<uuid> retorna plano JSON"
 *
 * This command projects, approves, rejects or inspects the
 * `atlas.dev.plan_visible.v1` envelope on a programming work item. The
 * provider is never invoked here; Atlas Dev A2 is explicitly the
 * pre-provider stage that humans approve before any code runs.
 *
 * Actions (mutually exclusive on a single invocation):
 *
 *   project    Build a new plan from --target-files / --tests / --summary
 *              and persist it as pending approval.
 *   approve    Transition the persisted plan to approved.
 *   reject     Transition the persisted plan to rejected and cancel run.
 *   inspect    Show the persisted plan + approval gate decision.
 *   telemetry  Print plan telemetry snapshot (approval rate, revisions).
 *              When passed without --task, aggregates across all items.
 *
 * The command always emits provider-safe JSON when `--json` is passed.
 */
class AtlasCliDevPlanCommand extends Command
{
    protected $signature = 'atlas:cli:dev:plan
        {action=inspect : project|approve|reject|inspect|telemetry}
        {--task= : Programming work item UUID}
        {--target-files=* : One or more file paths the plan should touch (for project)}
        {--tests=* : Focused tests to run (for project)}
        {--risk-band= : low|medium|high (for project, defaults to work item risk_level)}
        {--summary= : Proposed diff summary (for project)}
        {--json : Print machine-readable JSON}';

    protected $description = 'Atlas Dev A2 — project, approve, reject or inspect a Plan Visible for a work item.';

    public function handle(
        AtlasDevPlanProjectionService $projection,
        AtlasDevPlanApprovalGate $gate,
        AtlasDevPlanTelemetry $telemetry,
    ): int {
        $action = (string) $this->argument('action');
        $json = (bool) $this->option('json');

        if ($action === 'telemetry') {
            $snapshot = $telemetry->snapshot();
            $this->printOut($snapshot, $json);

            return self::SUCCESS;
        }

        $taskId = $this->option('task');
        if (! is_string($taskId) || $taskId === '') {
            $this->printOut([
                'status' => 'error',
                'error' => 'task_option_required',
                'message' => "--task=<uuid> is required for action '{$action}'.",
            ], $json);

            return self::INVALID;
        }

        $workItem = AtlasProgrammingWorkItem::query()->find($taskId);
        if ($workItem === null) {
            $this->printOut([
                'status' => 'error',
                'error' => 'work_item_not_found',
                'task_id' => $taskId,
            ], $json);

            return self::FAILURE;
        }

        return match ($action) {
            'project' => $this->runProject($projection, $gate, $workItem, $json),
            'approve' => $this->runApprove($projection, $gate, $workItem, $json),
            'reject' => $this->runReject($projection, $gate, $workItem, $json),
            'inspect' => $this->runInspect($projection, $gate, $workItem, $json),
            default => $this->failWith($json, 'unknown_action', "Unknown action '{$action}'. Use project|approve|reject|inspect|telemetry."),
        };
    }

    private function runProject(
        AtlasDevPlanProjectionService $projection,
        AtlasDevPlanApprovalGate $gate,
        AtlasProgrammingWorkItem $workItem,
        bool $json,
    ): int {
        $targetFiles = $this->stringList('target-files');
        $tests = $this->stringList('tests');
        $summary = (string) ($this->option('summary') ?? '');
        $riskBand = (string) ($this->option('risk-band') ?? $workItem->risk_level ?? 'medium');

        if ($targetFiles === []) {
            return $this->failWith($json, 'missing_target_files', 'project requires at least one --target-files entry.');
        }
        if (trim($summary) === '') {
            return $this->failWith($json, 'missing_summary', 'project requires --summary.');
        }

        $plan = $projection->projectAndPersist($workItem, [
            'target_files' => $targetFiles,
            'tests_to_run' => $tests,
            'proposed_diff_summary' => $summary,
            'risk_band' => $riskBand,
        ]);

        $this->printOut([
            'status' => 'ok',
            'action' => 'project',
            'task_id' => $workItem->id,
            'plan' => $plan->toCanonicalArray(),
            'gate' => $gate->evaluate($plan),
        ], $json);

        return self::SUCCESS;
    }

    private function runApprove(
        AtlasDevPlanProjectionService $projection,
        AtlasDevPlanApprovalGate $gate,
        AtlasProgrammingWorkItem $workItem,
        bool $json,
    ): int {
        $plan = $projection->loadPersisted($workItem);
        if ($plan === null) {
            return $this->failWith($json, 'no_plan_to_approve', 'No persisted plan on work item; run project first.');
        }
        $approved = $gate->applyOperatorDecision($plan, 'approve');
        $workItem->plan_json = $approved->toCanonicalArray();
        $workItem->plan_hash = $approved->hash();
        $this->recordRevision($workItem, 'approved');
        $workItem->save();

        $this->printOut([
            'status' => 'ok',
            'action' => 'approve',
            'task_id' => $workItem->id,
            'plan' => $approved->toCanonicalArray(),
            'gate' => $gate->evaluate($approved),
        ], $json);

        return self::SUCCESS;
    }

    private function runReject(
        AtlasDevPlanProjectionService $projection,
        AtlasDevPlanApprovalGate $gate,
        AtlasProgrammingWorkItem $workItem,
        bool $json,
    ): int {
        $plan = $projection->loadPersisted($workItem);
        if ($plan === null) {
            return $this->failWith($json, 'no_plan_to_reject', 'No persisted plan on work item; nothing to reject.');
        }
        $rejected = $gate->applyOperatorDecision($plan, 'reject');
        $workItem->plan_json = $rejected->toCanonicalArray();
        $workItem->plan_hash = $rejected->hash();
        $this->recordRevision($workItem, 'rejected');
        $workItem->save();

        $this->printOut([
            'status' => 'ok',
            'action' => 'reject',
            'task_id' => $workItem->id,
            'plan' => $rejected->toCanonicalArray(),
            'gate' => $gate->evaluate($rejected),
        ], $json);

        return self::SUCCESS;
    }

    private function runInspect(
        AtlasDevPlanProjectionService $projection,
        AtlasDevPlanApprovalGate $gate,
        AtlasProgrammingWorkItem $workItem,
        bool $json,
    ): int {
        $plan = $projection->loadPersisted($workItem);
        $this->printOut([
            'status' => 'ok',
            'action' => 'inspect',
            'task_id' => $workItem->id,
            'plan' => $plan?->toCanonicalArray(),
            'gate' => $gate->evaluate($plan),
        ], $json);

        return self::SUCCESS;
    }

    private function recordRevision(AtlasProgrammingWorkItem $workItem, string $decision): void
    {
        $meta = (array) ($workItem->metadata_json ?? []);
        $revisions = (array) ($meta['plan_revisions'] ?? []);
        $revisions[] = [
            'at' => now()->toAtomString(),
            'decision' => $decision,
            'plan_hash' => (string) $workItem->plan_hash,
        ];
        $meta['plan_revisions'] = $revisions;
        $workItem->metadata_json = $meta;
    }

    /**
     * @return list<string>
     */
    private function stringList(string $option): array
    {
        $raw = (array) ($this->option($option) ?? []);
        $clean = [];
        foreach ($raw as $value) {
            if (is_string($value) && $value !== '') {
                $clean[] = $value;
            }
        }

        return $clean;
    }

    private function failWith(bool $json, string $error, string $message): int
    {
        $this->printOut([
            'status' => 'error',
            'error' => $error,
            'message' => $message,
        ], $json);

        return self::FAILURE;
    }

    private function printOut(array $payload, bool $json): void
    {
        if ($json) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');

            return;
        }
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}');
    }
}
