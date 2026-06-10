<?php

namespace App\Services\Ai;

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
            ->map(fn (array $entry): string => $this->canonicalUrl((string) $entry['url']))
            ->filter(fn (string $url): bool => $this->videoIdFromUrl($url) !== null)
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
            ->map(fn (string $url): string => $this->canonicalUrl(trim($url)))
            ->filter(fn (string $url): bool => $this->videoIdFromUrl($url) !== null)
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
            ->map(fn (string $url): string => $this->canonicalUrl($url))
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
            $metadata = $this->mergeMetadata($officialMetadata, $captionMetadata);
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

        $caption = $this->selectCaptionTrack($metadata);
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
                'metadata' => $this->publicMetadata($metadata),
                'audio_fallback' => $audioFallback,
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        try {
            $captionBody = $this->downloadCaption((string) $caption['url'], (string) ($caption['ext'] ?? ''));
            $segments = $this->parseCaptionPayload($captionBody, (string) ($caption['ext'] ?? ''));
            $chunks = $this->chunkSegments($segments);
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
                'metadata' => $this->publicMetadata($metadata),
                'caption' => $this->publicCaption($caption),
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
                'metadata' => $this->publicMetadata($metadata),
                'caption' => $this->publicCaption($caption),
                'audio_fallback' => $audioFallback,
                'ingestion_ms' => $this->elapsedMs($startedAt),
            ]));
        }

        return $this->cacheVideoResult(array_merge($base, [
            'status' => 'ready',
            'metadata' => $this->publicMetadata($metadata),
            'caption' => $this->publicCaption($caption),
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

        $videoId = $this->videoIdFromUrl($url);
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
        $trimmed = trim($body);
        if ($trimmed === '') {
            return [];
        }

        if (str_starts_with($trimmed, 'WEBVTT')) {
            return $this->parseVttSegments($trimmed);
        }

        if ($ext === 'json3' || str_starts_with($trimmed, '{')) {
            $json = json_decode($trimmed, true);
            if (! is_array($json)) {
                return str_starts_with($trimmed, '<') ? $this->parseXmlSegments($trimmed) : [];
            }

            return $this->parseJson3Segments($json);
        }

        if (str_starts_with($trimmed, '<')) {
            return $this->parseXmlSegments($trimmed);
        }

        return $this->parseVttSegments($trimmed);
    }

    /**
     * @param  array<int,array<string,mixed>>  $segments
     * @return array<int,array<string,mixed>>
     */
    public function chunkSegments(array $segments): array
    {
        $chunkSeconds = max(60, (int) config('atlas.youtube.chunk_seconds', 300));
        $maxChunks = max(1, (int) config('atlas.youtube.max_chunks', 80));
        $maxChars = max(4000, (int) config('atlas.youtube.max_transcript_chars', 120000));
        $maxChunkChars = max(1200, (int) config('atlas.youtube.max_chunk_chars', 5000));
        $chunks = [];
        $current = null;
        $totalChars = 0;

        foreach ($segments as $segment) {
            $text = trim((string) ($segment['text'] ?? ''));
            if ($text === '') {
                continue;
            }

            $start = (float) ($segment['start'] ?? 0);
            $end = (float) ($segment['end'] ?? $start);
            $shouldStart = ! is_array($current)
                || $start >= ((float) $current['start'] + $chunkSeconds)
                || mb_strlen((string) $current['text']) + mb_strlen($text) + 1 > $maxChunkChars;

            if ($shouldStart) {
                if (is_array($current)) {
                    $chunks[] = $this->finalizeChunk($current, count($chunks) + 1);
                    if (count($chunks) >= $maxChunks || $totalChars >= $maxChars) {
                        break;
                    }
                }

                $current = [
                    'start' => $start,
                    'end' => $end,
                    'text' => $text,
                ];
            } else {
                $current['end'] = max((float) $current['end'], $end);
                $current['text'] = trim((string) $current['text'].' '.$text);
            }

            $totalChars += mb_strlen($text);
            if ($totalChars >= $maxChars) {
                break;
            }
        }

        if (is_array($current) && count($chunks) < $maxChunks) {
            $chunks[] = $this->finalizeChunk($current, count($chunks) + 1);
        }

        return $chunks;
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
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = trim((string) ($parts['path'] ?? ''), '/');
        parse_str((string) ($parts['query'] ?? ''), $query);

        if (str_contains($host, 'youtu.be') && $path !== '') {
            return strtok($path, '/') ?: null;
        }

        if (isset($query['v']) && is_string($query['v']) && $query['v'] !== '') {
            return $query['v'];
        }

        foreach (['shorts/', 'live/'] as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $id = substr($path, strlen($prefix));

                return strtok($id, '/') ?: null;
            }
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function cachedVideoResult(string $url, float $startedAt, bool $allowProcessing = false): ?array
    {
        if (! (bool) config('atlas.youtube.cache_enabled', true)) {
            return $this->storedVideoResult($url, $startedAt, $allowProcessing);
        }

        $videoId = $this->videoIdFromUrl($url);
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

        $videoId = $this->videoIdFromUrl($url);
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
            $processing = $this->processingDiagnostics(
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

        $videoId = $this->videoIdFromUrl((string) ($result['url'] ?? ''));
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
            'metadata' => $this->publicMetadata($metadata),
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
            'metadata' => $this->publicMetadata($metadata),
            'caption' => $caption ? $this->publicCaption($caption) : null,
            'audio_fallback' => [
                'status' => 'queued',
                'reason' => 'Whisper audio fallback is running in background.',
            ],
            'processing' => $this->processingDiagnostics($this->publicMetadata($metadata)),
            'ingestion_ms' => $this->elapsedMs($startedAt),
        ]);

        $this->persistVideoResult($result);

        $videoId = $this->videoIdFromUrl($url);
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
        $videoId = $this->videoIdFromUrl($url);
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
        $videoId = $this->videoIdFromUrl($url);
        if ($videoId === null) {
            return;
        }

        Cache::forget('atlas:youtube:processing:'.$videoId);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function processingDiagnostics(array $metadata, ?int $startedAt = null): array
    {
        $duration = isset($metadata['duration_seconds'])
            ? (int) $metadata['duration_seconds']
            : (isset($metadata['duration']) ? (int) $metadata['duration'] : null);
        $eta = $this->estimatedAudioFallbackSeconds($duration);
        $elapsed = $startedAt ? max(0, time() - $startedAt) : 0;
        $progress = $eta > 0
            ? min(0.88, round($elapsed / $eta, 2))
            : null;

        return [
            'stage' => 'audio_transcription',
            'status' => 'processing',
            'progress' => $progress,
            'elapsed_seconds' => $elapsed,
            'estimated_total_seconds' => $eta,
            'estimated_remaining_seconds' => $eta > 0 ? max(30, $eta - $elapsed) : null,
            'message' => 'Transcrevendo o audio do YouTube em background.',
            'retry_after_seconds' => 45,
        ];
    }

    private function estimatedAudioFallbackSeconds(?int $durationSeconds): ?int
    {
        if ($durationSeconds === null || $durationSeconds <= 0) {
            return null;
        }

        $ratio = (float) config('atlas.youtube.audio_transcription_realtime_ratio', 0.65);
        $ratio = min(1.5, max(0.1, $ratio));

        return max(60, (int) ceil($durationSeconds * $ratio) + 45);
    }

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
        $videoId = $this->videoIdFromUrl($url);
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
            'duration' => $this->secondsFromIso8601Duration((string) ($content['duration'] ?? '')),
            'webpage_url' => 'https://www.youtube.com/watch?v='.$videoId,
            'language' => $snippet['defaultAudioLanguage'] ?? $snippet['defaultLanguage'] ?? null,
            'category_id' => $snippet['categoryId'] ?? null,
            'view_count' => isset($statistics['viewCount']) ? (int) $statistics['viewCount'] : null,
            'like_count' => isset($statistics['likeCount']) ? (int) $statistics['likeCount'] : null,
            'comment_count' => isset($statistics['commentCount']) ? (int) $statistics['commentCount'] : null,
            'chapters' => $this->chaptersFromDescription($description),
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
    private function mergeMetadata(array $official, array $captionSource): array
    {
        if ($official === []) {
            return $captionSource;
        }

        if ($captionSource === []) {
            return $official;
        }

        $merged = array_merge($captionSource, array_filter($official, fn (mixed $value): bool => $value !== null && $value !== '' && $value !== []));
        $merged['subtitles'] = is_array($captionSource['subtitles'] ?? null) ? $captionSource['subtitles'] : (is_array($official['subtitles'] ?? null) ? $official['subtitles'] : []);
        $merged['automatic_captions'] = is_array($captionSource['automatic_captions'] ?? null) ? $captionSource['automatic_captions'] : (is_array($official['automatic_captions'] ?? null) ? $official['automatic_captions'] : []);
        $merged['metadata_source'] = ($official['metadata_source'] ?? null) === 'youtube_data_api'
            ? 'youtube_data_api+captions'
            : ($merged['metadata_source'] ?? 'unknown');

        return $merged;
    }

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
    private function chaptersFromDescription(string $description): array
    {
        if (trim($description) === '') {
            return [];
        }

        preg_match_all('/(?:^|\n)\s*((?:\d{1,2}:)?\d{1,2}:\d{2})\s+(.+?)(?=\n|$)/u', $description, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(fn (array $match): array => [
                'start' => $this->secondsFromTimestamp((string) $match[1]),
                'start_label' => (string) $match[1],
                'title' => trim((string) $match[2]),
            ])
            ->filter(fn (array $chapter): bool => $chapter['title'] !== '')
            ->take(80)
            ->values()
            ->all();
    }

    private function secondsFromIso8601Duration(string $duration): ?int
    {
        if ($duration === '') {
            return null;
        }

        try {
            $interval = new \DateInterval($duration);
        } catch (Throwable) {
            return null;
        }

        return ($interval->d * 86400)
            + ($interval->h * 3600)
            + ($interval->i * 60)
            + $interval->s;
    }

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
        $videoId = $this->videoIdFromUrl($url);
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

        $player = $this->extractInitialPlayerResponse($response->body());
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
    private function extractInitialPlayerResponse(string $html): array
    {
        $needle = 'ytInitialPlayerResponse';
        $offset = strpos($html, $needle);
        if ($offset === false) {
            return [];
        }

        $start = strpos($html, '{', $offset);
        if ($start === false) {
            return [];
        }

        $depth = 0;
        $inString = false;
        $escape = false;
        $length = strlen($html);
        for ($i = $start; $i < $length; $i++) {
            $char = $html[$i];
            if ($inString) {
                if ($escape) {
                    $escape = false;
                } elseif ($char === '\\') {
                    $escape = true;
                } elseif ($char === '"') {
                    $inString = false;
                }

                continue;
            }

            if ($char === '"') {
                $inString = true;
            } elseif ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    $json = substr($html, $start, $i - $start + 1);
                    $decoded = json_decode($json, true);

                    return is_array($decoded) ? $decoded : [];
                }
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    private function selectCaptionTrack(array $metadata): ?array
    {
        $preferred = array_values(array_filter(array_map(
            'trim',
            explode(',', (string) config('atlas.youtube.preferred_caption_languages', 'pt-BR,pt,en,ja,zh-Hans,zh-Hant,zh')),
        )));
        $groups = [
            'manual' => is_array($metadata['subtitles'] ?? null) ? $metadata['subtitles'] : [],
            'automatic' => is_array($metadata['automatic_captions'] ?? null) ? $metadata['automatic_captions'] : [],
        ];

        foreach ($groups as $kind => $tracksByLanguage) {
            foreach ($preferred as $language) {
                $track = $this->firstCaptionForLanguage($tracksByLanguage, $language);
                if ($track) {
                    return array_merge($track, [
                        'track_kind' => $kind,
                        'language' => $track['language'] ?? $language,
                    ]);
                }
            }
        }

        foreach ($groups as $kind => $tracksByLanguage) {
            foreach ($tracksByLanguage as $language => $tracks) {
                if (is_array($tracks) && isset($tracks[0]) && is_array($tracks[0])) {
                    return array_merge($tracks[0], [
                        'track_kind' => $kind,
                        'language' => is_string($language) ? $language : (string) ($tracks[0]['language'] ?? 'und'),
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $tracksByLanguage
     * @return array<string,mixed>|null
     */
    private function firstCaptionForLanguage(array $tracksByLanguage, string $language): ?array
    {
        foreach ($tracksByLanguage as $key => $tracks) {
            if (! is_string($key) || ! is_array($tracks)) {
                continue;
            }

            $normalizedKey = Str::of($key)->lower()->replace('_', '-')->value();
            $normalizedLanguage = Str::of($language)->lower()->replace('_', '-')->value();
            if ($normalizedKey !== $normalizedLanguage && ! str_starts_with($normalizedKey, $normalizedLanguage.'-')) {
                continue;
            }

            foreach ($tracks as $track) {
                if (is_array($track) && is_string($track['url'] ?? null)) {
                    $track['language'] ??= $key;

                    return $track;
                }
            }
        }

        return null;
    }

    private function downloadCaption(string $url, string $ext): string
    {
        if ($url === '') {
            throw new \RuntimeException('Caption URL is empty.');
        }

        $attempts = max(1, (int) config('atlas.youtube.caption_download_retries', 2) + 1);
        $sleepMs = max(100, (int) config('atlas.youtube.caption_download_retry_sleep_ms', 700));
        $lastStatus = null;

        foreach ($this->captionDownloadUrls($url, $ext) as $captionUrl) {
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

    /**
     * @return array<int,string>
     */
    private function captionDownloadUrls(string $url, string $ext): array
    {
        $formats = match ($ext) {
            'vtt' => ['vtt', 'json3'],
            'srv1', 'srv2', 'srv3', 'xml' => [$ext, 'json3', 'vtt'],
            default => ['json3', 'vtt'],
        };

        return collect($formats)
            ->map(fn (string $format): string => $this->captionUrlWithFormat($url, $format))
            ->prepend($url)
            ->unique()
            ->values()
            ->all();
    }

    private function captionUrlWithFormat(string $url, string $format): string
    {
        if (str_contains($url, 'fmt=')) {
            return (string) preg_replace('/([?&])fmt=[^&]*/', '$1fmt='.$format, $url);
        }

        return $url.(str_contains($url, '?') ? '&' : '?').'fmt='.$format;
    }

    /**
     * @return array<string,mixed>
     */
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

            $segments = $this->segmentsFromPlainTranscript($text, $duration);
            $chunks = $this->chunkSegments($segments);
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
            if ($attempt < $attempts && $this->isRetryableDownloadError($lastReason)) {
                usleep(min(3_000_000, 400_000 * $attempt));

                continue;
            }

            break;
        }

        return [
            'status' => 'download_failed',
            'reason' => $lastReason ?: 'YouTube audio download failed.',
            'attempts' => $attempts,
            'retryable' => $lastReason ? $this->isRetryableDownloadError($lastReason) : null,
        ];
    }

    private function isRetryableDownloadError(string $reason): bool
    {
        $text = Str::of($reason)->lower()->value();

        return str_contains($text, 'http error 429')
            || str_contains($text, 'http error 403')
            || str_contains($text, 'too many requests')
            || str_contains($text, 'temporarily unavailable')
            || str_contains($text, 'timed out')
            || str_contains($text, 'timeout');
    }

    private function audioFallbackEnabled(): bool
    {
        if ((bool) config('atlas.youtube.audio_fallback_enabled', false)) {
            return true;
        }

        return (bool) config('atlas.transcription.enabled', false);
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    private function whisperLanguageForVideo(array $metadata): ?string
    {
        $language = (string) ($metadata['language'] ?? config('atlas.transcription.language', 'pt'));
        $language = trim(Str::of($language)->lower()->replace('_', '-')->value());
        if ($language === '' || $language === 'und') {
            return null;
        }

        return match (true) {
            str_starts_with($language, 'pt') => 'pt',
            str_starts_with($language, 'en') => 'en',
            str_starts_with($language, 'ja') => 'ja',
            str_starts_with($language, 'zh') => 'zh',
            str_starts_with($language, 'es') => 'es',
            str_starts_with($language, 'fr') => 'fr',
            str_starts_with($language, 'de') => 'de',
            str_starts_with($language, 'it') => 'it',
            str_starts_with($language, 'ko') => 'ko',
            default => substr($language, 0, 2),
        };
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
        $error = trim($process->getErrorOutput());
        $output = trim($process->getOutput());

        return Str::limit($error !== '' ? $error : ($output !== '' ? $output : $fallback), 220, '');
    }

    /**
     * @param  array<string,mixed>  $json
     * @return array<int,array<string,mixed>>
     */
    private function parseJson3Segments(array $json): array
    {
        $segments = [];
        foreach ((array) ($json['events'] ?? []) as $event) {
            if (! is_array($event) || ! is_array($event['segs'] ?? null)) {
                continue;
            }

            $text = collect($event['segs'])
                ->map(fn (mixed $seg): string => is_array($seg) ? (string) ($seg['utf8'] ?? '') : '')
                ->implode('');
            $text = $this->normalizeTranscriptText($text);
            if ($text === '') {
                continue;
            }

            $start = ((float) ($event['tStartMs'] ?? 0)) / 1000;
            $duration = ((float) ($event['dDurationMs'] ?? 0)) / 1000;
            $segments[] = [
                'start' => $start,
                'end' => $start + max(0.1, $duration),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseVttSegments(string $body): array
    {
        $segments = [];
        $blocks = preg_split("/\R{2,}/", trim($body)) ?: [];
        foreach ($blocks as $block) {
            $lines = array_values(array_filter(array_map('trim', preg_split('/\R/', $block) ?: []), fn (string $line): bool => $line !== ''));
            if ($lines === [] || str_starts_with($lines[0], 'WEBVTT')) {
                continue;
            }

            $timingIndex = null;
            foreach ($lines as $index => $line) {
                if (str_contains($line, '-->')) {
                    $timingIndex = $index;
                    break;
                }
            }
            if ($timingIndex === null) {
                continue;
            }

            [$startRaw, $endRaw] = array_map('trim', explode('-->', $lines[$timingIndex], 2));
            $endRaw = trim((string) preg_replace('/\s+.+$/', '', $endRaw));
            $text = $this->normalizeTranscriptText(implode(' ', array_slice($lines, $timingIndex + 1)));
            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => $this->secondsFromTimestamp($startRaw),
                'end' => $this->secondsFromTimestamp($endRaw),
                'text' => $text,
            ];
        }

        return $segments;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function parseXmlSegments(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (! $xml) {
            return [];
        }

        $segments = [];
        foreach ($xml->xpath('//text') ?: [] as $node) {
            $attributes = $node->attributes();
            $start = (float) ($attributes['start'] ?? 0);
            $duration = (float) ($attributes['dur'] ?? 0);
            $text = $this->normalizeTranscriptText((string) $node);
            if ($text === '') {
                continue;
            }

            $segments[] = [
                'start' => $start,
                'end' => $start + max(0.1, $duration),
                'text' => $text,
            ];
        }

        foreach ($xml->xpath('//p') ?: [] as $node) {
            $attributes = $node->attributes();
            $text = $this->normalizeTranscriptText((string) $node);
            if ($text === '') {
                continue;
            }

            $start = isset($attributes['t'])
                ? ((float) $attributes['t']) / 1000
                : (isset($attributes['start'])
                    ? (float) $attributes['start']
                    : (isset($attributes['begin']) ? $this->secondsFromTimestamp((string) $attributes['begin']) : 0));
            $end = isset($attributes['d'])
                ? $start + (((float) $attributes['d']) / 1000)
                : (isset($attributes['dur'])
                    ? $start + (float) $attributes['dur']
                    : (isset($attributes['end']) ? $this->secondsFromTimestamp((string) $attributes['end']) : $start + 0.1));

            $segments[] = [
                'start' => $start,
                'end' => max($start + 0.1, $end),
                'text' => $text,
            ];
        }

        return collect($segments)
            ->sortBy(fn (array $segment): float => (float) ($segment['start'] ?? 0))
            ->values()
            ->all();
    }

    private function normalizeTranscriptText(string $text): string
    {
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?: '';

        return trim($text);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function segmentsFromPlainTranscript(string $text, ?int $durationSeconds): array
    {
        $parts = preg_split('/(?<=[.!?。！？])\s+/u', trim($text)) ?: [];
        $parts = array_values(array_filter(array_map('trim', $parts), fn (string $part): bool => $part !== ''));
        if ($parts === []) {
            return [];
        }

        $totalChars = max(1, collect($parts)->sum(fn (string $part): int => mb_strlen($part)));
        $duration = max(60, $durationSeconds ?: (int) ceil(count($parts) * 8));
        $segments = [];
        $cursor = 0.0;
        $buffer = '';

        foreach ($parts as $part) {
            $candidate = trim($buffer === '' ? $part : $buffer.' '.$part);
            if (mb_strlen($candidate) < 700) {
                $buffer = $candidate;

                continue;
            }

            $chars = mb_strlen($candidate);
            $segmentDuration = max(4.0, ($chars / $totalChars) * $duration);
            $segments[] = [
                'start' => $cursor,
                'end' => min($duration, $cursor + $segmentDuration),
                'text' => $candidate,
            ];
            $cursor += $segmentDuration;
            $buffer = '';
        }

        if ($buffer !== '') {
            $chars = mb_strlen($buffer);
            $segmentDuration = max(4.0, ($chars / $totalChars) * $duration);
            $segments[] = [
                'start' => $cursor,
                'end' => min($duration, $cursor + $segmentDuration),
                'text' => $buffer,
            ];
        }

        return $segments;
    }

    private function secondsFromTimestamp(string $timestamp): float
    {
        $parts = array_map('floatval', explode(':', str_replace(',', '.', $timestamp)));
        if (count($parts) === 3) {
            return ($parts[0] * 3600) + ($parts[1] * 60) + $parts[2];
        }
        if (count($parts) === 2) {
            return ($parts[0] * 60) + $parts[1];
        }

        return (float) ($parts[0] ?? 0);
    }

    /**
     * @param  array<string,mixed>  $chunk
     * @return array<string,mixed>
     */
    private function finalizeChunk(array $chunk, int $index): array
    {
        return [
            'index' => $index,
            'start' => round((float) ($chunk['start'] ?? 0), 2),
            'end' => round((float) ($chunk['end'] ?? 0), 2),
            'start_label' => $this->timeLabel((float) ($chunk['start'] ?? 0)),
            'end_label' => $this->timeLabel((float) ($chunk['end'] ?? 0)),
            'text' => trim((string) ($chunk['text'] ?? '')),
        ];
    }

    private function timeLabel(float $seconds): string
    {
        $seconds = max(0, (int) round($seconds));
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;

        return $hours > 0
            ? sprintf('%d:%02d:%02d', $hours, $minutes, $remaining)
            : sprintf('%02d:%02d', $minutes, $remaining);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>
     */
    private function publicMetadata(array $metadata): array
    {
        return [
            'id' => $metadata['id'] ?? null,
            'title' => $metadata['title'] ?? null,
            'channel' => $metadata['channel'] ?? $metadata['uploader'] ?? null,
            'channel_id' => $metadata['channel_id'] ?? null,
            'duration_seconds' => isset($metadata['duration']) ? (int) $metadata['duration'] : null,
            'webpage_url' => $metadata['webpage_url'] ?? $metadata['original_url'] ?? null,
            'language' => $metadata['language'] ?? null,
            'published_at' => $metadata['published_at'] ?? $metadata['upload_date'] ?? null,
            'description_excerpt' => is_string($metadata['description'] ?? null)
                ? Str::limit(trim((string) $metadata['description']), 1200, '')
                : null,
            'chapters' => is_array($metadata['chapters'] ?? null) ? array_slice($metadata['chapters'], 0, 80) : [],
            'view_count' => isset($metadata['view_count']) ? (int) $metadata['view_count'] : null,
            'like_count' => isset($metadata['like_count']) ? (int) $metadata['like_count'] : null,
            'metadata_source' => $metadata['metadata_source'] ?? 'yt_dlp',
            'data_api_status' => $metadata['data_api_status'] ?? null,
            'data_api_quota_units' => $metadata['data_api_quota_units'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $caption
     * @return array<string,mixed>
     */
    private function publicCaption(array $caption): array
    {
        return [
            'language' => $caption['language'] ?? null,
            'name' => $caption['name'] ?? null,
            'kind' => $caption['track_kind'] ?? $caption['kind'] ?? null,
            'ext' => $caption['ext'] ?? null,
        ];
    }

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
