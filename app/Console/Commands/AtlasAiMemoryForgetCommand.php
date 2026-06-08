<?php

namespace App\Console\Commands;

use App\Models\AtlasMemoryEntry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * The operator's "prune from the Sunday digest" handle: ARCHIVE a memory entry the
 * autonomous loop saved that they don't want, or --restore it. Archiving is
 * non-destructive (status=archived + archived_at; never a hard delete), so every
 * prune is fully reversible — the operator can always change their mind.
 */
class AtlasAiMemoryForgetCommand extends Command
{
    protected $signature = 'atlas:ai:memory-forget
        {id : AtlasMemoryEntry id to archive (or restore)}
        {--restore : Restore a previously archived entry instead of archiving it}
        {--json : Print machine-readable JSON}';

    protected $description = 'Archive (or --restore) an Atlas memory entry — non-destructive and reversible (the Sunday-digest prune handle).';

    public function handle(): int
    {
        if (! Schema::hasTable('atlas_memory_entries')) {
            $this->warn('Table atlas_memory_entries unavailable — nothing to do.');

            return self::SUCCESS;
        }

        $id = (string) $this->argument('id');
        $entry = AtlasMemoryEntry::query()->where('id', $id)->first();
        if ($entry === null) {
            $this->error('Memory entry not found: '.$id);

            return self::FAILURE;
        }

        $restore = (bool) $this->option('restore');
        if ($restore) {
            $entry->forceFill(['status' => 'active', 'archived_at' => null])->save();
            $action = 'restored';
        } else {
            $entry->forceFill(['status' => 'archived', 'archived_at' => now()])->save();
            $action = 'archived';
        }

        $result = [
            'schema_version' => 'atlas.ai.memory_forget.v1',
            'id' => $id,
            'action' => $action,
            'reversible' => true,
            'undo' => $restore
                ? 'php artisan atlas:ai:memory-forget '.$id
                : 'php artisan atlas:ai:memory-forget '.$id.' --restore',
        ];

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Memory entry '.$id.' '.$action.' (non-destructive).');
        $this->line('Undo: '.$result['undo']);

        return self::SUCCESS;
    }
}
