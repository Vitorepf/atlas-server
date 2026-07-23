<?php

namespace App\Services\Ai\AiWorkerSupport;

use App\Models\AiJob;
use App\Services\Ai\AiPromptBuilder;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService;
use App\Services\Ai\Router\AtlasSemanticFlowArbiterService;
use App\Services\Ai\Support\AppendOnlyJsonlStore;

/**
 * Post-claim ready-job prompt enrichment family (YouTube processing→ready
 * refresh, semantic flow arbiter rewrite) extracted VERBATIM from AiWorker
 * (GOD-DEBULK D3 split).
 *
 * Facade AiWorker keeps same-signature delegators; call-site/signature/ctor
 * scanner pins stay on the facade. No scanner pin token moved with this family.
 */
class ReadyPromptPrepSection
{
    public function __construct(
        private readonly YouTubeKnowledgeIngestionService $youtubeKnowledge,
        private readonly AiPromptBuilder $prompts,
    ) {}

    public function refreshReadyYouTubePrompt(AiJob $job): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $videos = data_get($payload, 'youtube_ingestion.videos', []);
        if (! is_array($videos) || $videos === []) {
            return $job;
        }

        $hasProcessingVideo = collect($videos)
            ->contains(fn (mixed $video): bool => is_array($video) && ($video['status'] ?? null) === 'processing');
        if (! $hasProcessingVideo) {
            return $job;
        }

        // Canonical capability · union URLs from input_text and
        // rich_input_payload.url_attachments[] so the refresh path matches
        // the gateway extraction path. Mobile/desktop that attach via
        // payload only otherwise miss the processing→ready refresh.
        $payloadUrls = data_get($payload, 'rich_input_payload.url_attachments');
        $urlsFromText = trim((string) $job->input_text) !== ''
            ? $this->youtubeKnowledge->extractUrls((string) $job->input_text)
            : [];
        $urlsFromPayload = is_array($payloadUrls)
            ? $this->youtubeKnowledge->extractUrlsFromRichInputPayload($payloadUrls)
            : [];
        $urls = collect([...$urlsFromText, ...$urlsFromPayload])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();

        if ($urls === []) {
            return $job;
        }

        try {
            $fresh = $this->youtubeKnowledge->ingestFromUrls($urls, [
                'defer_audio_fallback' => true,
            ]);
        } catch (\Throwable) {
            return $job;
        }

        $freshVideos = data_get($fresh, 'videos', []);
        if (! is_array($freshVideos) || $freshVideos === []) {
            return $job;
        }

        $hasReadyVideo = collect($freshVideos)
            ->contains(fn (mixed $video): bool => is_array($video) && ($video['status'] ?? null) === 'ready');
        if (! $hasReadyVideo) {
            return $job;
        }

        $payload['youtube_ingestion'] = $fresh;
        $prompt = $this->prompts->build((string) $job->input_text, [
            'provider' => $job->provider,
            'model' => $job->model,
            'source_type' => $job->trace?->source_type,
            'payload' => $payload,
        ]);
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $metadata['youtube_ingestion'] = $fresh;
        $metadata['task_request'] = $prompt->taskRequest;
        $metadata['context_pack'] = $prompt->contextPack;
        $metadata['open_brain_injection'] = $prompt->openBrainInjection;
        $metadata['execution_plan'] = $prompt->executionPlan;
        $metadata['skills_activated'] = $prompt->activatedSkills;

        $job->forceFill([
            'prompt' => $prompt->prompt,
            'context_refs' => $prompt->contextRefs,
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        if ($job->trace) {
            $traceMetadata = is_array($job->trace->metadata) ? $job->trace->metadata : [];
            $traceMetadata['youtube_ingestion'] = $fresh;
            $traceMetadata['task_request'] = $prompt->taskRequest;
            $traceMetadata['context_pack'] = $prompt->contextPack;
            $traceMetadata['open_brain_injection'] = $prompt->openBrainInjection;
            $traceMetadata['execution_plan'] = $prompt->executionPlan;
            $traceMetadata['skills_activated'] = $prompt->activatedSkills;
            $job->trace->forceFill([
                'prompt_hash' => hash('sha256', $prompt->prompt),
                'context_refs' => $prompt->contextRefs,
                'metadata' => $traceMetadata,
            ])->save();
        }

        return $job->refresh();
    }

    /**
     * Árbitro SEMÂNTICO no worker (decisão do operador 03/07): quando o
     * léxico caiu no fallback (S52), um modelo LOCAL lê a mensagem contra o
     * catálogo de flows e escolhe o destino — "analise esse ativo" vira
     * finanças, "essa página quebrou" vira debug — sem lista de frases.
     * Roda AQUI (job assíncrono; ~15-20s do hermes são invisíveis) e nunca
     * no router HTTP. Fail-open em qualquer falha: o job segue como estava
     * (gateway agêntico S52). Segue o padrão rebuild-prompt-pós-claim do
     * {@see refreshReadyYouTubePrompt}.
     */
    public function applySemanticFlowArbiter(AiJob $job): AiJob
    {
        $payload = is_array($job->payload) ? $job->payload : [];
        $reason = (string) data_get($payload, 'atlas_ai_router.routing_reason', '');
        if (! in_array($reason, ['agentic_gateway_default', 'fallback_conversation'], true)) {
            return $job;
        }
        $message = trim((string) $job->input_text);
        if (mb_strlen($message) < 13) {
            return $job;
        }

        try {
            $flowId = app(AtlasSemanticFlowArbiterService::class)->arbitrate($message);
        } catch (\Throwable) {
            return $job;
        }
        if ($flowId === null || $flowId === (string) data_get($payload, 'atlas_ai_router.flow_id')) {
            return $job;
        }

        // Reescreve a decisão de forma auditável e mapeia a execução como o
        // AiInteractionController mapeia flows de programação.
        $payload['atlas_ai_router']['flow_id'] = $flowId;
        $payload['atlas_ai_router']['routing_reason'] = 'semantic_arbiter:'.$reason;
        $payload['flow_id'] = $flowId;
        if (in_array($flowId, ['atlas_dev', 'atlas_debug', 'atlas_review', 'atlas_plan'], true)
            && (bool) data_get($payload, 'atlas_ai_router.handoff_payload.workspace_present', false)) {
            $payload['atlas_mode'] = 'programming';
            $payload['routing_task'] = match ($flowId) {
                'atlas_debug' => 'debug',
                'atlas_review' => 'review',
                'atlas_plan' => 'plan',
                default => 'dev',
            };
        }

        $prompt = $this->prompts->build((string) $job->input_text, [
            'provider' => $job->provider,
            'model' => $job->model,
            'source_type' => $job->trace?->source_type,
            'payload' => $payload,
        ]);
        $metadata = is_array($job->metadata) ? $job->metadata : [];
        $metadata['semantic_flow_arbiter'] = ['flow_id' => $flowId, 'superseded_reason' => $reason];

        // Cada arbitragem é um EXEMPLO ROTULADO grátis (frase real → flow
        // escolhido pelo modelo): gravar a resolução no ledger de misses
        // fecha o ciclo de aprendizado — frases recorrentes viram atalho
        // léxico por evidência e o custo do árbitro amortiza sozinho.
        try {
            AppendOnlyJsonlStore::append(
                storage_path('atlas/router/misroute_candidates.jsonl'),
                [
                    'schema_version' => 'atlas.router.misroute_candidate.v1',
                    'recorded_at' => now()->toIso8601String(),
                    'surface_id' => (string) data_get($payload, 'surface_id', ''),
                    'intent' => mb_substr($message, 0, 500),
                    'decision' => 'semantic_arbiter_resolved',
                    'resolved_flow' => $flowId,
                ],
            );
        } catch (\Throwable) {
            // fail-open
        }
        $job->forceFill([
            'prompt' => $prompt->prompt,
            'context_refs' => $prompt->contextRefs,
            'payload' => $payload,
            'metadata' => $metadata,
        ])->save();

        return $job->refresh();
    }
}
