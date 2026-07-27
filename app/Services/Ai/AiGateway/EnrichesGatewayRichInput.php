<?php

namespace App\Services\Ai\AiGateway;

use App\Models\AiJob;
use Illuminate\Support\Str;

/**
 * Rich-input enrichment (PDF question visuals + YouTube knowledge ingestion)
 * extracted VERBATIM from AiGatewayService (GOD-DEBULK D3 split). Trait composition
 * preserves behaviour and dependency access exactly.
 */
trait EnrichesGatewayRichInput
{
    private function optionsWithPdfQuestionVisuals(string $input, array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $attachments = is_array($payload['attachments'] ?? null) ? $payload['attachments'] : [];
        $files = is_array($attachments['files'] ?? null) ? $attachments['files'] : [];
        if ($files === []) {
            return $options;
        }

        $stats = [
            'status' => 'skipped',
            'files_checked' => 0,
            'files_enriched' => 0,
            'pages_added' => 0,
        ];

        $attachments['files'] = collect($files)
            ->map(function (mixed $file) use ($input, &$stats): mixed {
                if (! is_array($file)) {
                    return $file;
                }

                $mime = strtolower((string) ($file['mime_type'] ?? ''));
                $name = strtolower((string) ($file['original_name'] ?? ''));
                if (! str_contains($mime, 'pdf') && ! str_ends_with($name, '.pdf')) {
                    return $file;
                }

                $stats['files_checked']++;
                try {
                    $enhanced = $this->fileAttachments->enhancePdfForQuery($file, $input, 8);
                } catch (\Throwable) {
                    return $file;
                }

                $added = count((array) data_get($enhanced, 'pdf_query_visualization.added_pages', []));
                if ($added > 0) {
                    $stats['files_enriched']++;
                    $stats['pages_added'] += $added;
                }

                return $enhanced;
            })
            ->values()
            ->all();

        $payload['attachments'] = $attachments;
        if ($stats['files_checked'] > 0) {
            $stats['status'] = $stats['pages_added'] > 0 ? 'enriched' : 'cache_hit_or_no_match';
            $payload['pdf_question_visualization'] = $stats;
        }
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithYouTubeKnowledge(string $input, array $options): array
    {
        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        if (is_array(data_get($payload, 'youtube_ingestion'))) {
            return $options;
        }

        // Canonical capability · union YouTube URLs from `input_text` and
        // `rich_input_payload.url_attachments[]` (kind='youtube'), dedup by
        // canonical video URL. Desktop/mobile that attach via payload no
        // longer get silently ignored. See docs/rich-input/youtube-canon.md.
        $urlsFromText = $this->youtubeKnowledge->extractUrls($input);
        $payloadUrls = data_get($payload, 'rich_input_payload.url_attachments');
        $urlsFromPayload = is_array($payloadUrls)
            ? $this->youtubeKnowledge->extractUrlsFromRichInputPayload($payloadUrls)
            : [];

        $urls = collect([...$urlsFromText, ...$urlsFromPayload])
            ->filter(fn (mixed $url): bool => is_string($url) && $url !== '')
            ->unique()
            ->values()
            ->all();

        if ($urls === []) {
            return $this->optionsWithRecentThreadYouTubeKnowledge($input, $options);
        }

        try {
            $ingestion = $this->youtubeKnowledge->ingestFromUrls($urls, [
                'defer_audio_fallback' => (bool) config('atlas.youtube.defer_audio_fallback', true),
            ]);
        } catch (\Throwable $e) {
            $ingestion = [
                'schema_version' => 1,
                'status' => 'failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'videos' => [],
            ];
        }

        $payload['youtube_ingestion'] = $ingestion;
        $options['payload'] = $payload;

        return $options;
    }

    private function optionsWithRecentThreadYouTubeKnowledge(string $input, array $options): array
    {
        if (! $this->isLikelyYouTubeContinuation($input)) {
            return $options;
        }

        $threadId = data_get($options, 'payload.thread_id', data_get($options, 'thread_id'));
        if (! is_string($threadId) || trim($threadId) === '') {
            return $options;
        }

        $recent = $this->recentThreadYouTubeVideo($threadId);
        $url = is_array($recent) ? (string) ($recent['url'] ?? '') : '';
        if ($url === '') {
            return $options;
        }

        try {
            $ingestion = $this->youtubeKnowledge->ingestFromInput($url, [
                'defer_audio_fallback' => (bool) config('atlas.youtube.defer_audio_fallback', true),
            ]);
        } catch (\Throwable $e) {
            $ingestion = [
                'schema_version' => 1,
                'status' => 'failed',
                'reason' => Str::limit($e->getMessage(), 220, ''),
                'videos' => [],
            ];
        }

        if (! is_array($ingestion['videos'] ?? null) || $ingestion['videos'] === []) {
            return $options;
        }

        $payload = is_array($options['payload'] ?? null) ? $options['payload'] : [];
        $payload['youtube_ingestion'] = $ingestion;
        $payload['youtube_continuation'] = [
            'source' => 'recent_thread_youtube',
            'matched_by' => 'short_follow_up_without_url',
            'previous_job_id' => $recent['job_id'] ?? null,
            'previous_trace_id' => $recent['trace_id'] ?? null,
            'url' => $url,
        ];
        $options['payload'] = $payload;

        return $options;
    }

    private function isLikelyYouTubeContinuation(string $input): bool
    {
        $normalized = Str::of($input)->lower()->ascii()->squish()->toString();
        if ($normalized === '' || mb_strlen($normalized) > 180) {
            return false;
        }

        foreach ([
            'conseguiu',
            'ficou pronto',
            'ja ficou pronto',
            'ja terminou',
            'terminou',
            'e agora',
            'agora vai',
            'deu certo',
            'pode analisar',
            'analise completa',
            'manda a analise',
            'me manda',
            'continua',
            'pronto',
        ] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return str_contains($normalized, 'video')
            && (str_contains($normalized, 'transcricao') || str_contains($normalized, 'youtube'));
    }

    /**
     * @return array{url:string,job_id:string|null,trace_id:string|null}|null
     */
    private function recentThreadYouTubeVideo(string $threadId): ?array
    {
        $jobs = AiJob::query()
            ->whereHas('trace', fn ($query) => $query->where('thread_id', $threadId))
            ->latest('created_at')
            ->limit(12)
            ->get(['id', 'trace_id', 'payload', 'created_at']);

        foreach ($jobs as $job) {
            if ($job->created_at && $job->created_at->lt(now()->subHours(6))) {
                continue;
            }

            $videos = data_get($job->payload, 'youtube_ingestion.videos', []);
            if (! is_array($videos)) {
                continue;
            }

            foreach ($videos as $video) {
                if (! is_array($video)) {
                    continue;
                }

                $url = (string) ($video['url'] ?? data_get($video, 'metadata.webpage_url', ''));
                if ($url === '') {
                    continue;
                }

                return [
                    'url' => $url,
                    'job_id' => $job->id,
                    'trace_id' => $job->trace_id,
                ];
            }
        }

        return null;
    }
}
