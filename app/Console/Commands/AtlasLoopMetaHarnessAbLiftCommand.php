<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMetaHarnessAbLiftService;
use Illuminate\Console\Command;

/**
 * L6-1: read-only A/B lift measurement for meta-harness Loop work.
 */
final class AtlasLoopMetaHarnessAbLiftCommand extends Command
{
    protected $signature = 'atlas:loop:meta-harness-ab-lift
        {--window-hours= : Lookback window}
        {--min-cases= : Minimum cases per arm}
        {--min-lift= : Minimum positive certification-rate lift}
        {--max-tasks= : Max tasks to scan}
        {--strict : Exit non-zero unless live positive lift is proven}
        {--json : Machine-readable JSON output}';

    protected $description = 'Measure whether admissible meta-harness Loop work improves certification rate versus ordinary Loop work.';

    public function handle(AtlasLoopMetaHarnessAbLiftService $service): int
    {
        $payload = $service->measure(array_filter([
            'window_hours' => $this->intOption('window-hours'),
            'min_cases_per_arm' => $this->intOption('min-cases'),
            'min_lift' => $this->floatOption('min-lift'),
            'max_tasks' => $this->intOption('max-tasks'),
        ], static fn (mixed $value): bool => $value !== null));

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['completion_claim_allowed'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop meta-harness A/B lift');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Meta cases', (string) data_get($payload, 'arms.meta_harness.case_count', 0));
        $this->components->twoColumnDetail('Ordinary cases', (string) data_get($payload, 'arms.ordinary.case_count', 0));
        $this->components->twoColumnDetail('Lift', (string) data_get($payload, 'lift.certification_rate_delta', 'n/a'));

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    private function floatOption(string $key): ?float
    {
        $raw = trim((string) ($this->option($key) ?? ''));
        if ($raw === '' || ! is_numeric($raw)) {
            return null;
        }

        return (float) $raw;
    }
}
