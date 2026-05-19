<?php

namespace App\Services\Ai;

use App\Enums\YoutubeIngestionStatus;
use App\Enums\YoutubeTranscriptStatus;
use App\Enums\YoutubeTranslationStatus;
use App\Models\AiYoutubeIngestion;

/**
 * Project raw YouTube ingestion results onto the canonical three-status
 * schema shared with `@atlas/rich-input-canon`. Single point of derivation
 * on the server — gateway, worker and prompt builder all go through here
 * so mobile / desktop never see divergent shapes.
 *
 * Doctrine:
 *   - Ingestion, transcript and translation are independent dimensions.
 *   - `translated_ready` is unreachable today: there is no translation
 *     pipeline. Foreign videos stay `Required` until a future pipeline
 *     lands. Never claim translation that did not happen.
 *
 * See `docs/rich-input/youtube-canon.md`.
 */
class YoutubeCanonicalProjection
{
    public const DEFAULT_TARGET_LANGUAGE = 'pt-BR';

    /**
     * Project a single raw `videos[]` entry from the ingestion service
     * onto the canonical schema. Returns the same entry enriched with
     * `ingestion_status`, `transcript_status`, `translation_status`,
     * `source_language`, `target_language`, `translation_required`.
     *
     * @param  array<string,mixed>  $video
     * @return array<string,mixed>
     */
    public function project(array $video): array
    {
        $legacyStatus = isset($video['status']) ? (string) $video['status'] : '';
        $ingestion = YoutubeIngestionStatus::fromLegacy($legacyStatus);
        $transcript = YoutubeTranscriptStatus::fromLegacy($legacyStatus);

        // Guarantee video_id is present in the snapshot. The trace re-resolve
        // path (`AiJobResource::withFreshYoutubeIngestion`) needs it to look
        // up `ai_youtube_ingestions` rows by canonical id.
        if (! isset($video['video_id']) || $video['video_id'] === '' || $video['video_id'] === null) {
            $url = is_string($video['url'] ?? null) ? (string) $video['url'] : '';
            $videoId = $this->extractVideoIdFromUrl($url);
            if ($videoId !== null) {
                $video['video_id'] = $videoId;
            }
        }

        $caption = is_array($video['caption'] ?? null) ? $video['caption'] : [];
        $captionLanguage = is_string($caption['language'] ?? null) ? $caption['language'] : null;
        if ($captionLanguage === null) {
            $captionLanguage = is_string($video['caption_language'] ?? null) ? $video['caption_language'] : null;
        }
        $sourceLanguage = $this->inferSourceLanguage($captionLanguage);
        $targetLanguage = is_string($video['target_language'] ?? null) && $video['target_language'] !== ''
            ? $video['target_language']
            : self::DEFAULT_TARGET_LANGUAGE;

        $translationRequired = $this->deriveTranslationRequirement($sourceLanguage, $targetLanguage);
        $translationStatus = $this->deriveTranslationStatus(
            $transcript,
            $translationRequired,
            $video['translation_status'] ?? null,
        );

        return array_merge($video, [
            'ingestion_status' => $ingestion->value,
            'transcript_status' => $transcript->value,
            'translation_status' => $translationStatus->value,
            'source_language' => $sourceLanguage,
            'target_language' => $targetLanguage,
            'translation_required' => $translationRequired,
        ]);
    }

    /**
     * Project the FULL `youtube_ingestion` block from the gateway.
     * Returns the same shape with each video projected.
     *
     * @param  array<string,mixed>  $ingestion
     * @return array<string,mixed>
     */
    public function projectIngestion(array $ingestion): array
    {
        $videos = is_array($ingestion['videos'] ?? null) ? $ingestion['videos'] : [];
        $projected = [];
        foreach ($videos as $video) {
            if (! is_array($video)) {
                continue;
            }
            $projected[] = $this->project($video);
        }
        $ingestion['videos'] = $projected;

        return $ingestion;
    }

    /**
     * Project from a persisted DB record. Convenience for code paths that
     * need to re-resolve fresh state at GET time without re-running
     * ingestion.
     *
     * @return array<string,mixed>
     */
    public function projectFromRecord(AiYoutubeIngestion $record): array
    {
        $caption = is_array($record->caption ?? null) ? $record->caption : [];
        $metadata = is_array($record->metadata ?? null) ? $record->metadata : [];

        return $this->project([
            'url' => $record->url,
            'video_id' => $record->video_id,
            'status' => (string) $record->status,
            'metadata' => $metadata,
            'caption' => $caption,
            'caption_language' => $record->caption_language,
            'caption_kind' => $record->caption_kind,
            'cache_hit' => (bool) $record->cache_hit,
            'ingestion_status' => $record->ingestion_status,
            'transcript_status' => $record->transcript_status,
            'translation_status' => $record->translation_status,
            'source_language' => $record->source_language,
            'target_language' => $record->target_language ?: self::DEFAULT_TARGET_LANGUAGE,
            'translation_required' => $record->translation_required,
        ]);
    }

    private function extractVideoIdFromUrl(string $url): ?string
    {
        if ($url === '') {
            return null;
        }
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

    public function inferSourceLanguage(?string $captionLanguage): ?string
    {
        if (! is_string($captionLanguage)) {
            return null;
        }
        $trimmed = strtolower(trim(str_replace('_', '-', $captionLanguage)));
        if ($trimmed === '' || $trimmed === 'und' || $trimmed === 'unknown') {
            return null;
        }
        if ($trimmed === 'pt-br' || str_starts_with($trimmed, 'pt-br-')) {
            return 'pt-BR';
        }
        if ($trimmed === 'pt-pt' || str_starts_with($trimmed, 'pt-pt-')) {
            return 'pt-PT';
        }
        if ($trimmed === 'pt' || str_starts_with($trimmed, 'pt-')) {
            return 'pt';
        }
        if ($trimmed === 'zh-hans' || str_starts_with($trimmed, 'zh-hans-')) {
            return 'zh-Hans';
        }
        if ($trimmed === 'zh-hant' || str_starts_with($trimmed, 'zh-hant-')) {
            return 'zh-Hant';
        }
        $primary = explode('-', $trimmed)[0] ?? '';

        return $primary !== '' && preg_match('/^[a-z]{2,3}$/', $primary) === 1 ? $primary : null;
    }

    public function deriveTranslationRequirement(?string $sourceLanguage, ?string $targetLanguage): bool
    {
        $target = $targetLanguage ? strtolower(trim($targetLanguage)) : '';
        $source = $sourceLanguage ? strtolower(trim($sourceLanguage)) : '';
        if ($target === '' || $source === '') {
            return false;
        }
        if ($source === $target) {
            return false;
        }
        if (str_starts_with($target, 'pt') && str_starts_with($source, 'pt')) {
            return false;
        }

        return true;
    }

    public function deriveTranslationStatus(
        YoutubeTranscriptStatus $transcript,
        bool $translationRequired,
        ?string $explicit,
    ): YoutubeTranslationStatus {
        if (is_string($explicit) && $explicit !== '') {
            $candidate = YoutubeTranslationStatus::tryFrom($explicit);
            if ($candidate !== null) {
                return $candidate;
            }
        }

        if ($transcript === YoutubeTranscriptStatus::Failed) {
            return YoutubeTranslationStatus::Failed;
        }
        if ($transcript === YoutubeTranscriptStatus::Unavailable) {
            return YoutubeTranslationStatus::NotRequired;
        }
        if ($transcript === YoutubeTranscriptStatus::Pending) {
            return YoutubeTranslationStatus::Pending;
        }
        if (! $translationRequired) {
            return YoutubeTranslationStatus::NotRequired;
        }

        return YoutubeTranslationStatus::Required;
    }
}
