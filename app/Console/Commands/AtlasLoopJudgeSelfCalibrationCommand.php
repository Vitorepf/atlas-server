<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopJudgeSelfCalibrationService;
use Illuminate\Console\Command;

/**
 * L6-2: calibrate the judge from historical RED-canary fix-forward evidence.
 */
final class AtlasLoopJudgeSelfCalibrationCommand extends Command
{
    protected $signature = 'atlas:loop:judge-calibration
        {--window-hours= : Lookback window}
        {--limit= : Max historical fix-forward cases to inspect}
        {--timeout= : Verifier command timeout seconds}
        {--manifest= : Optional manifest output path}
        {--packet-dir= : Optional verifier packet directory}
        {--write : Write manifest and verifier packets}
        {--strict : Exit non-zero unless at least one frozen verifier is ready}
        {--json : Machine-readable JSON output}';

    protected $description = 'Turn historical RED-canary fix-forward cases into frozen verifier candidates that tighten the Loop judge.';

    public function handle(AtlasLoopJudgeSelfCalibrationService $service): int
    {
        $payload = $service->calibrate(array_filter([
            'window_hours' => $this->intOption('window-hours'),
            'limit' => $this->intOption('limit'),
            'timeout_seconds' => $this->intOption('timeout'),
            'manifest_path' => $this->stringOption('manifest'),
            'packet_dir' => $this->stringOption('packet-dir'),
            'write' => (bool) $this->option('write'),
        ], static fn (mixed $value): bool => $value !== null));

        $exit = (bool) $this->option('strict') && ! (bool) ($payload['completion_claim_allowed'] ?? false)
            ? self::FAILURE
            : self::SUCCESS;

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop judge self-calibration');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Historical cases', (string) data_get($payload, 'counts.historical_fix_forward_cases', 0));
        $this->components->twoColumnDetail('Ready verifiers', (string) data_get($payload, 'counts.ready_verifier_candidates', 0));
        $this->components->twoColumnDetail('Manifest', (string) data_get($payload, 'artifacts.manifest_path', '-'));

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    private function stringOption(string $key): ?string
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        return $raw !== '' ? $raw : null;
    }
}
