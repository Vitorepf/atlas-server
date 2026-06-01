<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService;
use Illuminate\Console\Command;
use Throwable;

/**
 * CLI surface for the runtime described by the canonical doc — the service
 * existed but had no operator-runnable command. Thin read-only wrapper over
 * build(); zero-arg, no side effects.
 *
 * @see docs/engineering-knowledge-base/atlas-self-construction-human-completion-receipt-closure-execution-pack-v1.md
 */
class AtlasSelfConstructionClosureExecutionPackCommand extends Command
{
    protected $signature = 'atlas:self-construction:closure-execution-pack {--json}';

    protected $description = 'Build the human completion receipt closure execution pack envelope.';

    public function handle(AtlasSelfConstructionHumanCompletionReceiptClosureExecutionPackService $pack): int
    {
        try {
            $result = $pack->build();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

        return self::SUCCESS;
    }
}
