<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Observability\AtlasLoopStagnationAlarmDetector;
use Illuminate\Console\Command;

/**
 * Arms the dormant {@see AtlasLoopStagnationAlarmDetector::evaluate()} at the operator surface: for a campaign
 * id it emits the deterministic stagnation alarm facts (stagnated bool, reason_code, decision/merge/cert counts)
 * so the operator can SEE a campaign claiming work but making no merging progress. Read-only.
 */
final class AtlasLoopStagnationAlarmCommand extends Command
{
    protected $signature = 'atlas:loop:stagnation-alarm {--campaign=} {--window=3600} {--json}';

    protected $description = 'Read-only stagnation alarm for a campaign (claims without merging progress in window).';

    public function handle(AtlasLoopStagnationAlarmDetector $detector): int
    {
        $campaign = trim((string) $this->option('campaign'));
        if ($campaign === '') {
            $this->line((string) json_encode([
                'outcome' => 'refused',
                'reason' => 'usage_error',
                'message' => 'stagnation-alarm requires --campaign=<id>',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }

        $facts = $detector->evaluate($campaign, max(1, (int) $this->option('window')));
        $this->line((string) json_encode($facts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
