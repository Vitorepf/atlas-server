<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfImprovement\AtlasSelfImprovementRegressionSentinelService;
use Illuminate\Console\Command;
use Throwable;

final class AtlasSelfImprovementRegressionSentinelCommand extends Command
{
    protected $signature = 'atlas:self-improvement:regression-sentinel
        {--before-snapshot=}
        {--after-snapshot=}
        {--diff=}
        {--json}
        {--strict : Exit non-zero unless status is clear (no severe findings)}';

    protected $description = 'Atlas Self-Improvement Regression Sentinel — finds hidden regressions. Read-model.';

    public function handle(AtlasSelfImprovementRegressionSentinelService $service): int
    {
        $before = $this->resolveJsonOption('before-snapshot') ?? [];
        $after = $this->resolveJsonOption('after-snapshot') ?? [];
        $diff = $this->resolveJsonOption('diff') ?? [];

        $report = $service->scan($before, $after, $diff);
        $this->emit($report);

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $report['status'] === AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function resolveJsonOption(string $key): ?array
    {
        $raw = $this->option($key);
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        $raw = trim($raw);
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            if (! is_file($path)) {
                return null;
            }
            $raw = (string) file_get_contents($path);
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));

            return;
        }
        $this->components->twoColumnDetail('schema_version', (string) ($payload['schema_version'] ?? '—'));
        $this->components->twoColumnDetail('status', (string) ($payload['status'] ?? '—'));
        $counts = $payload['finding_counts'] ?? [];
        if (is_array($counts)) {
            $this->components->twoColumnDetail(
                'findings',
                sprintf('severe=%d warn=%d info=%d', (int) ($counts['severe'] ?? 0), (int) ($counts['warn'] ?? 0), (int) ($counts['info'] ?? 0)),
            );
        }
    }
}
