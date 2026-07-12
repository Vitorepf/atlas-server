<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideReplayDivergenceService;
use Illuminate\Console\Command;

final class AtlasDecideReplayDivergenceCommand extends Command
{
    protected $signature = 'atlas:decide:replay-divergence
        {--consultations= : Override gateway_consultations JSONL path}
        {--live-outcomes= : Override live_outcomes JSONL path for no-write hash checks}
        {--min-n= : Minimum replayed consultations per cell}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when global denominator is insufficient}';

    protected $description = 'MULTK-03 — read-only decision replay divergence over gateway consultations.';

    public function handle(AtlasDecideReplayDivergenceService $service): int
    {
        $consultations = trim((string) ($this->option('consultations') ?: ''));
        $liveOutcomes = trim((string) ($this->option('live-outcomes') ?: ''));
        $report = $service->report(
            consultationsPath: $consultations !== '' ? $consultations : null,
            liveOutcomesPath: $liveOutcomes !== '' ? $liveOutcomes : null,
            minN: $this->intOption('min-n') ?? 10,
        );

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Decision replay divergence</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Replayed', (string) ($report['n_replayed'] ?? 0));
            $this->components->twoColumnDetail('Divergence rate', (string) ($report['divergence_rate'] ?? 'n/a'));
        }

        if ((bool) $this->option('strict') && ($report['status'] ?? null) !== 'ok') {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);
        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
