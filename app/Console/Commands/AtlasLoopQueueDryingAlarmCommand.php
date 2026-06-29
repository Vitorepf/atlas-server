<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopQueueDryingAlarmDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopQueueDryingAlarmDetector::evaluate()} at the operator surface: for a campaign
 * id it emits the deterministic queue-drying alarm facts (drying bool, slope_signal, bucketed evidence) so the
 * operator can SEE a campaign's work supply trending toward empty. Read-only.
 */
final class AtlasLoopQueueDryingAlarmCommand extends Command
{
    protected $signature = 'atlas:loop:queue-drying-alarm {--campaign=} {--window=7200} {--json}';

    protected $description = 'Read-only queue-drying alarm for a campaign (is the work supply trending toward empty?).';

    public function handle(AtlasLoopQueueDryingAlarmDetector $detector): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'queue-drying-alarm requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = $detector->evaluate($campaign, max(1, (int) $this->option('window')));
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
