<?php

namespace App\Console\Commands;

use App\Services\Semantic\SemanticNoteIndexer;
use App\Services\Semantic\VaultFileStore;
use Illuminate\Console\Command;

class SemanticIndexCommand extends Command
{
    protected $signature = 'atlas:semantic:index {--changed : Skip files whose hash did not change}';

    protected $description = 'Index markdown files from the Atlas semantic memory vault.';

    public function handle(SemanticNoteIndexer $indexer, VaultFileStore $vault): int
    {
        $vault->ensureVaultStructure();
        $stats = $indexer->indexAll(changedOnly: (bool) $this->option('changed'));

        $this->line(json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
