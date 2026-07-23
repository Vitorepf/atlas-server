<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:department-registry`.
 */
final class AtlasDeprecatedAaeosDepartmentRegistryCommand extends Command
{
    protected $signature = 'atlas:aaeos:department-registry
        {--registry= : JSON list of department contracts to validate}
        {--json : Print machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:aeos:department-registry] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:department-registry` (was `atlas:aaeos:department-registry`). Forwarding…");

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

        return (int) Artisan::call('atlas:aeos:department-registry', $params, $this->output);
    }
}
