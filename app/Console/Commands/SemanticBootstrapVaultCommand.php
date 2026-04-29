<?php

namespace App\Console\Commands;

use App\Services\Semantic\VaultFileStore;
use Illuminate\Console\Command;

class SemanticBootstrapVaultCommand extends Command
{
    protected $signature = 'atlas:semantic:bootstrap';

    protected $description = 'Create the Atlas semantic memory vault structure and templates.';

    public function handle(VaultFileStore $vault): int
    {
        $created = $vault->ensureVaultStructure();

        $this->info('Vault path: '.$vault->rootPath());
        $this->line(json_encode(['created' => $created->values()->all()], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
