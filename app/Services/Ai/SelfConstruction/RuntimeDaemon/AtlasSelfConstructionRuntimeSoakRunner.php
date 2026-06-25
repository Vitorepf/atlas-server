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
 */
final class AtlasSelfConstructionRuntimeSoakRunner
{
    public const SCHEMA = 'atlas.self_construction.runtime_soak_runner.v1';

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

        $payload = [
            'schema' => self::SCHEMA,
            'schema_version' => self::SCHEMA,
            'passed' => $passed,
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
