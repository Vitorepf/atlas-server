<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Brain\AtlasMemoryJournal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * SIS8 (Obra #20) — `atlas:brain:replay`.
 *
 * Rebuilds the brain's memory rows from the append-only hash-chained disk
 * journal after a `DROP DATABASE` ("Postgres vira gado"). This is the reverse
 * gear the Carta de Autonomia requires: reversibility is the license that
 * replaces human approval — the Atlas may act on its own because every act
 * replays.
 *
 * Kill-test target: DROP → replay <5min → identical content digest; RTO ≤15min.
 * Proven by tests/Feature/Ai/Brain/MemoryJournalReplayTest.php.
 */
class AtlasBrainReplayCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:brain:replay
        {--journal= : Journal path (defaults to config atlas.brain.journal.path)}
        {--dry-run : Verify the chain and report the digest without writing rows}
        {--truncate : Delete existing memory rows before replaying (clean rebuild)}
        {--exclude-seq=* : Journal seq(s) to omit — the "replay-sem-a-entrada" reversal (implies --truncate)}
        {--json : Machine-readable output}';

    protected $description = 'Rebuild brain memory rows from the hash-chained journal (SIS8 reversibility).';

    public function handle(): int
    {
        $started = microtime(true);
        $journal = new AtlasMemoryJournal($this->option('journal') ?: null);

        $chain = $journal->verifyChain();
        if (! $chain['ok']) {
            return $this->report([
                'ok' => false,
                'stage' => 'verify_chain',
                'reason' => $chain['reason'],
                'broken_at' => $chain['broken_at'],
                'journal' => $journal->path(),
            ], self::FAILURE);
        }

        if (! Schema::hasTable('atlas_memory_entries')) {
            return $this->report([
                'ok' => false,
                'stage' => 'schema',
                'reason' => 'atlas_memory_entries table is missing',
                'journal' => $journal->path(),
            ], self::FAILURE);
        }

        $dryRun = (bool) $this->option('dry-run');
        $excluded = array_map('intval', (array) $this->option('exclude-seq'));
        // Excluding a seq is a reversal: rebuild the brain as if that mutation
        // never happened, which only holds from a clean slate.
        $truncate = (bool) $this->option('truncate') || $excluded !== [];
        $applied = 0;

        if (! $dryRun) {
            if ($truncate) {
                AtlasMemoryEntry::withTrashed()->cursor()->each->forceDelete();
            }

            // Only fill columns that actually exist in this schema — a defensive
            // guard at a trust boundary (reconstructing the brain) against a
            // journal written under a slightly different schema.
            $columns = array_flip(Schema::getColumnListing('atlas_memory_entries'));

            foreach ($journal->read() as $record) {
                if (in_array((int) ($record['seq'] ?? -1), $excluded, true)) {
                    continue; // omitted mutation — the reversal
                }
                $id = (string) ($record['id'] ?? '');
                if ($id === '' || ! is_array($record['attributes'] ?? null)) {
                    continue;
                }

                $model = AtlasMemoryEntry::withTrashed()->find($id) ?? new AtlasMemoryEntry;
                $model->forceFill(array_intersect_key($record['attributes'], $columns));
                $model->timestamps = false; // preserve journaled created_at/updated_at
                $model->saveQuietly();       // no events → no re-journaling
                $applied++;
            }
        }

        $digest = AtlasMemoryJournal::rowsDigest(
            AtlasMemoryEntry::withTrashed()->orderBy('id')->get(),
        );

        return $this->report([
            'ok' => true,
            'journal' => $journal->path(),
            'chain_lines' => $chain['count'],
            'rows_applied' => $applied,
            'rows_present' => AtlasMemoryEntry::withTrashed()->count(),
            'content_digest' => $digest,
            'excluded_seqs' => $excluded,
            'dry_run' => $dryRun,
            'rto_seconds' => round(microtime(true) - $started, 3),
        ], self::SUCCESS);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function report(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line($this->encode($payload));

            return $exit;
        }

        foreach ($payload as $key => $value) {
            $this->line(sprintf('  %-16s %s', $key, is_scalar($value) || $value === null ? var_export($value, true) : json_encode($value)));
        }

        return $exit;
    }
}
