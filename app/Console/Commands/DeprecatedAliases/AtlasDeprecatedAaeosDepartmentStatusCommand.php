<?php

declare(strict_types=1);

namespace App\Console\Commands\DeprecatedAliases;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;

/**
 * Deprecated alias — TRI-HYGIENE W2. Prefer `atlas:aeos:department-status`.
 */
final class AtlasDeprecatedAaeosDepartmentStatusCommand extends Command
{
    protected $signature = 'atlas:aaeos:department-status
        {--quality-bar : Include the quality-bar breach signal emission}
        {--claim-file= : Path to a JSON completion claim to validate against the Definition of Done}
        {--repair-iteration= : Current repair-loop iteration to guard (max-3 contract before escalation)}
        {--veto-events= : Path to a JSON list of veto events to replay through the veto-propagation watchdog}
        {--doc-maturity= : Path to a JSON sections map to classify DOC L0..L4 maturity}
        {--phase-gates= : Path to a JSON phase_outputs map (intent/spec_pack/task_pack) to evaluate runbook gate signals}
        {--cognitive-immune-input= : Path to a JSON {text, metadata} capture to classify through the cognitive immune input router}
        {--json : Print machine-readable JSON}';

    protected $description = '[DEPRECATED → atlas:aeos:department-status] TRI-HYGIENE wrapper; will be removed after one cycle.';

    public function handle(): int
    {
        $this->components->warn("DEPRECATED: use `atlas:aeos:department-status` (was `atlas:aaeos:department-status`). Forwarding…");

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

        return (int) Artisan::call('atlas:aeos:department-status', $params, $this->output);
    }
}
