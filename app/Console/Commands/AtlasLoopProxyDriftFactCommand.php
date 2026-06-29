<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopProxyDriftFactDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopProxyDriftFactDetector::evaluate()} at the operator surface: for a campaign id
 * it emits the deterministic proxy-drift facts (drifting bool, drift_ratio, proxy-vs-material evidence) so the
 * operator can SEE origination sliding toward proxy (behaviour-preserving) work. Read-only.
 */
final class AtlasLoopProxyDriftFactCommand extends Command
{
    protected $signature = 'atlas:loop:proxy-drift-facts {--campaign=} {--sample-size=20} {--json}';

    protected $description = 'Read-only proxy-drift facts for a campaign (is origination sliding toward proxy work?).';

    public function handle(AtlasLoopProxyDriftFactDetector $detector): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'proxy-drift-facts requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = $detector->evaluate($campaign, max(1, (int) $this->option('sample-size')));
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
