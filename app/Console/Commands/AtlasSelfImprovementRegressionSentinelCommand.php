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
        {--fixture= : Fixture proof: safe|uncovered-drift}
        {--receipt= : Receipt path; defaults to atlas.loop.self_improvement_regression_oracle.receipt_path}
        {--write-receipt : Persist the report receipt}
        {--json}
        {--strict : Exit non-zero unless status is clear (no severe findings)}';

    protected $description = 'Atlas Self-Improvement Regression Sentinel — finds hidden regressions. Read-model.';

    public function handle(AtlasSelfImprovementRegressionSentinelService $service): int
    {
        $fixture = trim((string) ($this->option('fixture') ?: ''));
        if ($fixture !== '') {
            $payload = $this->fixtureSnapshots($fixture);
            if ($payload === null) {
                $report = [
                    'schema_version' => 'atlas.self_improvement.regression_sentinel.command.v1',
                    'status' => 'invalid_input',
                    'reason' => 'unknown_regression_sentinel_fixture',
                    'allowed_fixtures' => ['safe', 'uncovered-drift'],
                ];
                $this->emit($report);

                return self::FAILURE;
            }
            [$before, $after, $diff] = $payload;
        } else {
            $before = $this->resolveJsonOption('before-snapshot') ?? [];
            $after = $this->resolveJsonOption('after-snapshot') ?? [];
            $diff = $this->resolveJsonOption('diff') ?? [];
        }

        $report = $service->scan($before, $after, $diff);
        if ($fixture !== '') {
            $report['fixture'] = $fixture;
        }
        if ((bool) $this->option('write-receipt') || trim((string) ($this->option('receipt') ?: '')) !== '') {
            $report['receipt_path'] = $this->writeReceipt($report);
        }
        $this->emit($report);

        if (! (bool) $this->option('strict')) {
            return self::SUCCESS;
        }

        return $report['status'] === AtlasSelfImprovementRegressionSentinelService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @return array{array<string,mixed>,array<string,mixed>,array<string,mixed>}|null
     */
    private function fixtureSnapshots(string $fixture): ?array
    {
        $before = [
            'observed_behavior' => [
                'contracts' => [
                    [
                        'id' => 'atlas.invoice.total.rounding',
                        'source' => 'live_observation_fixture',
                        'observed_value' => 100.0,
                        'covered_by_test' => false,
                        'tolerance_abs' => 0.01,
                    ],
                ],
            ],
        ];

        return match ($fixture) {
            'safe' => [
                $before,
                [
                    'observed_behavior' => [
                        'contracts' => [
                            [
                                'id' => 'atlas.invoice.total.rounding',
                                'source' => 'live_observation_fixture',
                                'observed_value' => 100.0,
                                'covered_by_test' => false,
                                'tolerance_abs' => 0.01,
                            ],
                        ],
                    ],
                ],
                ['test_spec_status' => 'green', 'updated_canonical_docs' => false],
            ],
            'uncovered-drift' => [
                $before,
                [
                    'observed_behavior' => [
                        'contracts' => [
                            [
                                'id' => 'atlas.invoice.total.rounding',
                                'source' => 'live_observation_fixture',
                                'observed_value' => 95.0,
                                'covered_by_test' => false,
                                'tolerance_abs' => 0.01,
                            ],
                        ],
                    ],
                ],
                [
                    'test_spec_status' => 'green',
                    'updated_canonical_docs' => false,
                    'behavior_drift_not_covered_by_test' => true,
                ],
            ],
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $report
     */
    private function writeReceipt(array $report): string
    {
        $path = trim((string) ($this->option('receipt') ?: ''));
        if ($path === '') {
            $path = (string) config(
                'atlas.loop.self_improvement_regression_oracle.receipt_path',
                storage_path('app/atlas/evidence/self-improvement-regression-oracle.json'),
            );
        }

        $dir = dirname($path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        file_put_contents(
            $path,
            json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL,
        );

        return $path;
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
