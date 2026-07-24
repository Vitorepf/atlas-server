<?php

namespace App\Console\Commands;

use App\Services\Ai\Surface\ConstelacaoPositionsService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI surface for the Constelação positions read model — the doc described the
 * runtime but the service had no operator-runnable command. Thin wrapper over
 * positions(); read-only.
 *
 * @see docs/engineering-knowledge-base/atlas-constelacao-surface.md
 */
class AtlasConstelacaoPositionsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:surface:constelacao-positions {--json}';

    protected $description = 'Show the Constelação surface positions read model.';

    public function handle(ConstelacaoPositionsService $positions): int
    {
        try {
            $result = $positions->positions();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->jsonLine($result);

        return self::SUCCESS;
    }
}
