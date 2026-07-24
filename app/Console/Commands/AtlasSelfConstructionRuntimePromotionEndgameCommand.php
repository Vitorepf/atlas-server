<?php

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\NativeImplementation\AtlasSelfConstructionRuntimePromotionEndgameService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI surface for the runtime described by the canonical doc — the service
 * existed but had no operator-runnable command. Thin read-only wrapper over
 * build(); zero-arg, no side effects.
 *
 * @see docs/engineering-knowledge-base/runtime-promotion-endgame-v1.md
 */
class AtlasSelfConstructionRuntimePromotionEndgameCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:self-construction:runtime-promotion-endgame {--json}';

    protected $description = 'Build the self-construction runtime promotion endgame envelope.';

    public function handle(AtlasSelfConstructionRuntimePromotionEndgameService $endgame): int
    {
        try {
            $result = $endgame->build();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->jsonLine($result);

        return self::SUCCESS;
    }
}
