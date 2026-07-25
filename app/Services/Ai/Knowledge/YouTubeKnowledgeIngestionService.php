<?php

namespace App\Services\Ai\Knowledge;

use App\Jobs\ProcessYouTubeIngestionJob;
use App\Models\AiYoutubeIngestion;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\WhisperTranscriber;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Throwable;

class YouTubeKnowledgeIngestionService
{
    public function __construct(
        private readonly WhisperTranscriber $whisper,
        private readonly YoutubeCanonicalProjection $projection = new YoutubeCanonicalProjection,
    ) {}

    /**
     * Extract canonical YouTube URLs from a rich_input_payload `url_attachments[]`
     * structure. Caller is responsible for filtering payload shape.
     *
     * @param  array<int,mixed>|null  $urlAttachments
     * @return array<int,string>
     */
    public function extractUrlsFromRichInputPayload(?array $urlAttachments): array
    {
        if (! is_array($urlAttachments) || $urlAttachments === []) {
            return [];
        }

        return collect($urlAttachments)
            ->filter(fn (mixed $entry): bool => is_array($entry))
            ->filter(function (array $entry): bool {
                $kind = isset($entry['kind']) ? strtolower((string) $entry['kind']) : '';
                $url = isset($entry['url']) ? trim((string) $entry['url']) : '';

                return $kind === 'youtube' && $url !== '';
            })
            ->map(fn (array $entry): string => YouTubeUrlSupport::canonicalUrl((string) $entry['url']))
            ->filter(fn (string $url): bool => YouTubeUrlSupport::videoIdFromUrl($url) !== null)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Ingest from an explicit URL list (already extracted + canonicalized).
     * Honors the per-turn limit configured under `atlas.youtube.max_videos_per_turn`.
     *
     * Returns the same shape as `ingestFromInput` but skips re-parsing the
     * free text. Used by the gateway when YouTube URLs come from
     * `rich_input_payload.url_attachments[]` instead of (or alongside)
     * `input_text`.
     *
     * @param  array<int,string>  $urls
     * @return array<string,mixed>
     */
    public function ingestFromUrls(array $urls, array $options = []): array
    {
        $urls = collect($urls)
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->map(fn (string $url): string => YouTubeUrlSupport::canonicalUrl(trim($url)))
            ->filter(fn (string $url): bool => YouTubeUrlSupport::videoIdFromUrl($url) !== null)
            ->unique()
            ->values()
            ->all();

        if ($urls === []) {
            return [
                'schema_version' => 1,
                'status' => 'skipped',
                'videos' => [],
            ];
        }

        $limit = max(1, (int) config('atlas.youtube.max_videos_per_turn', 2));
        $videos = [];
        foreach (array_slice($urls, 0, $limit) as $url) {
            $videos[] = $this->ingestUrl($url, $options);
        }

        return [
            'schema_version' => 1,
            'status' => collect($videos)->contains(fn (array $video): bool => ($video['status'] ?? null) === 'ready')
                ? 'ready'
                : (collect($videos)->contains(fn (array $video): bool => ($video['status'] ?? null) === 'processing') ? 'processing' : 'unavailable'),
            'videos' => $videos,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function ingestFromInput(string $input, array $options = []): array
    {
        $urls = $this->extractUrls($input);
        if ($urls === []) {
            return [
                'schema_version' => 1,
                'status' => 'skipped',
                'videos' => [],
            ];
        }

        $limit = max(1, (int) config('atlas.youtube.max_videos_per_turn', 2));
        $videos = [];
        foreach (array_slice($urls, 0, $limit) as $url) {
            $videos[] = $this->ingestUrl($url, $options);
        }

        return [
            'schema_version' => 1,
            'status' => collect($videos)->contains(fn (array $video): bool => ($video['status'] ?? null) === 'ready')
                ? 'ready'
                : (collect($videos)->contains(fn (array $video): bool => ($video['status'] ?? null) === 'processing') ? 'processing' : 'unavailable'),
            'videos' => $videos,
        ];
    }

    /**
     * @return array<int,string>
     */
    public function extractUrls(string $input): array
    {
        preg_match_all('~https?://(?:www\.|m\.)?(?:youtube\.com/(?:watch\?[^\\s<>"\']*v=|shorts/|live/)|youtu\.be/)[^\\s<>"\']+~i', $input, $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $url): string => rtrim($url, ".,;:)]}\n\r\t "))
            ->map(fn (string $url): string => YouTubeUrlSupport::canonicalUrl($url))
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    public function ingestUrl(string $url, array $options = []): array
    {
        $startedAt = microtime(true);
        $deferAudioFallback = (bool) ($options['defer_audio_fallback'] ?? false);
        $base = [
            'url' => $url,
            'status' => 'unavailable',
            'ingestion_ms' => null,
            'metadata' => [],
            'caption' => null,
            'chunks' => [],
            'limits' => [
                'max_transcript_chars' => (int) config('atlas.youtube.max_transcript_chars', 120000),
                'chunk_seconds' => (int) config('atlas.youtube.chunk_seconds', 300),
                'max_chunks' => (int) config('atlas.youtube.max_chunks', 80),
            ],
        ];

        if (! (bool) config('atlas.youtube.enabled', true)) {
            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'disabled',
                'reason' => 'YouTube ingestion is disabled.',
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        $cached = $this->cachedVideoResult($url, $startedAt, $deferAudioFallback);
        if ($cached !== null) {
            return $cached;
        }

        try {
            $officialMetadata = $this->metadataViaDataApi($url);
            $captionMetadata = $this->metadataViaYtDlp($url) ?: $this->metadataViaWatchPage($url);
            $metadata = YouTubeMetadataSupport::mergeMetadata($officialMetadata, $captionMetadata);
        } catch (Throwable $e) {
            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        if ($metadata === []) {
            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'metadata_unavailable',
                'reason' => 'Atlas could not read YouTube metadata/captions for this URL.',
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        $caption = YouTubeCaptionSupport::selectCaptionTrack($metadata);
        if (! $caption) {
            if ($deferAudioFallback && $this->audioFallbackEnabled()) {
                return $this->queueAudioFallback($base, $url, $metadata, null, 'No manual or automatic captions were exposed for this video.', $startedAt);
            }

            $audioFallback = $this->transcribeAudioFallback($url, $metadata);
            if (($audioFallback['status'] ?? null) === 'ready') {
                return $this->readyResultFromAudioFallback($base, $metadata, $audioFallback, $startedAt);
            }

            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'caption_unavailable',
                'reason' => 'No manual or automatic captions were exposed for this video.',
                'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
                'audio_fallback' => $audioFallback,
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        try {
            $captionBody = $this->downloadCaption((string) $caption['url'], (string) ($caption['ext'] ?? ''));
            $segments = YouTubeCaptionSupport::parsePayload($captionBody, (string) ($caption['ext'] ?? ''));
            $chunks = YouTubeCaptionSupport::chunkSegments($segments);
        } catch (Throwable $e) {
            if ($deferAudioFallback && $this->audioFallbackEnabled()) {
                return $this->queueAudioFallback($base, $url, $metadata, $caption, Str::limit($e->getMessage(), 220, ''), $startedAt);
            }

            $audioFallback = $this->transcribeAudioFallback($url, $metadata);
            if (($audioFallback['status'] ?? null) === 'ready') {
                return $this->readyResultFromAudioFallback($base, $metadata, $audioFallback, $startedAt);
            }

            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'caption_failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
                'caption' => YouTubeMetadataSupport::publicCaption($caption),
                'audio_fallback' => $audioFallback,
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        if ($chunks === []) {
            if ($deferAudioFallback && $this->audioFallbackEnabled()) {
                return $this->queueAudioFallback($base, $url, $metadata, $caption, 'Caption track was available, but did not contain readable transcript text.', $startedAt);
            }

            $audioFallback = $this->transcribeAudioFallback($url, $metadata);
            if (($audioFallback['status'] ?? null) === 'ready') {
                return $this->readyResultFromAudioFallback($base, $metadata, $audioFallback, $startedAt);
            }

            return $this->finalizeVideoResult(array_merge($base, [
                'status' => 'transcript_empty',
                'reason' => 'Caption track was available, but did not contain readable transcript text.',
                'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
                'caption' => YouTubeMetadataSupport::publicCaption($caption),
                'audio_fallback' => $audioFallback,
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        return $this->cacheVideoResult(array_merge($base, [
            'status' => 'ready',
            'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
            'caption' => YouTubeMetadataSupport::publicCaption($caption),
            'chunks' => $chunks,
            'segment_count' => count($segments),
            'transcript_chars' => collect($chunks)->sum(fn (array $chunk): int => mb_strlen((string) ($chunk['text'] ?? ''))),
            'ingestion_ms' => $this->elapsedMs($startedAt),
        ]));
    }

    public function markBackgroundIngestionFailed(string $url, Throwable $exception): void
    {
        if (! DatabaseTableAvailability::has('ai_youtube_ingestions')) {
            return;
        }

        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return;
        }

        $stored = AiYoutubeIngestion::query()->where('video_id', $videoId)->first();
        if (! $stored || $stored->status === 'ready') {
            $this->clearProcessingLock(['url' => $url, 'status' => 'failed']);

            return;
        }

        $diagnostics = is_array($stored->diagnostics ?? null) ? $stored->diagnostics : [];
        $message = Str::limit($exception->getMessage(), 500, '');

        $stored->update([
            'status' => 'failed',
            'reason' => $message !== ''
                ? 'YouTube audio transcription failed in background: '.$message
                : 'YouTube audio transcription failed in background.',
            'audio_fallback_status' => 'failed',
            'diagnostics' => array_filter([
                ...$diagnostics,
                'processing' => null,
                'audio_fallback' => [
                    'status' => 'failed',
                    'reason' => $message,
                    'exception_class' => $exception::class,
                    'failed_at' => now()->toIso8601String(),
                ],
            ], fn (mixed $value): bool => $value !== null && $value !== []),
            'last_ingested_at' => now(),
            'ingestion_status' => 'failed',
            'transcript_status' => 'failed',
            'translation_status' => 'failed',
        ]);

        $this->clearProcessingLock(['url' => $stored->url, 'status' => 'failed']);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function parseCaptionPayload(string $body, string $ext): array
    {
        return YouTubeCaptionSupport::parsePayload($body, $ext);
    }


    /**
     * @param  array<int,array<string,mixed>>  $segments
     * @return array<int,array<string,mixed>>
     */
    public function chunkSegments(array $segments): array
    {
        return YouTubeCaptionSupport::chunkSegments($segments);
    }


    /**
     * @return array<string,mixed>|null
     */
    private function cachedVideoResult(string $url, float $startedAt, bool $allowProcessing = false): ?array
    {
        if (! (bool) config('atlas.youtube.cache_enabled', true)) {
            return $this->storedVideoResult($url, $startedAt, $allowProcessing);
        }

        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return $this->storedVideoResult($url, $startedAt, $allowProcessing);
        }

        $cached = Cache::get($this->videoCacheKey($videoId));
        if (! is_array($cached) || ($cached['status'] ?? null) !== 'ready') {
            return $this->storedVideoResult($url, $startedAt, $allowProcessing);
        }

        $cached['cache_hit'] = true;
        $cached['ingestion_ms'] = $this->elapsedMs($startedAt);

        $this->persistVideoResult($cached);

        return $cached;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function storedVideoResult(string $url, float $startedAt, bool $allowProcessing): ?array
    {
        if (! DatabaseTableAvailability::has('ai_youtube_ingestions')) {
            return null;
        }

        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return null;
        }

        $stored = AiYoutubeIngestion::query()->where('video_id', $videoId)->first();
        if (! $stored) {
            return null;
        }

        if ($stored->status === 'ready') {
            $diagnostics = is_array($stored->diagnostics ?? null) ? $stored->diagnostics : [];
            $audioFallback = is_array($diagnostics['audio_fallback'] ?? null) ? $diagnostics['audio_fallback'] : null;
            $result = [
                'url' => $stored->url,
                'status' => 'ready',
                'ingestion_ms' => $this->elapsedMs($startedAt),
                'metadata' => $stored->metadata ?? [],
                'caption' => $stored->caption,
                'chunks' => $stored->chunks ?? [],
                'transcript_chars' => $stored->transcript_chars,
                'audio_fallback' => $audioFallback,
                'diagnostics' => $diagnostics,
                'cache_hit' => true,
                'stored_hit' => true,
                'limits' => [
                    'max_transcript_chars' => (int) config('atlas.youtube.max_transcript_chars', 120000),
                    'chunk_seconds' => (int) config('atlas.youtube.chunk_seconds', 300),
                    'max_chunks' => (int) config('atlas.youtube.max_chunks', 80),
                ],
            ];

            if ((bool) config('atlas.youtube.cache_enabled', true)) {
                Cache::put($this->videoCacheKey($videoId), $result, now()->addDays(max(1, (int) config('atlas.youtube.cache_ttl_days', 30))));
            }

            return $result;
        }

        if ($allowProcessing && $stored->status === 'processing') {
            if ($this->storedProcessingIsStale($stored)) {
                $stored->update([
                    'status' => 'processing_stale',
                    'reason' => 'YouTube audio transcription exceeded the processing window and will be retried.',
                    'audio_fallback_status' => 'stale',
                    'last_ingested_at' => now(),
                ]);
                $this->clearProcessingLock(['url' => $stored->url, 'status' => 'processing_stale']);

                return null;
            }

            $metadata = $stored->metadata ?? [];
            $diagnostics = is_array($stored->diagnostics ?? null) ? $stored->diagnostics : [];
            $processing = YouTubeMetadataSupport::processingDiagnostics(
                $metadata,
                $stored->last_ingested_at?->getTimestamp() ?? $stored->updated_at?->getTimestamp(),
            );

            return [
                'url' => $stored->url,
                'status' => 'processing',
                'reason' => $stored->reason ?: 'YouTube audio transcription is still processing.',
                'ingestion_ms' => $this->elapsedMs($startedAt),
                'metadata' => $metadata,
                'caption' => $stored->caption,
                'chunks' => [],
                'audio_fallback' => [
                    'status' => 'queued',
                    'reason' => 'Whisper audio fallback is running in background.',
                ],
                'processing' => $processing,
                'diagnostics' => array_merge($diagnostics, ['processing' => $processing]),
                'limits' => [
                    'max_transcript_chars' => (int) config('atlas.youtube.max_transcript_chars', 120000),
                    'chunk_seconds' => (int) config('atlas.youtube.chunk_seconds', 300),
                    'max_chunks' => (int) config('atlas.youtube.max_chunks', 80),
                ],
            ];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function cacheVideoResult(array $result): array
    {
        if (($result['status'] ?? null) !== 'ready') {
            return $this->finalizeVideoResult($result);
        }

        if (! (bool) config('atlas.youtube.cache_enabled', true)) {
            return $this->finalizeVideoResult($result);
        }

        $videoId = YouTubeUrlSupport::videoIdFromUrl((string) ($result['url'] ?? ''));
        if ($videoId === null) {
            return $this->finalizeVideoResult($result);
        }

        $result['cache_hit'] = false;
        $result['cached_at'] = now()->toIso8601String();
        Cache::put(
            $this->videoCacheKey($videoId),
            $result,
            now()->addDays(max(1, (int) config('atlas.youtube.cache_ttl_days', 30))),
        );

        return $this->finalizeVideoResult($result);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $audioFallback
     * @return array<string,mixed>
     */
    private function readyResultFromAudioFallback(array $base, array $metadata, array $audioFallback, float $startedAt): array
    {
        return $this->cacheVideoResult(array_merge($base, [
            'status' => 'ready',
            'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
            'caption' => [
                'language' => $audioFallback['language'] ?? config('atlas.transcription.language', 'pt'),
                'name' => 'Whisper audio fallback',
                'kind' => 'whisper_audio',
                'ext' => 'txt',
                'timestamp_source' => 'estimated',
            ],
            'chunks' => $audioFallback['chunks'],
            'segment_count' => $audioFallback['segment_count'] ?? null,
            'transcript_chars' => $audioFallback['transcript_chars'] ?? null,
            'audio_fallback' => $audioFallback,
            'ingestion_ms' => $this->elapsedMs($startedAt),
        ]));
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>|null  $caption
     * @return array<string,mixed>
     */
    private function queueAudioFallback(array $base, string $url, array $metadata, ?array $caption, string $reason, float $startedAt): array
    {
        $result = array_merge($base, [
            'status' => 'processing',
            'reason' => $reason,
            'metadata' => YouTubeMetadataSupport::publicMetadata($metadata),
            'caption' => $caption ? YouTubeMetadataSupport::publicCaption($caption) : null,
            'audio_fallback' => [
                'status' => 'queued',
                'reason' => 'Whisper audio fallback is running in background.',
            ],
            'processing' => YouTubeMetadataSupport::processingDiagnostics(YouTubeMetadataSupport::publicMetadata($metadata)),
            'ingestion_ms' => $this->elapsedMs($startedAt),
        ]);

        $this->persistVideoResult($result);

        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        $queueKey = $videoId ? 'atlas:youtube:processing:'.$videoId : 'atlas:youtube:processing:'.sha1($url);
        $lockMinutes = max(5, (int) config('atlas.youtube.processing_lock_minutes', 90));
        if (Cache::add($queueKey, true, now()->addMinutes($lockMinutes))) {
            ProcessYouTubeIngestionJob::dispatch($url)
                ->onQueue((string) config('atlas.youtube.queue', 'transcription'));
        }

        return $result;
    }

    /**
     * @param  array<string,mixed>  $result
     * @return array<string,mixed>
     */
    private function finalizeVideoResult(array $result): array
    {
        $projected = $this->projection->project($result);
        $this->persistVideoResult($projected);
        $this->clearProcessingLock($projected);

        return $projected;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function persistVideoResult(array $result): void
    {
        if (! DatabaseTableAvailability::has('ai_youtube_ingestions')) {
            return;
        }

        $url = (string) ($result['url'] ?? '');
        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return;
        }

        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $caption = is_array($result['caption'] ?? null) ? $result['caption'] : null;
        $chunks = is_array($result['chunks'] ?? null) ? $result['chunks'] : [];
        $audioFallback = is_array($result['audio_fallback'] ?? null) ? $result['audio_fallback'] : [];
        $processing = is_array($result['processing'] ?? null) ? $result['processing'] : null;
        $diagnostics = is_array($result['diagnostics'] ?? null) ? $result['diagnostics'] : [];
        $diagnostics = array_filter([
            ...$diagnostics,
            'schema_version' => 1,
            'segment_count' => $result['segment_count'] ?? null,
            'limits' => $result['limits'] ?? null,
            'cached_at' => $result['cached_at'] ?? null,
            'processing' => $processing,
            'audio_fallback' => $audioFallback ?: null,
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        // Canonical 3-status fields — may have been projected upstream by
        // `finalizeVideoResult`; if missing (e.g. external caller), project here.
        $hasCanonical = isset($result['ingestion_status'])
            && isset($result['transcript_status'])
            && isset($result['translation_status']);
        $canonical = $hasCanonical ? $result : $this->projection->project($result);

        AiYoutubeIngestion::query()->updateOrCreate(
            ['video_id' => $videoId],
            [
                'url' => $url,
                'title' => is_string($metadata['title'] ?? null) ? $metadata['title'] : null,
                'channel' => is_string($metadata['channel'] ?? null) ? $metadata['channel'] : null,
                'status' => (string) ($result['status'] ?? 'unknown'),
                'reason' => is_string($result['reason'] ?? null) ? $result['reason'] : null,
                'metadata_source' => is_string($metadata['metadata_source'] ?? null) ? $metadata['metadata_source'] : null,
                'caption_kind' => is_array($caption) && is_string($caption['kind'] ?? null) ? $caption['kind'] : null,
                'caption_language' => is_array($caption) && is_string($caption['language'] ?? null) ? $caption['language'] : null,
                'audio_fallback_status' => is_string($audioFallback['status'] ?? null) ? $audioFallback['status'] : null,
                'chunk_count' => count($chunks),
                'transcript_chars' => (int) ($result['transcript_chars'] ?? collect($chunks)->sum(fn (array $chunk): int => mb_strlen((string) ($chunk['text'] ?? '')))),
                'ingestion_ms' => isset($result['ingestion_ms']) ? (int) $result['ingestion_ms'] : null,
                'cache_hit' => (bool) ($result['cache_hit'] ?? false),
                'metadata' => $metadata,
                'caption' => $caption,
                'chunks' => $chunks,
                'diagnostics' => $diagnostics,
                'last_ingested_at' => now(),
                'ingestion_status' => is_string($canonical['ingestion_status'] ?? null) ? $canonical['ingestion_status'] : null,
                'transcript_status' => is_string($canonical['transcript_status'] ?? null) ? $canonical['transcript_status'] : null,
                'translation_status' => is_string($canonical['translation_status'] ?? null) ? $canonical['translation_status'] : null,
                'source_language' => is_string($canonical['source_language'] ?? null) ? $canonical['source_language'] : null,
                'target_language' => is_string($canonical['target_language'] ?? null) ? $canonical['target_language'] : YoutubeCanonicalProjection::DEFAULT_TARGET_LANGUAGE,
                'translation_required' => isset($canonical['translation_required']) ? (bool) $canonical['translation_required'] : null,
            ],
        );
    }

    private function videoCacheKey(string $videoId): string
    {
        return 'atlas:youtube:video:v3:'.$videoId;
    }

    /**
     * @param  array<string,mixed>  $result
     */
    private function clearProcessingLock(array $result): void
    {
        if (($result['status'] ?? null) === 'processing') {
            return;
        }

        $url = (string) ($result['url'] ?? '');
        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return;
        }

        Cache::forget('atlas:youtube:processing:'.$videoId);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function storedProcessingIsStale(AiYoutubeIngestion $stored): bool
    {
        $startedAt = $stored->last_ingested_at ?? $stored->updated_at;
        if (! $startedAt) {
            return false;
        }

        return $startedAt->getTimestamp() < (time() - $this->processingStaleAfterSeconds());
    }

    private function processingStaleAfterSeconds(): int
    {
        $lockSeconds = max(5, (int) config('atlas.youtube.processing_lock_minutes', 90)) * 60;
        $jobSeconds = 5400;

        return max($lockSeconds, $jobSeconds) + 300;
    }

    /**
     * @return array<string,mixed>
     */
    private function metadataViaDataApi(string $url): array
    {
        if (! (bool) config('atlas.youtube.data_api_enabled', false)) {
            return [];
        }

        $apiKey = trim((string) config('atlas.youtube.data_api_key', ''));
        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($apiKey === '' || $videoId === null || ! $this->consumeDataApiQuota(1)) {
            return [];
        }

        $response = Http::timeout(max(5, (int) config('atlas.youtube.timeout_seconds', 35)))
            ->get('https://www.googleapis.com/youtube/v3/videos', [
                'part' => 'snippet,contentDetails,statistics,topicDetails',
                'id' => $videoId,
                'key' => $apiKey,
                'maxResults' => 1,
            ]);

        if (! $response->ok()) {
            return [
                'id' => $videoId,
                'webpage_url' => 'https://www.youtube.com/watch?v='.$videoId,
                'metadata_source' => 'youtube_data_api_failed',
                'data_api_status' => 'http_'.$response->status(),
            ];
        }

        $item = data_get($response->json(), 'items.0');
        if (! is_array($item)) {
            return [
                'id' => $videoId,
                'webpage_url' => 'https://www.youtube.com/watch?v='.$videoId,
                'metadata_source' => 'youtube_data_api_empty',
                'data_api_status' => 'empty',
            ];
        }

        $snippet = is_array($item['snippet'] ?? null) ? $item['snippet'] : [];
        $content = is_array($item['contentDetails'] ?? null) ? $item['contentDetails'] : [];
        $statistics = is_array($item['statistics'] ?? null) ? $item['statistics'] : [];
        $description = is_string($snippet['description'] ?? null) ? (string) $snippet['description'] : '';

        return [
            'id' => $videoId,
            'title' => $snippet['title'] ?? null,
            'channel' => $snippet['channelTitle'] ?? null,
            'channel_id' => $snippet['channelId'] ?? null,
            'description' => $description,
            'published_at' => $snippet['publishedAt'] ?? null,
            'duration' => YouTubeUrlSupport::secondsFromIso8601Duration((string) ($content['duration'] ?? '')),
            'webpage_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'language' => $snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? null,
            'category_id' => $snippet['categoryId'] ?? null,
            'view_count' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            'like_count' => isset($statistics['likeCount']) ? (int) $statistics['likeCount'] : null,
            'comment_count' => isset($statistics['commentCount']) ? (int) $statistics['commentCount'] : null,
            'chapters' => YouTubeMetadataSupport::chaptersFromDescription($description),
            'metadata_source' => 'youtube_data_api',
            'data_api_status' => 'ready',
            'data_api_quota_units' => 1,
        ];
    }

    /**
     * @param  array<string,mixed>  $official
     * @param  array<string,mixed>  $captionSource
     * @return array<string,mixed>
     */
    private function consumeDataApiQuota(int $units): bool
    {
        $limit = max(0, (int) config('atlas.youtube.data_api_daily_unit_limit', 500));
        if ($limit === 0) {
            return false;
        }

        $key = 'atlas:youtube:data-api-units:'.now()->toDateString();
        $used = (int) Cache::get($key, 0);
        if ($used + $units > $limit) {
            return false;
        }

        Cache::put($key, $used + $units, now()->endOfDay()->addMinute());

        return true;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    /**
     * @return array<string,mixed>
     */
    private function metadataViaYtDlp(string $url): array
    {
        $command = $this->ytDlpCommand();
        if ($command === []) {
            return [];
        }

        $timeout = max(5, (int) config('atlas.youtube.timeout_seconds', 35));
        try {
            $process = new Process([
                ...$command,
                '--dump-json',
                '--skip-download',
                '--no-warnings',
                '--no-playlist',
                $url,
            ]);
            $process->setTimeout($timeout);
            $process->run();
        } catch (Throwable) {
            return [];
        }

        if (! $process->isSuccessful()) {
            return [];
        }

        $metadata = json_decode($process->getOutput(), true);

        return is_array($metadata) ? $metadata : [];
    }

    /**
     * @return array<string,mixed>
     */
    private function metadataViaWatchPage(string $url): array
    {
        $videoId = YouTubeUrlSupport::videoIdFromUrl($url);
        if ($videoId === null) {
            return [];
        }

        $timeout = max(5, (int) config('atlas.youtube.timeout_seconds', 35));
        $response = Http::timeout($timeout)
            ->withHeaders([
                'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
                'User-Agent' => 'Mozilla/5.0 AtlasAI/1.0',
            ])
            ->get('https://www.youtube.com/watch', [
                'v' => $videoId,
                'hl' => 'pt-BR',
                'persist_hl' => 1,
            ]);

        if (! $response->ok()) {
            return [];
        }

        $player = YouTubeMetadataSupport::extractInitialPlayerResponse($response->body());
        if ($player === []) {
            return [];
        }

        $captionTracks = data_get($player, 'captions.playerCaptionsTracklistRenderer.captionTracks', []);
        $subtitles = [];
        $automaticCaptions = [];
        foreach (is_array($captionTracks) ? $captionTracks : [] as $track) {
            if (! is_array($track) || ! is_string($track['baseUrl'] ?? null)) {
                continue;
            }

            $language = (string) ($track['languageCode'] ?? 'und');
            $entry = [
                'url' => $track['baseUrl'],
                'ext' => 'json3',
                'language' => $language,
                'name' => data_get($track, 'name.simpleText') ?: data_get($track, 'name.runs.0.text') ?: $language,
                'kind' => (string) ($track['kind'] ?? 'manual'),
            ];

            if (($track['kind'] ?? null) === 'asr') {
                $automaticCaptions[$language][] = $entry;
            } else {
                $subtitles[$language][] = $entry;
            }
        }

        $details = is_array($player['videoDetails'] ?? null) ? $player['videoDetails'] : [];

        return [
            'id' => $details['videoId'] ?? $videoId,
            'title' => $details['title'] ?? null,
            'channel' => $details['author'] ?? null,
            'duration' => isset($details['lengthSeconds']) ? (int) $details['lengthSeconds'] : null,
            'webpage_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'subtitles' => $subtitles,
            'automatic_captions' => $automaticCaptions,
            'metadata_source' => 'watch_page',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function downloadCaption(string $url, string $ext): string
    {
        if ($url === '') {
            throw new \RuntimeException('Caption URL is empty.');
        }

        $attempts = max(1, (int) config('atlas.youtube.caption_download_retries', 2) + 1);
        $sleepMs = max(100, (int) config('atlas.youtube.caption_download_retry_sleep_ms', 700));
        $lastStatus = null;

        foreach (YouTubeCaptionSupport::captionDownloadUrls($url, $ext) as $captionUrl) {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                $response = Http::timeout(max(5, (int) config('atlas.youtube.timeout_seconds', 35)))
                    ->withHeaders([
                        'Accept' => '*/*',
                        'Accept-Language' => 'pt-BR,pt;q=0.9,en;q=0.8',
                        'Referer' => 'https://www.youtube.com/',
                        'User-Agent' => 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
                    ])
                    ->get($captionUrl);

                if ($response->ok()) {
                    return $response->body();
                }

                $lastStatus = $response->status();
                if (! in_array($lastStatus, [408, 425, 429, 500, 502, 503, 504], true)) {
                    break;
                }

                if ($attempt < $attempts) {
                    usleep(min(3_000_000, $sleepMs * 1000 * $attempt));
                }
            }
        }

        throw new \RuntimeException('Caption download failed with HTTP '.($lastStatus ?? 'unknown').'.');
    }

    private function transcribeAudioFallback(string $url, array $metadata): array
    {
        if (! $this->audioFallbackEnabled()) {
            return [
                'status' => 'disabled',
                'reason' => 'YouTube audio fallback is disabled.',
            ];
        }

        $duration = isset($metadata['duration']) ? (int) $metadata['duration'] : null;
        $maxDuration = max(60, (int) config('atlas.youtube.max_audio_duration_seconds', 7200));
        if ($duration !== null && $duration > $maxDuration) {
            return [
                'status' => 'skipped_duration',
                'reason' => "Video duration exceeds audio fallback limit ({$maxDuration}s).",
                'duration_seconds' => $duration,
            ];
        }

        $command = $this->ytDlpCommand();
        if ($command === []) {
            return [
                'status' => 'runtime_missing',
                'reason' => 'yt-dlp is not available for audio fallback.',
            ];
        }

        $workDir = storage_path('framework/youtube/'.uniqid('atlas_youtube_', true));
        if (! is_dir($workDir)) {
            mkdir($workDir, 0775, true);
        }

        try {
            $outputTemplate = $workDir.'/audio.%(ext)s';
            $download = $this->downloadYoutubeAudio($command, $url, $outputTemplate);
            if (($download['status'] ?? null) !== 'ready') {
                return $download;
            }

            $audioPath = collect(glob($workDir.'/audio.*') ?: [])
                ->first(fn (string $path): bool => is_file($path) && filesize($path) > 0);
            if (! is_string($audioPath)) {
                return [
                    'status' => 'download_empty',
                    'reason' => 'YouTube audio download did not produce a readable file.',
                ];
            }

            $language = $this->whisperLanguageForVideo($metadata);
            $text = trim($this->whisper->transcribe($audioPath, $language));
            if ($text === '') {
                return [
                    'status' => 'whisper_empty',
                    'reason' => 'Whisper returned an empty transcript.',
                    'language' => $language,
                ];
            }

            $segments = YouTubeCaptionSupport::segmentsFromPlainTranscript($text, $duration);
            $chunks = YouTubeCaptionSupport::chunkSegments($segments);
            if ($chunks === []) {
                return [
                    'status' => 'chunk_empty',
                    'reason' => 'Whisper transcript could not be chunked.',
                ];
            }

            return [
                'status' => 'ready',
                'engine' => config('atlas.transcription.engine', 'whisper'),
                'language' => $language,
                'duration_seconds' => $duration,
                'timestamp_source' => 'estimated',
                'segment_count' => count($segments),
                'transcript_chars' => mb_strlen($text),
                'download_attempts' => $download['attempts'] ?? 1,
                'chunks' => $chunks,
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();

            return [
                'status' => str_contains(Str::lower($message), 'timed out') ? 'timeout' : 'failed',
                'reason' => Str::limit($message, 220, ''),
            ];
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    /**
     * @param  array<int,string>  $command
     * @return array<string,mixed>
     */
    private function downloadYoutubeAudio(array $command, string $url, string $outputTemplate): array
    {
        $attempts = max(1, (int) config('atlas.youtube.audio_download_retries', 2) + 1);
        $lastReason = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $process = new Process([
                ...$command,
                '-f',
                'ba/bestaudio/best[acodec!=none]/best',
                '--no-playlist',
                '--no-warnings',
                '--retries',
                '3',
                '--fragment-retries',
                '3',
                '-o',
                $outputTemplate,
                $url,
            ]);
            $process->setTimeout(max(30, (int) config('atlas.youtube.audio_download_timeout_seconds', 300)));
            $process->run();

            if ($process->isSuccessful()) {
                return [
                    'status' => 'ready',
                    'attempts' => $attempt,
                ];
            }

            $lastReason = $this->processError($process, 'YouTube audio download failed.');
            if ($attempt < $attempts && YouTubeMetadataSupport::isRetryableDownloadError($lastReason)) {
                usleep(min(3_000_000, 400_000 * $attempt));

                continue;
            }

            break;
        }

        return [
            'status' => 'download_failed',
            'reason' => $lastReason ?: 'YouTube audio download failed.',
            'attempts' => $attempts,
            'retryable' => $lastReason ? YouTubeMetadataSupport::isRetryableDownloadError($lastReason) : null,
        ];
    }

    private function audioFallbackEnabled(): bool
    {
        return YouTubeMetadataSupport::audioFallbackEnabled();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function whisperLanguageForVideo(array $metadata): ?string
    {
        return YouTubeMetadataSupport::whisperLanguageForVideo($metadata);
    }

    /**
     * @return array<int,string>
     */
    private function ytDlpCommand(): array
    {
        $configured = (string) config('atlas.youtube.yt_dlp_binary', '');
        if ($configured !== '' && is_file($configured) && is_executable($configured)) {
            return [$configured];
        }

        $binary = (new ExecutableFinder)->find('yt-dlp');
        if ($binary) {
            return [$binary];
        }

        $python = (new ExecutableFinder)->find('python3');
        if (! $python) {
            return [];
        }

        try {
            $process = new Process([$python, '-m', 'yt_dlp', '--version']);
            $process->setTimeout(5);
            $process->run();

            return $process->isSuccessful() ? [$python, '-m', 'yt_dlp'] : [];
        } catch (Throwable) {
            return [];
        }
    }

    private function processError(Process $process, string $fallback): string
    {
        return YouTubeMetadataSupport::processError($process, $fallback);
    }

    /**
     * @param  array<string,mixed>  $json
     * @return array<int,array<string,mixed>>
     */
    /**
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,mixed>  $caption
     * @return array<string,mixed>
     */
    private function elapsedMs(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }

    private function removeDirectory(string $workDir): void
    {
        if (! is_dir($workDir)) {
            return;
        }

        foreach (glob($workDir.'/*') ?: [] as $path) {
            try {
                is_file($path) && @unlink($path);
            } catch (Throwable) {
                //
            }
        }

        @rmdir($workDir);
    }
}
