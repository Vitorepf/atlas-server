<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:memory:cognitive-immune-kernel`.
 */
final class AtlasDeprecatedAaeosMemoryCognitiveCommand extends Command
{
    protected $signature = 'atlas:aaeos:memory-cognitive-immune-learning-kernel
        {--class=strategic_insight_candidate : Input Class to classify}
        {--scope=session : candidate scope (global|workspace|project|task|domain|session|policy)}
        {--json : machine-readable JSON output (default true)}';

    protected $description = '[DEPRECATED → atlas:memory:cognitive-immune-kernel] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:memory:cognitive-immune-kernel` (was `atlas:aaeos:memory-cognitive-immune-learning-kernel`). Forwarding…");

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

        return (int) Artisan::call('atlas:memory:cognitive-immune-kernel', $params, $this->output);
    }
}
