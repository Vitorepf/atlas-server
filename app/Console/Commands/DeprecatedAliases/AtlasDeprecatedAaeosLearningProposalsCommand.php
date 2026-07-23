<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:learning:proposals-decision`.
 */
final class AtlasDeprecatedAaeosLearningProposalsCommand extends Command
{
    protected $signature = 'atlas:aaeos:learning-proposals {--json : Machine-readable JSON output}';

    protected $description = '[DEPRECATED → atlas:learning:proposals-decision] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:learning:proposals-decision` (was `atlas:aaeos:learning-proposals`). Forwarding…");

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

        return (int) Artisan::call('atlas:learning:proposals-decision', $params, $this->output);
    }
}
