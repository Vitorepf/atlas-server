<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;

/**
 * UNIT 2.2 — the dev/test held-out METRIC HARNESS (the Arbor `eval.php` scalar harness, ported).
 *
 * It evaluates ONE split's metric in a candidate workspace and reduces it to a single finite
 * scalar, the same way {@see AtlasEvolutionFrozenJudge::computeMetric()} does. The whole moat
 * of this unit is the dev/test SPLIT:
 *
 *   - The OPTIMIZER (proposer) is only ever allowed to see and optimize the DEV command — its
 *     train split. evaluate($ws, $heldOut, 'dev') is the only call it gets.
 *   - The CERTIFIER proves the gain on the held-out TEST command — evaluate($ws, $heldOut, 'test').
 *     The optimizer never runs this, so it cannot overfit the number that decides certification.
 *
 * Nothing here is self-declared: scalars come from RUNNING the command and regex-extracting the
 * capture group, never from a model claim. A passing run whose pattern matches nothing is treated
 * as the worst possible number (metric_finite=false), so a candidate cannot "win" by emitting
 * unparseable output.
 *
 * Provider-agnostic and git-free by construction: the only side effect is the injected $runner,
 * which defaults to Symfony Process but is a plain callable so tests inject a fake.
 */
final class AtlasLoopMetricHarness
{
    public const METRIC_GATE = 'gate';

    public const METRIC_MINIMIZE = 'minimize';

    public const METRIC_MAXIMIZE = 'maximize';

    /**
     * Worst-case clamp materialized as a FINITE double, mirroring computeMetric's ±INF but kept
     * representable (JSON-safe, comparable, persistable). maximize-worst = -CLAMP, minimize-worst = +CLAMP.
     */
    private const WORST_CLAMP = 1.0e308;

    private const DEFAULT_TIMEOUT = 600;

    /**
     * @var \Closure(string, string, int): array{stdout: string, exit: int}
     */
    private \Closure $runner;

    /**
     * @param  (\Closure(string, string, int): array{stdout: string, exit: int})|null  $runner
     *                                                                                          fn($command, $workspace, $timeout) => {stdout, exit}. Defaults to a real Symfony Process
     *                                                                                          shell run; inject a fake in tests so no real provider/git/process is needed.
     */
    public function __construct(?\Closure $runner = null)
    {
        $this->runner = $runner ?? function (string $command, string $workspace, int $timeout): array {
            $process = Process::fromShellCommandline(
                $command,
                $workspace,
                AtlasLoopHermeticCommandEnvironment::forAcceptance(),
                null,
                (float) $timeout,
            );
            $process->run();

            return [
                'stdout' => (string) $process->getOutput(),
                'exit' => $process->getExitCode() ?? 1,
            ];
        };
    }

    /**
     * Evaluate ONE split's metric in a workspace.
     *
     * @param  array<string, mixed>  $heldOut  the acceptance['held_out'] block
     * @param  string  $which  'dev' (train split — optimizer-visible) or 'test' (frozen held-out)
     * @return array{metric: float, metric_finite: bool, raw: string, command: string}
     */
    public function evaluate(string $workspace, array $heldOut, string $which): array
    {
        $isDev = $which === 'dev';
        $command = trim((string) ($heldOut[$isDev ? 'dev_command' : 'test_command'] ?? ''));
        $patternRaw = (string) ($heldOut[$isDev ? 'dev_pattern' : 'test_pattern'] ?? '');
        $pattern = $patternRaw === '' ? null : $patternRaw;
        $kind = $this->normalizeKind((string) ($heldOut['metric_kind'] ?? self::METRIC_GATE));

        if ($command === '') {
            // No command for this split = nothing to measure = worst by construction, not finite.
            return [
                'metric' => $this->worst($kind),
                'metric_finite' => false,
                'raw' => '',
                'command' => '',
            ];
        }

        $timeout = (int) ($heldOut['timeout_seconds'] ?? self::DEFAULT_TIMEOUT);
        $result = ($this->runner)($command, $workspace, $timeout);
        $stdout = (string) ($result['stdout'] ?? '');
        $exit = (int) ($result['exit'] ?? 1);
        $passed = $exit === 0;

        [$metric, $finite] = $this->computeMetric($kind, $passed, $stdout, $pattern);

        return [
            'metric' => $metric,
            'metric_finite' => $finite,
            'raw' => $stdout,
            'command' => $command,
        ];
    }

    /**
     * Signed delta candidate-minus-baseline, ORIENTED so POSITIVE always means "better".
     *
     *   - maximize: candidate - baseline  (0.73 -> 0.84 => +0.11)
     *   - minimize: baseline - candidate  (10 -> 8 => +2)
     *   - gate:     candidate - baseline  (0 -> 1 => +1)
     */
    public function improvement(float $baseline, float $candidate, string $metricKind): float
    {
        return match ($this->normalizeKind($metricKind)) {
            self::METRIC_MINIMIZE => $baseline - $candidate,
            default => $candidate - $baseline,
        };
    }

    /**
     * Does the held_out block arm this harness? True iff BOTH split commands are non-empty strings.
     *
     * @param  array<string, mixed>  $acceptance
     */
    public static function isArmed(array $acceptance): bool
    {
        $heldOut = $acceptance['held_out'] ?? null;
        if (! is_array($heldOut)) {
            return false;
        }

        $dev = $heldOut['dev_command'] ?? null;
        $test = $heldOut['test_command'] ?? null;

        return is_string($dev) && trim($dev) !== ''
            && is_string($test) && trim($test) !== '';
    }

    /**
     * Reduce a run to (scalar, finite?), mirroring AtlasEvolutionFrozenJudge::computeMetric but
     * returning the finiteness flag and a FINITE worst-case clamp instead of ±INF.
     *
     * @return array{0: float, 1: bool}
     */
    private function computeMetric(string $kind, bool $passed, string $stdout, ?string $pattern): array
    {
        if ($kind === self::METRIC_GATE) {
            return [$passed ? 1.0 : 0.0, true];
        }

        if (! $passed) {
            // A failing candidate has no meaningful number; worst by construction.
            return [$this->worst($kind), false];
        }

        if ($pattern === null) {
            // No pattern on a passing run = pure pass/fail semantics; pass = 1.0.
            return [1.0, true];
        }

        if (preg_match($pattern, $stdout, $m) === 1 && isset($m[1]) && is_numeric($m[1])) {
            return [(float) $m[1], true];
        }

        // Pattern declared but not found in a passing run = inconclusive number; treat as worst.
        return [$this->worst($kind), false];
    }

    /**
     * Worst representable scalar for a kind: maximize wants high so worst is very negative;
     * minimize wants low so worst is very positive. gate worst = 0.0 (a failed gate).
     */
    private function worst(string $kind): float
    {
        return match ($kind) {
            self::METRIC_MINIMIZE => self::WORST_CLAMP,
            self::METRIC_MAXIMIZE => -self::WORST_CLAMP,
            default => 0.0,
        };
    }

    private function normalizeKind(string $kind): string
    {
        $kind = strtolower(trim($kind));

        return match ($kind) {
            self::METRIC_MINIMIZE => self::METRIC_MINIMIZE,
            self::METRIC_MAXIMIZE => self::METRIC_MAXIMIZE,
            default => self::METRIC_GATE,
        };
    }
}
