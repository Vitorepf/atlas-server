<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Pattern;

use InvalidArgumentException;

/**
 * LOOP-PATTERN-REGISTRY · Slice 1 — the provider-safe outcome ledger for loop patterns.
 *
 * Append-only JSONL: every pattern run records WHICH pattern (id/version), the objective class, the
 * honest result, which success gates passed/failed, a measured outcome, and a timestamp. This is the
 * evidence the {@see AtlasLoopPatternChampionGate} reads to decide whether a challenger has actually
 * earned promotion — so it is deliberately narrow and provider-safe: it never stores prompts, diffs,
 * secrets or provider details, only the governed outcome fields (unknown keys are dropped on write).
 *
 * Determinism: the storage path and the timestamp are both injectable, so tests are exact.
 */
final class AtlasLoopPatternLearningLedger
{
    /** Honest result vocabulary (mirrors the pattern terminal states, collapsed to outcome classes). */
    public const RESULT_SUCCESS = 'success';

    public const RESULT_NO_OP = 'clean_no_op';

    public const RESULT_BLOCKED = 'blocked';

    public const RESULT_EXHAUSTED = 'exhausted';

    public const RESULT_STAGNATED = 'stagnated';

    public const RESULTS = [
        self::RESULT_SUCCESS,
        self::RESULT_NO_OP,
        self::RESULT_BLOCKED,
        self::RESULT_EXHAUSTED,
        self::RESULT_STAGNATED,
    ];

    private readonly string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? self::defaultPath();
    }

    public static function defaultPath(): string
    {
        // storage_path() is available in the Laravel runtime; fall back to a repo-relative path otherwise.
        $base = function_exists('storage_path') ? storage_path('atlas-loop') : sys_get_temp_dir().'/atlas-loop';

        return rtrim($base, '/').'/pattern-learning-ledger.jsonl';
    }

    /**
     * Append one outcome. Fail-closed: a record without a pattern id, version, objective class or a
     * known result is rejected (an unattributable outcome is worse than no outcome). Returns the exact
     * normalized, provider-safe row that was written.
     *
     * @param  array<string,mixed>  $entry
     * @return array<string,mixed>
     *
     * @throws InvalidArgumentException
     */
    public function record(array $entry, ?int $at = null): array
    {
        $patternId = trim((string) ($entry['pattern_id'] ?? ''));
        $patternVersion = trim((string) ($entry['pattern_version'] ?? ''));
        $objectiveClass = trim((string) ($entry['objective_class'] ?? ''));
        $result = (string) ($entry['result'] ?? '');

        $errors = [];
        if ($patternId === '') {
            $errors[] = 'pattern_id is required';
        }
        if ($patternVersion === '') {
            $errors[] = 'pattern_version is required';
        }
        if ($objectiveClass === '') {
            $errors[] = 'objective_class is required';
        }
        if (! in_array($result, self::RESULTS, true)) {
            $errors[] = "result '{$result}' is not one of ".implode('|', self::RESULTS);
        }
        if ($errors !== []) {
            throw new InvalidArgumentException('Invalid pattern outcome: '.implode('; ', $errors));
        }

        $row = [
            'pattern_id' => $patternId,
            'pattern_version' => $patternVersion,
            'objective_class' => $objectiveClass,
            'result' => $result,
            'gates_passed' => array_values(array_map(static fn ($g): string => (string) $g, (array) ($entry['gates_passed'] ?? []))),
            'gates_failed' => array_values(array_map(static fn ($g): string => (string) $g, (array) ($entry['gates_failed'] ?? []))),
            'measured_outcome' => $this->normalizeMeasured($entry['measured_outcome'] ?? null),
            'recorded_at' => $at ?? time(),
        ];

        $this->ensureDir();
        file_put_contents($this->path, json_encode($row, JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND | LOCK_EX);

        return $row;
    }

    /** @return list<array<string,mixed>> every recorded outcome, in write order. */
    public function all(): array
    {
        if (! is_file($this->path)) {
            return [];
        }

        $rows = [];
        foreach (explode("\n", (string) file_get_contents($this->path)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $rows[] = $decoded;
            }
        }

        return $rows;
    }

    /** @return list<array<string,mixed>> outcomes for one pattern (optionally a specific version). */
    public function forPattern(string $id, ?string $version = null): array
    {
        return array_values(array_filter(
            $this->all(),
            static fn (array $r): bool => (string) ($r['pattern_id'] ?? '') === $id
                && ($version === null || (string) ($r['pattern_version'] ?? '') === $version)
        ));
    }

    /**
     * Aggregate stats the ChampionGate reads: run count, success rate, and the mean measured outcome.
     *
     * @return array{runs:int, successes:int, success_rate:float, mean_outcome:?float}
     */
    public function stats(string $id, ?string $version = null): array
    {
        $rows = $this->forPattern($id, $version);
        $runs = count($rows);
        $successes = count(array_filter($rows, static fn (array $r): bool => ($r['result'] ?? '') === self::RESULT_SUCCESS));

        $measured = array_values(array_filter(
            array_map(static fn (array $r): mixed => $r['measured_outcome'] ?? null, $rows),
            static fn ($m): bool => is_int($m) || is_float($m)
        ));
        $mean = $measured === [] ? null : array_sum($measured) / count($measured);

        return [
            'runs' => $runs,
            'successes' => $successes,
            'success_rate' => $runs === 0 ? 0.0 : $successes / $runs,
            'mean_outcome' => $mean === null ? null : (float) $mean,
        ];
    }

    private function normalizeMeasured(mixed $value): float|int|string|null
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        return null;
    }

    private function ensureDir(): void
    {
        $dir = dirname($this->path);
        if (! is_dir($dir)) {
            @mkdir($dir, 0o775, true);
        }
    }
}
