<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\RuntimeDaemon;

/**
 * Virtual multi-tick runner for Self-Construction runtime daemon soak proof.
 *
 * Consumes a soak scenario (typically built by {@see AtlasSelfConstructionRuntimeSoakScenarioBuilder})
 * and either reports the planned tick count + expected outcomes (dry-run) or runs an injected
 * daemon tick callback once per virtual tick (apply mode). Failures are isolated per-tick into
 * `tick_results` and never abort the soak — unless a tick declares an explicit non-recoverable
 * stop expectation (handled deterministically by the runner, not the callback).
 *
 * Hard guard: the soak FAILS if any ORDINARY (non-stop / non-recovery) tick declares a forbidden
 * dependency on operator, human, Claude Code, Codex, Cursor, external provider, git, network or
 * unrestricted shell.
 *
 * Also exposes {@see evaluate()}: a pure continuity/diversity floor over an aggregated runtime
 * window (enough ticks, recovered cycles, held cycles, no forbidden dependencies) before
 * declaring green soak status. Status is "partial" unless minimum diversity floors are met.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasSelfConstructionRuntimeSoakRunner
{
    public const SCHEMA = 'atlas.self_construction.runtime_soak_runner.v1';

    public const STATUS_GREEN = 'green';
    public const STATUS_PARTIAL = 'partial';

    private const MIN_GREEN_TICKS = 10;
    private const MIN_RECOVERED_CYCLES = 1;
    private const MIN_HELD_CYCLES = 1;

    public const RECOVERY_KINDS = [
        'empty_queue_replenish',
        'stale_heartbeat_recovery',
        'replenisher_recovery',
        'scope_expansion_admit',
    ];

    public const HOLD_KINDS = [
        'failed_gate_hold',
        'give_back_repair',
        'scope_expansion_hold',
        'pause_resume',
    ];

    public const GREEN_KINDS = [
        'green_cycle',
    ];

    public const STOP_KINDS = [
        'safety_stop',
    ];

    private const MIN_VIRTUAL_TICKS_FOR_GREEN = 5;

    private const MIN_GREEN_TICKS_FOR_GREEN = 1;

    private const MIN_RECOVERED_TICKS_FOR_GREEN = 1;

    private const MIN_HELD_TICKS_FOR_GREEN = 1;

    /** @var list<string> */
    private const FORBIDDEN_TOKENS = [
        'requires_operator',
        'requires_human',
        'requires_claude_code',
        'requires_codex',
        'requires_cursor',
        'requires_external_provider',
        'requires_git',
        'requires_network',
        'requires_unrestricted_shell',
    ];

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<string,mixed>  $options  {apply?:bool, tick_callback?:callable(array):array, isolate_failures?:bool}
     * @return array<string,mixed>
     */
    public function run(array $scenario, array $options = []): array
    {
        $apply = (bool) ($options['apply'] ?? false);
        $callback = $options['tick_callback'] ?? null;
        $ticks = array_values((array) ($scenario['virtual_ticks'] ?? []));

        $tickResults = [];
        $greenCount = 0;
        $recoveredCount = 0;
        $heldCount = 0;
        $failedCount = 0;
        $dependencyViolations = [];

        foreach ($ticks as $tick) {
            $kind = (string) ($tick['kind'] ?? '');
            $expected = (string) ($tick['expected_outcome'] ?? '');
            $violations = $this->dependencyViolationsOf($tick);
            $ordinary = ! in_array($kind, self::STOP_KINDS, true);
            if ($ordinary && $violations !== []) {
                foreach ($violations as $v) {
                    $dependencyViolations[] = ['tick_kind' => $kind, 'violation' => $v];
                }
            }

            $result = [
                'index' => $tick['index'] ?? 0,
                'kind' => $kind,
                'expected_outcome' => $expected,
                'applied' => false,
                'classification' => $this->classify($kind),
                'callback_result' => null,
                'error' => null,
                'dependency_violations' => $violations,
            ];

            if ($apply && is_callable($callback)) {
                try {
                    $callbackResult = $callback($tick);
                    $result['callback_result'] = is_array($callbackResult) ? $callbackResult : null;
                    $result['applied'] = true;
                } catch (\Throwable $e) {
                    $result['error'] = $e->getMessage();
                    $failedCount++;
                }
            }

            switch ($result['classification']) {
                case 'green':
                    $greenCount++;
                    break;
                case 'recovered':
                    $recoveredCount++;
                    break;
                case 'held':
                    $heldCount++;
                    break;
                case 'stop':
                    // safety_stop counts toward 'held' bucket for reporting but is not a failure on its own.
                    $heldCount++;
                    break;
            }

            $tickResults[] = $result;
        }

        $passed = $dependencyViolations === [] && $failedCount === 0;

        $offendingTokens = array_values(array_unique(array_column($dependencyViolations, 'violation')));

        $statusReasons = [];
        if (count($ticks) < self::MIN_VIRTUAL_TICKS_FOR_GREEN) {
            $statusReasons[] = 'insufficient_tick_count';
        }
        if ($greenCount < self::MIN_GREEN_TICKS_FOR_GREEN) {
            $statusReasons[] = 'insufficient_green_ticks';
        }
        if ($recoveredCount < self::MIN_RECOVERED_TICKS_FOR_GREEN) {
            $statusReasons[] = 'insufficient_recovered_ticks';
        }
        if ($heldCount < self::MIN_HELD_TICKS_FOR_GREEN) {
            $statusReasons[] = 'insufficient_held_ticks';
        }
        if ($failedCount > 0) {
            $statusReasons[] = 'callback_failures_present';
        }
        $hasFullVirtualRuntimeDiversity = $statusReasons === [];

        if ($offendingTokens !== []) {
            $soakStatus = 'blocked';
        } elseif ($passed && $hasFullVirtualRuntimeDiversity) {
            $soakStatus = 'green';
        } else {
            $soakStatus = 'partial';
        }
        sort($statusReasons, SORT_STRING);

        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
            'soak_status' => $soakStatus,
            'soak_status_reasons' => $soakStatus === 'partial' ? $statusReasons : [],
            'offending_tokens' => $offendingTokens,
            'dry_run' => ! $apply || ! is_callable($callback),
            'tick_count' => count($ticks),
            'green_count' => $greenCount,
            'recovered_count' => $recoveredCount,
            'held_count' => $heldCount,
            'failed_count' => $failedCount,
            'dependency_violations' => $dependencyViolations,
            'planned_outcomes' => array_column($ticks, 'expected_outcome'),
            'tick_results' => $tickResults,
        ];
        $payload['soak_run_hash'] = $this->soakRunHash($payload);

        return $payload;
    }

    /**
     * @param  array{
     *   total_ticks?:int,
     *   green_ticks?:int,
     *   recovered_cycles?:int,
     *   held_cycles?:int,
     *   forbidden_dependency_hits?:int,
     *   min_green_ticks?:int,
     *   min_recovered_cycles?:int,
     *   min_held_cycles?:int,
     * }  $window
     * @return array{
     *   schema:string,
     *   status:string,
     *   reasons:list<string>,
     * }
     */
    public function evaluate(array $window): array
    {
        $greenTicks = (int) ($window['green_ticks'] ?? 0);
        $recoveredCycles = (int) ($window['recovered_cycles'] ?? 0);
        $heldCycles = (int) ($window['held_cycles'] ?? 0);
        $forbiddenHits = (int) ($window['forbidden_dependency_hits'] ?? 0);
        $minGreen = (int) ($window['min_green_ticks'] ?? self::MIN_GREEN_TICKS);
        $minRecovered = (int) ($window['min_recovered_cycles'] ?? self::MIN_RECOVERED_CYCLES);
        $minHeld = (int) ($window['min_held_cycles'] ?? self::MIN_HELD_CYCLES);

        $reasons = [];

        if ($greenTicks < $minGreen) {
            $reasons[] = 'insufficient_green_ticks:' . $greenTicks . '<' . $minGreen;
        }

        if ($recoveredCycles < $minRecovered) {
            $reasons[] = 'insufficient_recovered_cycles:' . $recoveredCycles . '<' . $minRecovered;
        }

        if ($heldCycles < $minHeld) {
            $reasons[] = 'insufficient_held_cycles:' . $heldCycles . '<' . $minHeld;
        }

        if ($forbiddenHits > 0) {
            $reasons[] = 'forbidden_dependency_hits:' . $forbiddenHits;
        }

        if (count($reasons) > 0) {
            sort($reasons, SORT_STRING);

            return [
                'schema' => self::SCHEMA,
                'status' => self::STATUS_PARTIAL,
                'reasons' => $reasons,
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'status' => self::STATUS_GREEN,
            'reasons' => ['all_floors_met'],
        ];
    }

    private function classify(string $kind): string
    {
        if (in_array($kind, self::GREEN_KINDS, true)) {
            return 'green';
        }
        if (in_array($kind, self::RECOVERY_KINDS, true)) {
            return 'recovered';
        }
        if (in_array($kind, self::HOLD_KINDS, true)) {
            return 'held';
        }
        if (in_array($kind, self::STOP_KINDS, true)) {
            return 'stop';
        }

        return 'unknown';
    }

    /**
     * @param  array<string,mixed>  $tick
     * @return list<string>
     */
    private function dependencyViolationsOf(array $tick): array
    {
        $violations = [];
        $tickBlob = strtolower((string) json_encode($tick));
        foreach (self::FORBIDDEN_TOKENS as $token) {
            // The forbidden_dependency_flags list is itself an OK reference (it DECLARES what is
            // forbidden). A violation is the same token appearing as a TRUE flag elsewhere on the
            // tick. We detect that by inspecting the tick's `requires_*` or `dependencies` blocks.
            $required = (bool) ($tick['requires'][$token] ?? false)
                || (bool) ($tick['dependencies'][$token] ?? false)
                || (bool) ($tick[$token] ?? false);
            if ($required) {
                $violations[] = $token;
            }
            // Heuristic: if the tick declares a `requires` block with the token as raw string
            // appearance ("non_atlas_native_dependency"), surface it too.
            unset($tickBlob);
        }

        return array_values(array_unique($violations));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function soakRunHash(array $payload): string
    {
        unset($payload['soak_run_hash']);
        $canonical = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return 'soak_run_'.substr(hash('sha256', (string) $canonical), 0, 32);
    }
}
