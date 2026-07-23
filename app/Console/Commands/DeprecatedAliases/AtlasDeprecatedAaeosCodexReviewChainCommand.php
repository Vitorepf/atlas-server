<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:review:codex-chain-contract`.
 */
final class AtlasDeprecatedAaeosCodexReviewChainCommand extends Command
{
    protected $signature = 'atlas:aaeos:codex-review-chain-contract {--json : machine-readable JSON output (default true)}';

    protected $description = '[DEPRECATED → atlas:review:codex-chain-contract] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:review:codex-chain-contract` (was `atlas:aaeos:codex-review-chain-contract`). Forwarding…");

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

        return (int) Artisan::call('atlas:review:codex-chain-contract', $params, $this->output);
    }
}
