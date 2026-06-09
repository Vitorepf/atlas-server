<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Models\AtlasVerbatimMemory;
use App\Services\Ai\Memory\AtlasMemorySemanticIndexer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * R1 — backfill REAL embeddings for existing Atlas Memory Core rows
 * (`atlas_memory_entries` + `atlas_verbatim_memories`) so recall can rank them
 * by vector similarity. New rows are embedded on-write by the registry/verbatim
 * services; this catches everything written before the embedding column existed.
 *
 * pgvector-only by design (the column + ivfflat index are pgsql). On sqlite or
 * when the real embedding engine is unavailable (no venv + no key) it reports
 * an HONEST skip instead of fabricating vectors — that is the canon.
 *
 * @see app/Services/Ai/Memory/AtlasMemorySemanticIndexer.php
 */
class AtlasMemoryEmbedBackfillCommand extends Command
{
    protected $signature = 'atlas:memory:embed-backfill
        {--only= : Limit to one table: entries|verbatim}
        {--missing-only : Only rows whose embedding is still NULL (default)}
        {--all : Re-embed every eligible row, not just rows missing an embedding}
        {--limit=0 : Max rows per table (0 = no limit)}
        {--json : Emit a machine-readable JSON receipt}';

    protected $description = 'Backfill real vector embeddings for existing Atlas memory + verbatim rows (pgvector).';

    public function handle(AtlasMemorySemanticIndexer $indexer): int
    {
        $only = (string) ($this->option('only') ?? '');
        $reembedAll = (bool) $this->option('all');
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

        $result = ['status' => 'completed', 'driver' => DB::getDriverName(), 'tables' => []];

        if ($only === '' || $only === 'entries') {
            $result['tables']['atlas_memory_entries'] = $this->backfillEntries($indexer, $reembedAll, $limit);
        }
        if ($only === '' || $only === 'verbatim') {
            $result['tables']['atlas_verbatim_memories'] = $this->backfillVerbatim($indexer, $reembedAll, $limit);
        }

        if ($result['tables'] === []) {
            $result['status'] = 'skipped';
            $result['reason'] = "unknown --only value '{$only}' (use entries|verbatim)";
        }

        return $this->report($result, self::SUCCESS);
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillEntries(AtlasMemorySemanticIndexer $indexer, bool $reembedAll, int $limit): array
    {
        if (! Schema::hasTable('atlas_memory_entries') || ! Schema::hasColumn('atlas_memory_entries', 'embedding')) {
            return ['status' => 'skipped', 'reason' => 'table or embedding column missing'];
        }

        $embedded = 0;
        $skipped = 0;
        $processed = 0;

        $query = AtlasMemoryEntry::query()->whereNull('deleted_at');
        if (! $reembedAll) {
            $query->whereNull('embedding');
        }

        foreach ($this->cursor($query, $limit) as $entry) {
            $processed++;
            $indexer->indexEntry($entry) ? $embedded++ : $skipped++;
        }

        return ['status' => 'completed', 'processed' => $processed, 'embedded' => $embedded, 'skipped' => $skipped];
    }

    /**
     * @return array<string,int|string>
     */
    private function backfillVerbatim(AtlasMemorySemanticIndexer $indexer, bool $reembedAll, int $limit): array
    {
        if (! Schema::hasTable('atlas_verbatim_memories') || ! Schema::hasColumn('atlas_verbatim_memories', 'embedding')) {
            return ['status' => 'skipped', 'reason' => 'table or embedding column missing'];
        }

        $embedded = 0;
        $skipped = 0;
        $processed = 0;

        $query = AtlasVerbatimMemory::query()->whereNull('deleted_at');
        if (! $reembedAll) {
            $query->whereNull('embedding');
        }

        foreach ($this->cursor($query, $limit) as $memory) {
            $processed++;
            $indexer->indexVerbatim($memory) ? $embedded++ : $skipped++;
        }

        return ['status' => 'completed', 'processed' => $processed, 'embedded' => $embedded, 'skipped' => $skipped];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return iterable<\Illuminate\Database\Eloquent\Model>
     */
    private function cursor($query, int $limit): iterable
    {
        if ($limit > 0) {
            $query->limit($limit);
        }

        return $query->cursor();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function report(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return $exit;
        }

        $this->info('atlas:memory:embed-backfill — '.($payload['status'] ?? 'unknown'));
        if (isset($payload['reason'])) {
            $this->line('  reason: '.$payload['reason']);
        }
        foreach ((array) ($payload['tables'] ?? []) as $table => $stats) {
            $this->line(sprintf(
                '  %s: %s (processed=%s embedded=%s skipped=%s)',
                $table,
                $stats['status'] ?? 'unknown',
                $stats['processed'] ?? 0,
                $stats['embedded'] ?? 0,
                $stats['skipped'] ?? 0,
            ));
        }

        return $exit;
    }
}
