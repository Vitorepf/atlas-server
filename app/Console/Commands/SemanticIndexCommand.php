<?php

namespace App\Console\Commands;

use App\Services\Semantic\SemanticNoteIndexer;
use App\Services\Semantic\VaultFileStore;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class SemanticIndexCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:semantic:index {--changed : Skip files whose hash did not change}';

    protected $description = 'Index markdown files from the Atlas semantic memory vault.';

    public function handle(SemanticNoteIndexer $indexer, VaultFileStore $vault): int
    {
        $vault->ensureVaultStructure();
        $stats = $indexer->indexAll(changedOnly: (bool) $this->option('changed'));

        $this->line($this->encode($stats));

        return self::SUCCESS;
    }
}
