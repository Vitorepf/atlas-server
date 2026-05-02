<?php

namespace App\Console\Commands;

use App\Services\Ai\AiSkillStore;
use Illuminate\Console\Command;

class AiBootstrapSkillsCommand extends Command
{
    protected $signature = 'atlas:ai:bootstrap-skills';

    protected $description = 'Create Atlas master prompt and default skill files inside the AtlasVault.';

    public function handle(AiSkillStore $skills): int
    {
        $created = $skills->ensureStructure();

        $this->info(json_encode([
            'created' => $created->values()->all(),
            'created_count' => $created->count(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
