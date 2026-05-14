<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Jobs\AtlasCodeForgeLiveExecutionJob;
use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeGovernedExecutionService;
use App\Services\Ai\Programming\AtlasForgeLiveExecutionService;
use App\Services\Ai\Programming\Governance\ProgrammingGovernanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Throwable;

/**
 * Atlas Code -> Forge Live Execution bridge.
 *
 * This is the first product-facing execution entrypoint for Atlas Code SCOR-1:
 * a selected Obra triggers the certified Forge Live Execution path, then the
 * result is persisted as engineering run/evidence for the cockpit to render.
 */
final class AtlasCodeForgeExecutionController extends Controller
{
    public function store(
        Request $request,
        AtlasProject $project,
        AtlasForgeLiveExecutionService $service,
    ): JsonResponse {
        $data = $request->validate([
            'simulate_failure' => ['nullable', 'boolean'],
        ]);

        $simulateFailure = (bool) ($data['simulate_failure'] ?? false);
        $result = $this->executeAndPersist($project, $service, $simulateFailure);

        return response()->json($result, ($result['report']['forge_live_execution_status'] ?? null) === 'blocked' ? 409 : 201);
    }

    public function startAsync(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'simulate_failure' => ['nullable', 'boolean'],
        ]);

        $simulateFailure = (bool) ($data['simulate_failure'] ?? false);
        $execution = $this->rememberAsyncExecution($project, [
            'schema_version' => 'atlas.code.forge_live_execution.async.v1',
            'execution_id' => (string) Str::ulid(),
            'status' => 'queued',
            'obra_id' => (string) $project->getKey(),
            'simulate_failure' => $simulateFailure,
            'queued_at' => now()->toJSON(),
            'started_at' => null,
            'finished_at' => null,
            'job_dispatched' => true,
            'command' => $this->commandFor($project, strict: true, simulateFailure: $simulateFailure),
            'snapshot_status' => null,
            'run_id' => null,
            'evidence_id' => null,
            'error' => null,
        ]);

        AtlasCodeForgeLiveExecutionJob::dispatch((string) $project->getKey(), $simulateFailure, (string) $execution['execution_id']);

        return response()->json([
            'schema_version' => 'atlas.code.forge_live_execution_async_response.v1',
            'work_id' => (string) $project->getKey(),
            'execution' => $execution,
        ], 202);
    }

    public function showAsync(AtlasProject $project, string $executionId): JsonResponse
    {
        $execution = $this->asyncExecutionFor($project, $executionId);
        if ($execution === null) {
            return response()->json([
                'schema_version' => 'atlas.code.forge_live_execution_async_response.v1',
                'work_id' => (string) $project->getKey(),
                'execution' => null,
                'error' => 'forge_async_execution_not_found',
            ], 404);
        }

        return response()->json([
            'schema_version' => 'atlas.code.forge_live_execution_async_response.v1',
            'work_id' => (string) $project->getKey(),
            'execution' => $execution,
            'snapshot' => data_get($project->metadata, 'latest_forge_live_execution'),
        ]);
    }

    public function showHistory(AtlasProject $project, string $historyId): JsonResponse
    {
        $entry = $this->historyEntryFor($project, $historyId);
        if ($entry === null) {
            return response()->json([
                'schema_version' => 'atlas.code.forge_live_execution_history_replay.v1',
                'work_id' => (string) $project->getKey(),
                'history_id' => $historyId,
                'status' => 'missing',
                'error' => 'forge_history_entry_not_found',
            ], 404);
        }

        $entryObraId = (string) ($entry['obra_id'] ?? '');
        if ($entryObraId !== '' && $entryObraId !== (string) $project->getKey()) {
            return response()->json([
                'schema_version' => 'atlas.code.forge_live_execution_history_replay.v1',
                'work_id' => (string) $project->getKey(),
                'history_id' => $historyId,
                'status' => 'blocked',
                'error' => 'forge_history_obra_mismatch',
                'blocker' => 'obra_binding_mismatch',
            ], 403);
        }

        $historyEntry = $this->historyEntryForResponse($entry);
        $snapshot = $this->fullSnapshotForHistoryEntry($project, $historyEntry);
        $digest = is_array($historyEntry['evidence_pack_digest'] ?? null)
            ? $historyEntry['evidence_pack_digest']
            : ($snapshot ? $this->evidencePackDigestFromSnapshot($snapshot) : null);
        $stageTimeline = is_array(data_get($snapshot, 'stage_timeline')) ? (array) data_get($snapshot, 'stage_timeline') : [];

        return response()->json([
            'schema_version' => 'atlas.code.forge_live_execution_history_replay.v1',
            'work_id' => (string) $project->getKey(),
            'history_id' => $historyId,
            'status' => (string) ($historyEntry['status'] ?? 'unknown'),
            'source_authority' => 'AtlasProject.metadata.atlas_code_forge_live_execution_history',
            'history_entry' => $historyEntry,
            'evidence_pack_digest' => $digest,
            'stage_timeline_digest' => [
                'schema_version' => 'atlas.code.forge_live_execution.stage_timeline_digest.v1',
                'status' => (string) ($historyEntry['status'] ?? 'unknown'),
                'stage_timeline_hash' => data_get($digest, 'stage_timeline_hash'),
                'total' => data_get($stageTimeline, 'total', $historyEntry['stage_count'] ?? null),
                'blocking' => data_get($stageTimeline, 'blocking'),
            ],
            'replay' => [
                'read_only' => true,
                'command' => $historyEntry['command'] ?? null,
                'strict_command' => $historyEntry['strict_command'] ?? null,
                'failure_probe_command' => str_contains((string) ($historyEntry['command'] ?? ''), '--simulate-failure')
                    ? ($historyEntry['command'] ?? null)
                    : null,
                'external_provider_call' => (bool) ($historyEntry['external_provider_call'] ?? false),
            ],
            'snapshot' => $snapshot,
            'snapshot_available' => $snapshot !== null,
            'review' => $this->reviewForHistoryId($project, $historyId),
        ]);
    }

    public function executeAsyncJob(
        AtlasProject $project,
        AtlasForgeLiveExecutionService $service,
        bool $simulateFailure,
        string $executionId,
    ): void {
        $this->rememberAsyncExecution($project, [
            'execution_id' => $executionId,
            'status' => 'running',
            'started_at' => now()->toJSON(),
            'error' => null,
        ]);

        try {
            $result = $this->executeAndPersist($project, $service, $simulateFailure);
            $snapshot = (array) ($result['snapshot'] ?? []);
            $status = (string) ($snapshot['status'] ?? 'unknown');

            $this->rememberAsyncExecution($project, [
                'execution_id' => $executionId,
                'status' => $status === 'passed' ? 'completed' : $status,
                'finished_at' => now()->toJSON(),
                'snapshot_status' => $status,
                'run_id' => $snapshot['run_id'] ?? null,
                'evidence_id' => $snapshot['evidence_id'] ?? null,
                'remaining_blockers' => array_values((array) ($snapshot['remaining_blockers'] ?? [])),
                'completion_claim_allowed' => (bool) data_get($snapshot, 'diff_scope.completion_gate.completion_claim_allowed', false),
            ]);
        } catch (Throwable $throwable) {
            $this->markAsyncFailed($project, $executionId, $throwable);

            throw $throwable;
        }
    }

    public function markAsyncFailed(AtlasProject $project, string $executionId, Throwable $throwable): void
    {
        $this->rememberAsyncExecution($project, [
            'execution_id' => $executionId,
            'status' => 'failed',
            'finished_at' => now()->toJSON(),
            'error' => $throwable->getMessage(),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function executeAndPersist(
        AtlasProject $project,
        AtlasForgeLiveExecutionService $service,
        bool $simulateFailure,
    ): array {
        $report = $service->execute([
            'obra_id' => (string) $project->getKey(),
            'simulate_test_failure' => $simulateFailure,
        ]);

        $governedExecution = $simulateFailure ? null : $this->governedExecutionForProject($project);
        $snapshot = $this->snapshotFromReport($project, $report, $simulateFailure, $governedExecution);
        $run = $this->persistRun($project, $report, $snapshot);
        $evidence = $this->persistEvidence($project, $report, $snapshot, $run?->id);

        $snapshot['run_id'] = $run?->id;
        $snapshot['evidence_id'] = $evidence?->id;
        $snapshotStatus = (string) ($snapshot['status'] ?? 'blocked');
        $snapshot['evidence_pack'] = $this->evidencePackFromReport($project, $report, $snapshotStatus, $simulateFailure, $run?->id, $evidence?->id, $governedExecution);
        $snapshot['programming_governance_feedback'] = $this->syncProgrammingGovernanceFeedback($project, $report, $snapshot, $run?->id, $evidence?->id);

        $this->rememberProjectSnapshot($project, $snapshot);

        return [
            'schema_version' => 'atlas.code.forge_live_execution_response.v1',
            'work_id' => (string) $project->getKey(),
            'report' => $report,
            'snapshot' => $snapshot,
            'persistence' => [
                'engineering_run_id' => $run?->id,
                'engineering_evidence_id' => $evidence?->id,
                'engineering_run_persisted' => $run !== null,
                'engineering_evidence_persisted' => $evidence !== null,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function snapshotFromReport(AtlasProject $project, array $report, bool $simulateFailure, ?array $governedExecution = null): array
    {
        $stages = collect((array) ($report['stages'] ?? []));
        $contextPack = (array) data_get($stages->firstWhere('name', 'context_pack'), 'context_pack', []);
        $repair = (array) ($stages->firstWhere('name', 'repair_loop') ?? []);
        $status = (string) ($report['forge_live_execution_status'] ?? 'blocked');
        $stageTimeline = $this->stageTimelineFromStages($stages->values()->all());
        $governedTaskContract = $this->governedTaskContractForProject($project, $status);
        $governedStatus = is_array($governedExecution) ? (string) ($governedExecution['status'] ?? 'blocked') : null;
        $effectiveStatus = $status;
        if ($governedTaskContract !== null && $governedStatus !== null && $governedStatus !== 'passed') {
            $effectiveStatus = 'blocked';
        }
        $remainingBlockers = array_values(array_unique(array_merge(
            (array) ($report['remaining_blockers'] ?? []),
            $governedStatus !== null && $governedStatus !== 'passed'
                ? (array) ($governedExecution['remaining_blockers'] ?? ['governed_execution_not_passed'])
                : [],
        )));

        return [
            'schema_version' => 'atlas.code.forge_live_execution.snapshot.v1',
            'status' => $effectiveStatus,
            'obra_id' => (string) $project->getKey(),
            'execution_source' => $governedTaskContract !== null
                ? 'programming_governance.tasks_json'
                : 'fallback_live_task_contract',
            'command' => $this->commandFor($project, strict: false, simulateFailure: $simulateFailure),
            'strict_command' => $this->commandFor($project, strict: true, simulateFailure: false),
            'simulate_failure' => $simulateFailure,
            'last_run_at' => now()->toJSON(),
            'stage_count' => $stages->count(),
            'stage_statuses' => $stages
                ->map(fn (array $stage): array => [
                    'name' => (string) ($stage['name'] ?? 'stage'),
                    'status' => (string) ($stage['status'] ?? 'unknown'),
                    'blocker' => $stage['blocker'] ?? null,
                ])
                ->values()
                ->all(),
            'stage_timeline' => $stageTimeline,
            'context_pack' => [
                'schema_version' => 'atlas.code.context_pack_artifact.v1',
                'context_completeness' => $contextPack['context_completeness'] ?? null,
                'ranked_ref_count' => $contextPack['ranked_ref_count'] ?? null,
                'present_ref_count' => $contextPack['present_ref_count'] ?? null,
                'context_pack_hash' => $contextPack['context_pack_hash'] ?? null,
                'ranked_refs' => $this->contextPackRefs($contextPack),
            ],
            'repair_loop' => [
                'status' => $repair['status'] ?? null,
                'triggered' => (bool) ($repair['triggered'] ?? false),
                'plan_status' => $repair['plan_status'] ?? data_get($repair, 'plan.status'),
                'next_action' => $repair['next_action'] ?? data_get($repair, 'plan.next_action'),
            ],
            'task_contract' => $governedTaskContract ?? $this->taskContractFromReport($report, $effectiveStatus),
            'governed_execution' => $this->governedExecutionSnapshot($governedExecution),
            'diff_scope' => $this->diffScopeFromGovernedExecution($governedExecution, $governedTaskContract, $effectiveStatus)
                ?? $this->diffScopeFromReport($report, $effectiveStatus),
            'evidence_pack' => $this->evidencePackFromReport($project, $report, $effectiveStatus, $simulateFailure, governedExecution: $governedExecution),
            'evidence_ref_count' => count((array) ($report['evidence_refs'] ?? [])),
            'ledger_event_count' => count((array) ($report['ledger_event_ids'] ?? [])),
            'remaining_blockers' => $remainingBlockers,
            'external_provider_call' => (bool) ($report['external_provider_call'] ?? false),
        ];
    }

    /**
     * Push the execution evidence back into the governed WorkItem when Atlas
     * Code is operating from a real Spec/Plan/Task queue.
     *
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    private function syncProgrammingGovernanceFeedback(
        AtlasProject $project,
        array $report,
        array $snapshot,
        mixed $runId,
        mixed $evidenceId,
    ): ?array {
        $governedFeedback = data_get($snapshot, 'governed_execution.governance_feedback');
        if (is_array($governedFeedback)) {
            return $governedFeedback;
        }

        $workItem = $this->programmingWorkItemForProject($project);
        if (! $workItem) {
            return null;
        }

        try {
            /** @var ProgrammingGovernanceService $governance */
            $governance = app(ProgrammingGovernanceService::class);
            $receipt = $this->programmingEvidenceReceipt($project, $report, $snapshot, $runId, $evidenceId);
            $governance->appendEvidence($workItem, $receipt);
            $verification = $governance->verify($workItem->refresh(), ['evidence-required', 'scope-guard']);
            $gateSummary = (array) ($verification['gate_summary'] ?? []);

            return [
                'schema_version' => 'atlas.code.programming_governance_feedback.v1',
                'status' => 'synced',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'evidence_appended' => true,
                'receipt_id' => $receipt['receipt_id'],
                'gate_summary' => $gateSummary,
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.code.programming_governance_feedback.v1',
                'status' => 'degraded',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'evidence_appended' => false,
                'reason' => $e->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function governedExecutionForProject(AtlasProject $project): ?array
    {
        $workItem = $this->programmingWorkItemForProject($project);
        if (! $workItem || (array) $workItem->tasks_json === []) {
            return null;
        }

        try {
            /** @var AtlasForgeGovernedExecutionService $service */
            $service = app(AtlasForgeGovernedExecutionService::class);

            return $service->execute($project, $workItem);
        } catch (Throwable $e) {
            return [
                'schema_version' => AtlasForgeGovernedExecutionService::SCHEMA_VERSION,
                'status' => 'blocked',
                'obra_id' => (string) $project->getKey(),
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'remaining_blockers' => ['governed_execution_exception'],
                'reason' => $e->getMessage(),
                'external_provider_call' => false,
                'live_workspace_mutated' => false,
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function programmingEvidenceReceipt(
        AtlasProject $project,
        array $report,
        array $snapshot,
        mixed $runId,
        mixed $evidenceId,
    ): array {
        $files = $this->changedFilesFromReport($report);
        $tests = array_values(array_filter(array_merge(
            (array) data_get($snapshot, 'task_contract.validation_commands', []),
            [(string) data_get(collect((array) ($report['stages'] ?? []))->firstWhere('name', 'test_run'), 'result.command', '')],
        ), fn (string $command): bool => $command !== ''));
        $receiptPayload = [
            'obra_id' => (string) $project->getKey(),
            'run_id' => $runId ? (string) $runId : null,
            'evidence_id' => $evidenceId ? (string) $evidenceId : null,
            'status' => (string) ($snapshot['status'] ?? 'unknown'),
            'command' => (string) ($snapshot['command'] ?? ''),
            'files' => $files,
            'tests' => $tests,
            'stage_receipt_ids' => array_values((array) ($report['evidence_refs'] ?? [])),
            'ledger_event_ids' => array_values((array) ($report['ledger_event_ids'] ?? [])),
            'evidence_pack_hash' => data_get($snapshot, 'evidence_pack.integrity.evidence_pack_hash'),
            'execution_source' => $snapshot['execution_source'] ?? null,
        ];

        return [
            'schema_version' => 'atlas.code.programming_work_item_execution_evidence.v1',
            'receipt_id' => hash('sha256', json_encode($receiptPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'evidence_type' => 'forge_live_execution',
            'status' => (string) ($snapshot['status'] ?? 'unknown'),
            'command' => (string) ($snapshot['command'] ?? ''),
            'output' => sprintf(
                'forge_live_execution=%s; stages=%d; evidence_refs=%d; ledger_events=%d',
                (string) ($snapshot['status'] ?? 'unknown'),
                (int) ($snapshot['stage_count'] ?? 0),
                (int) ($snapshot['evidence_ref_count'] ?? 0),
                (int) ($snapshot['ledger_event_count'] ?? 0),
            ),
            'files' => $files,
            'tests' => $tests,
            'diff_path' => null,
            'artifact_url' => null,
            'summary' => 'Atlas Code Forge Live Execution evidence synced to Programming Governance WorkItem.',
            'storage' => [
                'persisted' => $evidenceId !== null,
                'table' => $evidenceId !== null ? 'atlas_engineering_evidence' : null,
                'id' => $evidenceId ? (string) $evidenceId : null,
            ],
            'run_id' => $runId ? (string) $runId : null,
            'evidence_id' => $evidenceId ? (string) $evidenceId : null,
            'stage_receipt_ids' => $receiptPayload['stage_receipt_ids'],
            'ledger_event_ids' => $receiptPayload['ledger_event_ids'],
            'evidence_pack_hash' => $receiptPayload['evidence_pack_hash'],
            'execution_source' => $receiptPayload['execution_source'],
            'recorded_at' => now()->toJSON(),
        ];
    }

    private function governedTaskContractForProject(AtlasProject $project, string $executionStatus): ?array
    {
        if (! Schema::hasTable('atlas_programming_work_items')) {
            return null;
        }

        $workItem = $this->programmingWorkItemForProject($project);
        if (! $workItem) {
            return null;
        }

        $task = collect((array) $workItem->tasks_json)
            ->first(fn (mixed $candidate): bool => is_array($candidate) && (array) ($candidate['allowed_files'] ?? []) !== []);
        if (! is_array($task)) {
            $task = collect((array) $workItem->tasks_json)->first(fn (mixed $candidate): bool => is_array($candidate));
        }
        if (! is_array($task)) {
            return null;
        }

        $rollback = $task['rollback'] ?? null;

        return [
            'schema_version' => 'atlas.code.task_contract_artifact.v1',
            'task_id' => (string) ($task['task_id'] ?? $task['id'] ?? $task['code'] ?? sprintf('%s-task-01', (string) $workItem->code)),
            'status' => $executionStatus === 'passed' ? 'verified' : 'needs_review',
            'objective' => (string) ($task['objective'] ?? $workItem->intent_text),
            'owner' => $task['owner'] ?? $workItem->owner,
            'risk_level' => $task['risk_level'] ?? $workItem->risk_level,
            'allowed_files' => array_values((array) ($task['allowed_files'] ?? [])),
            'forbidden_files' => array_values((array) ($task['forbidden_files'] ?? [])),
            'expected_files' => array_values((array) ($task['expected_files'] ?? $task['allowed_files'] ?? [])),
            'validation_commands' => array_values((array) ($task['validation_commands'] ?? [])),
            'acceptance_criteria' => array_values((array) ($task['acceptance_criteria'] ?? [])),
            'rollback' => [
                'available' => is_string($rollback) ? trim($rollback) !== '' : is_array($rollback),
                'command' => is_string($rollback) ? $rollback : data_get($rollback, 'command'),
            ],
            'evidence_required' => array_values((array) ($task['evidence_required'] ?? [])),
            'docs_required' => array_values((array) ($task['docs_required'] ?? [])),
            'cartography_required' => (bool) ($task['cartography_required'] ?? false),
            'source_authority' => 'programming_governance.tasks_json',
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'plan_hash' => $workItem->plan_hash,
            'spec_hash' => $workItem->spec_hash,
        ];
    }

    private function programmingWorkItemForProject(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $id = (string) data_get($metadata, 'programming_work_item_id', '');
        if ($id !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('id', $id)->first();
            if ($item) {
                return $item;
            }
        }

        $code = (string) data_get($metadata, 'programming_work_item_code', '');
        if ($code !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('code', $code)->first();
            if ($item) {
                return $item;
            }
        }

        $projectId = (string) $project->getKey();
        $workspace = trim((string) data_get($metadata, 'workspace_path', ''));

        return AtlasProgrammingWorkItem::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->first(function (AtlasProgrammingWorkItem $item) use ($projectId, $workspace): bool {
                $itemMetadata = is_array($item->metadata_json) ? $item->metadata_json : [];
                if ((string) data_get($itemMetadata, 'obra_id', '') === $projectId
                    || (string) data_get($itemMetadata, 'atlas_project_id', '') === $projectId
                ) {
                    return true;
                }

                return $workspace !== '' && trim((string) ($item->workspace ?? '')) === $workspace;
            });
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $snapshot
     */
    private function persistRun(AtlasProject $project, array $report, array $snapshot): ?AtlasEngineeringRun
    {
        if (! Schema::hasTable('atlas_engineering_runs')) {
            return null;
        }

        $status = (string) ($snapshot['status'] ?? 'blocked');
        $fields = $this->onlyExistingColumns('atlas_engineering_runs', [
            'task_id' => (string) $project->getKey(),
            'project_id' => (string) $project->getKey(),
            'project_step_id' => null,
            'trace_id' => null,
            'workspace_path_hash' => (string) data_get($report, 'sandbox.workspace_hash', hash('sha256', (string) $project->getKey())),
            'workspace_label' => 'atlas-code:forge-live-execution',
            'provider_strategy_json' => [
                'external_provider_call' => false,
                'surface_id' => 'atlas_code',
                'flow_id' => 'programming.forge',
            ],
            'context_pack_hash' => data_get($snapshot, 'context_pack.context_pack_hash'),
            'harnessability_score' => $status === 'passed' ? 100 : 60,
            'status' => $status,
            'decision' => match ($status) {
                'passed' => 'passed',
                'blocked' => 'blocked',
                default => 'degraded',
            },
            'score' => $status === 'passed' ? 100 : ($status === 'blocked' ? 0 : 60),
            'max_attempts' => 1,
            'attempt_count' => 1,
            'started_at' => now(),
            'finished_at' => now(),
            'metadata' => [
                'schema_version' => 'atlas.code.forge_live_execution.run_metadata.v1',
                'trace_ulid' => data_get($report, 'inputs.trace_id'),
                'plan_id' => data_get($report, 'inputs.plan_id'),
                'envelope_id' => data_get($report, 'inputs.envelope_id'),
                'snapshot' => $snapshot,
            ],
        ]);

        return AtlasEngineeringRun::query()->create($fields);
    }

    /**
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $snapshot
     */
    private function persistEvidence(
        AtlasProject $project,
        array $report,
        array $snapshot,
        mixed $runId,
    ): ?AtlasEngineeringEvidence {
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            return null;
        }

        $status = (string) ($snapshot['status'] ?? 'blocked');
        $output = sprintf(
            'forge_live_execution=%s; stages=%d; evidence_refs=%d; ledger_events=%d; repair=%s',
            $status,
            (int) ($snapshot['stage_count'] ?? 0),
            (int) ($snapshot['evidence_ref_count'] ?? 0),
            (int) ($snapshot['ledger_event_count'] ?? 0),
            (string) data_get($snapshot, 'repair_loop.status', 'unknown'),
        );

        $fields = $this->onlyExistingColumns('atlas_engineering_evidence', [
            'task_id' => (string) $project->getKey(),
            'project_id' => (string) $project->getKey(),
            'project_step_id' => null,
            'trace_id' => null,
            'evidence_type' => 'forge_live_execution',
            'target_id' => $runId ? (string) $runId : (string) $project->getKey(),
            'status' => $status,
            'confidence' => $status === 'passed' ? 1.0 : 0.6,
            'summary' => sprintf(
                'Forge Live Execution %s · stages=%d · evidence=%d · ledger=%d',
                $status,
                (int) ($snapshot['stage_count'] ?? 0),
                (int) ($snapshot['evidence_ref_count'] ?? 0),
                (int) ($snapshot['ledger_event_count'] ?? 0),
            ),
            'command' => (string) ($snapshot['command'] ?? ''),
            'artifact_url' => null,
            'output_excerpt' => $output,
            'files' => $this->changedFilesFromSnapshotOrReport($snapshot, $report),
            'metadata' => [
                'schema_version' => 'atlas.code.forge_live_execution.evidence_metadata.v1',
                'snapshot' => $snapshot,
                'report' => [
                    'schema_version' => $report['schema_version'] ?? null,
                    'forge_live_execution_status' => $report['forge_live_execution_status'] ?? null,
                    'inputs' => $report['inputs'] ?? [],
                    'sandbox' => $report['sandbox'] ?? [],
                    'evidence_refs' => $report['evidence_refs'] ?? [],
                    'ledger_event_ids' => $report['ledger_event_ids'] ?? [],
                    'remaining_blockers' => $report['remaining_blockers'] ?? [],
                    'external_provider_call' => $report['external_provider_call'] ?? false,
                ],
            ],
            'source' => 'atlas_code_forge_live_execution',
            'recorded_at' => now(),
        ]);

        return AtlasEngineeringEvidence::query()->create($fields);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    private function rememberProjectSnapshot(AtlasProject $project, array $snapshot): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $history = array_values((array) ($metadata['atlas_code_forge_live_execution_history'] ?? []));
        array_unshift($history, $this->historyEntryFromSnapshot($snapshot));

        $metadata['latest_forge_live_execution'] = $snapshot;
        $metadata['atlas_code_forge_live_execution_history'] = array_slice($history, 0, 20);
        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function historyEntryFromSnapshot(array $snapshot): array
    {
        return [
            'schema_version' => 'atlas.code.forge_live_execution.history_entry.v1',
            'history_id' => (string) Str::ulid(),
            'run_id' => $snapshot['run_id'] ?? null,
            'evidence_id' => $snapshot['evidence_id'] ?? null,
            'status' => (string) ($snapshot['status'] ?? 'unknown'),
            'obra_id' => $snapshot['obra_id'] ?? null,
            'last_run_at' => $snapshot['last_run_at'] ?? now()->toJSON(),
            'command' => $snapshot['command'] ?? null,
            'strict_command' => $snapshot['strict_command'] ?? null,
            'simulate_failure' => (bool) ($snapshot['simulate_failure'] ?? false),
            'stage_count' => $snapshot['stage_count'] ?? null,
            'context_pack_hash' => data_get($snapshot, 'context_pack.context_pack_hash'),
            'context_completeness' => data_get($snapshot, 'context_pack.context_completeness'),
            'task_contract_status' => data_get($snapshot, 'task_contract.status'),
            'diff_scope_status' => data_get($snapshot, 'diff_scope.status'),
            'scope_status' => data_get($snapshot, 'diff_scope.scope_status'),
            'completion_claim_allowed' => (bool) data_get($snapshot, 'diff_scope.completion_gate.completion_claim_allowed', false),
            'repair_status' => data_get($snapshot, 'repair_loop.status'),
            'repair_triggered' => (bool) data_get($snapshot, 'repair_loop.triggered', false),
            'evidence_ref_count' => $snapshot['evidence_ref_count'] ?? null,
            'ledger_event_count' => $snapshot['ledger_event_count'] ?? null,
            'evidence_pack_digest' => $this->evidencePackDigestFromSnapshot($snapshot),
            'remaining_blockers' => array_values((array) ($snapshot['remaining_blockers'] ?? [])),
            'external_provider_call' => (bool) ($snapshot['external_provider_call'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>|null
     */
    private function evidencePackDigestFromSnapshot(array $snapshot): ?array
    {
        $pack = data_get($snapshot, 'evidence_pack');
        if (! is_array($pack)) {
            return null;
        }

        $stageReceiptIds = collect((array) ($pack['stage_receipts'] ?? []))
            ->filter(fn (mixed $receipt): bool => is_array($receipt))
            ->map(fn (array $receipt): string => (string) ($receipt['receipt_id'] ?? ''))
            ->filter(fn (string $receiptId): bool => $receiptId !== '')
            ->values()
            ->all();

        $ledgerEventIds = collect((array) ($pack['ledger_events'] ?? []))
            ->filter(fn (mixed $event): bool => is_array($event))
            ->map(fn (array $event): string => (string) ($event['event_id'] ?? ''))
            ->filter(fn (string $eventId): bool => $eventId !== '')
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.code.forge_live_execution.evidence_pack_digest.v1',
            'status' => (string) ($pack['status'] ?? data_get($snapshot, 'status', 'unknown')),
            'stage_receipt_count' => (int) ($pack['stage_receipt_count'] ?? count($stageReceiptIds)),
            'stage_receipt_ids' => $stageReceiptIds,
            'ledger_event_count' => (int) ($pack['ledger_event_count'] ?? count($ledgerEventIds)),
            'ledger_event_ids' => $ledgerEventIds,
            'changed_files' => array_values((array) ($pack['changed_files'] ?? [])),
            'engineering_run_id' => data_get($pack, 'persistence.engineering_run_id'),
            'engineering_evidence_id' => data_get($pack, 'persistence.engineering_evidence_id'),
            'engineering_run_persisted' => (bool) data_get($pack, 'persistence.engineering_run_persisted', false),
            'engineering_evidence_persisted' => (bool) data_get($pack, 'persistence.engineering_evidence_persisted', false),
            'report_hash' => data_get($pack, 'integrity.report_hash'),
            'stage_timeline_hash' => data_get($pack, 'integrity.stage_timeline_hash'),
            'evidence_pack_hash' => data_get($pack, 'integrity.evidence_pack_hash'),
        ];
    }

    /**
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    private function rememberAsyncExecution(AtlasProject $project, array $patch): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $history = collect((array) ($metadata['atlas_code_forge_live_execution_async_history'] ?? []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->values();

        $executionId = (string) ($patch['execution_id'] ?? '');
        $existing = $executionId === ''
            ? []
            : (array) ($history->first(fn (array $entry): bool => (string) ($entry['execution_id'] ?? '') === $executionId) ?? []);

        $execution = array_merge([
            'schema_version' => 'atlas.code.forge_live_execution.async.v1',
            'execution_id' => $executionId !== '' ? $executionId : (string) Str::ulid(),
            'status' => 'queued',
            'obra_id' => (string) $project->getKey(),
            'simulate_failure' => false,
            'queued_at' => null,
            'started_at' => null,
            'finished_at' => null,
            'job_dispatched' => false,
            'command' => null,
            'snapshot_status' => null,
            'run_id' => null,
            'evidence_id' => null,
            'remaining_blockers' => [],
            'completion_claim_allowed' => false,
            'error' => null,
            'updated_at' => now()->toJSON(),
        ], $existing, $patch, [
            'updated_at' => now()->toJSON(),
        ]);

        $history = $history
            ->reject(fn (array $entry): bool => (string) ($entry['execution_id'] ?? '') === (string) $execution['execution_id'])
            ->prepend($execution)
            ->take(20)
            ->values();

        $metadata['latest_forge_live_execution_async'] = $execution;
        $metadata['atlas_code_forge_live_execution_async_history'] = $history->all();

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();

        return $execution;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function asyncExecutionFor(AtlasProject $project, string $executionId): ?array
    {
        $latest = data_get($project->metadata, 'latest_forge_live_execution_async');
        if (is_array($latest) && (string) ($latest['execution_id'] ?? '') === $executionId) {
            return $latest;
        }

        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_live_execution_async_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));
        $entry = $history->first(fn (array $entry): bool => (string) ($entry['execution_id'] ?? '') === $executionId);

        return is_array($entry) ? $entry : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function historyEntryFor(AtlasProject $project, string $historyId): ?array
    {
        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_live_execution_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));
        $entry = $history->first(fn (array $entry): bool => (string) ($entry['history_id'] ?? '') === $historyId);

        return is_array($entry) ? $entry : null;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function historyEntryForResponse(array $entry): array
    {
        return [
            'schema_version' => 'atlas.code.forge_live_execution.history_entry.v1',
            'history_id' => (string) ($entry['history_id'] ?? hash('sha256', json_encode($entry) ?: 'forge-live-execution')),
            'run_id' => $entry['run_id'] ?? null,
            'evidence_id' => $entry['evidence_id'] ?? null,
            'status' => (string) ($entry['status'] ?? 'unknown'),
            'obra_id' => $entry['obra_id'] ?? null,
            'last_run_at' => $entry['last_run_at'] ?? null,
            'command' => $entry['command'] ?? null,
            'strict_command' => $entry['strict_command'] ?? null,
            'simulate_failure' => (bool) ($entry['simulate_failure'] ?? false),
            'stage_count' => $entry['stage_count'] ?? null,
            'context_pack_hash' => $entry['context_pack_hash'] ?? data_get($entry, 'context_pack.context_pack_hash'),
            'context_completeness' => $entry['context_completeness'] ?? data_get($entry, 'context_pack.context_completeness'),
            'task_contract_status' => $entry['task_contract_status'] ?? data_get($entry, 'task_contract.status'),
            'diff_scope_status' => $entry['diff_scope_status'] ?? data_get($entry, 'diff_scope.status'),
            'scope_status' => $entry['scope_status'] ?? data_get($entry, 'diff_scope.scope_status'),
            'completion_claim_allowed' => (bool) ($entry['completion_claim_allowed'] ?? data_get($entry, 'diff_scope.completion_gate.completion_claim_allowed', false)),
            'repair_status' => $entry['repair_status'] ?? data_get($entry, 'repair_loop.status'),
            'repair_triggered' => (bool) ($entry['repair_triggered'] ?? data_get($entry, 'repair_loop.triggered', false)),
            'evidence_ref_count' => $entry['evidence_ref_count'] ?? null,
            'ledger_event_count' => $entry['ledger_event_count'] ?? null,
            'evidence_pack_digest' => $entry['evidence_pack_digest'] ?? $this->evidencePackDigestFromSnapshot($entry),
            'remaining_blockers' => array_values((array) ($entry['remaining_blockers'] ?? [])),
            'external_provider_call' => (bool) ($entry['external_provider_call'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $historyEntry
     * @return array<string,mixed>|null
     */
    private function fullSnapshotForHistoryEntry(AtlasProject $project, array $historyEntry): ?array
    {
        $latest = data_get($project->metadata, 'latest_forge_live_execution');
        if (is_array($latest) && $this->snapshotMatchesHistoryEntry($latest, $historyEntry)) {
            return $latest;
        }

        $evidenceId = $historyEntry['evidence_id'] ?? null;
        if (! $evidenceId
            || ! Schema::hasTable('atlas_engineering_evidence')
            || ! Schema::hasColumn('atlas_engineering_evidence', 'metadata')
        ) {
            return null;
        }

        $evidence = AtlasEngineeringEvidence::query()
            ->where('id', (string) $evidenceId)
            ->where('project_id', (string) $project->getKey())
            ->first();
        $snapshot = data_get($evidence?->metadata, 'snapshot');

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $historyEntry
     */
    private function snapshotMatchesHistoryEntry(array $snapshot, array $historyEntry): bool
    {
        foreach (['run_id', 'evidence_id'] as $key) {
            if (($historyEntry[$key] ?? null) !== null && (string) ($historyEntry[$key] ?? '') === (string) ($snapshot[$key] ?? '')) {
                return true;
            }
        }

        $snapshotPackHash = (string) data_get($snapshot, 'evidence_pack.integrity.evidence_pack_hash', '');
        $historyPackHash = (string) data_get($historyEntry, 'evidence_pack_digest.evidence_pack_hash', '');

        return $snapshotPackHash !== '' && $snapshotPackHash === $historyPackHash;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reviewForHistoryId(AtlasProject $project, string $historyId): ?array
    {
        $latest = data_get($project->metadata, 'latest_atlas_code_forge_review');
        if (is_array($latest) && (string) ($latest['history_id'] ?? '') === $historyId) {
            return $latest;
        }

        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_review_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));
        $review = $history->first(fn (array $entry): bool => (string) ($entry['history_id'] ?? '') === $historyId);

        return is_array($review) ? $review : null;
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function evidencePackFromReport(
        AtlasProject $project,
        array $report,
        string $status,
        bool $simulateFailure,
        mixed $runId = null,
        mixed $evidenceId = null,
        ?array $governedExecution = null,
    ): array {
        $stageTimeline = $this->stageTimelineFromStages(collect((array) ($report['stages'] ?? []))->values()->all());
        $stageReceipts = $this->stageReceiptsFromReport($report);
        $governedChangedFiles = $this->changedFilesFromGovernedExecution($governedExecution);
        $ledgerEvents = collect((array) ($report['ledger_event_ids'] ?? []))
            ->filter(fn (mixed $eventId): bool => is_string($eventId) && $eventId !== '')
            ->values()
            ->map(fn (string $eventId, int $index): array => [
                'index' => $index + 1,
                'event_id' => $eventId,
                'source' => 'atlas_ledger_events',
            ])
            ->all();

        $reportHash = hash('sha256', json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');

        return [
            'schema_version' => 'atlas.code.forge_live_execution.evidence_pack.v1',
            'status' => $status,
            'obra_id' => (string) $project->getKey(),
            'source_authority' => 'AtlasForgeLiveExecutionService.report + AtlasProject.metadata.latest_forge_live_execution',
            'generated_at' => now()->toJSON(),
            'replay' => [
                'command' => $this->commandFor($project, strict: false, simulateFailure: $simulateFailure),
                'strict_command' => $this->commandFor($project, strict: true, simulateFailure: false),
                'failure_probe_command' => $this->commandFor($project, strict: false, simulateFailure: true),
                'external_provider_call' => false,
            ],
            'persistence' => [
                'engineering_run_id' => $runId ? (string) $runId : null,
                'engineering_evidence_id' => $evidenceId ? (string) $evidenceId : null,
                'engineering_run_persisted' => $runId !== null,
                'engineering_evidence_persisted' => $evidenceId !== null,
            ],
            'stage_receipt_count' => count($stageReceipts),
            'stage_receipts' => $stageReceipts,
            'ledger_event_count' => count($ledgerEvents),
            'ledger_events' => $ledgerEvents,
            'changed_files' => $governedChangedFiles !== [] ? $governedChangedFiles : $this->changedFilesFromReport($report),
            'governed_execution' => $this->governedExecutionDigest($governedExecution),
            'remaining_blockers' => array_values((array) ($report['remaining_blockers'] ?? [])),
            'integrity' => [
                'report_hash' => $reportHash,
                'stage_timeline_hash' => hash('sha256', json_encode($stageTimeline, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
                'evidence_pack_hash' => hash('sha256', json_encode([
                    'obra_id' => (string) $project->getKey(),
                    'status' => $status,
                    'report_hash' => $reportHash,
                    'stage_receipts' => $stageReceipts,
                    'ledger_events' => $ledgerEvents,
                    'governed_execution' => $this->governedExecutionDigest($governedExecution),
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,array<string,mixed>>
     */
    private function stageReceiptsFromReport(array $report): array
    {
        $stage = collect((array) ($report['stages'] ?? []))->firstWhere('name', 'stage_receipts');

        return collect((array) data_get($stage, 'receipts', []))
            ->filter(fn (mixed $receipt): bool => is_array($receipt))
            ->values()
            ->map(fn (array $receipt, int $index): array => [
                'index' => $index + 1,
                'schema_version' => $receipt['schema_version'] ?? null,
                'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
                'stage' => (string) ($receipt['stage'] ?? 'unknown'),
                'status' => (string) ($receipt['status'] ?? 'unknown'),
                'attempt' => (int) ($receipt['attempt'] ?? 0),
                'input_hash' => $receipt['input_hash'] ?? null,
                'output_hash' => $receipt['output_hash'] ?? null,
                'validation_status' => data_get($receipt, 'validation.status'),
                'evidence_refs' => array_values((array) ($receipt['evidence_refs'] ?? [])),
                'created_at' => $receipt['created_at'] ?? null,
            ])
            ->filter(fn (array $receipt): bool => $receipt['receipt_id'] !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $stages
     * @return array<string,mixed>
     */
    private function stageTimelineFromStages(array $stages): array
    {
        $entries = collect($stages)
            ->values()
            ->map(function (array $stage, int $index): array {
                $name = (string) ($stage['name'] ?? 'stage');
                $status = (string) ($stage['status'] ?? 'unknown');
                $blocker = $stage['blocker'] ?? null;

                return [
                    'index' => $index + 1,
                    'name' => $name,
                    'phase' => $this->phaseForStage($name),
                    'status' => $status,
                    'blocking' => $this->stageIsBlocking($status, $blocker),
                    'blocker' => is_string($blocker) && $blocker !== '' ? $blocker : null,
                    'summary' => $this->stageSummary($stage),
                ];
            })
            ->values();

        return [
            'schema_version' => 'atlas.code.forge_live_execution.stage_timeline.v1',
            'total' => $entries->count(),
            'passed' => $entries->where('status', 'passed')->count(),
            'blocked' => $entries->filter(fn (array $entry): bool => in_array($entry['status'], ['blocked', 'failed'], true))->count(),
            'degraded' => $entries->where('status', 'degraded')->count(),
            'skipped' => $entries->filter(fn (array $entry): bool => str_starts_with((string) $entry['status'], 'skipped'))->count(),
            'blocking' => $entries->where('blocking', true)->count(),
            'entries' => $entries->all(),
        ];
    }

    private function phaseForStage(string $name): string
    {
        return match ($name) {
            'obra_binding', 'sandbox_provision', 'context_pack' => 'context',
            'patch_apply', 'action_manifest' => 'execute',
            'patch_verifier', 'test_run' => 'verify',
            'stage_receipts', 'evidence_ledger' => 'evidence',
            'repair_loop' => 'repair',
            'sandbox_rollback' => 'cleanup',
            default => 'runtime',
        };
    }

    private function stageIsBlocking(string $status, mixed $blocker): bool
    {
        $hasBlocker = is_string($blocker) && $blocker !== '';

        return $hasBlocker || in_array($status, ['blocked', 'failed', 'degraded'], true);
    }

    /**
     * @param  array<string,mixed>  $stage
     */
    private function stageSummary(array $stage): string
    {
        $name = (string) ($stage['name'] ?? 'stage');
        $status = (string) ($stage['status'] ?? 'unknown');

        return match ($name) {
            'obra_binding' => isset($stage['obra_id'])
                ? 'obra vinculada | '.substr((string) $stage['obra_id'], 0, 8)
                : (string) ($stage['reason'] ?? 'obra binding '.$status),
            'sandbox_provision' => sprintf(
                'sandbox %s | workspace %s',
                (string) ($stage['mode'] ?? data_get($stage, 'sandbox.mode', 'unknown')),
                data_get($stage, 'sandbox.workspace_hash') ? substr((string) data_get($stage, 'sandbox.workspace_hash'), 0, 12) : 'unknown',
            ),
            'context_pack' => sprintf(
                'contexto %s | refs %d/%d',
                (string) data_get($stage, 'context_pack.context_completeness', 'unknown'),
                (int) data_get($stage, 'context_pack.present_ref_count', 0),
                (int) data_get($stage, 'context_pack.ranked_ref_count', 0),
            ),
            'patch_apply' => sprintf(
                'patch aplicado | files %d',
                count((array) ($stage['changed_files'] ?? [])),
            ),
            'action_manifest' => sprintf(
                'manifest %s | rollback %s',
                (string) data_get($stage, 'action_manifest.manifest_id', 'unknown'),
                data_get($stage, 'action_manifest.rollback.available') ? 'available' : 'missing',
            ),
            'patch_verifier' => sprintf(
                'verifier %s | completion %s',
                (string) data_get($stage, 'report.status', 'unknown'),
                data_get($stage, 'report.completion_claim_allowed') ? 'allowed' : 'blocked',
            ),
            'test_run' => sprintf(
                'test exit %s | passed %s',
                (string) data_get($stage, 'result.exit_code', 'unknown'),
                data_get($stage, 'result.passed') ? 'true' : 'false',
            ),
            'stage_receipts' => sprintf(
                'receipts %d',
                count((array) ($stage['receipts'] ?? [])),
            ),
            'repair_loop' => data_get($stage, 'triggered')
                ? 'repair triggered | plan '.(string) data_get($stage, 'plan_status', data_get($stage, 'plan.status', 'unknown'))
                : 'repair skipped | '.(string) ($stage['reason'] ?? 'not needed'),
            'evidence_ledger' => sprintf(
                'ledger events %d',
                count((array) ($stage['ledger_event_ids'] ?? [])),
            ),
            'sandbox_rollback' => data_get($stage, 'workspace_cleaned')
                ? 'sandbox cleanup confirmed'
                : 'sandbox cleanup degraded',
            default => 'stage '.$status,
        };
    }

    /**
     * @param  array<string,mixed>|null  $governedExecution
     * @return array<string,mixed>|null
     */
    private function governedExecutionSnapshot(?array $governedExecution): ?array
    {
        if (! is_array($governedExecution)) {
            return null;
        }

        return [
            'schema_version' => $governedExecution['schema_version'] ?? AtlasForgeGovernedExecutionService::SCHEMA_VERSION,
            'execution_id' => $governedExecution['execution_id'] ?? null,
            'status' => (string) ($governedExecution['status'] ?? 'blocked'),
            'execution_mode' => (string) ($governedExecution['execution_mode'] ?? 'governed_shadow_patch'),
            'source_authority' => (string) ($governedExecution['source_authority'] ?? 'programming_governance.tasks_json'),
            'work_item_id' => $governedExecution['work_item_id'] ?? null,
            'work_item_code' => $governedExecution['work_item_code'] ?? null,
            'task_id' => $governedExecution['task_id'] ?? null,
            'changed_files' => $this->changedFilesFromGovernedExecution($governedExecution),
            'stage_receipt_ids' => array_values((array) ($governedExecution['stage_receipt_ids'] ?? [])),
            'receipt_id' => $governedExecution['receipt_id'] ?? null,
            'validation_result' => is_array($governedExecution['validation_result'] ?? null) ? $governedExecution['validation_result'] : null,
            'governance_feedback' => is_array($governedExecution['governance_feedback'] ?? null) ? $governedExecution['governance_feedback'] : null,
            'remaining_blockers' => array_values((array) ($governedExecution['remaining_blockers'] ?? [])),
            'external_provider_call' => (bool) ($governedExecution['external_provider_call'] ?? false),
            'live_workspace_mutated' => (bool) ($governedExecution['live_workspace_mutated'] ?? false),
            'promotion_status' => (string) ($governedExecution['promotion_status'] ?? 'requires_human_approval'),
            'promotion_artifact' => $this->promotionArtifactFromGovernedExecution($governedExecution),
            'stage_count' => count((array) ($governedExecution['stages'] ?? [])),
        ];
    }

    /**
     * @param  array<string,mixed>|null  $governedExecution
     * @return array<string,mixed>|null
     */
    private function governedExecutionDigest(?array $governedExecution): ?array
    {
        $snapshot = $this->governedExecutionSnapshot($governedExecution);
        if ($snapshot === null) {
            return null;
        }

        return [
            'schema_version' => 'atlas.code.forge_governed_execution.digest.v1',
            'execution_id' => $snapshot['execution_id'],
            'status' => $snapshot['status'],
            'execution_mode' => $snapshot['execution_mode'],
            'changed_files' => $snapshot['changed_files'],
            'receipt_id' => $snapshot['receipt_id'],
            'stage_receipt_count' => count((array) $snapshot['stage_receipt_ids']),
            'validation_passed' => (bool) data_get($snapshot, 'validation_result.passed', false),
            'governance_feedback_status' => data_get($snapshot, 'governance_feedback.status'),
            'promotion_status' => $snapshot['promotion_status'],
            'promotion_artifact_hash' => data_get($snapshot, 'promotion_artifact.sha256'),
        ];
    }

    /**
     * @param  array<string,mixed>  $governedExecution
     * @return array<string,mixed>|null
     */
    private function promotionArtifactFromGovernedExecution(array $governedExecution): ?array
    {
        $stage = collect((array) ($governedExecution['stages'] ?? []))->firstWhere('name', 'patch_dry_run');
        $artifact = is_array($stage) ? data_get($stage, 'promotion_artifact') : null;
        if (! is_array($artifact)) {
            return null;
        }

        return [
            'schema_version' => $artifact['schema_version'] ?? 'atlas.forge_governed_execution.patch_artifact.v1',
            'path' => $artifact['path'] ?? null,
            'sha256' => $artifact['sha256'] ?? null,
            'operation' => $artifact['operation'] ?? null,
            'target_file' => $artifact['target_file'] ?? null,
            'expected_before_hash' => $artifact['expected_before_hash'] ?? null,
            'expected_after_hash' => $artifact['expected_after_hash'] ?? null,
            'diff_path' => $artifact['diff_path'] ?? null,
            'diff_hash' => $artifact['diff_hash'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $governedExecution
     * @return list<string>
     */
    private function changedFilesFromGovernedExecution(?array $governedExecution): array
    {
        if (! is_array($governedExecution)) {
            return [];
        }

        return array_values(array_filter((array) ($governedExecution['changed_files'] ?? []), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<int,string>
     */
    private function changedFilesFromReport(array $report): array
    {
        $stage = collect((array) ($report['stages'] ?? []))->firstWhere('name', 'patch_apply');

        return array_values(array_filter((array) data_get($stage, 'changed_files', []), 'is_string'));
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  array<string,mixed>  $report
     * @return list<string>
     */
    private function changedFilesFromSnapshotOrReport(array $snapshot, array $report): array
    {
        $fromScope = collect((array) data_get($snapshot, 'diff_scope.files', []))
            ->filter(fn (mixed $file): bool => is_array($file) && is_string($file['path'] ?? null))
            ->map(fn (array $file): string => (string) $file['path'])
            ->values()
            ->all();

        return $fromScope !== [] ? $fromScope : $this->changedFilesFromReport($report);
    }

    /**
     * @param  array<string,mixed>|null  $governedExecution
     * @param  array<string,mixed>|null  $taskContract
     * @return array<string,mixed>|null
     */
    private function diffScopeFromGovernedExecution(?array $governedExecution, ?array $taskContract, string $executionStatus): ?array
    {
        if (! is_array($governedExecution) || ! is_array($taskContract)) {
            return null;
        }

        $changedFiles = $this->changedFilesFromGovernedExecution($governedExecution);
        $allowed = $this->stringList($taskContract['allowed_files'] ?? []);
        $forbidden = $this->stringList($taskContract['forbidden_files'] ?? []);
        $verifierReport = (array) data_get(collect((array) ($governedExecution['stages'] ?? []))->firstWhere('name', 'patch_verifier'), 'report', []);
        $verifierBlocking = $this->stringList($verifierReport['blocking_reasons'] ?? []);
        $governedBlockers = $this->stringList($governedExecution['remaining_blockers'] ?? []);

        $files = collect($changedFiles)
            ->map(function (string $path) use ($allowed, $forbidden): array {
                $forbiddenHit = $this->matchesAnyPath($path, $forbidden);
                $allowedHit = $this->matchesAnyPath($path, $allowed);
                $status = $forbiddenHit ? 'forbidden' : ($allowedHit ? 'in_scope' : 'needs_replan');

                return [
                    'path' => $path,
                    'status' => $status,
                    'ownership' => $allowedHit ? 'programming_governance.tasks_json' : 'unclaimed',
                    'manifest_covered' => true,
                    'reason' => match ($status) {
                        'forbidden' => 'matches_forbidden_files',
                        'in_scope' => 'covered_by_task_contract',
                        default => 'changed_file_not_allowed_by_task_contract',
                    },
                ];
            })
            ->values()
            ->all();

        $scopeBlocking = collect($files)
            ->filter(fn (array $file): bool => $file['status'] !== 'in_scope')
            ->map(fn (array $file): string => 'scope_'.$file['status'].':'.$file['path'])
            ->values()
            ->all();

        $blockingReasons = array_values(array_unique(array_merge($verifierBlocking, $governedBlockers, $scopeBlocking)));
        if ($executionStatus !== 'passed') {
            $blockingReasons[] = 'live_execution_not_passed';
        }

        $verifierAllowsCompletion = (bool) ($verifierReport['completion_claim_allowed'] ?? false);
        $governedPassed = (string) ($governedExecution['status'] ?? 'blocked') === 'passed';
        $completionAllowed = $executionStatus === 'passed'
            && $governedPassed
            && $verifierAllowsCompletion
            && $blockingReasons === [];

        return [
            'schema_version' => 'atlas.code.diff_scope_artifact.v1',
            'status' => $blockingReasons === [] ? 'passed' : 'blocked',
            'scope_status' => $blockingReasons === [] ? 'in_scope' : 'needs_review',
            'changed_file_count' => count($changedFiles),
            'files' => $files,
            'manifest_id' => data_get(collect((array) ($governedExecution['stages'] ?? []))->firstWhere('name', 'action_manifest'), 'action_manifest.manifest_id'),
            'patch_target_hash' => data_get(collect((array) ($governedExecution['stages'] ?? []))->firstWhere('name', 'patch_dry_run'), 'after_hash'),
            'rollback_available' => true,
            'blocking_reasons' => array_values(array_unique($blockingReasons)),
            'patch_verifier' => [
                'schema_version' => $verifierReport['schema_version'] ?? null,
                'status' => $verifierReport['status'] ?? null,
                'next_action' => $verifierReport['next_action'] ?? null,
                'completion_claim_allowed' => $verifierAllowsCompletion,
            ],
            'completion_gate' => [
                'status' => $completionAllowed ? 'passed' : 'blocked',
                'completion_claim_allowed' => $completionAllowed,
                'reasons' => $completionAllowed ? [] : array_values(array_unique($blockingReasons)),
            ],
            'source_authority' => 'atlas.forge_governed_execution.v1',
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function diffScopeFromReport(array $report, string $executionStatus): array
    {
        $stages = collect((array) ($report['stages'] ?? []));
        $actionManifest = (array) data_get($stages->firstWhere('name', 'action_manifest'), 'action_manifest', []);
        $verifierReport = (array) data_get($stages->firstWhere('name', 'patch_verifier'), 'report', []);

        $changedFiles = $this->changedFilesFromReport($report);
        $manifestCovered = $this->stringList($verifierReport['manifest_covered_files'] ?? $actionManifest['changed_files'] ?? []);
        $uncovered = $this->stringList($verifierReport['uncovered_changed_files'] ?? []);
        $verifierBlocking = $this->stringList($verifierReport['blocking_reasons'] ?? []);

        $files = collect($changedFiles)
            ->map(function (string $path) use ($manifestCovered, $uncovered): array {
                $covered = in_array($path, $manifestCovered, true);
                $isUncovered = in_array($path, $uncovered, true);
                $status = $isUncovered ? 'unknown' : ($covered ? 'in_scope' : 'unknown');

                return [
                    'path' => $path,
                    'status' => $status,
                    'ownership' => $covered ? 'action_manifest' : 'unclaimed',
                    'manifest_covered' => $covered,
                    'reason' => $covered
                        ? 'covered_by_action_manifest'
                        : 'changed_file_not_covered_by_manifest',
                ];
            })
            ->values()
            ->all();

        $scopeBlocking = collect($files)
            ->filter(fn (array $file): bool => in_array($file['status'], ['forbidden', 'unknown', 'needs_replan'], true))
            ->map(fn (array $file): string => 'scope_'.$file['status'].':'.$file['path'])
            ->values()
            ->all();

        $blockingReasons = array_values(array_unique(array_merge($verifierBlocking, $scopeBlocking)));
        if ($executionStatus !== 'passed') {
            $blockingReasons[] = 'live_execution_not_passed';
        }

        $verifierAllowsCompletion = (bool) ($verifierReport['completion_claim_allowed'] ?? false);
        $completionAllowed = $executionStatus === 'passed'
            && $verifierAllowsCompletion
            && $blockingReasons === [];

        return [
            'schema_version' => 'atlas.code.diff_scope_artifact.v1',
            'status' => $blockingReasons === [] ? 'passed' : 'blocked',
            'scope_status' => $blockingReasons === [] ? 'in_scope' : 'needs_review',
            'changed_file_count' => count($changedFiles),
            'files' => $files,
            'manifest_id' => $actionManifest['manifest_id'] ?? null,
            'patch_target_hash' => $actionManifest['patch_target_hash'] ?? null,
            'rollback_available' => (bool) data_get($actionManifest, 'rollback.available', false),
            'blocking_reasons' => $blockingReasons,
            'patch_verifier' => [
                'schema_version' => $verifierReport['schema_version'] ?? null,
                'status' => $verifierReport['status'] ?? null,
                'next_action' => $verifierReport['next_action'] ?? null,
                'completion_claim_allowed' => $verifierAllowsCompletion,
            ],
            'completion_gate' => [
                'status' => $completionAllowed ? 'passed' : 'blocked',
                'completion_claim_allowed' => $completionAllowed,
                'reasons' => $completionAllowed ? [] : $blockingReasons,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function taskContractFromReport(array $report, string $executionStatus): array
    {
        $stages = collect((array) ($report['stages'] ?? []));
        $changedFiles = $this->changedFilesFromReport($report);
        $actionManifest = (array) data_get($stages->firstWhere('name', 'action_manifest'), 'action_manifest', []);
        $testResult = (array) data_get($stages->firstWhere('name', 'test_run'), 'result', []);
        $rollback = (array) ($actionManifest['rollback'] ?? []);

        return [
            'schema_version' => 'atlas.code.task_contract_artifact.v1',
            'task_id' => (string) ($actionManifest['manifest_id'] ?? hash('sha256', implode('|', $changedFiles))),
            'status' => $executionStatus === 'passed' ? 'verified' : 'needs_review',
            'objective' => 'Execute Forge Live deterministic patch and verification chain for the selected Obra.',
            'owner' => 'atlas_forge_live_execution',
            'risk_level' => 'low',
            'allowed_files' => $changedFiles,
            'forbidden_files' => [],
            'expected_files' => $changedFiles,
            'validation_commands' => array_values(array_filter([
                (string) ($testResult['command'] ?? ''),
            ], fn (string $command): bool => $command !== '')),
            'acceptance_criteria' => [
                'patch_apply.status=passed',
                'patch_verifier.status=passed',
                'test_run.result.passed=true',
                'diff_scope.completion_gate.completion_claim_allowed=true',
                'evidence_ledger records canonical events or reports degraded explicitly',
            ],
            'rollback' => [
                'available' => (bool) ($rollback['available'] ?? false),
                'command' => $rollback['command'] ?? null,
            ],
            'evidence_required' => [
                'stage_receipts',
                'evidence_pack',
                'evidence_refs',
                'ledger_event_ids',
                'diff_scope',
            ],
            'docs_required' => [
                'docs/engineering-knowledge-base/atlas-code-forge-live-execution-surface-contract.md',
                'docs/engineering-knowledge-base/atlas-forge-live-execution-e2e-v1.md',
            ],
            'cartography_required' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $contextPack
     * @return array<int,array<string,mixed>>
     */
    private function contextPackRefs(array $contextPack): array
    {
        return collect((array) ($contextPack['ranked_refs'] ?? []))
            ->map(function (mixed $ref): array {
                $item = is_array($ref) ? $ref : [];

                return [
                    'rank' => (int) ($item['rank'] ?? 0),
                    'path' => (string) ($item['path'] ?? ''),
                    'kind' => (string) ($item['kind'] ?? 'unknown'),
                    'reason' => (string) ($item['reason'] ?? ''),
                    'evidence_marker' => (string) ($item['evidence_marker'] ?? 'unknown'),
                    'content_hash' => $item['content_hash'] ?? null,
                    'size_bytes' => $item['size_bytes'] ?? null,
                ];
            })
            ->filter(fn (array $ref): bool => $ref['path'] !== '')
            ->values()
            ->all();
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter((array) $value, 'is_string'));
    }

    /**
     * @param  list<string>  $patterns
     */
    private function matchesAnyPath(string $file, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === $file) {
                return true;
            }
            if (str_contains($pattern, '*') && fnmatch($pattern, $file, FNM_NOESCAPE)) {
                return true;
            }
            if (str_ends_with($pattern, '/') && str_starts_with($file, $pattern)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $_, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }

    private function commandFor(AtlasProject $project, bool $strict, bool $simulateFailure): string
    {
        $command = 'php artisan atlas:forge:live-execute --obra='.(string) $project->getKey();
        if ($simulateFailure) {
            $command .= ' --simulate-failure';
        }
        $command .= ' --json';
        if ($strict) {
            $command .= ' --strict';
        }

        return $command;
    }
}
