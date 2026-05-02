<?php

namespace App\Http\Controllers;

use App\Http\Requests\IndexAtlasMemoryEntryRequest;
use App\Http\Requests\IndexAtlasMemoryRelationRequest;
use App\Http\Requests\IndexAtlasMemoryReviewQueueRequest;
use App\Http\Requests\IndexAtlasMemoryProviderProjectionAuditRequest;
use App\Http\Requests\IndexAtlasVerbatimMemoryRequest;
use App\Http\Requests\ApplyAtlasMemoryProviderProjectionRequest;
use App\Http\Requests\AtlasMemoryProviderProjectionRequest;
use App\Http\Requests\FeedbackAtlasMemoryUsageRequest;
use App\Http\Requests\PromoteAtlasMemoryDeltaRequest;
use App\Http\Requests\PurgeAtlasMemoryProviderProjectionAuditRequest;
use App\Http\Requests\ReviewAtlasVerbatimMemoryRequest;
use App\Http\Requests\ReviewAtlasMemoryRelationRequest;
use App\Http\Requests\ReviewAtlasMemoryPrivacyRequest;
use App\Http\Requests\ScanAtlasMemoryGovernanceRequest;
use App\Http\Requests\ScanAtlasMemoryPrivacyRequest;
use App\Http\Requests\StoreAtlasMemoryEntryRequest;
use App\Http\Requests\StoreAtlasVerbatimMemoryRequest;
use App\Http\Requests\SummarizeAtlasMemoryProviderProjectionAuditRequest;
use App\Http\Requests\UpdateAtlasMemoryEntryRequest;
use App\Http\Requests\UpdateAtlasVerbatimMemoryRequest;
use App\Http\Resources\AtlasMemoryEntryResource;
use App\Http\Resources\AtlasVerbatimMemoryResource;
use App\Models\AiMemoryDelta;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasMemoryEntryRelation;
use App\Models\AtlasMemoryEntryUsage;
use App\Models\AtlasProject;
use App\Models\AtlasTask;
use App\Models\AiTrace;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\AtlasMemoryDeltaPromotionService;
use App\Services\Ai\AtlasMemoryGovernanceService;
use App\Services\Ai\AtlasMemoryPrivacyService;
use App\Services\Ai\AtlasMemoryRegistryService;
use App\Services\Ai\AtlasMemoryReviewQueueService;
use App\Services\Ai\AtlasMemoryUsageService;
use App\Services\Ai\AtlasProviderProjectionAuditService;
use App\Services\Ai\AtlasProviderProjectionService;
use App\Services\Ai\AtlasVerbatimMemoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class AtlasMemoryController extends Controller
{
    public function index(IndexAtlasMemoryEntryRequest $request, AtlasMemoryRegistryService $memory): JsonResponse
    {
        $data = $request->validated();
        $entries = $memory->search($data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'memories' => AtlasMemoryEntryResource::collection($entries)->resolve(),
        ]);
    }

    public function store(StoreAtlasMemoryEntryRequest $request, AtlasMemoryRegistryService $memory): JsonResponse
    {
        $entry = $memory->record($request->validated());

        return response()->json([
            'memory' => (new AtlasMemoryEntryResource($entry))->resolve(),
        ], 201);
    }

    public function show(AtlasMemoryEntry $memoryEntry): JsonResponse
    {
        return response()->json([
            'memory' => (new AtlasMemoryEntryResource($memoryEntry))->resolve(),
        ]);
    }

    public function update(UpdateAtlasMemoryEntryRequest $request, AtlasMemoryEntry $memoryEntry): JsonResponse
    {
        $data = $request->validated();
        $status = (string) $data['status'];
        $metadata = array_merge($memoryEntry->metadata ?? [], (array) ($data['metadata'] ?? []));

        $memoryEntry->forceFill([
            'status' => $status,
            'metadata' => $metadata,
            'archived_at' => $status === 'archived' ? ($memoryEntry->archived_at ?: now()) : null,
        ])->save();

        return response()->json([
            'memory' => (new AtlasMemoryEntryResource($memoryEntry->refresh()))->resolve(),
        ]);
    }

    public function auditTrace(AiTrace $trace, AtlasMemoryUsageService $usages): JsonResponse
    {
        return response()->json([
            'audit' => $usages->auditTrace($trace),
        ]);
    }

    public function feedbackUsage(
        FeedbackAtlasMemoryUsageRequest $request,
        AtlasMemoryEntryUsage $usage,
        AtlasMemoryUsageService $usages,
    ): JsonResponse {
        $usage = $usages->recordFeedback($usage, $request->validated() + [
            'feedback_source' => 'api',
        ]);

        return response()->json([
            'usage' => $usages->usagePayload($usage),
        ]);
    }

    public function promoteDelta(
        PromoteAtlasMemoryDeltaRequest $request,
        AiMemoryDelta $delta,
        AtlasMemoryDeltaPromotionService $promoter,
    ): JsonResponse {
        try {
            $entry = $promoter->promote($delta, $request->validated() + [
                'promoted_by' => 'api',
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'memory' => (new AtlasMemoryEntryResource($entry->refresh()))->resolve(),
            'memory_delta' => [
                'id' => $delta->id,
                'status' => $delta->refresh()->status,
                'promoted_memory_entry_id' => $delta->promoted_memory_entry_id,
                'promoted_at' => $delta->promoted_at?->toJSON(),
            ],
        ]);
    }

    public function scanGovernance(
        ScanAtlasMemoryGovernanceRequest $request,
        AtlasMemoryGovernanceService $governance,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'governance' => $governance->scan(
                $data,
                (int) ($data['limit'] ?? 200),
                (bool) ($data['dry_run'] ?? false),
            ),
        ]);
    }

    public function scanPrivacy(
        ScanAtlasMemoryPrivacyRequest $request,
        AtlasMemoryPrivacyService $privacy,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'privacy' => $privacy->scan(
                $data,
                (int) ($data['limit'] ?? 200),
                (bool) ($data['dry_run'] ?? true),
            ),
        ]);
    }

    public function reviewPrivacy(
        ReviewAtlasMemoryPrivacyRequest $request,
        AtlasMemoryEntry $memoryEntry,
        AtlasMemoryPrivacyService $privacy,
    ): JsonResponse {
        $entry = $privacy->apply($memoryEntry, $request->validated() + [
            'reviewed_by' => 'api',
        ]);

        return response()->json([
            'memory' => (new AtlasMemoryEntryResource($entry))->resolve(),
        ]);
    }

    public function reviewQueue(
        IndexAtlasMemoryReviewQueueRequest $request,
        AtlasMemoryReviewQueueService $queue,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'review_queue' => $queue->queue($data, (int) ($data['limit'] ?? 50)),
        ]);
    }

    public function providerProjectionStatus(
        AtlasMemoryProviderProjectionRequest $request,
        AtlasProviderProjectionService $projection,
    ): JsonResponse {
        [$target, $context, $options] = $this->providerProjectionInput($request->validated());

        return response()->json([
            'provider_projection' => $projection->status($target, $context, $options),
        ]);
    }

    public function providerProjectionReview(
        AtlasMemoryProviderProjectionRequest $request,
        AtlasProviderProjectionService $projection,
    ): JsonResponse {
        [$target, $context, $options] = $this->providerProjectionInput($request->validated());

        return response()->json([
            'provider_projection' => $projection->review($target, $context, $options),
        ]);
    }

    public function providerProjectionAudits(
        IndexAtlasMemoryProviderProjectionAuditRequest $request,
        AtlasProviderProjectionAuditService $audits,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'provider_projection_audits' => $audits
                ->search($data, (int) ($data['limit'] ?? 50))
                ->map(fn ($audit): array => $audits->payload($audit))
                ->values()
                ->all(),
        ]);
    }

    public function providerProjectionAuditSummary(
        SummarizeAtlasMemoryProviderProjectionAuditRequest $request,
        AtlasProviderProjectionAuditService $audits,
    ): JsonResponse {
        $data = $request->validated();

        return response()->json([
            'provider_projection_audit_summary' => $audits->summary($data, (int) ($data['days'] ?? 30)),
        ]);
    }

    public function providerProjectionAuditPurge(
        PurgeAtlasMemoryProviderProjectionAuditRequest $request,
        AtlasProviderProjectionAuditService $audits,
    ): JsonResponse {
        $data = $request->validated();
        $purge = $audits->purge(
            $data,
            (int) ($data['older_than_days'] ?? 90),
            $request->boolean('dry_run', true),
        );

        return response()->json([
            'provider_projection_audit_purge' => $purge,
        ], (bool) ($purge['ok'] ?? false) ? 200 : 409);
    }

    public function providerProjectionApply(
        ApplyAtlasMemoryProviderProjectionRequest $request,
        AtlasProviderProjectionService $projection,
        AtlasProviderProjectionAuditService $audits,
    ): JsonResponse {
        $data = $request->validated();
        [$target, $context, $options] = $this->providerProjectionInput($data);

        if ((bool) ($data['allow_partial'] ?? false) !== true) {
            $review = $projection->review($target, $context, $options);
            $blocked = collect((array) ($review['projections'] ?? []))
                ->filter(fn (array $item): bool => ($item['change_type'] ?? null) === 'manual_drift')
                ->values();

            if ($blocked->isNotEmpty()) {
                $blockedResult = $this->providerProjectionBlockedPayload($target, $review, $blocked);
                $audit = $audits->recordApply($blockedResult, $context, [
                    'initiator' => 'api',
                    'confirmation_mode' => 'api_confirm',
                    'target' => $target,
                    'allow_partial' => false,
                ]);

                return response()->json([
                    'provider_projection' => $blockedResult,
                    'audit' => $audit ? $audits->payload($audit) : null,
                ], 409);
            }
        }

        $result = $projection->applyReviewed($target, $context, $options);
        $audit = $audits->recordApply($result, $context, [
            'initiator' => 'api',
            'confirmation_mode' => 'api_confirm',
            'target' => $target,
            'allow_partial' => (bool) ($data['allow_partial'] ?? false),
        ]);

        return response()->json([
            'provider_projection' => $result,
            'audit' => $audit ? $audits->payload($audit) : null,
        ], ($result['ok'] ?? false) === true ? 200 : 409);
    }

    public function governance(AtlasMemoryEntry $memoryEntry, AtlasMemoryGovernanceService $governance): JsonResponse
    {
        return response()->json([
            'governance' => $governance->audit($memoryEntry),
        ]);
    }

    public function indexRelations(
        IndexAtlasMemoryRelationRequest $request,
        AtlasMemoryGovernanceService $governance,
    ): JsonResponse {
        $data = $request->validated();
        $relations = $governance->listRelations($data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'relations' => $relations
                ->map(fn (AtlasMemoryEntryRelation $relation): array => $governance->relationPayload($relation))
                ->values()
                ->all(),
        ]);
    }

    public function reviewRelation(
        ReviewAtlasMemoryRelationRequest $request,
        AtlasMemoryEntryRelation $relation,
        AtlasMemoryGovernanceService $governance,
    ): JsonResponse {
        $relation = $governance->reviewRelation($relation, $request->validated() + [
            'reviewed_by' => 'api',
        ]);

        return response()->json([
            'relation' => $governance->relationPayload($relation),
        ]);
    }

    public function indexVerbatim(
        IndexAtlasVerbatimMemoryRequest $request,
        AtlasVerbatimMemoryService $verbatim,
    ): JsonResponse {
        $data = $request->validated();
        $memories = $verbatim->search($data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'verbatim_memories' => AtlasVerbatimMemoryResource::collection($memories)->resolve(),
        ]);
    }

    public function storeVerbatim(
        StoreAtlasVerbatimMemoryRequest $request,
        AtlasVerbatimMemoryService $verbatim,
    ): JsonResponse {
        $memory = $verbatim->record($request->validated());

        return response()->json([
            'verbatim_memory' => (new AtlasVerbatimMemoryResource($memory))->resolve(),
        ], 201);
    }

    public function showVerbatim(AtlasVerbatimMemory $verbatimMemory): JsonResponse
    {
        return response()->json([
            'verbatim_memory' => (new AtlasVerbatimMemoryResource($verbatimMemory))->resolve(),
        ]);
    }

    public function updateVerbatim(
        UpdateAtlasVerbatimMemoryRequest $request,
        AtlasVerbatimMemory $verbatimMemory,
        AtlasVerbatimMemoryService $verbatim,
    ): JsonResponse {
        $data = $request->validated();
        $memory = $verbatim->transition(
            $verbatimMemory,
            (string) $data['status'],
            (array) ($data['metadata'] ?? []),
        );

        return response()->json([
            'verbatim_memory' => (new AtlasVerbatimMemoryResource($memory))->resolve(),
        ]);
    }

    public function reviewVerbatim(
        ReviewAtlasVerbatimMemoryRequest $request,
        AtlasVerbatimMemory $verbatimMemory,
        AtlasVerbatimMemoryService $verbatim,
    ): JsonResponse {
        $memory = $verbatim->review($verbatimMemory, $request->validated() + [
            'review_action' => 'api_review',
        ]);

        return response()->json([
            'verbatim_memory' => (new AtlasVerbatimMemoryResource($memory))->resolve(),
        ]);
    }

    public function forProject(
        IndexAtlasMemoryEntryRequest $request,
        AtlasProject $project,
        AtlasMemoryRegistryService $memory,
    ): JsonResponse {
        $data = $request->validated();
        $entries = $memory->relevantForProject($project, $data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'project_id' => $project->id,
            'memories' => AtlasMemoryEntryResource::collection($entries)->resolve(),
        ]);
    }

    public function forTask(
        IndexAtlasMemoryEntryRequest $request,
        AtlasTask $task,
        AtlasMemoryRegistryService $memory,
    ): JsonResponse {
        $data = $request->validated();
        $entries = $memory->relevantForTask($task, $data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'task_id' => $task->id,
            'project_id' => $task->project_id,
            'memories' => AtlasMemoryEntryResource::collection($entries)->resolve(),
        ]);
    }

    public function forRun(
        IndexAtlasMemoryEntryRequest $request,
        AtlasEngineeringRun $run,
        AtlasMemoryRegistryService $memory,
    ): JsonResponse {
        $data = $request->validated();
        $entries = $memory->relevantForRun($run, $data, (int) ($data['limit'] ?? 50));

        return response()->json([
            'engineering_run_id' => $run->id,
            'task_id' => $run->task_id,
            'project_id' => $run->project_id,
            'memories' => AtlasMemoryEntryResource::collection($entries)->resolve(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array{0:string,1:array<string,mixed>,2:array<string,mixed>}
     */
    private function providerProjectionInput(array $data): array
    {
        $target = (string) ($data['target'] ?? 'all');
        $context = [];
        $workspace = trim((string) ($data['workspace'] ?? ''));
        if ($workspace !== '') {
            $context['workspace'] = $workspace;
        }

        $options = [];
        foreach (['max_lines', 'memory_limit', 'force'] as $key) {
            if (array_key_exists($key, $data)) {
                $options[$key] = $data[$key];
            }
        }

        return [$target, $context, $options];
    }

    /**
     * @param  array<string,mixed>  $review
     * @param  Collection<int,array<string,mixed>>  $blocked
     * @return array<string,mixed>
     */
    private function providerProjectionBlockedPayload(string $target, array $review, Collection $blocked): array
    {
        $projections = collect((array) ($review['projections'] ?? []));

        return [
            'ok' => false,
            'status' => 'needs_review',
            'workspace' => $review['workspace'] ?? null,
            'target' => $target,
            'review' => $review,
            'applied' => [],
            'blocked' => $blocked->all(),
            'failed' => [],
            'summary' => [
                'reviewed' => $projections->count(),
                'applicable' => $projections
                    ->filter(fn (array $item): bool => in_array($item['change_type'] ?? null, ['create', 'update', 'adopt'], true))
                    ->count(),
                'applied' => 0,
                'blocked' => $blocked->count(),
                'failed' => 0,
            ],
            'detail' => 'Provider projection apply pela API bloqueia drift manual; resolva antes de escrever.',
            'next_actions' => (array) ($review['next_actions'] ?? []),
        ];
    }
}
