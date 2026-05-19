<?php

namespace App\Http\Controllers\Ai;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessYouTubeIngestionJob;
use App\Models\AiYoutubeIngestion;
use App\Services\Ai\YoutubeCanonicalProjection;
use App\Services\Ai\YouTubeKnowledgeIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

/**
 * YouTube prewarm — paste-time background ingestion.
 *
 * Operator collou um link YouTube no composer → cliente bate aqui e o
 * backend dispara `ProcessYouTubeIngestionJob` em background ANTES do
 * envio final da turn. Quando o operador clicar enviar, `optionsWithYouTubeKnowledge`
 * já encontra a transcrição em cache → resposta sai sem espera.
 *
 * Robustez canônica:
 *   - Idempotente por video_id (várias chamadas → 1 ingestão real)
 *   - Lock distribuído `atlas:youtube:prewarming:{video_id}` (TTL 5min)
 *   - Reuse cache + DB (se já `ingestion_status=ready` recente → no-op + cache_hit=true)
 *   - Batch (cliente pode enviar N URLs num único POST)
 *   - Audit timestamps + canonical projection no response
 *   - GET status endpoint para feedback visual opcional
 *
 * Doutrina: ver `docs/rich-input/youtube-canon.md`.
 */
class YoutubePrewarmController extends Controller
{
    private const LOCK_PREFIX = 'atlas:youtube:prewarming:';

    private const LOCK_TTL_SECONDS = 300; // 5min — Whisper longo cabe; lock auto-expira

    /**
     * Frescor máximo de uma ingestão `ready` para ser considerada cache hit.
     * Após esse intervalo um prewarm dispara nova ingestão (metadata pode ter
     * mudado, captions podem ter sido publicadas, etc).
     */
    private const READY_FRESH_TTL_HOURS = 168; // 7 dias

    public function __construct(
        private readonly YouTubeKnowledgeIngestionService $ingestion,
        private readonly YoutubeCanonicalProjection $projection,
    ) {}

    /**
     * POST /api/ai/youtube/prewarm
     *
     * Body: { "urls": ["https://youtu.be/..."] }  OR  { "url": "https://..." }
     *
     * Returns:
     * {
     *   "schema_version": 1,
     *   "items": [
     *     {
     *       "url": "<input>",
     *       "canonical_url": "https://www.youtube.com/watch?v=...",
     *       "video_id": "...",
     *       "ingestion_status": "ready|processing|queued|failed",
     *       "transcript_status": "...",
     *       "translation_status": "...",
     *       "source_language": "...|null",
     *       "translation_required": bool,
     *       "cache_hit": bool,         // veio do DB sem disparar job novo
     *       "dispatched": bool,         // job foi enfileirado nesta chamada
     *       "locked": bool,             // alguém já estava processando
     *       "last_ingested_at": "ISO-8601|null"
     *     }
     *   ]
     * }
     */
    public function prewarm(Request $request): JsonResponse
    {
        $request->validate([
            'url' => ['nullable', 'string', 'max:2048'],
            'urls' => ['nullable', 'array', 'max:16'],
            'urls.*' => ['string', 'max:2048'],
        ]);

        $rawUrls = $this->collectInputUrls($request);
        if ($rawUrls === []) {
            return response()->json([
                'schema_version' => 1,
                'items' => [],
            ]);
        }

        $items = [];
        $seenVideoIds = [];

        foreach ($rawUrls as $rawUrl) {
            $item = $this->prewarmOne($rawUrl, $seenVideoIds);
            if ($item === null) {
                continue;
            }
            $items[] = $item;
            if (isset($item['video_id']) && is_string($item['video_id'])) {
                $seenVideoIds[$item['video_id']] = true;
            }
        }

        return response()->json([
            'schema_version' => 1,
            'items' => $items,
        ]);
    }

    /**
     * GET /api/ai/youtube/ingestion/{videoId}
     *
     * Returns the canonical projection of the persisted ingestion record so
     * the composer can render the live 3-status while the operator is still
     * typing. Returns 404 when the video has never been ingested.
     */
    public function status(string $videoId): JsonResponse
    {
        if (preg_match('/^[A-Za-z0-9_-]{11}$/', $videoId) !== 1) {
            return response()->json(['error' => 'invalid_video_id'], 422);
        }

        if (! Schema::hasTable('ai_youtube_ingestions')) {
            return response()->json(['error' => 'ingestion_disabled'], 503);
        }

        $record = AiYoutubeIngestion::query()->where('video_id', $videoId)->first();
        if (! $record) {
            return response()->json(['error' => 'not_found'], 404);
        }

        $projected = $this->projection->projectFromRecord($record);
        $projected['last_ingested_at'] = $record->last_ingested_at?->toIso8601String();

        return response()->json([
            'schema_version' => 1,
            'item' => $projected,
        ]);
    }

    /**
     * @param  array<string,bool>  $seenVideoIds  in-request dedup map
     * @return array<string,mixed>|null
     */
    private function prewarmOne(string $rawUrl, array $seenVideoIds): ?array
    {
        $rawUrl = trim($rawUrl);
        if ($rawUrl === '') {
            return null;
        }

        // Canonicalize early (mirrors gateway extraction).
        $canonicalUrl = $this->canonicalUrl($rawUrl);
        $videoId = $this->videoIdFromUrl($canonicalUrl);
        if ($videoId === null) {
            return [
                'url' => $rawUrl,
                'canonical_url' => null,
                'video_id' => null,
                'ingestion_status' => 'failed',
                'transcript_status' => 'failed',
                'translation_status' => 'failed',
                'translation_required' => false,
                'cache_hit' => false,
                'dispatched' => false,
                'locked' => false,
                'reason' => 'not_a_youtube_url',
                'last_ingested_at' => null,
            ];
        }

        // In-request dedup — caller may pass 3 URL formats of the same vid.
        if (isset($seenVideoIds[$videoId])) {
            return null;
        }

        $cacheHit = $this->existingFreshIngestion($videoId);
        if ($cacheHit !== null) {
            return array_merge($cacheHit, [
                'url' => $rawUrl,
                'canonical_url' => $canonicalUrl,
                'cache_hit' => true,
                'dispatched' => false,
                'locked' => false,
            ]);
        }

        $lockKey = self::LOCK_PREFIX.$videoId;
        $acquired = Cache::add($lockKey, [
            'started_at' => now()->toIso8601String(),
            'origin' => 'prewarm',
        ], now()->addSeconds(self::LOCK_TTL_SECONDS));

        if (! $acquired) {
            // Someone else (gateway, worker, prior prewarm) is already on it.
            // Surface the current snapshot so the client can render progress.
            $snapshot = $this->snapshotFromDb($videoId);

            return array_merge($snapshot, [
                'url' => $rawUrl,
                'canonical_url' => $canonicalUrl,
                'cache_hit' => false,
                'dispatched' => false,
                'locked' => true,
            ]);
        }

        ProcessYouTubeIngestionJob::dispatch($canonicalUrl)
            ->onQueue((string) config('atlas.youtube.queue', 'transcription'));

        $snapshot = $this->snapshotFromDb($videoId);

        return array_merge($snapshot, [
            'url' => $rawUrl,
            'canonical_url' => $canonicalUrl,
            'video_id' => $videoId,
            'cache_hit' => false,
            'dispatched' => true,
            'locked' => false,
        ]);
    }

    /**
     * @return array<int,string>
     */
    private function collectInputUrls(Request $request): array
    {
        $singular = $request->input('url');
        $plural = $request->input('urls');
        $candidates = [];
        if (is_string($singular) && $singular !== '') {
            $candidates[] = $singular;
        }
        if (is_array($plural)) {
            foreach ($plural as $entry) {
                if (is_string($entry) && $entry !== '') {
                    $candidates[] = $entry;
                }
            }
        }

        // Dedup raw input by exact string (canonical dedup happens in prewarmOne).
        return array_values(array_unique($candidates));
    }

    /**
     * If an AiYoutubeIngestion exists with `ingestion_status='ready'` and is
     * fresh (within READY_FRESH_TTL_HOURS), return its canonical projection.
     * Otherwise return null (caller will dispatch a new job).
     *
     * @return array<string,mixed>|null
     */
    private function existingFreshIngestion(string $videoId): ?array
    {
        if (! Schema::hasTable('ai_youtube_ingestions')) {
            return null;
        }

        $record = AiYoutubeIngestion::query()->where('video_id', $videoId)->first();
        if (! $record) {
            return null;
        }

        $ingestionStatus = is_string($record->ingestion_status) ? $record->ingestion_status : null;
        if ($ingestionStatus !== 'ready') {
            return null;
        }

        if ($record->last_ingested_at instanceof Carbon
            && $record->last_ingested_at->lt(now()->subHours(self::READY_FRESH_TTL_HOURS))) {
            return null;
        }

        $projected = $this->projection->projectFromRecord($record);
        $projected['last_ingested_at'] = $record->last_ingested_at?->toIso8601String();

        return $projected;
    }

    /**
     * Best-effort projection from the current DB row (or a synthetic "queued"
     * shape when nothing exists yet). Used after dispatch so the client can
     * render a placeholder immediately.
     *
     * @return array<string,mixed>
     */
    private function snapshotFromDb(string $videoId): array
    {
        if (Schema::hasTable('ai_youtube_ingestions')) {
            $record = AiYoutubeIngestion::query()->where('video_id', $videoId)->first();
            if ($record) {
                $projected = $this->projection->projectFromRecord($record);
                $projected['last_ingested_at'] = $record->last_ingested_at?->toIso8601String();

                return $projected;
            }
        }

        return [
            'video_id' => $videoId,
            'ingestion_status' => 'queued',
            'transcript_status' => 'pending',
            'translation_status' => 'pending',
            'source_language' => null,
            'target_language' => YoutubeCanonicalProjection::DEFAULT_TARGET_LANGUAGE,
            'translation_required' => false,
            'last_ingested_at' => null,
        ];
    }

    private function canonicalUrl(string $url): string
    {
        $videoId = $this->videoIdFromUrl($url);
        if ($videoId === null) {
            return $url;
        }

        return 'https://www.youtube.com/watch?v='.$videoId;
    }

    private function videoIdFromUrl(string $url): ?string
    {
        $patterns = [
            '~(?:youtube\.com/watch\?(?:[^#\s]*&)?v=)([A-Za-z0-9_-]{11})~',
            '~(?:youtu\.be/)([A-Za-z0-9_-]{11})~',
            '~(?:youtube\.com/embed/)([A-Za-z0-9_-]{11})~',
            '~(?:youtube\.com/shorts/)([A-Za-z0-9_-]{11})~',
            '~(?:youtube\.com/live/)([A-Za-z0-9_-]{11})~',
            '~(?:m\.youtube\.com/watch\?(?:[^#\s]*&)?v=)([A-Za-z0-9_-]{11})~',
        ];
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $url, $match) === 1) {
                return $match[1];
            }
        }

        return null;
    }
}
