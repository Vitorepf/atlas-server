<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AiAttachmentIndexEntry;
use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Models\SemanticNote;
use App\Services\Ai\Memory\AtlasMemorySemanticIndexer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Semantic\EmbeddingProvenance;
use App\Services\Semantic\EmbeddingService;
use App\Services\Semantic\SemanticNoteIndexer;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * R1 — backfill REAL embeddings for existing vector-backed Atlas memory rows so
 * recall can rank them by vector similarity. MAXA-03 adds per-vector provenance
 * (`embedding_model`, `embedded_content_hash`) and `--stale` incremental re-embed
 * by changed embedded text hash.
 *
 * pgvector-only by design. On sqlite or when the real embedding engine is
 * unavailable it reports an HONEST skip instead of fabricating vectors.
 *
 * @see app/Services/Ai/Memory/AtlasMemorySemanticIndexer.php
 */
class AtlasMemoryEmbedBackfillCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:memory:embed-backfill
        {--only= : Limit to one table: entries|verbatim|notes|attachments}
        {--missing-only : Only rows whose embedding is still NULL (default)}
        {--stale : Re-embed rows whose stored embedded_content_hash/model provenance is missing or stale}
        {--all : Re-embed every eligible row, not just rows missing an embedding}
        {--limit=0 : Max rows per table (0 = no limit)}
        {--json : Emit a machine-readable JSON receipt}';

    protected $description = 'Backfill real vector embeddings and MAXA-03 provenance for Atlas memory vector tables (pgvector).';

    public function handle(AtlasMemorySemanticIndexer $indexer, EmbeddingService $embeddings, SemanticNoteIndexer $noteIndexer): int
    {
        $only = (string) ($this->option('only') ?? '');
        $reembedAll = (bool) $this->option('all');
        $stale = (bool) $this->option('stale');
        $limit = max(0, (int) $this->option('limit'));

        if (! $indexer->isEnabled()) {
            return $this->report([
                'status' => 'skipped',
                'reason' => DB::getDriverName() !== 'pgsql'
                    ? 'embedding column + vector search are pgvector-only; current driver is '.DB::getDriverName()
                    : 'memory vector recall is disabled (atlas.semantic_memory.memory_vector_recall_enabled)',
                'driver' => DB::getDriverName(),
            ], self::SUCCESS);
        }

        $result = ['status' => 'completed', 'driver' => DB::getDriverName(), 'mode' => $this->mode($reembedAll, $stale), 'tables' => []];

        if ($only === '' || $only === 'entries') {
            $result['tables']['atlas_memory_entries'] = $this->backfillEntries($indexer, $reembedAll, $stale, $limit);
        }
        if ($only === '' || $only === 'verbatim') {
            $result['tables']['atlas_verbatim_memories'] = $this->backfillVerbatim($indexer, $reembedAll, $stale, $limit);
        }
        if ($only === '' || $only === 'notes') {
            $result['tables']['semantic_notes'] = $this->backfillSemanticNotes($noteIndexer, $reembedAll, $stale, $limit);
        }
        if ($only === '' || $only === 'attachments') {
            $result['tables']['ai_attachment_index_entries'] = $this->backfillAttachments($embeddings, $reembedAll, $stale, $limit);
        }

        if ($result['tables'] === []) {
            $result['status'] = 'skipped';
            $result['reason'] = "unknown --only value '{$only}' (use entries|verbatim|notes|attachments)";
        }

        return $this->report($result, self::SUCCESS);
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillEntries(AtlasMemorySemanticIndexer $indexer, bool $reembedAll, bool $stale, int $limit): array
    {
        if (! $this->tableReady('atlas_memory_entries')) {
            return ['status' => 'skipped', 'reason' => 'table, embedding, or provenance columns missing'];
        }

        return $this->backfillModels(
            AtlasMemoryEntry::query()->whereNull('deleted_at'),
            $reembedAll,
            $stale,
            $limit,
            fn (AtlasMemoryEntry $entry): string => $indexer->entryText($entry),
            fn (AtlasMemoryEntry $entry, string $_text): bool => $indexer->indexEntry($entry),
        );
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillVerbatim(AtlasMemorySemanticIndexer $indexer, bool $reembedAll, bool $stale, int $limit): array
    {
        if (! $this->tableReady('atlas_verbatim_memories')) {
            return ['status' => 'skipped', 'reason' => 'table, embedding, or provenance columns missing'];
        }

        return $this->backfillModels(
            AtlasVerbatimMemory::query()->whereNull('deleted_at'),
            $reembedAll,
            $stale,
            $limit,
            fn (AtlasVerbatimMemory $memory): string => $indexer->verbatimText($memory),
            fn (AtlasVerbatimMemory $memory, string $_text): bool => $indexer->indexVerbatim($memory),
        );
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillSemanticNotes(SemanticNoteIndexer $noteIndexer, bool $reembedAll, bool $stale, int $limit): array
    {
        if (! $this->tableReady('semantic_notes')) {
            return ['status' => 'skipped', 'reason' => 'table, embedding, or provenance columns missing'];
        }

        return $this->backfillModels(
            SemanticNote::query()->whereNull('deleted_at'),
            $reembedAll,
            $stale,
            $limit,
            fn (SemanticNote $note): string => $noteIndexer->embeddedTextForPath((string) $note->path),
            fn (SemanticNote $note, string $_text): bool => ! (bool) ($noteIndexer->indexFile((string) $note->path)['skipped'] ?? true),
        );
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillAttachments(EmbeddingService $embeddings, bool $reembedAll, bool $stale, int $limit): array
    {
        if (! $this->tableReady('ai_attachment_index_entries')) {
            return ['status' => 'skipped', 'reason' => 'table, embedding, or provenance columns missing'];
        }

        return $this->backfillModels(
            AiAttachmentIndexEntry::query(),
            $reembedAll,
            $stale,
            $limit,
            fn (AiAttachmentIndexEntry $entry): string => trim(((string) $entry->title)."\n".((string) $entry->excerpt)),
            fn (AiAttachmentIndexEntry $entry, string $text): bool => $this->storeEmbedding($embeddings, 'ai_attachment_index_entries', (string) $entry->getKey(), $text),
        );
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @param  callable(Model): string  $textForEmbedding
     * @param  callable(Model, string): bool  $embed
     * @return array<string,int|string>
     */
    private function backfillModels($query, bool $reembedAll, bool $stale, int $limit, callable $textForEmbedding, callable $embed): array
    {
        if (! $reembedAll && ! $stale) {
            $query->whereNull('embedding');
        }

        $embedded = 0;
        $skipped = 0;
        $processed = 0;
        $scanned = 0;

        foreach ($this->cursor($query, $limit) as $model) {
            $scanned++;
            $text = trim($textForEmbedding($model));
            if ($text === '') {
                $skipped++;

                continue;
            }

            if ($stale && ! $reembedAll && ! $this->needsEmbedding($model, $text)) {
                continue;
            }

            $processed++;
            $embed($model, $text) ? $embedded++ : $skipped++;
        }

        return [
            'status' => 'completed',
            'scanned' => $scanned,
            'processed' => $processed,
            'embedded' => $embedded,
            'skipped' => $skipped,
        ];
    }

    private function storeEmbedding(EmbeddingService $embeddings, string $table, string $id, string $text): bool
    {
        try {
            $vector = $embeddings->embedText($text);
            $embeddingModel = EmbeddingProvenance::modelId($embeddings->lastInfo());
            $embeddedContentHash = EmbeddingProvenance::contentHash($text);

            DB::update(
                "UPDATE {$table} SET embedding = ?::vector, embedding_model = ?, embedded_content_hash = ? WHERE id = ?",
                [$embeddings->vectorLiteral($vector), $embeddingModel, $embeddedContentHash, $id],
            );
        } catch (Throwable $throwable) {
            report($throwable);

            return false;
        }

        return true;
    }

    private function needsEmbedding(Model $model, string $text): bool
    {
        return $model->getAttribute('embedding') === null
            || trim((string) $model->getAttribute('embedding_model')) === ''
            || (string) $model->getAttribute('embedded_content_hash') !== EmbeddingProvenance::contentHash($text);
    }

    private function tableReady(string $table): bool
    {
        return DatabaseTableAvailability::has($table)
            && DatabaseTableAvailability::hasColumn($table, 'embedding')
            && DatabaseTableAvailability::hasColumn($table, 'embedding_model')
            && DatabaseTableAvailability::hasColumn($table, 'embedded_content_hash');
    }

    /**
     * @param  Builder<covariant Model>  $query
     * @return iterable<Model>
     */
    private function cursor($query, int $limit): iterable
    {
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->cursor();
    }

    private function mode(bool $reembedAll, bool $stale): string
    {
        if ($reembedAll) {
            return 'all';
        }
        if ($stale) {
            return 'stale';
        }

        return 'missing_only';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function report(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->jsonLine($payload);

            return $exit;
        }

        $this->info('atlas:memory:embed-backfill — '.($payload['status'] ?? 'unknown'));
        if (isset($payload['reason'])) {
            $this->line('  reason: '.$payload['reason']);
        }
        foreach ((array) ($payload['tables'] ?? []) as $table => $stats) {
            $this->line(sprintf(
                '  %s: %s (scanned=%s processed=%s embedded=%s skipped=%s)',
                $table,
                $stats['status'] ?? 'unknown',
                $stats['scanned'] ?? 0,
                $stats['processed'] ?? 0,
                $stats['embedded'] ?? 0,
                $stats['skipped'] ?? 0,
            ));
        }

        return $exit;
    }
}
