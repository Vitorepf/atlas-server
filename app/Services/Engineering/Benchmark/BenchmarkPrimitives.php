<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AiTraceMetricSummary;
use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkResult;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReleaseGateAlertService;
use App\Services\Engineering\EngineeringBenchmarkInput;
use App\Services\Engineering\EngineeringStringListNormalizer;

class BenchmarkPrimitives
{
    public function uniqueReasonStrings(array ...$reasonGroups): array
    {
        return EngineeringStringListNormalizer::uniqueStringCasts(array_merge(...$reasonGroups));
    }

    public function runsCostMicrousd(Collection $runs): ?int
    {
        $cost = $runs
            ->sum(fn (AtlasEngineeringBenchmarkRun $run): int => max(0, (int) ($run->cost_microusd ?? data_get($run->summary_json ?? [], 'quality_metrics.cost_microusd', 0))));

        return $cost > 0 ? $cost : null;
    }

    public function rate(int|float $numerator, int|float $denominator): ?float
    {
        if ($denominator <= 0) {
            return null;
        }

        return round(((float) $numerator / (float) $denominator) * 100, 2);
    }

    public function prettyJson(mixed $value): string
    {
        $json = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);

        return (is_string($json) ? $json : 'null')."\n";
    }

    public function markdownText(string $value): string
    {
        return str_replace(["\r", "\n"], ' ', trim($value));
    }

    public function markdownInline(string $value): string
    {
        return str_replace('`', "'", $this->markdownText($value));
    }

    public function markdownCell(string $value): string
    {
        return str_replace('|', '\\|', $this->markdownText($value));
    }

    public function markdownNumber(mixed $value): string
    {
        return is_numeric($value) ? (string) $value : '-';
    }

    public function markdownPercent(mixed $value): string
    {
        return is_numeric($value) ? ((string) $value).'%' : '-';
    }

    public function canonicalJsonForHash(mixed $value): string
    {
        $encoded = json_encode(
            $this->canonicalSortForHash($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );

        return is_string($encoded) ? $encoded : '';
    }

    public function canonicalSortForHash(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalSortForHash($item), $value);
        }
        ksort($value);
        foreach ($value as $key => $sub) {
            $value[$key] = $this->canonicalSortForHash($sub);
        }

        return $value;
    }

    public function hashableReplayPacket(mixed $packet): array
    {
        $packet = is_array($packet) ? $packet : [];

        return Arr::except($packet, ['created_at']);
    }

    public function redactWorkspace(array $options, bool $redact = true): array
    {
        foreach (['workspace', 'claude_code_baseline_workspace'] as $key) {
            if (! isset($options[$key]) || ! is_string($options[$key]) || trim($options[$key]) === '') {
                continue;
            }

            $workspace = $this->workspaceFrom($options[$key]) ?: trim($options[$key]);
            if ($redact) {
                $options[$key.'_hash'] = hash('sha256', $workspace);
                unset($options[$key]);

                continue;
            }

            $options[$key] = $workspace;
        }

        return $options;
    }

    public function durationMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    public function workspaceFrom(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $workspace = trim($value);
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }

    public function arrayValue(mixed $value): array
    {
        return is_array($value) ? $value : [];
    }

    public function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }

    public function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    public function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    public function slug(mixed $value): string
    {
        $slug = Str::slug((string) ($this->nonEmptyString($value) ?: 'engineering-benchmark'));

        return $slug !== '' ? $slug : 'engineering-benchmark';
    }

    public function caseCode(mixed $value): string
    {
        $slug = Str::slug((string) ($this->nonEmptyString($value) ?: Str::uuid()->toString()), '_');

        return $slug !== '' ? $slug : 'benchmark_case';
    }

    public function harnessVersion(): string
    {
        return 'runner-v1.visual-smoke';
    }
}
