<?php

declare(strict_types=1);

namespace App\Services\Ai\Context\Retrieval;

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
    public const FIELD_REASON = 'reason';
    public const FIELD_TITLE = 'title';
    public const FIELD_SECTION = 'section';
    public const FIELD_TEXT = 'text';
    public const FIELD_PRIVACY_CLASS = 'privacy_class';
    public const FIELD_PROVIDER_SAFE = 'provider_safe';
    public const FIELD_DELETE_CASCADE_KEY = 'delete_cascade_key';
    public const FIELD_CHUNK_TEXT = 'chunk_text';
    public const FIELD_EMBEDDED_TEXT = 'embedded_text';
    public const FIELD_EMBEDDING_STATUS = 'embedding_status';
    public const FIELD_DOCUMENTS = 'documents';
    public const FIELD_EMBEDDING_MODEL = 'embedding_model';
    public const FIELD_ERRORS = 'errors';
    public const FIELD_EMBEDDED_AT = 'embedded_at';
    public const FIELD_SOURCE_HASH = 'source_hash';
    public const FIELD_CHUNK_INDEX = 'chunk_index';
    public const FIELD_UPDATED_AT = 'updated_at';
    public const FIELD_EMBEDDED_CONTENT_HASH = 'embedded_content_hash';
    public const FIELD_CREATED_AT = 'created_at';
    public const FIELD_MANIFEST_STATUS = 'manifest_status';
    public const FIELD_CANDIDATE_SET = 'candidate_set';
    public const FIELD_CHUNK_HIT_COUNT = 'chunk_hit_count';
    public const FIELD_ID = 'id';
    public const FIELD_CHUNK_HITS = 'chunk_hits';
    public const FIELD_PGSQL = 'pgsql';
    public const FIELD_ASEF_CHUNKS = 'asef_chunks';
    public const FIELD_EMBEDDING = 'embedding';
    public const FIELD_NORMAL = 'normal';
    public const FIELD_ASEF_ = 'asef_';

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
        if (! DatabaseTableAvailability::has(self::FIELD_ASEF_CHUNKS)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                self::FIELD_REASON => self::REASON_ASEF_CHUNKS_TABLE_MISSING,
                self::FIELD_CHUNKS_WRITTEN => 0,
                self::FIELD_CHUNKS_SKIPPED => 0,
            ];
        }

        $sourceRef = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_SOURCE_REF] ?? null) ?? '';
        $text = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_TEXT] ?? null) ?? '';
        $title = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_TITLE] ?? null) ?? '';
        $section = AiValueNormalizer::trimmedStringOrNull($source[self::FIELD_SECTION] ?? null) ?? '';

        if ($sourceRef === '' || $text === '') {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_BLOCKED,
                self::FIELD_REASON => self::REASON_EMPTY_SOURCE_REF_OR_TEXT,
                self::FIELD_CHUNKS_WRITTEN => 0,
                self::FIELD_CHUNKS_SKIPPED => 0,
            ];
        }

        $manifest = $this->asef->candidateSet([$source]);
        $chunks = $manifest[self::FIELD_CANDIDATE_SET][self::FIELD_CHUNKS] ?? [];
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
                self::FIELD_ID => (string) Str::uuid(),
                self::FIELD_CHUNK_ID => AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_CHUNK_ID] ?? null) ?? (self::FIELD_ASEF_.substr($chunkHash, 0, 24)),
                self::FIELD_SOURCE_REF => $sourceRef,
                self::FIELD_SOURCE_HASH => AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_SOURCE_HASH] ?? null) ?? MissionCanonicalHash::sha256($sourceRef),
                self::FIELD_CHUNK_INDEX => (int) (AiValueNormalizer::finiteFloatOrNull($chunk[self::FIELD_CHUNK_INDEX] ?? null) ?? 0),
                self::FIELD_CHUNK_HASH => $chunkHash,
                self::FIELD_TITLE => $title,
                self::FIELD_SECTION => $section,
                self::FIELD_CHUNK_TEXT => $chunkText,
                self::FIELD_EMBEDDED_TEXT => $embeddedText,
                self::FIELD_PRIVACY_CLASS => (AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_PRIVACY_CLASS] ?? null) ?? self::FIELD_NORMAL),
                self::FIELD_PROVIDER_SAFE => (AiValueNormalizer::boolOrNull($chunk[self::FIELD_PROVIDER_SAFE] ?? null) ?? true),
                self::FIELD_DELETE_CASCADE_KEY => (AiValueNormalizer::trimmedStringOrNull($chunk[self::FIELD_DELETE_CASCADE_KEY] ?? null) ?? ''),
                self::FIELD_EMBEDDING_STATUS => self::EMBEDDING_STATUS_PENDING,
                self::FIELD_CREATED_AT => Carbon::now(),
                self::FIELD_UPDATED_AT => Carbon::now(),
            ];

            try {
                $this->upsertChunk($row, $embeddedText, $allowExternalProvider);
                $written++;
            } catch (Throwable $e) {
                $skipped++;
                $errors[] = [
                    self::FIELD_CHUNK_HASH => $chunkHash,
                    self::FIELD_REASON => $e->getMessage(),
                ];
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $written > 0 ? self::STATUS_OK : ($errors !== [] ? self::STATUS_FAILED : self::STATUS_EMPTY),
            self::FIELD_SOURCE_REF => $sourceRef,
            self::FIELD_CHUNKS_WRITTEN => $written,
            self::FIELD_CHUNKS_SKIPPED => $skipped,
            self::FIELD_ERRORS => $errors,
            self::FIELD_MANIFEST_STATUS => $manifest[self::FIELD_STATUS] ?? null,
        ];
    }

    public function deleteCascade(string $deleteCascadeKey): int
    {
        $key = AiValueNormalizer::trimmedStringOrNull($deleteCascadeKey) ?? '';
        if ($key === '' || ! DatabaseTableAvailability::has(self::FIELD_ASEF_CHUNKS)) {
            return 0;
        }

        return (int) DB::table(self::FIELD_ASEF_CHUNKS)->where(self::FIELD_DELETE_CASCADE_KEY, $key)->delete();
    }

    public function deleteBySourceRef(string $sourceRef): int
    {
        $ref = AiValueNormalizer::trimmedStringOrNull($sourceRef) ?? '';
        if ($ref === '' || ! DatabaseTableAvailability::has(self::FIELD_ASEF_CHUNKS)) {
            return 0;
        }

        return (int) DB::table(self::FIELD_ASEF_CHUNKS)->where(self::FIELD_SOURCE_REF, $ref)->delete();
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
            $docs[] = $row + [self::FIELD_CHUNK_HIT_COUNT => (int) $counts[$ref]];
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
        if (! DatabaseTableAvailability::has(self::FIELD_ASEF_CHUNKS)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_UNAVAILABLE,
                self::FIELD_REASON => self::REASON_ASEF_CHUNKS_TABLE_MISSING,
                self::FIELD_DOCUMENTS => [],
            ];
        }

        if (! DatabaseTableAvailability::hasColumn(self::FIELD_ASEF_CHUNKS, self::FIELD_EMBEDDING)) {
            return [
                self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                self::FIELD_STATUS => self::STATUS_DEGRADED,
                self::FIELD_REASON => self::REASON_EMBEDDING_COLUMN_ABSENT,
                self::FIELD_DOCUMENTS => [],
            ];
        }

        $vector = $this->embeddings->embedText(AiValueNormalizer::trimmedStringOrNull($query) ?? '', $allowExternalProvider);
        $literal = $this->embeddings->vectorLiteral($vector);
        $modelId = EmbeddingProvenance::modelId($this->embeddings->lastInfo());

        $builder = DB::table(self::FIELD_ASEF_CHUNKS)
            ->whereNotNull(self::FIELD_EMBEDDING)
            ->select([self::FIELD_SOURCE_REF, self::FIELD_CHUNK_HASH, self::FIELD_CHUNK_ID])
            ->selectRaw('(1 - (embedding <=> ?::vector)) AS similarity', [$literal]);

        EmbeddingProvenance::scopeCurrentModel($builder, self::FIELD_ASEF_CHUNKS, $modelId);

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
            self::FIELD_EMBEDDING_MODEL => $modelId,
            self::FIELD_CHUNK_HITS => count($rows),
            self::FIELD_DOCUMENTS => $documents,
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

        $row[self::FIELD_EMBEDDING_MODEL] = $modelId;
        $row[self::FIELD_EMBEDDED_CONTENT_HASH] = $contentHash;
        $row[self::FIELD_EMBEDDED_AT] = Carbon::now();
        $row[self::FIELD_EMBEDDING_STATUS] = self::EMBEDDING_STATUS_PERSISTED;

        $existing = DB::table(self::FIELD_ASEF_CHUNKS)
            ->where(self::FIELD_SOURCE_REF, $row[self::FIELD_SOURCE_REF])
            ->where(self::FIELD_CHUNK_HASH, $row[self::FIELD_CHUNK_HASH])
            ->first();

        if ($existing !== null) {
            $update = [
                self::FIELD_TITLE => $row[self::FIELD_TITLE],
                self::FIELD_SECTION => $row[self::FIELD_SECTION],
                self::FIELD_CHUNK_TEXT => $row[self::FIELD_CHUNK_TEXT],
                self::FIELD_EMBEDDED_TEXT => $row[self::FIELD_EMBEDDED_TEXT],
                self::FIELD_PRIVACY_CLASS => $row[self::FIELD_PRIVACY_CLASS],
                self::FIELD_PROVIDER_SAFE => $row[self::FIELD_PROVIDER_SAFE],
                self::FIELD_DELETE_CASCADE_KEY => $row[self::FIELD_DELETE_CASCADE_KEY],
                self::FIELD_EMBEDDING_MODEL => $modelId,
                self::FIELD_EMBEDDED_CONTENT_HASH => $contentHash,
                self::FIELD_EMBEDDED_AT => $row[self::FIELD_EMBEDDED_AT],
                self::FIELD_EMBEDDING_STATUS => self::EMBEDDING_STATUS_PERSISTED,
                self::FIELD_UPDATED_AT => Carbon::now(),
            ];
            DB::table(self::FIELD_ASEF_CHUNKS)->where(self::FIELD_ID, $existing->id)->update($update);
            $this->writeVector(AiValueNormalizer::trimmedScalarStringOrNull($existing->id ?? null) ?? '', $vector);

            return;
        }

        DB::table(self::FIELD_ASEF_CHUNKS)->insert($row);
        $this->writeVector(AiValueNormalizer::trimmedScalarStringOrNull($row[self::FIELD_ID] ?? null) ?? '', $vector);
    }

    /**
     * @param  array<int,float>  $vector
     */
    private function writeVector(string $id, array $vector): void
    {
        if (! DatabaseTableAvailability::hasColumn(self::FIELD_ASEF_CHUNKS, self::FIELD_EMBEDDING)) {
            return;
        }

        if (DB::connection()->getDriverName() !== self::FIELD_PGSQL) {
            return;
        }

        DB::update(
            'UPDATE asef_chunks SET embedding = ?::vector WHERE id = ?',
            [$this->embeddings->vectorLiteral($vector), $id],
        );
    }
};
