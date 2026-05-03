<?php

namespace App\Http\Controllers;

use App\Http\Requests\FeedbackAiTraceRequest;
use App\Http\Requests\StoreAiInteractionRequest;
use App\Http\Resources\AiTraceResource;
use App\Jobs\ProcessAiAttachmentVisuals;
use App\Models\AiStreamEvent;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Services\Ai\AiGatewayService;
use App\Services\Ai\Attachments\AiChunkedUploadService;
use App\Services\Ai\Cli\AtlasFileAttachmentService;
use App\Services\Ai\Cli\AtlasImageAttachmentService;
use App\Services\Ai\Telemetry\AiOutcomeAttributionService;
use App\Services\Ai\Telemetry\AiTraceMetricAggregator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiInteractionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $traces = AiTrace::query()
            ->with($this->traceListRelations())
            ->when($request->query('thread_id'), fn ($query, $threadId) => $query->where('thread_id', $threadId))
            ->when($request->query('status'), fn ($query, $status) => $query->where('status', $status))
            ->when($request->query('agent'), fn ($query, $agent) => $query->where('agent_slug', $agent))
            ->orderByDesc('created_at')
            ->limit(min((int) $request->query('limit', 50), 200))
            ->get();

        return response()->json([
            'traces' => AiTraceResource::collection($traces)->resolve(),
        ]);
    }

    public function store(
        StoreAiInteractionRequest $request,
        AiGatewayService $gateway,
        AtlasImageAttachmentService $images,
        AtlasFileAttachmentService $files,
        AiChunkedUploadService $chunkedUploads,
    ): JsonResponse {
        $data = $request->validated();
        $uploadedImages = $this->uploadedImageFiles($request->file('images', []));
        $uploadedDocuments = $this->uploadedDocumentFiles($request->file('documents', []));
        $uploadedImageIds = array_values((array) ($data['uploaded_images'] ?? []));
        $uploadedDocumentIds = array_values((array) ($data['uploaded_documents'] ?? []));
        unset($data['images']);
        unset($data['documents']);
        unset($data['uploaded_images']);
        unset($data['uploaded_documents']);

        try {
            if ($uploadedImages !== []) {
                $attachments = $images->fromUploadedFiles($uploadedImages, $this->workspaceFromPayload($data), 'mobile_upload');
                $data['payload'] = $this->payloadWithImageAttachments($data['payload'] ?? [], $attachments);
            }

            if ($uploadedImageIds !== []) {
                $attachments = $this->attachmentsFromChunkedUploads($uploadedImageIds, 'image', $data, $chunkedUploads, $images, $files);
                $data['payload'] = $this->payloadWithImageAttachments($data['payload'] ?? [], $attachments);
            }

            if ($uploadedDocuments !== []) {
                $attachments = $files->fromUploadedFiles($uploadedDocuments, $this->workspaceFromPayload($data), 'mobile_upload');
                $data['payload'] = $this->payloadWithFileAttachments($data['payload'] ?? [], $attachments);
            }

            if ($uploadedDocumentIds !== []) {
                $attachments = $this->attachmentsFromChunkedUploads($uploadedDocumentIds, 'file', $data, $chunkedUploads, $images, $files);
                $data['payload'] = $this->payloadWithFileAttachments($data['payload'] ?? [], $attachments);
            }
        } catch (RuntimeException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
                'code' => 'invalid_attachment',
            ], 422);
        }

        $data = $this->applyThreadRuntimePolicy($data);

        $trace = $gateway->enqueueInteraction((string) $data['input_text'], $data);
        if ((bool) config('atlas.attachments.pdf.background_processing_enabled', true)
            && is_array(data_get($data, 'payload.attachments'))
        ) {
            ProcessAiAttachmentVisuals::dispatch($trace->id)->onQueue('attachments');
        }

        return response()->json([
            'trace' => (new AiTraceResource($trace))->resolve(),
        ], 202);
    }

    public function show(AiTrace $trace): JsonResponse
    {
        return response()->json([
            'trace' => (new AiTraceResource($trace->load($this->traceShowRelations())))->resolve(),
        ]);
    }

    public function attachmentContent(AiTrace $trace, string $attachment): BinaryFileResponse
    {
        $match = $this->findTraceAttachment($trace, $attachment);
        abort_if(! $match, 404, 'Attachment not found.');

        $path = $this->safeAttachmentPath((string) ($match['attachment']['path'] ?? ''));
        abort_if(! $path, 404, 'Attachment file not found.');

        $name = $this->attachmentDownloadName($match['attachment'], $match['kind']);
        $mime = is_string($match['attachment']['mime_type'] ?? null)
            ? $match['attachment']['mime_type']
            : (File::mimeType($path) ?: 'application/octet-stream');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => 'inline; filename="'.$name.'"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function attachmentPage(AiTrace $trace, string $attachment, int $page): BinaryFileResponse
    {
        abort_if($page < 1, 404, 'Attachment page not found.');

        $match = $this->findTraceAttachment($trace, $attachment);
        abort_if(! $match, 404, 'Attachment not found.');

        $renderedPages = is_array($match['attachment']['pdf_rendered_pages'] ?? null)
            ? $match['attachment']['pdf_rendered_pages']
            : (is_array($match['attachment']['office_rendered_pages'] ?? null) ? $match['attachment']['office_rendered_pages'] : []);
        $renderedPage = collect($renderedPages)->first(
            fn (mixed $item): bool => is_array($item) && (int) ($item['page'] ?? 0) === $page,
        );
        abort_if(! is_array($renderedPage), 404, 'Attachment page not rendered.');

        $path = $this->safeAttachmentPath((string) ($renderedPage['path'] ?? ''));
        abort_if(! $path, 404, 'Attachment page file not found.');

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Content-Disposition' => 'inline; filename="page-'.$page.'.png"',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function stream(Request $request, AiTrace $trace): StreamedResponse
    {
        $after = max(0, (int) $request->query('after', 0));
        $timeoutSeconds = min(max((int) $request->query('timeout', 120), 5), 600);

        return response()->stream(function () use ($trace, $after, $timeoutSeconds): void {
            if (! Schema::hasTable('ai_stream_events')) {
                $this->sendSse('error', [
                    'error' => 'stream_events_unavailable',
                    'message' => 'ai_stream_events table is not available.',
                ]);

                return;
            }

            $lastSequence = $after;
            $deadline = microtime(true) + $timeoutSeconds;
            $lastHeartbeat = microtime(true);

            // Antes era poll fixo de 200ms = ~3000 queries por stream de 10min.
            // Agora: 250ms quando há eventos chegando (responsivo durante
            // streaming) e 1s quando o job está silencioso (espera de
            // primeira resposta / fim). Reduz queries 5-10× sem custo
            // perceptível pelo usuário (humano não distingue 250ms de 200ms).
            $idleStreak = 0;
            $activePollUs = 250_000;
            $idlePollUs = 1_000_000;

            while (microtime(true) <= $deadline && ! connection_aborted()) {
                $events = AiStreamEvent::query()
                    ->where('trace_id', $trace->id)
                    ->where('sequence', '>', $lastSequence)
                    ->orderBy('sequence')
                    ->limit(100)
                    ->get();

                foreach ($events as $event) {
                    $lastSequence = max($lastSequence, (int) $event->sequence);
                    $this->sendSse($event->event_type, [
                        'id' => $event->id,
                        'trace_id' => $event->trace_id,
                        'job_id' => $event->ai_job_id,
                        'attempt_id' => $event->ai_job_attempt_id,
                        'sequence' => $event->sequence,
                        'type' => $event->event_type,
                        'channel' => $event->channel,
                        'content' => $event->content,
                        'metadata' => $event->metadata ?? [],
                        'occurred_at' => $event->occurred_at?->toJSON(),
                    ], (string) $event->sequence);
                }

                if ($events->isEmpty()) {
                    // Só checa status do trace quando não veio evento — se
                    // veio evento, o job está vivo, não precisa de fresh().
                    $freshTrace = $trace->fresh(['jobs']);
                    if ($freshTrace && in_array($freshTrace->status, ['succeeded', 'failed', 'cancelled'], true)) {
                        $this->sendSse('done', [
                            'trace_id' => $freshTrace->id,
                            'status' => $freshTrace->status,
                            'last_sequence' => $lastSequence,
                        ], (string) ($lastSequence + 1));

                        return;
                    }
                    $idleStreak++;
                } else {
                    $idleStreak = 0;
                }

                if (microtime(true) - $lastHeartbeat >= 10) {
                    $this->sendSse('heartbeat', [
                        'trace_id' => $trace->id,
                        'last_sequence' => $lastSequence,
                    ]);
                    $lastHeartbeat = microtime(true);
                }

                usleep($idleStreak >= 2 ? $idlePollUs : $activePollUs);
            }

            $this->sendSse('timeout', [
                'trace_id' => $trace->id,
                'last_sequence' => $lastSequence,
            ]);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'Connection' => 'keep-alive',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function feedback(
        FeedbackAiTraceRequest $request,
        AiTrace $trace,
        AiGatewayService $gateway,
        AiOutcomeAttributionService $outcomes,
        AiTraceMetricAggregator $aggregator,
    ): JsonResponse {
        $data = $request->validated();
        $updatedTrace = $gateway->recordFeedback($trace, $data);
        $this->recordFeedbackOutcome($updatedTrace, $data, $outcomes, $aggregator);

        return response()->json([
            'trace' => (new AiTraceResource($updatedTrace))->resolve(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function sendSse(string $event, array $payload, ?string $id = null): void
    {
        if ($id !== null) {
            echo "id: {$id}\n";
        }

        echo "event: {$event}\n";
        echo 'data: '.json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n\n";

        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }

    /**
     * @return array<int,UploadedFile>
     */
    private function uploadedImageFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return collect($files)
            ->flatten()
            ->filter(fn (mixed $file): bool => $file instanceof UploadedFile)
            ->values()
            ->all();
    }

    /**
     * @return array<int,UploadedFile>
     */
    private function uploadedDocumentFiles(mixed $files): array
    {
        return $this->uploadedImageFiles($files);
    }

    private function workspaceFromPayload(array $data): string
    {
        $workspace = data_get($data, 'payload.workspace');

        return is_scalar($workspace) && trim((string) $workspace) !== ''
            ? (string) $workspace
            : (string) config('atlas.ai.workdir', dirname(base_path()));
    }

    /**
     * Operational mobile Inbox threads may be opened from the full Atlas
     * surface. The app is the runtime control plane, so legacy Inbox metadata
     * must not downgrade a conversation into read-only/no-execution mode.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function applyThreadRuntimePolicy(array $data): array
    {
        $threadId = $data['thread_id'] ?? null;
        if (! is_string($threadId) || $threadId === '') {
            return $data;
        }

        $thread = AiThread::query()->find($threadId);
        if (! $thread || ! $this->isMobileOperationalThread($thread)) {
            return $data;
        }

        $metadata = $thread->metadata ?? [];
        $payload = is_array($data['payload'] ?? null) ? $data['payload'] : [];
        $toolPermissions = is_array($payload['tool_permissions'] ?? null) ? $payload['tool_permissions'] : [];

        $payload['thread_source'] = 'mobile_gateway_inbox';
        $payload['inbox_item_id'] = $this->metadataString($metadata, 'inbox_item_id')
            ?? ($thread->source_type === 'inbox_item' ? $thread->source_id : null);
        $payload['context_bundle_id'] = $this->metadataString($metadata, 'context_bundle_id');
        $requestedFocus = $this->metadataString($payload, 'atlas_focus')
            ?? $this->metadataString($payload, 'current_focus')
            ?? $this->metadataString($metadata, 'current_focus')
            ?? $this->metadataString($metadata, 'atlas_focus')
            ?? 'operational';
        $requestedMode = $this->metadataString($payload, 'atlas_mode')
            ?? $this->metadataString($payload, 'current_mode')
            ?? $this->metadataString($metadata, 'current_mode')
            ?? $this->metadataString($metadata, 'atlas_mode')
            ?? ($requestedFocus === 'programming' ? 'programming' : 'operational');

        $payload['atlas_focus'] = $requestedFocus;
        $payload['atlas_mode'] = $requestedMode;
        $payload['capability_profile'] = 'atlas_full_access';
        $payload['permission_policy'] = 'full_access';
        $payload['execution_policy'] = 'provider_execution_allowed';
        $payload['permission_mode'] = 'danger';
        $payload['tool_permissions'] = array_merge($toolPermissions, [
            'mode' => 'danger',
            'workspace' => $this->runtimeWorkspace($toolPermissions['workspace'] ?? data_get($payload, 'workspace')),
            'confirmed' => true,
            'allow_unsandboxed_provider' => true,
            'source' => 'thread_policy_full_access',
        ]);
        $payload['mobile_runtime_policy'] = [
            'allows_code_execution' => true,
            'reason' => 'Atlas app runtime settings allow provider execution and full-access tooling.',
        ];

        $data['payload'] = $payload;

        return $data;
    }

    private function runtimeWorkspace(mixed $workspace): string
    {
        if (is_scalar($workspace)) {
            $workspace = trim((string) $workspace);
            if ($workspace !== '' && $workspace !== '/' && is_dir($workspace)) {
                return realpath($workspace) ?: $workspace;
            }
        }

        $configured = (string) config('atlas.ai.workdir', dirname(base_path()));
        $resolved = realpath($configured);

        return $resolved && is_dir($resolved) ? $resolved : $configured;
    }

    private function isMobileOperationalThread(AiThread $thread): bool
    {
        $metadata = $thread->metadata ?? [];

        return $this->metadataString($metadata, 'capability_profile') === 'mobile_operational_read'
            || $this->metadataString($metadata, 'capability_profile') === 'atlas_full_access'
            || $this->metadataString($metadata, 'source_type') === 'ai_inbox_item'
            || $thread->source_type === 'inbox_item';
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function metadataString(array $metadata, string $key): ?string
    {
        $value = $metadata[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @return array<string,mixed>
     */
    private function payloadWithImageAttachments(mixed $payload, array $attachments): array
    {
        $payload = is_array($payload) ? $payload : [];
        $existing = (array) data_get($payload, 'attachments.images', []);
        $payload['attachments'] = [
            ...((array) ($payload['attachments'] ?? [])),
            'images' => [...$existing, ...$attachments],
        ];
        $payload['visual_input'] = [
            'image_count' => count($existing) + count($attachments),
            'sources' => collect([...$existing, ...$attachments])
                ->map(fn (array $attachment): string => (string) ($attachment['source'] ?? 'upload'))
                ->unique()
                ->values()
                ->all(),
        ];

        return $payload;
    }

    /**
     * @param  array<int,array<string,mixed>>  $attachments
     * @return array<string,mixed>
     */
    private function payloadWithFileAttachments(mixed $payload, array $attachments): array
    {
        $payload = is_array($payload) ? $payload : [];
        $existing = (array) data_get($payload, 'attachments.files', []);
        $payload['attachments'] = [
            ...((array) ($payload['attachments'] ?? [])),
            'files' => [...$existing, ...$attachments],
        ];
        $payload['file_input'] = [
            'file_count' => count($existing) + count($attachments),
            'names' => collect([...$existing, ...$attachments])
                ->map(fn (array $attachment): string => (string) ($attachment['original_name'] ?? 'arquivo'))
                ->values()
                ->all(),
        ];

        return $payload;
    }

    /**
     * @param  array<int,mixed>  $uploadIds
     * @return array<int,array<string,mixed>>
     */
    private function attachmentsFromChunkedUploads(
        array $uploadIds,
        string $kind,
        array $data,
        AiChunkedUploadService $chunkedUploads,
        AtlasImageAttachmentService $images,
        AtlasFileAttachmentService $files,
    ): array {
        $attachments = [];
        $workspace = $this->workspaceFromPayload($data);
        foreach ($uploadIds as $uploadId) {
            if (! is_string($uploadId) || trim($uploadId) === '') {
                continue;
            }

            $upload = $chunkedUploads->consume($uploadId);
            $path = (string) ($upload['assembled_path'] ?? '');
            $name = (string) ($upload['file_name'] ?? 'arquivo');
            $mime = (string) ($upload['mime_type'] ?? 'application/octet-stream');
            $source = (string) ($upload['source'] ?? 'chunked_upload');
            $attachments[] = $kind === 'image'
                ? $images->fromLocalUploadPath($path, $name, $mime, $workspace, $source)
                : $files->fromLocalUploadPath($path, $name, $mime, $workspace, $source);
        }

        return $kind === 'image' ? $images->dedupe($attachments) : $files->dedupe($attachments);
    }

    /**
     * Eager loading enxuto pra listagens (index, GET /ai/interactions).
     *
     * Antes carregava qualityActions.remediationTrace por default, gerando
     * 100-150 queries por request com 50 traces. Agora a tela do chat usa
     * apenas thread/session/job/jobs no caminho quente; qualityActions e
     * streamEvents ficam para a rota show() (1 trace de cada vez).
     *
     * As relações opcionais só entram quando a tabela existe, preservando
     * testes e installs parciais sem esconder ausência de schema em cache.
     *
     * @return array<int,string>
     */
    private function traceListRelations(): array
    {
        $relations = ['thread', 'session', 'job', 'jobs'];

        if ($this->routerDecisionsAvailable()) {
            $relations[] = 'routerDecision';
        }

        if ($this->atlasDecisionsAvailable()) {
            $relations[] = 'atlasDecision';
        }

        return $relations;
    }

    /**
     * Eager loading completo para show() — N+1 aceitável porque é 1 trace.
     *
     * @return array<int,string>
     */
    private function traceShowRelations(): array
    {
        $relations = ['thread', 'session', 'job.attemptHistory', 'jobs.attemptHistory'];

        if ($this->routerDecisionsAvailable()) {
            $relations[] = 'routerDecision';
        }

        if ($this->atlasDecisionsAvailable()) {
            $relations[] = 'atlasDecision';
        }

        if ($this->qualityEvaluationsAvailable()) {
            $relations[] = 'qualityEvaluation';
        }

        if ($this->qualityActionsAvailable()) {
            $relations[] = 'qualityActions.remediationTrace';
        }

        if ($this->streamEventsAvailable()) {
            $relations[] = 'streamEvents';
        }

        return $relations;
    }

    private function routerDecisionsAvailable(): bool
    {
        return Schema::hasTable('ai_router_decisions');
    }

    private function atlasDecisionsAvailable(): bool
    {
        return Schema::hasTable('ai_decisions');
    }

    private function qualityEvaluationsAvailable(): bool
    {
        return Schema::hasTable('ai_quality_evaluations');
    }

    private function qualityActionsAvailable(): bool
    {
        return Schema::hasTable('ai_quality_actions');
    }

    private function streamEventsAvailable(): bool
    {
        return Schema::hasTable('ai_stream_events');
    }

    /**
     * @return array{kind:string,attachment:array<string,mixed>}|null
     */
    private function findTraceAttachment(AiTrace $trace, string $attachmentId): ?array
    {
        $trace->loadMissing(['job', 'jobs']);
        $jobs = $trace->jobs?->all() ?? [];
        if ($trace->job) {
            array_unshift($jobs, $trace->job);
        }

        foreach ($jobs as $job) {
            $payload = is_array($job->payload ?? null) ? $job->payload : [];
            $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];

            foreach (['images' => 'image', 'files' => 'file'] as $key => $kind) {
                foreach ((array) ($attachments[$key] ?? []) as $index => $attachment) {
                    if (! is_array($attachment)) {
                        continue;
                    }

                    $id = is_string($attachment['sha256'] ?? null) && $attachment['sha256'] !== ''
                        ? $attachment['sha256']
                        : $kind.'-'.($index + 1);
                    if (hash_equals($id, $attachmentId)) {
                        return ['kind' => $kind, 'attachment' => $attachment];
                    }
                }
            }
        }

        return null;
    }

    private function safeAttachmentPath(string $path): ?string
    {
        if ($path === '') {
            return null;
        }

        $resolved = realpath($path);
        if (! $resolved || ! File::isFile($resolved)) {
            return null;
        }

        $root = realpath(storage_path('app/ai/attachments'));
        if (! $root) {
            return null;
        }

        return $resolved === $root || str_starts_with($resolved, rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR)
            ? $resolved
            : null;
    }

    /**
     * @param  array<string,mixed>  $attachment
     */
    private function attachmentDownloadName(array $attachment, string $kind): string
    {
        $name = $kind === 'file'
            ? (string) ($attachment['original_name'] ?? 'arquivo')
            : 'imagem';
        $name = trim($name) !== '' ? $name : 'arquivo';
        $name = preg_replace('/[^\pL\pN._ -]+/u', '_', $name) ?: 'arquivo';

        return mb_substr($name, 0, 160);
    }

    /**
     * @param  array<string,mixed>  $data
     */
    private function recordFeedbackOutcome(
        AiTrace $trace,
        array $data,
        AiOutcomeAttributionService $outcomes,
        AiTraceMetricAggregator $aggregator,
    ): void {
        $action = is_string($data['feedback_action'] ?? null) ? $data['feedback_action'] : null;
        $score = is_numeric($data['feedback_score'] ?? null) ? (int) $data['feedback_score'] : null;
        $outcomeType = $this->feedbackOutcomeType($action, $score);
        if (! $outcomeType) {
            return;
        }

        try {
            $outcomes->record([
                'trace_id' => $trace->id,
                'thread_id' => $trace->thread_id,
                'session_id' => $trace->session_id,
                'outcome_type' => $outcomeType,
                'target_type' => 'ai_trace',
                'target_id' => $trace->id,
                'value_score' => $score !== null ? $score * 20 : $this->feedbackOutcomeDefaultScore($outcomeType),
                'confidence' => 1,
                'source' => 'human_feedback',
                'metadata' => [
                    'feedback_action' => $action,
                    'feedback_comment_present' => isset($data['feedback_comment']) && trim((string) $data['feedback_comment']) !== '',
                ],
            ]);

            if (Schema::hasTable('ai_trace_metric_summaries')) {
                $aggregator->recomputeTrace($trace->id);
            }
        } catch (\Throwable $exception) {
            report($exception);
        }
    }

    private function feedbackOutcomeType(?string $action, ?int $score): ?string
    {
        return match ($action) {
            'useful' => 'human_marked_useful',
            'wrong_context' => 'human_marked_wrong_context',
            'too_slow' => 'human_marked_too_slow',
            'too_expensive' => 'human_marked_too_expensive',
            'unsafe' => 'human_marked_unsafe',
            'not_useful', 'wrong_agent' => 'human_marked_not_useful',
            'dismissed' => 'human_dismissed',
            default => $score !== null ? ($score >= 4 ? 'human_marked_useful' : 'human_marked_not_useful') : null,
        };
    }

    private function feedbackOutcomeDefaultScore(string $outcomeType): int
    {
        return match ($outcomeType) {
            'human_marked_useful' => 90,
            'human_marked_too_slow', 'human_marked_too_expensive', 'human_dismissed' => 50,
            default => 20,
        };
    }
}
