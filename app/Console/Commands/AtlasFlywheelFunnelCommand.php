<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cognition\AcosProgram\AtlasFlywheelFunnelService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasFlywheelFunnelCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:flywheel:funnel
        {--outcomes= : Override live outcomes JSONL path}
        {--denominator-min= : Minimum denominator before a stage is measured}
        {--json : Emit canonical JSON}
        {--strict : Exit non-zero when the funnel has no signal}';

    protected $description = 'MULTX-02 — read-only M leakage funnel by executor and window.';

    public function handle(AtlasFlywheelFunnelService $service): int
    {
        $path = trim((string) ($this->option('outcomes') ?: ''));
        $report = $service->report(
            outcomesPath: $path !== '' ? $path : null,
            denominatorMin: $this->intOption('denominator-min') ?? 1,
        );

        if ((bool) $this->option('json')) {
            $this->line($this->encode($report));
        } else {
            $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Flywheel funnel</>', (string) ($report['status'] ?? 'unknown'));
            $this->components->twoColumnDetail('Outcome rows', (string) data_get($report, 'source.outcome_rows', 0));
            $this->components->twoColumnDetail('Executors', (string) count((array) ($report['by_executor'] ?? [])));
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
