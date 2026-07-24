<?php

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasDevRuntimeService;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * CLI surface for the runtime described by the canonical doc — the service
 * existed but had no operator-runnable command. Thin read-only wrapper over
 * supportedFlows(); zero-arg, no side effects.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-runtime-intelligence.md
 */
class AtlasDevRuntimeFlowsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:dev:runtime-flows {--json}';

    protected $description = 'List the supported flows of the dev runtime intelligence service.';

    public function handle(AtlasDevRuntimeService $devRuntime): int
    {
        try {
            $result = $devRuntime->supportedFlows();
        } catch (Throwable $e) {
            $result = ['error' => $e::class, 'message' => $e->getMessage()];
        }

        $this->jsonLine($result);

        return self::SUCCESS;
    }
}
