<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiDecision;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasLedgerEvent;
use App\Models\AtlasProgrammingWorkItem;
use App\Models\AtlasProject;
use App\Models\AtlasToolRun;
use App\Services\Ai\Programming\AtlasCodeEnterpriseCertificationService;
use App\Services\Ai\Programming\AtlasCodeForgeReviewCompletionService;
use App\Services\Ai\Programming\AtlasCodeForgeUxOrchestratorService;
use App\Services\Ai\Programming\AtlasCodeForgeWorkIntakeService;
use App\Services\Ai\Programming\AtlasCodeObraCommandCenterService;
use App\Services\Ai\Programming\AtlasForgeContinuumCertificationService;
use App\Services\Ai\Programming\AtlasForgeProviderCapacityService;
use App\Services\Ai\Programming\AtlasForgeProviderFailureMemoryService;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationDriverRouter;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationService;
use App\Services\Ai\Programming\AtlasForgeProviderTopologyService;
use App\Services\Ai\Programming\AtlasForgeRuntimeDispatchService;
use App\Services\Ai\Programming\Governance\ProgrammingScopeMode;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementHumanTrustLedgerService;
use App\Services\Ai\SelfImprovement\AtlasSelfImprovementStrategyPortfolioService;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Atlas Code · normalized "Obras" surface.
 *
 * Atlas Server's legacy `/projects` returns `{ projects: [...] }` with the
 * AtlasProjectResource shape. The desktop bridge expects a flat list of
 * `Obra` (camelCase, slim). This controller wraps:
 *
 *   GET    /api/atlas-code/works            · list flat Obra[]
 *   GET    /api/atlas-code/works/{project}  · show single Obra (joined with summary)
 *   POST   /api/atlas-code/works            · create Obra from intent+objective
 *   GET    /api/atlas-code/works/{project}/state · full snapshot for cockpit
 *
 * The `/projects` controller stays untouched (mobile + legacy still depend on
 * it). This wrapper is the canonical Desktop surface.
 */
final class AtlasCodeWorkController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['nullable', 'string', 'max:40'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'workspace' => ['nullable', 'string', 'max:120'],
        ]);
        $limit = (int) ($data['limit'] ?? 50);
        $workspaceFilter = isset($data['workspace']) ? trim((string) $data['workspace']) : '';

        $query = AtlasProject::query()
            ->orderByDesc('updated_at')
            ->limit(max($limit * 4, 100));
        if (! empty($data['status'])) {
            $query->where('status', $data['status']);
        }

        $profiles = app(AtlasCodeWorkspaceProfileService::class);
        $defaultSlug = $profiles->defaultSlug();
        $resolvedFilter = $workspaceFilter !== ''
            ? ($profiles->findBySlug($workspaceFilter)['slug'] ?? null)
            : null;

        $projects = $query->get()
            ->reject(fn (AtlasProject $project): bool => $this->isSystemCertificationProject($project));

        if ($resolvedFilter !== null) {
            $projects = $projects->filter(function (AtlasProject $project) use ($resolvedFilter, $defaultSlug): bool {
                $slug = $this->workspaceSlugFor($project, $defaultSlug);

                return $slug === $resolvedFilter;
            });
        }

        $projects = $projects->take($limit)->values();

        return response()->json([
            'data' => $projects->map(fn (AtlasProject $p): array => $this->shape($p))->all(),
            'meta' => [
                'total' => $projects->count(),
                'system_certification_hidden' => true,
                'workspace_filter' => $resolvedFilter,
                'workspace_default' => $defaultSlug,
            ],
        ]);
    }

    public function show(AtlasProject $project): JsonResponse
    {
        return response()->json([
            'work' => $this->shape($project, withDetail: true),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'intent' => ['required', 'string', 'max:240'],
            'objective' => ['required', 'string', 'max:240'],
            'domain' => ['nullable', 'string', 'max:80'],
            'title' => ['nullable', 'string', 'max:180'],
            'workspace_slug' => ['nullable', 'string', 'max:120'],
            // Atlas Unified Rich Input adapter. All sub-fields are optional —
            // plain-text Obra creation must continue to work untouched.
            'rich_input' => ['nullable', 'array'],
            'rich_input_payload' => ['nullable', 'array'],
            'rich_input.uploaded_images' => ['nullable', 'array', 'max:8'],
            'rich_input.uploaded_images.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input.uploaded_documents' => ['nullable', 'array', 'max:4'],
            'rich_input.uploaded_documents.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input.url_attachments' => ['nullable', 'array', 'max:16'],
            'rich_input.url_attachments.*' => ['array'],
            'rich_input.url_attachments.*.url' => ['required_with:rich_input.url_attachments.*', 'string', 'max:2048'],
            'rich_input.url_attachments.*.kind' => ['nullable', 'string', 'max:40'],
            'rich_input.url_attachments.*.title' => ['nullable', 'string', 'max:240'],
            'rich_input.url_attachments.*.author' => ['nullable', 'string', 'max:240'],
            'rich_input.url_attachments.*.duration_sec' => ['nullable', 'integer', 'min:0'],
            'rich_input.url_attachments.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'rich_input.url_attachments.*.ref_id' => ['nullable', 'string', 'max:120'],
            'rich_input.text_blocks' => ['nullable', 'array', 'max:8'],
            'rich_input.text_blocks.*' => ['array'],
            'rich_input.text_blocks.*.file_name' => ['nullable', 'string', 'max:240'],
            'rich_input.text_blocks.*.mime_type' => ['nullable', 'string', 'max:120'],
            'rich_input.text_blocks.*.language' => ['nullable', 'string', 'max:40'],
            'rich_input.text_blocks.*.content' => ['required_with:rich_input.text_blocks.*', 'string', 'max:200000'],
            'rich_input.text_blocks.*.page_count' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.schema_version' => ['nullable', 'string', 'max:80'],
            'rich_input_payload.uploaded_image_ids' => ['nullable', 'array', 'max:8'],
            'rich_input_payload.uploaded_image_ids.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input_payload.uploaded_document_ids' => ['nullable', 'array', 'max:4'],
            'rich_input_payload.uploaded_document_ids.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9._-]+$/'],
            'rich_input_payload.url_attachments' => ['nullable', 'array', 'max:16'],
            'rich_input_payload.url_attachments.*' => ['array'],
            'rich_input_payload.url_attachments.*.url' => ['required_with:rich_input_payload.url_attachments.*', 'string', 'max:2048'],
            'rich_input_payload.url_attachments.*.kind' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.url_attachments.*.title' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.url_attachments.*.author' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.url_attachments.*.duration_sec' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.url_attachments.*.thumbnail_url' => ['nullable', 'string', 'max:2048'],
            'rich_input_payload.url_attachments.*.ref_id' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.text_blocks' => ['nullable', 'array', 'max:8'],
            'rich_input_payload.text_blocks.*' => ['array'],
            'rich_input_payload.text_blocks.*.file_name' => ['nullable', 'string', 'max:240'],
            'rich_input_payload.text_blocks.*.mime_type' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.text_blocks.*.language' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.text_blocks.*.content' => ['required_with:rich_input_payload.text_blocks.*', 'string', 'max:200000'],
            'rich_input_payload.text_blocks.*.page_count' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.source_manifest' => ['nullable', 'array', 'max:36'],
            'rich_input_payload.source_manifest.*' => ['array'],
            'rich_input_payload.source_manifest.*.id' => ['nullable', 'string', 'max:160'],
            'rich_input_payload.source_manifest.*.kind' => ['nullable', 'string', 'max:40'],
            'rich_input_payload.source_manifest.*.file_name' => ['nullable', 'string', 'max:2048'],
            'rich_input_payload.source_manifest.*.mime_type' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.source_manifest.*.size' => ['nullable', 'integer', 'min:0'],
            'rich_input_payload.source_manifest.*.uploaded_id' => ['nullable', 'string', 'max:120'],
            'rich_input_payload.source_manifest.*.source_hash' => ['nullable', 'string', 'max:128'],
            'rich_input_payload.source_manifest.*.source' => ['nullable', 'string', 'max:80'],
        ]);

        $profiles = app(AtlasCodeWorkspaceProfileService::class);
        $workspaceSlug = $profiles->resolveActiveSlug($data['workspace_slug'] ?? null);
        $profile = $workspaceSlug !== null ? $profiles->findBySlug($workspaceSlug) : null;

        $richInput = $this->normaliseRichInput($this->rawRichInput($data));
        $hasRichInput = $this->richInputHasContent($richInput);
        $contextRefs = $this->deriveRichInputContextRefs($richInput);

        $title = trim((string) ($data['title'] ?? $data['objective']));
        $project = AtlasProject::query()->create([
            'title' => $title,
            'description' => $data['intent'],
            'status' => 'active',
            'domain' => $data['domain'] ?? 'atlas',
            'goal' => $data['objective'],
            'desired_outcome' => $data['objective'],
            'priority' => 'medium',
            'last_touched_at' => now(),
            'metadata' => array_filter([
                'origin' => 'atlas-code',
                'intent' => $data['intent'],
                'workspace_slug' => $workspaceSlug,
                'workspace_path' => $profile['workspace_path'] ?? null,
                'workspace_name' => $profile['name'] ?? null,
                'workspace_production_status' => $profile['production_status'] ?? null,
                // Atlas Unified Rich Input is persisted as evidence-style
                // structural metadata. We deliberately do NOT promote the
                // Obra to a "ready intake" just because attachments were
                // supplied — `forge_work_intake_ready` stays false here.
                'rich_input' => $hasRichInput ? $richInput : null,
                'rich_input_schema_version' => $hasRichInput ? ($richInput['schema_version'] ?? null) : null,
                'rich_input_has_attachments' => $hasRichInput,
                'context_refs' => $contextRefs !== [] ? $contextRefs : null,
                'forge_work_intake_ready' => false,
            ], static fn ($v): bool => $v !== null && $v !== ''),
        ]);

        return response()->json([
            'work' => $this->shape($project, withDetail: true),
        ], 201);
    }

    /**
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function rawRichInput(array $data): array
    {
        $legacy = is_array($data['rich_input'] ?? null) ? $data['rich_input'] : [];
        $canonical = is_array($data['rich_input_payload'] ?? null) ? $data['rich_input_payload'] : [];

        if ($canonical === []) {
            return $legacy;
        }

        return array_replace_recursive($legacy, $canonical);
    }

    /**
     * Normalise the raw rich_input payload into a stable, provider-safe shape.
     * Drops empty sub-arrays so absent attachments don't pollute metadata.
     *
     * @param  array<string,mixed>  $raw
     * @return array<string,mixed>
     */
    private function normaliseRichInput(array $raw): array
    {
        // Canon: `atlas.rich_input.payload.v1` (see @atlas/rich-input-canon).
        // When the caller omits the schema we still stamp the canonical v1
        // string so every Obra metadata carries the same provenance, in
        // lockstep with mobile/desktop composers.
        $schemaVersion = is_string($raw['schema_version'] ?? null)
            ? (string) $raw['schema_version']
            : 'atlas.rich_input.payload.v1';

        $uploadedImages = array_values(array_filter(
            (array) ($raw['uploaded_images'] ?? $raw['uploaded_image_ids'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));
        $uploadedDocuments = array_values(array_filter(
            (array) ($raw['uploaded_documents'] ?? $raw['uploaded_document_ids'] ?? []),
            static fn ($v): bool => is_string($v) && $v !== '',
        ));

        $urlAttachments = [];
        foreach ((array) ($raw['url_attachments'] ?? []) as $attachment) {
            if (! is_array($attachment) || ! is_string($attachment['url'] ?? null)) {
                continue;
            }
            $urlAttachments[] = [
                'url' => (string) $attachment['url'],
                'kind' => isset($attachment['kind']) && is_string($attachment['kind']) ? $attachment['kind'] : 'generic',
                'title' => isset($attachment['title']) && is_string($attachment['title']) ? $attachment['title'] : null,
                'author' => isset($attachment['author']) && is_string($attachment['author']) ? $attachment['author'] : null,
                'duration_sec' => is_numeric($attachment['duration_sec'] ?? null) ? (int) $attachment['duration_sec'] : null,
                'thumbnail_url' => isset($attachment['thumbnail_url']) && is_string($attachment['thumbnail_url']) ? $attachment['thumbnail_url'] : null,
                'ref_id' => isset($attachment['ref_id']) && is_string($attachment['ref_id']) ? $attachment['ref_id'] : null,
                // Deterministic hash for evidence/context refs without echoing
                // any operator secret. Same url+ref_id always produces the
                // same hash, so Forge Intake can dedupe deterministically.
                'content_hash' => hash('sha256', (string) $attachment['url'].'|'.((string) ($attachment['ref_id'] ?? ''))),
            ];
        }

        $textBlocks = [];
        foreach ((array) ($raw['text_blocks'] ?? []) as $block) {
            if (! is_array($block) || ! is_string($block['content'] ?? null) || $block['content'] === '') {
                continue;
            }
            $content = (string) $block['content'];
            $textBlocks[] = [
                'file_name' => isset($block['file_name']) && is_string($block['file_name']) ? $block['file_name'] : 'block.txt',
                'mime_type' => isset($block['mime_type']) && is_string($block['mime_type']) ? $block['mime_type'] : 'text/plain',
                'language' => isset($block['language']) && is_string($block['language']) ? $block['language'] : null,
                // Persist only metadata + a deterministic content_hash; the
                // raw body lives in the chunked upload store / receipt.
                'content_hash' => hash('sha256', $content),
                'content_length' => mb_strlen($content),
                'page_count' => is_numeric($block['page_count'] ?? null) ? (int) $block['page_count'] : null,
            ];
        }

        $sourceManifest = [];
        foreach ((array) ($raw['source_manifest'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $kind = isset($entry['kind']) && is_string($entry['kind']) ? $entry['kind'] : null;
            $fileName = isset($entry['file_name']) && is_string($entry['file_name']) ? $entry['file_name'] : null;
            if ($kind === null && $fileName === null) {
                continue;
            }

            $sourceManifest[] = array_filter([
                'id' => isset($entry['id']) && is_string($entry['id']) ? $entry['id'] : null,
                'kind' => $kind,
                'file_name' => $fileName,
                'mime_type' => isset($entry['mime_type']) && is_string($entry['mime_type']) ? $entry['mime_type'] : null,
                'size' => is_numeric($entry['size'] ?? null) ? (int) $entry['size'] : null,
                'uploaded_id' => isset($entry['uploaded_id']) && is_string($entry['uploaded_id']) ? $entry['uploaded_id'] : null,
                'source_hash' => isset($entry['source_hash']) && is_string($entry['source_hash']) ? $entry['source_hash'] : null,
                'source' => isset($entry['source']) && is_string($entry['source']) ? $entry['source'] : null,
                'manifest_hash' => hash('sha256', json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            ], static fn ($v): bool => $v !== null && $v !== '');
        }

        return array_filter([
            'schema_version' => $schemaVersion,
            'uploaded_images' => $uploadedImages !== [] ? $uploadedImages : null,
            'uploaded_documents' => $uploadedDocuments !== [] ? $uploadedDocuments : null,
            'url_attachments' => $urlAttachments !== [] ? $urlAttachments : null,
            'text_blocks' => $textBlocks !== [] ? $textBlocks : null,
            'source_manifest' => $sourceManifest !== [] ? $sourceManifest : null,
        ], static fn ($v): bool => $v !== null);
    }

    /**
     * @param  array<string,mixed>  $richInput
     */
    private function richInputHasContent(array $richInput): bool
    {
        foreach (['uploaded_images', 'uploaded_documents', 'url_attachments', 'text_blocks', 'source_manifest'] as $key) {
            if (is_array($richInput[$key] ?? null) && $richInput[$key] !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Project the rich_input into a flat, append-only list of evidence/context
     * refs so downstream Forge Intake can audit attachment provenance without
     * walking the full nested structure.
     *
     * @param  array<string,mixed>  $richInput
     * @return list<string>
     */
    private function deriveRichInputContextRefs(array $richInput): array
    {
        $refs = [];
        foreach ((array) ($richInput['uploaded_images'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $refs[] = 'image_asset:'.$id;
            }
        }
        foreach ((array) ($richInput['uploaded_documents'] ?? []) as $id) {
            if (is_string($id) && $id !== '') {
                $refs[] = 'document_asset:'.$id;
            }
        }
        foreach ((array) ($richInput['url_attachments'] ?? []) as $attachment) {
            $hash = is_array($attachment) ? ($attachment['content_hash'] ?? null) : null;
            if (is_string($hash) && $hash !== '') {
                $refs[] = 'url:'.$hash;
            }
        }
        foreach ((array) ($richInput['text_blocks'] ?? []) as $block) {
            $hash = is_array($block) ? ($block['content_hash'] ?? null) : null;
            if (is_string($hash) && $hash !== '') {
                $refs[] = 'text_block:'.$hash;
            }
        }
        foreach ((array) ($richInput['source_manifest'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $hash = $entry['source_hash'] ?? $entry['manifest_hash'] ?? null;
            $kind = is_string($entry['kind'] ?? null) && $entry['kind'] !== '' ? $entry['kind'] : 'source';
            if (is_string($hash) && $hash !== '') {
                $refs[] = 'source_manifest:'.$kind.':'.$hash;
            }
        }

        return array_values(array_unique($refs));
    }

    public function state(AtlasProject $project): JsonResponse
    {
        // Sessions linked to this Obra via AiThread.source_id == project_id
        $threads = AiThread::query()
            ->where(function ($q) use ($project) {
                $q->where('source_id', (string) $project->getKey())
                    ->orWhere('workspace', (string) $project->getKey());
            })
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        $activeThread = $threads->first(fn (AiThread $t) => in_array($t->status, ['active', 'running', 'streaming'], true))
            ?? $threads->first();

        $messages = $activeThread
            ? $activeThread->messages()->orderBy('position')->limit(120)->get()->map(fn ($m): array => [
                'id' => (string) $m->getKey(),
                'role' => (string) $m->role,
                'body' => $this->renderBody($m->content),
                'occurred_at' => $m->occurred_at?->toJSON() ?? $m->created_at?->toJSON(),
            ])->all()
            : [];

        $traceIds = $this->traceIdsForWork($project, $threads->pluck('id')->map(fn ($id): string => (string) $id)->all());
        $latestDecision = $traceIds === []
            ? null
            : AiDecision::query()->whereIn('trace_id', $traceIds)->latest('created_at')->first();
        $forgeLiveExecution = $this->forgeLiveExecutionForWork($project);
        $forgeLiveExecutionHistory = $this->forgeLiveExecutionHistoryForWork($project);
        $programmingGovernance = $this->programmingGovernanceForWork($project);

        return response()->json([
            'work' => $this->shape($project, withDetail: true),
            'sessions' => $threads->map(fn (AiThread $t): array => [
                'id' => (string) $t->getKey(),
                'title' => (string) ($t->title ?? 'Sessão'),
                'status' => $this->mapThreadStatus((string) ($t->status ?? 'idle')),
                'turns' => (int) ($t->message_count ?? 0),
                'last_message_at' => $t->last_message_at?->toJSON(),
            ])->all(),
            'active_thread' => $activeThread ? (string) $activeThread->getKey() : null,
            'messages' => $messages,
            'sdd' => $this->sddSnapshot($project),
            'receipt' => $latestDecision ? $this->receiptShape($latestDecision, $project) : null,
            'gates' => $this->gateRunsForWork($project),
            'evidence' => $this->evidenceForWork($project),
            'programming_governance' => $programmingGovernance,
            'forge_task_queue' => $this->forgeTaskQueueForWork($project, $programmingGovernance, $forgeLiveExecution, $forgeLiveExecutionHistory),
            'forge_fast_path' => $this->forgeFastPathForWork($project),
            'forge_live_execution' => $forgeLiveExecution,
            'forge_live_execution_async' => $this->forgeLiveExecutionAsyncForWork($project),
            'forge_live_execution_history' => $forgeLiveExecutionHistory,
            'forge_review' => $this->forgeReviewForWork($project),
            'forge_review_history' => $this->forgeReviewHistoryForWork($project),
            'forge_review_packet' => $this->forgeReviewPacketForWork($project),
            'forge_completion_claim' => $this->forgeCompletionClaimForWork($project),
            'forge_work_intake' => $this->forgeWorkIntakeForWork($project),
            'forge_provider_topology' => $this->forgeProviderTopologyForWork($project),
            'forge_continuum_certification' => $this->forgeContinuumCertificationForWork($project),
            'forge_runtime_dispatch' => $this->forgeRuntimeDispatchForWork($project),
            'forge_provider_capacity' => $this->forgeProviderCapacityForWork($project),
            'forge_provider_failure_memory' => $this->forgeProviderFailureMemoryForWork($project),
            'forge_provider_invocation' => $this->forgeProviderInvocationForWork($project),
            'forge_provider_invocation_receipt' => $this->forgeProviderInvocationReceiptForWork($project),
            'forge_provider_driver_status' => $this->forgeProviderDriverStatusForWork($project),
            'forge_ux_orchestrator' => $this->forgeUxOrchestratorForWork($project),
            'obra_command_center' => $this->obraCommandCenterForWork($project),
            'self_improvement_governance' => $this->selfImprovementGovernanceForWork($project),
            'self_improvement_activation' => $this->selfImprovementActivationForWork($project),
            'checkpoint' => $this->checkpointForWork($project),
            'atlas_code_enterprise_certification' => $this->atlasCodeEnterpriseCertificationForProduct(),
            'repair' => [],
            'learning_proposals' => [],
            'generated_at' => now()->toJSON(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function shape(AtlasProject $project, bool $withDetail = false): array
    {
        $profiles = app(AtlasCodeWorkspaceProfileService::class);
        $defaultSlug = $profiles->defaultSlug();
        $workspaceSlug = $this->workspaceSlugFor($project, $defaultSlug);
        $profile = $profiles->findBySlug($workspaceSlug);

        $base = [
            'id' => (string) $project->getKey(),
            'title' => (string) ($project->title ?? ''),
            'objective' => (string) ($project->goal ?? $project->desired_outcome ?? $project->title ?? ''),
            'status' => $this->mapStatus((string) ($project->status ?? 'active')),
            'domain' => (string) ($project->domain ?? 'atlas'),
            'workspace_path' => (string) (
                data_get($project->metadata, 'workspace_path')
                ?? ($profile['workspace_path'] ?? '')
            ),
            'workspace_slug' => $workspaceSlug,
            'workspace_name' => (string) (
                data_get($project->metadata, 'workspace_name')
                ?? ($profile['name'] ?? '')
            ),
            'created_at' => $project->created_at?->toJSON(),
            'updated_at' => $project->updated_at?->toJSON(),
        ];
        if (! $withDetail) {
            return $base;
        }

        return array_merge($base, [
            'description' => (string) ($project->description ?? ''),
            'next_action' => (string) ($project->next_action ?? ''),
            'priority' => (string) ($project->priority ?? 'medium'),
            'definition_of_done' => (string) ($project->definition_of_done ?? ''),
            'metadata' => $project->metadata ?? [],
            'last_touched_at' => $project->last_touched_at?->toJSON(),
            'next_review_at' => $project->next_review_at?->toJSON(),
        ]);
    }

    private function isSystemCertificationProject(AtlasProject $project): bool
    {
        return (string) data_get($project->metadata, 'origin', '') === 'atlas-code-enterprise-certification';
    }

    /**
     * Resolve which Project/Workspace owns a given Obra.
     *
     * Order:
     *   1. metadata.workspace_slug (set by future workspace-aware creations)
     *   2. metadata.workspace if it matches a known profile slug
     *   3. domain if it matches a known profile slug
     *   4. configured default (atlas)
     *
     * Honest fallback: when metadata is silent the Obra is treated as part of
     * the default project. This preserves backwards-compatibility with Obras
     * created before multi-project scoping existed.
     */
    private function workspaceSlugFor(AtlasProject $project, string $defaultSlug): string
    {
        $candidates = [
            (string) (data_get($project->metadata, 'workspace_slug') ?? ''),
            (string) (data_get($project->metadata, 'workspace') ?? ''),
            (string) ($project->domain ?? ''),
        ];
        $profiles = app(AtlasCodeWorkspaceProfileService::class);
        foreach ($candidates as $candidate) {
            $candidate = trim(strtolower($candidate));
            if ($candidate === '') {
                continue;
            }
            $profile = $profiles->findBySlug($candidate);
            if ($profile !== null) {
                return $profile['slug'];
            }
        }

        return $defaultSlug;
    }

    private function mapStatus(string $status): string
    {
        return match ($status) {
            'active', 'in_progress', 'planning' => 'active',
            'paused', 'snoozed' => 'idle',
            'archived', 'completed', 'done' => 'archived',
            default => 'active',
        };
    }

    private function mapThreadStatus(string $status): string
    {
        return match ($status) {
            'active', 'streaming' => 'running',
            'paused', 'awaiting_input' => 'paused',
            'completed', 'done', 'closed' => 'done',
            'failed', 'error' => 'failed',
            default => 'paused',
        };
    }

    private function renderBody(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        if (is_array($content)) {
            if (isset($content['text']) && is_string($content['text'])) {
                return $content['text'];
            }

            return (string) json_encode($content, JSON_UNESCAPED_UNICODE);
        }

        return '';
    }

    /**
     * Pipeline OS snapshot · canon stages: context · spec · plan · execute · verify · learn.
     * Stage is inferred from the AtlasProject lifecycle without inventing data.
     *
     * @return array<string, mixed>
     */
    private function sddSnapshot(AtlasProject $project): array
    {
        $status = (string) ($project->status ?? 'active');
        $stage = match ($status) {
            'planning', 'spec' => 'spec',
            'in_progress' => 'execute',
            'review', 'verify' => 'verify',
            'completed', 'archived' => 'learn',
            'idle', 'paused' => 'idle',
            default => 'context',
        };
        $steps = [
            ['key' => 'context', 'label' => 'Context', 'status' => $this->stepStatus('context', $stage)],
            ['key' => 'spec', 'label' => 'Spec', 'status' => $this->stepStatus('spec', $stage)],
            ['key' => 'plan', 'label' => 'Plan', 'status' => $this->stepStatus('plan', $stage)],
            ['key' => 'execute', 'label' => 'Execute', 'status' => $this->stepStatus('execute', $stage)],
            ['key' => 'verify', 'label' => 'Verify', 'status' => $this->stepStatus('verify', $stage)],
            ['key' => 'learn', 'label' => 'Learn', 'status' => $this->stepStatus('learn', $stage)],
        ];

        return [
            'stage' => $stage,
            'steps' => $steps,
            'spec' => null,
            'plan' => null,
        ];
    }

    private function stepStatus(string $step, string $current): string
    {
        $order = ['context', 'spec', 'plan', 'execute', 'verify', 'learn'];
        $stepIdx = array_search($step, $order, true);
        $currentIdx = array_search($current, $order, true);
        if ($currentIdx === false || $stepIdx === false) {
            return 'pending';
        }
        if ($stepIdx < $currentIdx) {
            return 'done';
        }
        if ($stepIdx === $currentIdx) {
            return 'active';
        }

        return 'pending';
    }

    /**
     * @param  array<int,string>  $threadIds
     * @return array<int,string>
     */
    private function traceIdsForWork(AtlasProject $project, array $threadIds): array
    {
        return AiTrace::query()
            ->where(function ($query) use ($project, $threadIds): void {
                $query->where('source_id', (string) $project->getKey());
                if ($threadIds !== []) {
                    $query->orWhereIn('thread_id', $threadIds);
                }
            })
            ->latest('created_at')
            ->limit(50)
            ->pluck('id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function receiptShape(AiDecision $decision, AtlasProject $project): array
    {
        $score = (int) ($decision->confidence_score ?? 0);
        $confidence = match (true) {
            $score >= 80 => 'high',
            $score >= 50 => 'medium',
            $score > 0 => 'low',
            default => 'unknown',
        };

        $candidates = is_array($decision->candidates) ? $decision->candidates : [];
        $fallback = [];
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $name = $candidate['provider'] ?? $candidate['name'] ?? null;
            if (is_string($name) && $name !== ($decision->selected_provider ?? null)) {
                $fallback[] = $name;
            }
        }

        $signature = $this->latestSignature($decision);

        return [
            'id' => (string) $decision->getKey(),
            'obraId' => (string) $project->getKey(),
            'traceId' => $decision->trace_id ? (string) $decision->trace_id : null,
            'primary' => (string) ($decision->selected_provider ?? 'unknown'),
            'model' => (string) ($decision->selected_model ?? ''),
            'confidence' => $confidence,
            'confidenceScore' => $score,
            'routeMode' => (string) ($decision->route_mode ?? ''),
            'taskType' => (string) ($decision->task_type ?? ''),
            'riskLevel' => (string) ($decision->risk_level ?? ''),
            'budgetEstUsd' => (float) data_get($decision->constraints, 'budget_est_usd', 0),
            'budgetUsedUsd' => (float) data_get($decision->metrics_snapshot, 'budget_used_usd', 0),
            'fallbackChain' => array_values(array_unique($fallback)),
            'signedBy' => $signature['signed_by'],
            'signature' => $signature['signature'],
            'signedAt' => $signature['signed_at'],
            'reason' => (string) ($decision->reason ?? ''),
            'createdAt' => $decision->created_at?->toJSON(),
        ];
    }

    /**
     * @return array{signed_by: ?string, signature: ?string, signed_at: ?string}
     */
    private function latestSignature(AiDecision $decision): array
    {
        $event = AtlasLedgerEvent::query()
            ->where('receipt_id', (string) $decision->getKey())
            ->where('event_type', 'atlas_code.receipt.signed')
            ->latest('occurred_at')
            ->first();

        if (! $event) {
            return ['signed_by' => null, 'signature' => null, 'signed_at' => null];
        }

        return [
            'signed_by' => is_string(data_get($event->payload, 'signer_id')) ? data_get($event->payload, 'signer_id') : null,
            'signature' => is_string(data_get($event->payload, 'signature')) ? data_get($event->payload, 'signature') : null,
            'signed_at' => is_string(data_get($event->payload, 'signed_at')) ? data_get($event->payload, 'signed_at') : null,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function gateRunsForWork(AtlasProject $project): array
    {
        $workspace = (string) (data_get($project->metadata, 'workspace_path') ?: '');

        $toolRuns = AtlasToolRun::query()
            ->where(function ($query) use ($project, $workspace): void {
                $query->where(function ($inner) use ($project): void {
                    $inner->where('run_context_type', 'atlas_project')
                        ->where('run_context_id', (string) $project->getKey());
                });

                if ($workspace !== '') {
                    $query->orWhere('workspace', $workspace);
                }
            })
            ->latest('created_at')
            ->limit(30)
            ->get(['id', 'tool_slug', 'status', 'summary_json', 'created_at']);

        $runs = $toolRuns->map(function (AtlasToolRun $run): array {
            return [
                'id' => (string) $run->id,
                'tool_slug' => (string) ($run->tool_slug ?? 'tool_run'),
                'status' => (string) ($run->status ?? 'pending'),
                'message' => (string) (data_get($run->summary_json, 'summary') ?? data_get($run->summary_json, 'message') ?? ''),
            ];
        });

        $engineeringRuns = AtlasEngineeringRun::query()
            ->where('project_id', $project->getKey())
            ->latest('updated_at')
            ->limit(10)
            ->get(['id', 'status', 'decision']);

        foreach ($engineeringRuns as $run) {
            $runs->push([
                'id' => 'engineering:'.$run->id,
                'tool_slug' => 'engineering_run',
                'status' => (string) ($run->status ?? $run->decision ?? 'pending'),
                'message' => (string) ($run->decision ?? ''),
            ]);
        }

        return $runs->values()->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function evidenceForWork(AtlasProject $project): array
    {
        $runs = AtlasEngineeringRun::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('updated_at')
            ->limit(25)
            ->get(['id', 'status', 'decision', 'finished_at', 'updated_at']);

        $evidence = AtlasEngineeringEvidence::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('recorded_at')
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        return collect()
            ->merge($runs->map(fn (AtlasEngineeringRun $run): array => [
                'id' => 'run:'.$run->id,
                'kind' => 'engineering_run',
                'summary' => sprintf('engineering run %s · %s', substr((string) $run->id, 0, 8), (string) ($run->status ?? 'unknown')),
                'createdAt' => ($run->finished_at ?? $run->updated_at)?->toJSON(),
            ]))
            ->merge($evidence->map(fn (AtlasEngineeringEvidence $item): array => [
                'id' => 'evidence:'.$item->id,
                'kind' => (string) ($item->evidence_type ?? 'evidence'),
                'summary' => (string) ($item->summary ?? $item->output_excerpt ?? 'evidence '.$item->id),
                'createdAt' => ($item->recorded_at ?? $item->created_at)?->toJSON(),
            ]))
            ->sortByDesc(fn (array $item): string => (string) ($item['createdAt'] ?? ''))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeLiveExecutionForWork(AtlasProject $project): ?array
    {
        $metadataSnapshot = data_get($project->metadata, 'latest_forge_live_execution');
        if (is_array($metadataSnapshot)) {
            return $metadataSnapshot;
        }

        if (! Schema::hasTable('atlas_engineering_evidence')
            || ! Schema::hasColumn('atlas_engineering_evidence', 'project_id')
        ) {
            return null;
        }

        $query = AtlasEngineeringEvidence::query()
            ->where('project_id', $project->getKey());

        if (Schema::hasColumn('atlas_engineering_evidence', 'evidence_type')) {
            $query->where('evidence_type', 'forge_live_execution');
        }

        if (Schema::hasColumn('atlas_engineering_evidence', 'recorded_at')) {
            $query->orderByDesc('recorded_at');
        }
        $evidence = $query->orderByDesc('created_at')->first();
        if (! $evidence) {
            return null;
        }

        $snapshot = data_get($evidence->metadata, 'snapshot');
        if (is_array($snapshot)) {
            $snapshot['evidence_id'] = (string) $evidence->id;

            return $snapshot;
        }

        return [
            'schema_version' => 'atlas.code.forge_live_execution.snapshot.v1',
            'status' => (string) ($evidence->status ?? 'unknown'),
            'obra_id' => (string) $project->getKey(),
            'command' => (string) ($evidence->command ?? ''),
            'last_run_at' => ($evidence->recorded_at ?? $evidence->created_at)?->toJSON(),
            'evidence_id' => (string) $evidence->id,
            'summary' => (string) ($evidence->summary ?? ''),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeLiveExecutionAsyncForWork(AtlasProject $project): ?array
    {
        $execution = data_get($project->metadata, 'latest_forge_live_execution_async');

        return is_array($execution) ? $execution : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeLiveExecutionHistoryForWork(AtlasProject $project): array
    {
        $metadataHistory = collect((array) data_get($project->metadata, 'atlas_code_forge_live_execution_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $entry): array => $this->forgeLiveExecutionHistoryEntry($entry))
            ->values();

        $source = 'AtlasProject.metadata.atlas_code_forge_live_execution_history';
        $entries = $metadataHistory;

        if ($entries->isEmpty()) {
            $source = 'atlas_engineering_evidence';
            $entries = $this->forgeLiveExecutionHistoryFromEvidence($project);
        }

        return [
            'schema_version' => 'atlas.code.forge_live_execution_history.v1',
            'obra_id' => (string) $project->getKey(),
            'source_authority' => $entries->isEmpty() ? 'none' : $source,
            'total' => $entries->count(),
            'latest_entry_id' => (string) data_get($entries->first(), 'history_id', ''),
            'entries' => $entries->take(20)->values()->all(),
        ];
    }

    /**
     * @return Collection<int,array<string,mixed>>
     */
    private function forgeLiveExecutionHistoryFromEvidence(AtlasProject $project): Collection
    {
        if (! Schema::hasTable('atlas_engineering_evidence')
            || ! Schema::hasColumn('atlas_engineering_evidence', 'project_id')
        ) {
            return collect();
        }

        $query = AtlasEngineeringEvidence::query()
            ->where('project_id', $project->getKey());

        if (Schema::hasColumn('atlas_engineering_evidence', 'evidence_type')) {
            $query->where('evidence_type', 'forge_live_execution');
        }

        if (Schema::hasColumn('atlas_engineering_evidence', 'recorded_at')) {
            $query->orderByDesc('recorded_at');
        }

        return $query
            ->orderByDesc('created_at')
            ->limit(20)
            ->get()
            ->map(function (AtlasEngineeringEvidence $evidence): array {
                $snapshot = data_get($evidence->metadata, 'snapshot');
                $entry = is_array($snapshot) ? $snapshot : [
                    'status' => (string) ($evidence->status ?? 'unknown'),
                    'command' => (string) ($evidence->command ?? ''),
                    'last_run_at' => ($evidence->recorded_at ?? $evidence->created_at)?->toJSON(),
                ];

                $entry['history_id'] = 'evidence:'.(string) $evidence->id;
                $entry['evidence_id'] = $entry['evidence_id'] ?? (string) $evidence->id;

                return $this->forgeLiveExecutionHistoryEntry($entry);
            })
            ->values();
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     */
    private function forgeLiveExecutionHistoryEntry(array $entry): array
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
            'evidence_pack_digest' => $entry['evidence_pack_digest'] ?? $this->forgeEvidencePackDigest($entry),
            'promotion_status' => $entry['promotion_status'] ?? data_get($entry, 'governed_execution.promotion_status'),
            'promotion_id' => $entry['promotion_id'] ?? data_get($entry, 'governed_execution.promotion.promotion_id'),
            'promotion_evidence_id' => $entry['promotion_evidence_id'] ?? data_get($entry, 'governed_execution.promotion.evidence_id'),
            'promotion_receipt_id' => $entry['promotion_receipt_id'] ?? data_get($entry, 'governed_execution.promotion.receipt_id'),
            'rollback_id' => $entry['rollback_id'] ?? data_get($entry, 'governed_execution.promotion.rollback_id'),
            'rollback_evidence_id' => $entry['rollback_evidence_id'] ?? data_get($entry, 'governed_execution.promotion.rollback_evidence_id'),
            'live_workspace_mutated' => (bool) ($entry['live_workspace_mutated'] ?? data_get($entry, 'governed_execution.live_workspace_mutated', false)),
            'remaining_blockers' => array_values((array) ($entry['remaining_blockers'] ?? [])),
            'external_provider_call' => (bool) ($entry['external_provider_call'] ?? false),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>|null
     */
    private function forgeEvidencePackDigest(array $entry): ?array
    {
        $pack = data_get($entry, 'evidence_pack');
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
            'status' => (string) ($pack['status'] ?? data_get($entry, 'status', 'unknown')),
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
     * @return array<string,mixed>|null
     */
    private function forgeReviewForWork(AtlasProject $project): ?array
    {
        $review = data_get($project->metadata, 'latest_atlas_code_forge_review');

        return is_array($review) ? $review : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeReviewPacketForWork(AtlasProject $project): ?array
    {
        $packet = data_get($project->metadata, 'latest_atlas_code_forge_review_packet');
        if (! is_array($packet)) {
            return null;
        }

        $runId = (string) ($packet['fast_path_run_id'] ?? '');
        if ($runId === '') {
            return null;
        }

        try {
            $service = app(AtlasCodeForgeReviewCompletionService::class);
            $payload = $service->packet($project, $runId);
            unset($payload['http_status']);

            return $payload;
        } catch (Throwable) {
            return $packet;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeWorkIntakeForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasCodeForgeWorkIntakeService::class)->get($project);
        } catch (Throwable) {
            $latest = data_get($project->metadata, 'latest_atlas_code_forge_work_intake');

            return is_array($latest) ? $latest : null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeCompletionClaimForWork(AtlasProject $project): ?array
    {
        $latestClaim = data_get($project->metadata, 'latest_atlas_code_forge_completion_claim');
        if (is_array($latestClaim)) {
            $runId = (string) ($latestClaim['fast_path_run_id'] ?? '');
            if ($runId !== '') {
                try {
                    $service = app(AtlasCodeForgeReviewCompletionService::class);

                    return $service->completionClaim($project, $runId);
                } catch (Throwable) {
                    return $latestClaim;
                }
            }

            return $latestClaim;
        }

        return null;
    }

    /**
     * @return array<string,mixed>
     */
    private function forgeReviewHistoryForWork(AtlasProject $project): array
    {
        $reviews = collect((array) data_get($project->metadata, 'atlas_code_forge_review_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->map(fn (array $review): array => $this->forgeReviewHistoryEntry($project, $review))
            ->values();

        return [
            'schema_version' => 'atlas.code.forge_review_history.v1',
            'obra_id' => (string) $project->getKey(),
            'source_authority' => $reviews->isEmpty() ? 'none' : 'AtlasProject.metadata.atlas_code_forge_review_history',
            'total' => $reviews->count(),
            'latest_review_id' => (string) data_get($reviews->first(), 'review_id', ''),
            'entries' => $reviews->take(20)->values()->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $review
     * @return array<string,mixed>
     */
    private function forgeReviewHistoryEntry(AtlasProject $project, array $review): array
    {
        $historyId = (string) ($review['history_id'] ?? '');
        $run = $this->forgeLiveExecutionHistoryEntryFor($project, $historyId);
        $digest = is_array(data_get($run, 'evidence_pack_digest')) ? (array) data_get($run, 'evidence_pack_digest') : [];

        return [
            'schema_version' => 'atlas.code.forge_review_history_entry.v1',
            'review_id' => (string) ($review['review_id'] ?? ''),
            'history_id' => $historyId !== '' ? $historyId : null,
            'execution_id' => $review['execution_id'] ?? null,
            'obra_id' => (string) ($review['obra_id'] ?? $project->getKey()),
            'decision' => (string) ($review['decision'] ?? 'unknown'),
            'status' => (string) ($review['status'] ?? 'unknown'),
            'comment' => $review['comment'] ?? null,
            'reviewed_at' => $review['reviewed_at'] ?? null,
            'reviewer_id' => $review['reviewer_id'] ?? null,
            'approval_effective' => (bool) ($review['approval_effective'] ?? false),
            'final_completion_allowed' => (bool) data_get($review, 'review_gate.final_completion_allowed', false),
            'completion_claim_allowed' => (bool) data_get($review, 'review_gate.completion_claim_allowed', false),
            'human_approved' => (bool) data_get($review, 'review_gate.human_approved', false),
            'live_execution_status' => (string) ($review['live_execution_status'] ?? data_get($run, 'status', 'unknown')),
            'run_id' => $review['run_id'] ?? data_get($run, 'run_id'),
            'run_evidence_id' => $review['run_evidence_id'] ?? data_get($run, 'evidence_id'),
            'review_evidence_id' => $review['review_evidence_id'] ?? $review['evidence_id'] ?? null,
            'promotion' => is_array($review['promotion'] ?? null) ? $review['promotion'] : null,
            'rollback' => is_array($review['rollback'] ?? null) ? $review['rollback'] : null,
            'promotion_status' => data_get($review, 'promotion.promotion_status'),
            'rollback_id' => data_get($review, 'promotion.rollback_execution.rollback_id'),
            'rollback_evidence_id' => data_get($review, 'promotion.rollback_execution.evidence.engineering_evidence_id'),
            'live_workspace_mutated' => (bool) data_get($review, 'promotion.live_workspace_mutated', false),
            'stage_receipt_count' => (int) ($review['stage_receipt_count'] ?? data_get($digest, 'stage_receipt_count', 0)),
            'ledger_event_count' => (int) ($review['ledger_event_count'] ?? data_get($digest, 'ledger_event_count', 0)),
            'report_hash' => $review['report_hash'] ?? data_get($digest, 'report_hash'),
            'stage_timeline_hash' => $review['stage_timeline_hash'] ?? data_get($digest, 'stage_timeline_hash'),
            'evidence_pack_hash' => $review['evidence_pack_hash'] ?? data_get($digest, 'evidence_pack_hash'),
            'blockers' => array_values((array) data_get($review, 'review_gate.blockers', [])),
            'source_authority' => (string) ($review['source_authority'] ?? 'AtlasProject.metadata.atlas_code_forge_review_history'),
            'summary' => $review['summary'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeLiveExecutionHistoryEntryFor(AtlasProject $project, string $historyId): ?array
    {
        if ($historyId === '') {
            return null;
        }

        $history = collect((array) data_get($project->metadata, 'atlas_code_forge_live_execution_history', []))
            ->filter(fn (mixed $entry): bool => is_array($entry));
        $entry = $history->first(fn (array $entry): bool => (string) ($entry['history_id'] ?? '') === $historyId);

        return is_array($entry) ? $this->forgeLiveExecutionHistoryEntry($entry) : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function checkpointForWork(AtlasProject $project): ?array
    {
        $checkpoint = data_get($project->metadata, 'latest_atlas_code_checkpoint');

        return is_array($checkpoint) ? $checkpoint : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeFastPathForWork(AtlasProject $project): ?array
    {
        $fastPath = data_get($project->metadata, 'latest_atlas_code_forge_fast_path');

        return is_array($fastPath) ? $fastPath : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function atlasCodeEnterpriseCertificationForProduct(): ?array
    {
        return app(AtlasCodeEnterpriseCertificationService::class)->latest();
    }

    /**
     * @return array<string,mixed>|null
     */
    private function selfImprovementActivationForWork(AtlasProject $project): ?array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $activation = data_get($metadata, 'self_improvement_activation');
        if (! is_array($activation)) {
            return null;
        }

        return [
            'schema_version' => 'atlas.self_improvement.forge_activation_state.v1',
            'activation_id' => $activation['activation_id'] ?? null,
            'proposal_id' => $activation['proposal_id'] ?? null,
            'proposal_hash' => $activation['proposal_hash'] ?? null,
            'power_gate_hash' => $activation['power_gate_hash'] ?? null,
            'invariant_lock_hash' => $activation['invariant_lock_hash'] ?? null,
            'regression_sentinel_hash' => $activation['regression_sentinel_hash'] ?? null,
            'strategy_bucket' => $activation['strategy_bucket'] ?? null,
            'portfolio_deviation' => $activation['portfolio_deviation'] ?? false,
            'maturity_target' => $activation['maturity_target'] ?? null,
            'reviewer' => $activation['reviewer'] ?? null,
            'reason' => $activation['reason'] ?? null,
            'approved_at' => $activation['approved_at'] ?? null,
            'external_provider_call' => false,
            'separated_from' => 'external_rivals_certification',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function selfImprovementGovernanceForWork(AtlasProject $project): ?array
    {
        try {
            $trustLedger = app(AtlasSelfImprovementHumanTrustLedgerService::class)
                ->snapshot($project);
            $portfolio = app(AtlasSelfImprovementStrategyPortfolioService::class)
                ->snapshot([]);

            return [
                'schema_version' => 'atlas.self_improvement.governance_state.v1',
                'trust_ledger' => $trustLedger,
                'strategy_portfolio' => $portfolio,
                'commands' => [
                    'proposal_gate' => 'php artisan atlas:self-improvement:proposal-gate --proposal=@path --json --strict',
                    'before_after' => 'php artisan atlas:self-improvement:before-after --before=@path --after=@path --json --strict',
                    'invariant_lock' => 'php artisan atlas:self-improvement:invariant-lock --after-snapshot=@path --proposal=@path --json --strict',
                    'regression_sentinel' => 'php artisan atlas:self-improvement:regression-sentinel --before-snapshot=@path --after-snapshot=@path --json --strict',
                    'maturity_score' => 'php artisan atlas:self-improvement:maturity-score --descriptor=@path --json --strict',
                    'trust_ledger' => 'php artisan atlas:self-improvement:trust-ledger --obra=<uuid> --json',
                ],
                'external_provider_call' => false,
                'separated_from' => 'external_rivals_certification',
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderCapacityForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasForgeProviderCapacityService::class)
                ->snapshot(['obra_id' => (string) $project->getKey()]);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderFailureMemoryForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasForgeProviderFailureMemoryService::class)
                ->snapshot($project);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderTopologyForWork(AtlasProject $project): ?array
    {
        return app(AtlasForgeProviderTopologyService::class)->topology([
            'obra_id' => (string) $project->getKey(),
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeRuntimeDispatchForWork(AtlasProject $project): ?array
    {
        return app(AtlasForgeRuntimeDispatchService::class)->latest($project);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderInvocationForWork(AtlasProject $project): ?array
    {
        return app(AtlasForgeProviderInvocationService::class)->latest($project);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderInvocationReceiptForWork(AtlasProject $project): ?array
    {
        return app(AtlasForgeProviderInvocationService::class)->latestReceipt($project);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeProviderDriverStatusForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasForgeProviderInvocationDriverRouter::class)->driverStatus();
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeUxOrchestratorForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasCodeForgeUxOrchestratorService::class)->snapshot([
                'obra_id' => (string) $project->getKey(),
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function obraCommandCenterForWork(AtlasProject $project): ?array
    {
        try {
            return app(AtlasCodeObraCommandCenterService::class)->snapshot([
                'obra_id' => (string) $project->getKey(),
            ]);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function forgeContinuumCertificationForWork(AtlasProject $project): ?array
    {
        $report = app(AtlasForgeContinuumCertificationService::class)->certify([
            'obra_id' => (string) $project->getKey(),
            'strict' => false,
        ]);

        // Slim projection for state snapshot: full audit lives at
        //   php artisan atlas:forge:continuum-certify --json --strict
        return [
            'schema_version' => $report['schema_version'] ?? null,
            'status' => $report['status'] ?? null,
            'obra_id' => $report['obra_id'] ?? null,
            'obra_present' => $report['obra_present'] ?? null,
            'invariants_all_true' => $report['invariants_all_true'] ?? null,
            'invariants' => $report['invariants'] ?? [],
            'blockers' => $report['blockers'] ?? [],
            'live_decide_runtime' => $report['live_decide_runtime'] ?? null,
            'evidence_command' => $report['evidence_command'] ?? null,
            'external_provider_call' => false,
            'separated_from' => $report['separated_from'] ?? 'external_rivals_certification',
            'note' => 'State projection slim — full audit em atlas:forge:continuum-certify --json --strict.',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function programmingGovernanceForWork(AtlasProject $project): ?array
    {
        if (! Schema::hasTable('atlas_programming_work_items')) {
            return null;
        }

        try {
            $workItem = $this->programmingWorkItemForWork($project);
            if (! $workItem) {
                return null;
            }

            return [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => $this->programmingWorkItemShape($workItem),
                'spec' => $this->nonEmptyArrayOrNull($workItem->spec_json),
                'plan' => $this->nonEmptyArrayOrNull($workItem->plan_json),
                'tasks' => array_values((array) $workItem->tasks_json),
                'gate_runs' => $this->programmingGateRunsForWorkItem($workItem),
                'reviews' => $this->programmingReviewsForWorkItem($workItem),
                'evidence_refs' => array_values((array) $workItem->evidence_refs_json),
                'artifacts' => array_values((array) data_get($workItem->metadata_json, 'artifacts', [])),
                'degraded' => false,
                'degraded_reason' => null,
            ];
        } catch (Throwable $e) {
            return [
                'schema_version' => 'atlas.code.programming_governance_snapshot.v1',
                'source_authority' => 'atlas_programming_work_items',
                'work_item' => null,
                'spec' => null,
                'plan' => null,
                'tasks' => [],
                'gate_runs' => [],
                'reviews' => [],
                'evidence_refs' => [],
                'artifacts' => [],
                'degraded' => true,
                'degraded_reason' => $e->getMessage(),
            ];
        }
    }

    private function programmingWorkItemForWork(AtlasProject $project): ?AtlasProgrammingWorkItem
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $explicitId = (string) data_get($metadata, 'programming_work_item_id', '');
        if ($explicitId !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('id', $explicitId)->first();
            if ($item) {
                return $item;
            }
        }

        $explicitCode = (string) data_get($metadata, 'programming_work_item_code', '');
        if ($explicitCode !== '') {
            $item = AtlasProgrammingWorkItem::query()->where('code', $explicitCode)->first();
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
     * @return array<string,mixed>
     */
    private function programmingWorkItemShape(AtlasProgrammingWorkItem $workItem): array
    {
        $scopeMode = ProgrammingScopeMode::tryFrom((string) $workItem->scope_mode) ?? ProgrammingScopeMode::Compact;

        return [
            'id' => (string) $workItem->id,
            'code' => (string) $workItem->code,
            'intent_text' => (string) $workItem->intent_text,
            'intent_type' => (string) $workItem->intent_type,
            'scope_mode' => $scopeMode->value,
            'risk_level' => (string) $workItem->risk_level,
            'status' => (string) $workItem->status,
            'current_stage' => (string) $workItem->current_stage,
            'spec_hash' => $workItem->spec_hash,
            'plan_hash' => $workItem->plan_hash,
            'required_gates' => $scopeMode->requiredGates(),
            'gaps' => array_values((array) $workItem->gaps_json),
            'created_at' => $workItem->created_at?->toJSON(),
            'updated_at' => $workItem->updated_at?->toJSON(),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function programmingGateRunsForWorkItem(AtlasProgrammingWorkItem $workItem): array
    {
        if (! Schema::hasTable('atlas_programming_gate_runs')) {
            return [];
        }

        return $workItem->gateRuns()
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($run): array => [
                'id' => (string) $run->id,
                'gate_name' => (string) $run->gate_name,
                'status' => (string) $run->status,
                'blocking' => (bool) $run->blocking,
                'reason' => $run->reason,
                'waiver_reason' => $run->waiver_reason ?? null,
                'payload' => $run->payload_json ?? null,
                'created_at' => $run->created_at?->toJSON(),
            ])
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function programmingReviewsForWorkItem(AtlasProgrammingWorkItem $workItem): array
    {
        if (! Schema::hasTable('atlas_programming_reviews')) {
            return [];
        }

        return $workItem->reviews()
            ->orderBy('created_at')
            ->get()
            ->map(static fn ($review): array => [
                'id' => (string) $review->id,
                'result' => (string) $review->result,
                'summary' => $review->summary,
                'risk_notes' => $review->risk_notes,
                'decided_by' => $review->decided_by,
                'created_at' => $review->created_at?->toJSON(),
            ])
            ->all();
    }

    private function nonEmptyArrayOrNull(mixed $value): ?array
    {
        if (! is_array($value) || $value === []) {
            return null;
        }

        return $value;
    }

    /**
     * @param  array<string,mixed>|null  $programmingGovernance
     * @param  array<string,mixed>|null  $forgeLiveExecution
     * @param  array<string,mixed>  $forgeLiveExecutionHistory
     * @return array<string,mixed>
     */
    private function forgeTaskQueueForWork(
        AtlasProject $project,
        ?array $programmingGovernance,
        ?array $forgeLiveExecution,
        array $forgeLiveExecutionHistory,
    ): array {
        $entries = [];
        $sourceAuthority = 'none';
        $workItem = is_array(data_get($programmingGovernance, 'work_item'))
            ? (array) data_get($programmingGovernance, 'work_item')
            : null;

        $governanceTasks = collect((array) data_get($programmingGovernance, 'tasks', []))
            ->filter(fn (mixed $task): bool => is_array($task))
            ->values();

        if ($governanceTasks->isNotEmpty() && $workItem !== null) {
            $sourceAuthority = 'programming_governance.tasks_json';
            $gateBlockers = $this->programmingGateBlockers($programmingGovernance);
            $entries = $governanceTasks
                ->map(fn (array $task, int $index): array => $this->forgeTaskQueueEntryFromGovernanceTask($task, $index, $workItem, $gateBlockers))
                ->all();
        } elseif (is_array(data_get($forgeLiveExecution, 'task_contract'))) {
            $sourceAuthority = 'latest_forge_live_execution.task_contract';
            $entries[] = $this->forgeTaskQueueEntryFromLiveExecution(
                (array) data_get($forgeLiveExecution, 'task_contract'),
                $forgeLiveExecution ?? [],
                (string) data_get($forgeLiveExecutionHistory, 'latest_entry_id', '')
            );
        }

        $total = count($entries);
        $active = collect($entries)->first(fn (array $entry): bool => ! in_array($entry['status'], ['verified'], true));

        return [
            'schema_version' => 'atlas.code.forge_task_queue.v1',
            'obra_id' => (string) $project->getKey(),
            'source_authority' => $sourceAuthority,
            'work_item_id' => $workItem['id'] ?? null,
            'work_item_code' => $workItem['code'] ?? null,
            'spec_hash' => $workItem['spec_hash'] ?? null,
            'plan_hash' => $workItem['plan_hash'] ?? null,
            'requires_spec' => $workItem !== null && ($workItem['spec_hash'] ?? null) === null,
            'requires_plan' => $workItem !== null && $governanceTasks->isEmpty(),
            'total' => $total,
            'ready_count' => collect($entries)->where('status', 'ready')->count(),
            'blocked_count' => collect($entries)->where('status', 'blocked')->count(),
            'verified_count' => collect($entries)->where('status', 'verified')->count(),
            'needs_review_count' => collect($entries)->where('status', 'needs_review')->count(),
            'pending_count' => collect($entries)->where('status', 'pending')->count(),
            'active_task_id' => is_array($active) ? (string) $active['task_id'] : null,
            'latest_history_id' => data_get($forgeLiveExecutionHistory, 'latest_entry_id'),
            'entries' => $entries,
        ];
    }

    /**
     * @param  array<string,mixed>|null  $programmingGovernance
     * @return array<int,string>
     */
    private function programmingGateBlockers(?array $programmingGovernance): array
    {
        return collect((array) data_get($programmingGovernance, 'gate_runs', []))
            ->filter(fn (mixed $gate): bool => is_array($gate)
                && (bool) ($gate['blocking'] ?? false)
                && in_array((string) ($gate['status'] ?? ''), ['failed', 'blocked'], true))
            ->map(fn (array $gate): string => (string) ($gate['reason'] ?? $gate['gate_name'] ?? 'programming_gate_blocked'))
            ->filter(fn (string $reason): bool => $reason !== '')
            ->values()
            ->all();
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $workItem
     * @param  array<int,string>  $gateBlockers
     * @return array<string,mixed>
     */
    private function forgeTaskQueueEntryFromGovernanceTask(array $task, int $index, array $workItem, array $gateBlockers): array
    {
        $status = $this->forgeTaskStatusFromGovernance((string) ($workItem['status'] ?? 'open'), $gateBlockers);
        $taskId = (string) ($task['task_id'] ?? $task['id'] ?? sprintf('%s-task-%02d', (string) ($workItem['code'] ?? 'work'), $index + 1));

        return [
            'schema_version' => 'atlas.code.forge_task_queue_entry.v1',
            'task_id' => $taskId,
            'sequence' => $index + 1,
            'title' => (string) ($task['title'] ?? $task['objective'] ?? $workItem['intent_text'] ?? 'Programming task'),
            'objective' => (string) ($task['objective'] ?? $workItem['intent_text'] ?? ''),
            'status' => $status,
            'owner' => $task['owner'] ?? data_get($workItem, 'owner'),
            'risk_level' => $task['risk_level'] ?? data_get($workItem, 'risk_level'),
            'allowed_files' => array_values((array) ($task['allowed_files'] ?? [])),
            'forbidden_files' => array_values((array) ($task['forbidden_files'] ?? [])),
            'expected_files' => array_values((array) ($task['expected_files'] ?? [])),
            'validation_commands' => array_values((array) ($task['validation_commands'] ?? [])),
            'acceptance_criteria' => array_values((array) ($task['acceptance_criteria'] ?? [])),
            'evidence_required' => array_values((array) ($task['evidence_required'] ?? [])),
            'docs_required' => array_values((array) ($task['docs_required'] ?? [])),
            'blockers' => $gateBlockers,
            'source' => 'programming_governance.tasks_json',
            'work_item_id' => $workItem['id'] ?? null,
            'work_item_code' => $workItem['code'] ?? null,
            'run_history_id' => null,
            'evidence_pack_hash' => null,
            'stage_timeline_hash' => null,
            'completion_claim_allowed' => $status === 'verified',
        ];
    }

    /**
     * @param  array<int,string>  $gateBlockers
     */
    private function forgeTaskStatusFromGovernance(string $workItemStatus, array $gateBlockers): string
    {
        if ($gateBlockers !== [] || $workItemStatus === 'blocked') {
            return 'blocked';
        }

        return match ($workItemStatus) {
            'closed' => 'verified',
            'review' => 'needs_review',
            'executing', 'verifying', 'open' => 'ready',
            default => 'pending',
        };
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function forgeTaskQueueEntryFromLiveExecution(array $task, array $snapshot, string $historyId): array
    {
        $blockers = array_values(array_unique(array_filter(array_merge(
            (array) data_get($snapshot, 'remaining_blockers', []),
            (array) data_get($snapshot, 'diff_scope.blocking_reasons', []),
            (array) data_get($snapshot, 'diff_scope.completion_gate.reasons', [])
        ), fn (mixed $value): bool => is_string($value) && $value !== '')));

        $status = $this->forgeTaskStatusFromLiveExecution($task, $snapshot, $blockers);

        return [
            'schema_version' => 'atlas.code.forge_task_queue_entry.v1',
            'task_id' => (string) ($task['task_id'] ?? 'latest-forge-live-task'),
            'sequence' => 1,
            'title' => (string) ($task['title'] ?? $task['objective'] ?? 'Forge Live task'),
            'objective' => (string) ($task['objective'] ?? ''),
            'status' => $status,
            'owner' => $task['owner'] ?? null,
            'risk_level' => $task['risk_level'] ?? null,
            'allowed_files' => array_values((array) ($task['allowed_files'] ?? [])),
            'forbidden_files' => array_values((array) ($task['forbidden_files'] ?? [])),
            'expected_files' => array_values((array) ($task['expected_files'] ?? [])),
            'validation_commands' => array_values((array) ($task['validation_commands'] ?? [])),
            'acceptance_criteria' => array_values((array) ($task['acceptance_criteria'] ?? [])),
            'evidence_required' => array_values((array) ($task['evidence_required'] ?? [])),
            'docs_required' => array_values((array) ($task['docs_required'] ?? [])),
            'blockers' => $blockers,
            'source' => 'latest_forge_live_execution.task_contract',
            'work_item_id' => null,
            'work_item_code' => null,
            'run_history_id' => $historyId !== '' ? $historyId : null,
            'evidence_pack_hash' => data_get($snapshot, 'evidence_pack.integrity.evidence_pack_hash'),
            'stage_timeline_hash' => data_get($snapshot, 'evidence_pack.integrity.stage_timeline_hash'),
            'completion_claim_allowed' => (bool) data_get($snapshot, 'diff_scope.completion_gate.completion_claim_allowed', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $task
     * @param  array<string,mixed>  $snapshot
     * @param  array<int,string>  $blockers
     */
    private function forgeTaskStatusFromLiveExecution(array $task, array $snapshot, array $blockers): string
    {
        if ($blockers !== []
            || (string) data_get($snapshot, 'diff_scope.status', '') === 'blocked'
            || (string) data_get($snapshot, 'status', '') === 'blocked'
        ) {
            return 'blocked';
        }

        if ((string) ($task['status'] ?? '') === 'verified'
            && (bool) data_get($snapshot, 'diff_scope.completion_gate.completion_claim_allowed', false)
        ) {
            return 'verified';
        }

        if ((string) ($task['status'] ?? '') === 'needs_review'
            || (string) data_get($snapshot, 'status', '') === 'degraded'
        ) {
            return 'needs_review';
        }

        return 'ready';
    }
}
