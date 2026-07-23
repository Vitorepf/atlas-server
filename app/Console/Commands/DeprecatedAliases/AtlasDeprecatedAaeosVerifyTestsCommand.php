<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:verify-tests`.
 */
final class AtlasDeprecatedAaeosVerifyTestsCommand extends Command
{
    protected $signature = 'atlas:aaeos:verify-tests
        {--capability= : Restrict to a single doc id/slug or path substring (recommended — running ALL is expensive)}
        {--json : Print machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:aeos:verify-tests] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:verify-tests` (was `atlas:aaeos:verify-tests`). Forwarding…");

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

        return (int) Artisan::call('atlas:aeos:verify-tests', $params, $this->output);
    }
}
