<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:maturity`.
 */
final class AtlasDeprecatedAaeosMaturityCommand extends Command
{
    protected $signature = 'atlas:aaeos:maturity
        {--capability= : Restrict to a single doc id/slug or path substring}
        {--coverage : Report corpus-wide doc<->runtime coverage instead of the per-doc ledger}
        {--strict : Exit non-zero when any doc over-claims (claimed rank > computed rank)}
        {--json : Print machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:aeos:maturity] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:maturity` (was `atlas:aaeos:maturity`). Forwarding…");

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

        return (int) Artisan::call('atlas:aeos:maturity', $params, $this->output);
    }
}
