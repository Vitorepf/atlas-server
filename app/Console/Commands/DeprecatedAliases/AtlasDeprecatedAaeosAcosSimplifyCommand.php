<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:acos:simplify-cycle`.
 */
final class AtlasDeprecatedAaeosAcosSimplifyCommand extends Command
{
    protected $signature = 'atlas:aaeos-acos:simplify-cycle
        {action=plan : plan|status|prompts}
        {--dry-run=1 : 1=plan only (default); 0 reserved for future execute}
        {--php=php : php binary for emitted commands}
        {--client=aaeos-acos-cycle : opaque client id}
        {--queue-claimable=0 : claimable queue depth hint}
        {--replenish-target=5 : top-up target when queue is dry}
        {--json : machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:acos:simplify-cycle] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:acos:simplify-cycle` (was `atlas:aaeos-acos:simplify-cycle`). Forwarding…");

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

        return (int) Artisan::call('atlas:acos:simplify-cycle', $params, $this->output);
    }
}
