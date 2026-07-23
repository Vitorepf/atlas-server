<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:choreography-status`.
 */
final class AtlasDeprecatedAaeosChoreographyStatusCommand extends Command
{
    protected $signature = 'atlas:aaeos:choreography-status
        {--veto= : Evaluate a veto raised by a department (security|architect|review|operator)}
        {--repair-iteration= : Evaluate the repair-loop decision at this iteration}
        {--json : Print machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:aeos:choreography-status] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:choreography-status` (was `atlas:aaeos:choreography-status`). Forwarding…");

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

        return (int) Artisan::call('atlas:aeos:choreography-status', $params, $this->output);
    }
}
