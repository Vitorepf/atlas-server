<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\Watchdog\AtlasAcosWatchdogHealthService;
use Illuminate\Console\Command;

final class AtlasCognitionScorecardWatchdogCommand extends Command
{
    protected $signature = 'atlas:cognition:scorecard:watchdog
        {--json : Emit canonical JSON}';

    protected $description = 'PIP-08 — record ACOS scorecard strict stability evidence.';

    public function handle(AtlasAcosWatchdogHealthService $health): int
    {
        $payload = $health->pipelineStabilityReport();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('pipeline_score', (string) ($payload['pipeline_score_out_of_10'] ?? 0));
            $this->components->twoColumnDetail('partial_count', (string) ($payload['partial_count'] ?? 0));
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
