<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:deferred-worker`.
 */
final class AtlasDeprecatedAaeosDeferredWorkerCommand extends Command
{
    protected $signature = 'atlas:aaeos:deferred-worker
        {--once : drain a single batch and exit}
        {--loop : run continuously with --interval sleep between batches}
        {--max=16 : maximum records claimed per batch}
        {--interval=5 : seconds between iterations when --loop}
        {--json : machine-readable JSON output}';

    protected $description = '[DEPRECATED → atlas:aeos:deferred-worker] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:deferred-worker` (was `atlas:aaeos:deferred-worker`). Forwarding…");

        $params = [];
        foreach ($this->arguments() as $key => $value) {
            if ($key === 'command') {
                continue;
            }
            $params[$key] = $value;
        }
        foreach ($this->options() as $key => $value) {
            if (in_array($key, ['help', 'quiet', 'verbose', 'version', 'ansi', 'no-ansi', 'no-interaction', 'env'], true)) {
                continue;
            }
            // Symfony options: pass as --key
            $params['--'.$key] = $value;
        }

        return (int) Artisan::call('atlas:aeos:deferred-worker', $params, $this->output);
    }
}
