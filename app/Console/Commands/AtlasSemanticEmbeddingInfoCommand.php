<?php

namespace App\Console\Commands;

use App\Services\Semantic\EmbeddingService;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI surface for the runtime described by the canonical doc — the service
 * existed but had no operator-runnable command. Thin read-only wrapper over
 * lastInfo(); zero-arg, no side effects.
 *
 * @see docs/engineering-knowledge-base/atlas-semantic-embedding-foundation.md
 */
class AtlasSemanticEmbeddingInfoCommand extends Command
{
    protected $signature = 'atlas:semantic:embedding-info {--json}';

    protected $description = 'Show the last embedding info from the semantic embedding foundation.';

    public function handle(EmbeddingService $embedding): int
    {
        try {
            $result = $embedding->lastInfo();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
