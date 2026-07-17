<?php

namespace App\Services\Ai\Arena;

use App\Services\Ai\Rivals\Support\RunPaths;
use RuntimeException;

final class ArenaMeasurementStore
{
    /** @return list<string> */
    public function suites(): array
    {
        $configured = array_values(array_filter((array) config('atlas_arena.suites', []), 'is_string'));
        if ($configured !== []) {
            return $configured;
        }

        return array_keys($this->weights());
    }

    /** @return array<string, float> */
    public function weights(): array
    {
        $weights = [];
        foreach ((array) config('atlas_arena.weights', []) as $suite => $weight) {
            if (is_string($suite) && is_numeric($weight)) {
                $weights[$suite] = (float) $weight;
            }
        }

        return $weights;
    }

    public function assertWeightsValid(): void
    {
        $sum = array_sum($this->weights());
        if (abs($sum - 1.0) > 0.000001) {
            throw new RuntimeException('arena_weights_must_sum_to_one');
        }
    }

    /**
     * @return list<array{
     *   run_id_public:string,suite:string,engine:string,arm:string,score:float,
     *   cases_passed:int,cases_failed:int,cases_total:int,duration_avg_ms:?int,
     *   round_at:string
     * }>
     */
    public function measurements(): array
    {
        $runsDir = RunPaths::runsDir();
        if (! is_dir($runsDir)) {
            return [];
        }

        $rows = [];
        foreach ($this->runIds($runsDir) as $runId) {
            $suite = $this->suiteForRun($runId);
            if ($suite === null) {
                continue;
            }
            $receipts = $this->receipts($runId);
            if ($receipts === []) {
                continue;
            }

            /** @var array<string, array<string, mixed>> $groups */
            $groups = [];
            foreach ($receipts as $receipt) {
                if (($receipt['failure_class'] ?? null) === 'environment') {
                    continue;
                }
                $arm = $this->publicArm((string) ($receipt['arm_id'] ?? ''));
                if ($arm === null) {
                    continue;
                }
                $key = $suite.'|'.$arm['engine'].'|'.$arm['arm'];
                $groups[$key] ??= [
                    'suite' => $suite,
                    'engine' => $arm['engine'],
                    'arm' => $arm['arm'],
                    'passed' => 0,
                    'failed' => 0,
                    'walls' => [],
                    'rounds' => [],
                ];
                if (($receipt['status'] ?? null) === 'success') {
                    $groups[$key]['passed']++;
                } else {
                    $groups[$key]['failed']++;
                }
                if (isset($receipt['wall_ms']) && is_numeric($receipt['wall_ms'])) {
                    $groups[$key]['walls'][] = (float) $receipt['wall_ms'];
                }
                $at = $this->timestamp($receipt['finished_at'] ?? $receipt['ended_at'] ?? $receipt['started_at'] ?? null);
                if ($at !== null) {
                    $groups[$key]['rounds'][] = $at;
                }
            }

            foreach ($groups as $group) {
                $total = (int) $group['passed'] + (int) $group['failed'];
                if ($total <= 0) {
                    continue;
                }
                $walls = (array) $group['walls'];
                $rounds = (array) $group['rounds'];
                $roundAt = $rounds === []
                    ? $this->stateUpdatedAt($runId) ?? $this->timestampFromRunId($runId)
                    : max($rounds);
                $rows[] = [
                    'run_id_public' => $this->publicRunId($runId),
                    'suite' => (string) $group['suite'],
                    'engine' => (string) $group['engine'],
                    'arm' => (string) $group['arm'],
                    'score' => round((int) $group['passed'] / $total, 4),
                    'cases_passed' => (int) $group['passed'],
                    'cases_failed' => (int) $group['failed'],
                    'cases_total' => $total,
                    'duration_avg_ms' => $walls === [] ? null : (int) round(array_sum($walls) / count($walls)),
                    'round_at' => $roundAt ?? now()->toIso8601String(),
                ];
            }
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['round_at'], $b['round_at']));

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function queuedRequests(): array
    {
        $path = $this->queuePath();
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line): mixed => json_decode($line, true),
            array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
        ), 'is_array'));
    }

    public function appendQueuedRequest(array $entry): void
    {
        RunPaths::ensureDir(dirname($this->queuePath()));
        file_put_contents($this->queuePath(), json_encode($entry, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    public function queuePath(): string
    {
        return rtrim((string) config('atlas_rivals.storage_root'), '/').'/arena/queued_runs.jsonl';
    }

    public function publicRunId(string $runId): string
    {
        return 'ar_'.substr(hash('sha256', $runId), 0, 20);
    }

    /** @return list<string> */
    private function runIds(string $runsDir): array
    {
        $ids = array_values(array_filter(
            array_diff(scandir($runsDir) ?: [], ['.', '..']),
            fn (string $runId): bool => is_dir($runsDir.'/'.$runId)
        ));
        sort($ids);

        return $ids;
    }

    private function suiteForRun(string $runId): ?string
    {
        $manifest = $this->readJson(RunPaths::nativeManifestPath($runId));
        $suite = $manifest['suite_id'] ?? null;
        if (is_string($suite) && $suite !== '') {
            return $suite;
        }

        $plan = $this->readJson(RunPaths::planPath($runId));
        $suite = $plan['suite_id'] ?? null;

        return is_string($suite) && $suite !== '' ? $suite : null;
    }

    /** @return list<array<string, mixed>> */
    private function receipts(string $runId): array
    {
        $path = RunPaths::receiptsPath($runId);
        if (! is_file($path)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (string $line): mixed => json_decode($line, true),
            array_filter(explode(PHP_EOL, (string) file_get_contents($path))),
        ), 'is_array'));
    }

    /** @return array{engine:string, arm:string}|null */
    private function publicArm(string $armId): ?array
    {
        if (! str_contains($armId, '@')) {
            return null;
        }
        [$engine, $runtime] = explode('@', $armId, 2);
        $arm = match ($runtime) {
            'bare', 'baseline' => 'baseline',
            'atlas_dev', 'with_atlas' => 'with_atlas',
            default => null,
        };
        if ($engine === '' || $arm === null) {
            return null;
        }

        return ['engine' => $engine, 'arm' => $arm];
    }

    private function stateUpdatedAt(string $runId): ?string
    {
        $state = $this->readJson(RunPaths::runDir($runId).'/state.json');

        return $this->timestamp($state['updated_at'] ?? null);
    }

    private function timestampFromRunId(string $runId): ?string
    {
        if (preg_match('/^(\d{8})_(\d{6})_/', $runId, $m) !== 1) {
            return null;
        }
        $dt = \DateTimeImmutable::createFromFormat('Ymd His', $m[1].' '.$m[2], new \DateTimeZone('UTC'));

        return $dt === false ? null : $dt->format(DATE_ATOM);
    }

    private function timestamp(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        $time = strtotime($value);

        return $time === false ? null : gmdate(DATE_ATOM, $time);
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
