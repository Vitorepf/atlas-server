<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Context\AtlasSemanticEmbeddingFoundationService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\EmbeddingProvenance;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * MAXA-05 — persist ASEF chunks with contextualized embeddings.
 *
 * Embed text is always `"{title} > {section}\n\n{chunk}"` (deterministic).
 * Retrieval maps chunk hits → documents with source_ref dedup.
 * Delete cascade uses the ASEF `delete_cascade_key` (never raw source wipe heuristics).
 */
final class AsefChunkIndexService
{
    public const SCHEMA_VERSION = 'atlas.asef_chunks.index.v1';

    public const STATUS_UNAVAILABLE = 'unavailable';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DEGRADED = 'degraded';

    public const STATUS_OK = 'ok';

    public const STATUS_FAILED = 'failed';

    public const STATUS_EMPTY = 'empty';

    public const EMBEDDING_STATUS_PENDING = 'pending';

    public const EMBEDDING_STATUS_PERSISTED = 'persisted';

    public const REASON_ASEF_CHUNKS_TABLE_MISSING = 'asef_chunks_table_missing';

    public const REASON_EMPTY_SOURCE_REF_OR_TEXT = 'empty_source_ref_or_text';

    public const REASON_EMBEDDING_COLUMN_ABSENT = 'embedding_column_absent';

    public const FIELD_CHUNKS_WRITTEN = 'chunks_written';

    public const FIELD_CHUNKS_SKIPPED = 'chunks_skipped';


    public const FIELD_STATUS = 'status';

    public const FIELD_SOURCE_REF = 'source_ref';

    public const FIELD_CHUNK_HASH = 'chunk_hash';

    public const FIELD_SCHEMA_VERSION = 'schema_version';

    public const FIELD_CHUNK_ID = 'chunk_id';

    public const FIELD_SIMILARITY = 'similarity';

    public const FIELD_CHUNKS = 'chunks';

    public const FIELD_QUERY = 'query';

    public function __construct(
        private readonly AtlasSemanticEmbeddingFoundationService $asef,
        private readonly EmbeddingService $embeddings,
    ) {}

    public static function contextualizedText(string $title, string $section, string $chunk): string
    {
        $title = AiValueNormalizer::trimmedStringOrNull($title) ?? '';
        $section = AiValueNormalizer::trimmedStringOrNull($section) ?? '';
        $chunk = AiValueNormalizer::trimmedStringOrNull($chunk) ?? '';

        return $title.' > '.$section."\n\n".$chunk;
    }

    /**
     * @param  array{source_ref?:string,text?:string,title?:string,section?:string,privacy_class?:string}  $source
     * @return array<string,mixed>
     */
    public function indexSource(array $source, bool $allowExternalProvider = false): array
    {
        if (! DatabaseTableAvailability::has('asef_chunks')) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                'reason' => self::REASON_ASEF_CHUNKS_TABLE_MISSING,
                self::FIELD_CHUNKS_WRITTEN => 0,
                self::FIELD_CHUNKS_SKIPPED => 0,
            ];
        }

        $sourceRef = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_SOURCE_REF] ?? null) ?? '';
        $text = AiValueNormalizer::trimmedStringOrNull($source['text'] ?? null) ?? '';
        $title = AiValueNormalizer::trimmedStringOrNull($source['title'] ?? null) ?? '';
        $section = AiValueNormalizer::trimmedStringOrNull($source['section'] ?? null) ?? '';

        if ($sourceRef === '' || $text === '') {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_BLOCKED,
                'reason' => self::REASON_EMPTY_SOURCE_REF_OR_TEXT,
                self::FIELD_CHUNKS_WRITTEN => 0,
                self::FIELD_CHUNKS_SKIPPED => 0,
            ];
        }

        $manifest = $this->asef->candidateSet([$source]);
        $chunks = $manifest['candidate_set'][self::FIELD_CHUNKS] ?? [];
        $hashToText = $this->asef->chunkTextsByHash($text);

        $written = 0;
        $skipped = 0;
        $errors = [];

        foreach ($chunks as $chunk) {
            if (! is_array($chunk)) {
                $skipped++;

                continue;
            }

            $chunkHash = (AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_CHUNK_HASH] ?? null) ?? '');
            $chunkText = (AiValueNormalizer::trimmedStringOrNull($hashToText[$chunkHash] ?? null) ?? '');
            if ($chunkHash === '' || $chunkText === '') {
                $skipped++;

                continue;
            }

            $embeddedText = self::contextualizedText($title, $section, $chunkText);
            $row = [
                'id' => (string) Str::uuid(),
                self::FIELD_CHUNK_ID => AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_CHUNK_ID] ?? null) ?? ('asef_'.substr($chunkHash, 0, 24)),
                self::FIELD_SOURCE_REF => $sourceRef,
                'source_hash' => AiValueNormalizer::trimmedStringOrNull($chunk['source_hash'] ?? null) ?? MissionCanonicalHash::sha256($sourceRef),
                'chunk_index' => (int) (AiValueNormalizer::finiteFloatOrNull($chunk['chunk_index'] ?? null) ?? 0),
                self::FIELD_CHUNK_HASH => $chunkHash,
                'title' => $title,
                'section' => $section,
                'chunk_text' => $chunkText,
                'embedded_text' => $embeddedText,
                'privacy_class' => (AiValueNormalizer::trimmedStringOrNull($chunk['privacy_class'] ?? null) ?? 'normal'),
                'provider_safe' => (AiValueNormalizer::boolOrNull($chunk['provider_safe'] ?? null) ?? true),
                'delete_cascade_key' => (AiValueNormalizer::trimmedStringOrNull($chunk['delete_cascade_key'] ?? null) ?? ''),
                'embedding_status' => self::EMBEDDING_STATUS_PENDING,
                'created_at' => Carbon::now(),
                'updated_at' => Carbon::now(),
            ];

            try {
                $this->upsertChunk($row, $embeddedText, $allowExternalProvider);
                $written++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = [
                    self::FIELD_CHUNK_HASH => $chunkHash,
                    'reason' => $e->getMessage(),
                ];
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $written > 0 ? self::STATUS_OK : ($errors !== [] ? self::STATUS_FAILED : self::STATUS_EMPTY),
            self::FIELD_SOURCE_REF => $sourceRef,
            self::FIELD_CHUNKS_WRITTEN => $written,
            self::FIELD_CHUNKS_SKIPPED => $skipped,
            'errors' => $errors,
            'manifest_status' => $manifest[self::FIELD_STATUS] ?? null,
        ];
    }

    public function deleteCascade(string $deleteCascadeKey): int
    {
        $key = AiValueNormalizer::trimmedStringOrNull($deleteCascadeKey) ?? '';
        if ($key === '' || ! DatabaseTableAvailability::has('asef_chunks')) {
            return 0;
        }

        return (int) DB::table('asef_chunks')->where('delete_cascade_key', $key)->delete();
    }

    public function deleteBySourceRef(string $sourceRef): int
    {
        $ref = AiValueNormalizer::trimmedStringOrNull($sourceRef) ?? '';
        if ($ref === '' || ! DatabaseTableAvailability::has('asef_chunks')) {
            return 0;
        }

        return (int) DB::table('asef_chunks')->where('source_ref', $ref)->delete();
    }

    /**
     * Pure chunk-hit → document mapping with source_ref dedup (highest similarity wins).
     *
     * @param  array<int,array{source_ref:string,similarity?:float,chunk_hash?:string,chunk_id?:string}>  $chunkHits
     * @return array<int,array{source_ref:string,similarity:float,chunk_hash:?string,chunk_id:?string,chunk_hit_count:int}>
     */
    public function mapChunkHitsToDocuments(array $chunkHits): array
    {
        $best = [];
        $counts = [];

        foreach ($chunkHits as $hit) {
            $ref = AiValueNormalizer::trimmedStringOrNull($hit[self::FIELD_SOURCE_REF] ?? null) ?? '';
            if ($ref === '') {
                continue;
            }
            $sim = AiValueNormalizer::finiteFloatOrNull($hit[self::FIELD_SIMILARITY] ?? null) ?? 0.0;
            $counts[$ref] = ($counts[$ref] ?? 0) + 1;
            if (! isset($best[$ref]) || $sim > (AiValueNormalizer::finiteFloatOrNull($best[$ref][self::FIELD_SIMILARITY] ?? null) ?? 0.0)) {
                $best[$ref] = [
                    self::FIELD_SOURCE_REF => $ref,
                    self::FIELD_SIMILARITY => $sim,
                    self::FIELD_CHUNK_HASH => AiValueNormalizer::trimmedScalarStringOrNull($hit[self::FIELD_CHUNK_HASH] ?? null),
                    self::FIELD_CHUNK_ID => AiValueNormalizer::trimmedScalarStringOrNull($hit[self::FIELD_CHUNK_ID] ?? null),
                ];
            }
        }

        $docs = [];
        foreach ($best as $ref => $row) {
            $docs[] = $row + ['chunk_hit_count' => (int) $counts[$ref]];
        }

        usort($docs, static fn (array $a, array $b): int => $b[self::FIELD_SIMILARITY] <=> $a[self::FIELD_SIMILARITY]);

        return array_values($docs);
    }

    /**
     * @return array<string,mixed>
     */
    public function search(string $query, int $limit = 5, bool $allowExternalProvider = false): array
    {
        $limit = max(1, min(50, $limit));
        if (! DatabaseTableAvailability::has('asef_chunks')) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                'reason' => self::REASON_ASEF_CHUNKS_TABLE_MISSING,
                'documents' => [],
            ];
        }

        if (! DatabaseTableAvailability::hasColumn('asef_chunks', 'embedding')) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_DEGRADED,
                'reason' => self::REASON_EMBEDDING_COLUMN_ABSENT,
                'documents' => [],
            ];
        }

        $vector = $this->embeddings->embedText(AiValueNormalizer::trimmedStringOrNull($query) ?? '', $allowExternalProvider);
        $literal = $this->embeddings->vectorLiteral($vector);
        $modelId = EmbeddingProvenance::modelId($this->embeddings->lastInfo());

        $builder = DB::table('asef_chunks')
            ->whereNotNull('embedding')
            ->select(['source_ref', 'chunk_hash', 'chunk_id'])
            ->selectRaw('(1 - (embedding <=> ?::vector)) AS similarity', [$literal]);

        EmbeddingProvenance::scopeCurrentModel($builder, 'asef_chunks', $modelId);

        $rows = $builder
            ->orderByRaw('embedding <=> ?::vector', [$literal])
            ->limit($limit * 8)
            ->get()
            ->map(static fn ($row): array => [
                self::FIELD_SOURCE_REF => AiValueNormalizer::trimmedScalarStringOrNull($row->source_ref ?? null) ?? '',
                self::FIELD_CHUNK_HASH => AiValueNormalizer::trimmedScalarStringOrNull($row->chunk_hash ?? null) ?? '',
                self::FIELD_CHUNK_ID => AiValueNormalizer::trimmedScalarStringOrNull($row->chunk_id ?? null) ?? '',
                self::FIELD_SIMILARITY => AiValueNormalizer::finiteFloatOrNull($row->similarity ?? null) ?? 0.0,
            ])
            ->all();

        $documents = array_slice($this->mapChunkHitsToDocuments($rows), 0, $limit);

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => self::STATUS_OK,
            'embedding_model' => $modelId,
            'chunk_hits' => count($rows),
            'documents' => $documents,
        ];
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function upsertChunk(array $row, string $embeddedText, bool $allowExternalProvider): void
    {
        $vector = $this->embeddings->embedText($embeddedText, $allowExternalProvider);
        $modelId = EmbeddingProvenance::modelId($this->embeddings->lastInfo());
        $contentHash = EmbeddingProvenance::contentHash($embeddedText);

        $row['embedding_model'] = $modelId;
        $row['embedded_content_hash'] = $contentHash;
        $row['embedded_at'] = Carbon::now();
        $row['embedding_status'] = self::EMBEDDING_STATUS_PERSISTED;

        $existing = DB::table('asef_chunks')
            ->where('source_ref', $row[self::FIELD_SOURCE_REF])
            ->where('chunk_hash', $row[self::FIELD_CHUNK_HASH])
            ->first();

        if ($existing !== null) {
            $update = [
                'title' => $row['title'],
                'section' => $row['section'],
                'chunk_text' => $row['chunk_text'],
                'embedded_text' => $row['embedded_text'],
                'privacy_class' => $row['privacy_class'],
                'provider_safe' => $row['provider_safe'],
                'delete_cascade_key' => $row['delete_cascade_key'],
                'embedding_model' => $modelId,
                'embedded_content_hash' => $contentHash,
                'embedded_at' => $row['embedded_at'],
                'embedding_status' => self::EMBEDDING_STATUS_PERSISTED,
                'updated_at' => Carbon::now(),
            ];
            DB::table('asef_chunks')->where('id', $existing->id)->update($update);
            $this->writeVector(AiValueNormalizer::trimmedScalarStringOrNull($existing->id ?? null) ?? '', $vector);

            return;
        }

        DB::table('asef_chunks')->insert($row);
        $this->writeVector(AiValueNormalizer::trimmedScalarStringOrNull($row['id'] ?? null) ?? '', $vector);
    }

    /**
     * @param  array<int,float>  $vector
     */
    private function writeVector(string $id, array $vector): void
    {
        if (! DatabaseTableAvailability::hasColumn('asef_chunks', 'embedding')) {
            return;
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::update(
            'UPDATE asef_chunks SET embedding = ?::vector WHERE id = ?',
            [$this->embeddings->vectorLiteral($vector), $id],
        );
    }
};
