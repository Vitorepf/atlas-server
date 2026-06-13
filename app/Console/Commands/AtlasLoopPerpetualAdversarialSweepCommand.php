<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopPerpetualAdversarialSweepService;
use Illuminate\Console\Command;

/**
 * L5-13: scheduled perpetual adversarial sweep for Loop safety findings.
 */
final class AtlasLoopPerpetualAdversarialSweepCommand extends Command
{
    protected $signature = 'atlas:loop:perpetual-sweep
        {--write : Persist LOW/HIGH findings into the governed backlog manifest}
        {--max-findings= : Max findings for this sweep}
        {--manifest-path= : Explicit manifest path}
        {--strict : Exit non-zero when the sweep cannot produce a verdict}
        {--json : Machine-readable JSON output}';

    protected $description = 'Run the fortnightly Loop adversarial safety sweep and park/queue findings safely.';

    public function handle(AtlasLoopPerpetualAdversarialSweepService $service): int
    {
        $payload = $service->sweep(array_filter([
            'write' => (bool) $this->option('write'),
            'max_findings' => $this->intOption('max-findings'),
            'manifest_path' => $this->stringOption('manifest-path'),
        ], static fn (mixed $value): bool => $value !== null));

        $exit = self::SUCCESS;
        if ((bool) $this->option('strict') && ! (bool) data_get($payload, 'verdict.produced', false)) {
            $exit = self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info('Atlas Loop perpetual adversarial sweep');
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Findings', (string) ($payload['finding_count'] ?? 0));
        $this->components->twoColumnDetail('HIGH', (string) ($payload['high_count'] ?? 0));
        $this->components->twoColumnDetail('LOW', (string) ($payload['low_count'] ?? 0));
        $this->components->twoColumnDetail('Actions', (string) ($payload['actions_count'] ?? 0));
        $this->components->twoColumnDetail('Verdict', (string) data_get($payload, 'verdict.result', 'unknown'));

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
