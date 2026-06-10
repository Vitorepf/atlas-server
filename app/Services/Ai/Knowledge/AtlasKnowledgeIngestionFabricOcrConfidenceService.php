<?php

declare(strict_types=1);

namespace App\Services\Ai\Knowledge;

use App\Services\Ai\Support\AppendOnlyJsonlStore;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * AKIF · OCR Confidence-Scored Ingestion (P1 gap closure).
 *
 * Wraps OCR / transcription artifacts with a canonical confidence envelope
 * so downstream consumers (memory promotion, evidence promotion) can gate
 * by confidence threshold. No external OCR engine is bundled here; this
 * service is the **envelope contract** — callers provide raw text + confidence
 * score from whichever engine they used (whisper.cpp, Tesseract, etc).
 *
 * Authority doc: scaffold under AKIF tree.
 *
 * Schemas:
 *   - atlas.akif.ocr_confidence_artifact.v1
 *
 * Invariants:
 *   - confidence ∈ [0, 1];
 *   - source_kind ∈ canon list;
 *   - append-only;
 *   - artifact never embeds raw external content above CONTENT_PREVIEW_MAX chars
 *     (long content is hashed to keep envelopes auditable but bounded).
 */
final class AtlasKnowledgeIngestionFabricOcrConfidenceService
{
    public const ARTIFACT_SCHEMA = 'atlas.akif.ocr_confidence_artifact.v1';

    public const SOURCE_OCR = 'ocr';

    public const SOURCE_AUDIO_TRANSCRIPTION = 'audio_transcription';

    public const SOURCE_VIDEO_TRANSCRIPTION = 'video_transcription';

    public const SOURCE_IMAGE_CAPTION = 'image_caption';

    public const SOURCE_PDF_EXTRACTION = 'pdf_extraction';

    public const VALID_SOURCES = [
        self::SOURCE_OCR, self::SOURCE_AUDIO_TRANSCRIPTION, self::SOURCE_VIDEO_TRANSCRIPTION,
        self::SOURCE_IMAGE_CAPTION, self::SOURCE_PDF_EXTRACTION,
    ];

    public const CONFIDENCE_HIGH_THRESHOLD = 0.85;

    public const CONFIDENCE_MEDIUM_THRESHOLD = 0.6;

    public const CONTENT_PREVIEW_MAX = 280;

    private ?string $artifactsLogOverride = null;

    public function setArtifactsLogPathForTesting(?string $path): void
    {
        $this->artifactsLogOverride = $path;
    }

    public function artifactsLogPath(): string
    {
        if ($this->artifactsLogOverride !== null) {
            return $this->artifactsLogOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/akif')
            : sys_get_temp_dir().'/atlas/akif';

        return $base.DIRECTORY_SEPARATOR.'ocr_artifacts.jsonl';
    }

    /**
     * Record a confidence-scored ingestion artifact.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $sourceKind = (string) ($input['source_kind'] ?? '');
        if (! in_array($sourceKind, self::VALID_SOURCES, true)) {
            throw new InvalidArgumentException("Unknown source_kind '{$sourceKind}'.");
        }
        $confidence = (float) ($input['confidence'] ?? -1.0);
        if ($confidence < 0.0 || $confidence > 1.0) {
            throw new InvalidArgumentException('confidence must be in [0,1].');
        }
        $rawContent = (string) ($input['content'] ?? '');
        if ($rawContent === '') {
            throw new InvalidArgumentException('content is required.');
        }
        $sourceUri = (string) ($input['source_uri'] ?? '');
        $language = (string) ($input['language'] ?? 'unknown');
        $engine = (string) ($input['engine'] ?? 'unknown');

        $bucket = $this->confidenceBucket($confidence);
        $promotable = $confidence >= self::CONFIDENCE_MEDIUM_THRESHOLD;

        $preview = mb_substr($rawContent, 0, self::CONTENT_PREVIEW_MAX);
        $contentHash = 'sha256:'.hash('sha256', $rawContent);

        $recordedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $artifactId = 'akif_'.substr(hash('sha256', $sourceKind.'|'.$contentHash.'|'.$recordedAt), 0, 12);

        $artifact = [
            'schema_version' => self::ARTIFACT_SCHEMA,
            'artifact_id' => $artifactId,
            'recorded_at' => $recordedAt,
            'source_kind' => $sourceKind,
            'source_uri' => $sourceUri,
            'language' => $language,
            'engine' => $engine,
            'confidence' => round($confidence, 4),
            'confidence_bucket' => $bucket,
            'promotable' => $promotable,
            'content_hash' => $contentHash,
            'content_preview' => $preview,
            'content_length' => strlen($rawContent),
        ];
        $artifact['artifact_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::ARTIFACT_SCHEMA,
            'source_kind' => $sourceKind,
            'content_hash' => $contentHash,
            'confidence' => $artifact['confidence'],
        ], JSON_THROW_ON_ERROR));

        AppendOnlyJsonlStore::append($this->artifactsLogPath(), $artifact);

        return $artifact;
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listArtifacts(int $limit = 100): array
    {
        $rows = AppendOnlyJsonlStore::read($this->artifactsLogPath());
        if ($limit > 0 && count($rows) > $limit) {
            return array_slice($rows, -$limit);
        }

        return $rows;
    }

    /**
     * Promotable artifacts = confidence ≥ medium threshold.
     *
     * @return list<array<string,mixed>>
     */
    public function listPromotable(int $limit = 100): array
    {
        $out = [];
        foreach (AppendOnlyJsonlStore::read($this->artifactsLogPath()) as $a) {
            if (! empty($a['promotable'])) {
                $out[] = $a;
            }
        }
        if ($limit > 0 && count($out) > $limit) {
            return array_slice($out, -$limit);
        }

        return $out;
    }

    // ---------- internals ----------

    private function confidenceBucket(float $c): string
    {
        if ($c >= self::CONFIDENCE_HIGH_THRESHOLD) {
            return 'high';
        }
        if ($c >= self::CONFIDENCE_MEDIUM_THRESHOLD) {
            return 'medium';
        }

        return 'low';
    }

}
