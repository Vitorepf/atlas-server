<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionFinalCompletionHumanGateService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI surface for the runtime described by the canonical doc — the service
 * existed but had no operator-runnable command. Thin read-only wrapper over
 * build(); zero-arg, no side effects.
 *
 * @see docs/engineering-knowledge-base/final-completion-human-gate-endgame-v1.md
 */
class AtlasSelfConstructionFinalCompletionGateCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:self-construction:final-completion-gate {--json}';

    protected $description = 'Build the self-construction final completion human-gate endgame envelope.';

    public function handle(AtlasSelfConstructionFinalCompletionHumanGateService $gate): int
    {
        try {
            $result = $gate->build();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->jsonLine($result);

        return self::SUCCESS;
    }
}
