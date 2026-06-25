<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use RuntimeException;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Safe local command-plan executor for Atlas-native worker gates. STRICTLY allowlisted: only commands
 * present in the execution-envelope's `gates`/`command_allowlist` may run. Supports dry_run that VALIDATES
 * without executing. Enforces per-command timeout, cwd, env-redaction (no SECRET-looking vars), and
 * REFUSES any command whose metadata declares network or external-provider intent.
 *
 * INVARIANTS:
 *   - DRY-RUN: never invokes Process; emits would_run entries.
 *   - DENY: any command not in the envelope allowlist returns status=denied with the unauthorized name.
 *   - REDACTION: env values whose keys match /SECRET|TOKEN|API_KEY|PASSWORD/i are replaced with '***'.
 *   - NETWORK/PROVIDER LABELED COMMANDS are rejected up-front (status=denied_label).
 *   - Result shape is deterministic for the same input + same exit code: same keys in same order.
 */
final class AtlasNativeWorkerCommandPlanRunner
{
    public const SCHEMA = 'atlas.native_worker.command_plan_result.v1';

    public const STATUS_OK = 'ok';

    public const STATUS_DENIED = 'denied';

    public const STATUS_DENIED_LABEL = 'denied_label';

    public const STATUS_TIMEOUT = 'timeout';

    public const STATUS_DRY_RUN = 'dry_run';

    public const FORBIDDEN_LABELS = ['network', 'external_provider', 'shell_escape', 'unrestricted'];

    /**
     * @param  array<string,mixed>  $envelope        AtlasNativeWorkerExecutionEnvelopeBuilder envelope
     * @param  list<array<string,mixed>>  $commandPlan list of {name, argv, cwd?, env?, labels?, timeout_seconds?}
     * @param  bool  $dryRun
     * @return array{schema:string, dry_run:bool, results:list<array<string,mixed>>}
     */
    public function execute(array $envelope, array $commandPlan, bool $dryRun = false): array
    {
        $allowlist = $this->extractAllowlist($envelope);
        $results = [];
        foreach ($commandPlan as $cmd) {
            $results[] = $this->runOne(is_array($cmd) ? $cmd : [], $allowlist, $dryRun);
        }

        return [
            'schema' => self::SCHEMA,
            'dry_run' => $dryRun,
            'results' => $results,
        ];
    }

    /**
     * @param  array<string,mixed>  $cmd
     * @param  list<string>  $allowlist
     * @return array<string,mixed>
     */
    private function runOne(array $cmd, array $allowlist, bool $dryRun): array
    {
        $name = trim((string) ($cmd['name'] ?? ''));
        $argv = is_array($cmd['argv'] ?? null) ? array_values(array_map('strval', $cmd['argv'])) : [];
        $cwd = (string) ($cmd['cwd'] ?? sys_get_temp_dir());
        $env = is_array($cmd['env'] ?? null) ? $this->redactEnv($cmd['env']) : [];
        $labels = is_array($cmd['labels'] ?? null) ? array_map('strval', $cmd['labels']) : [];
        $timeout = max(1, (int) ($cmd['timeout_seconds'] ?? 30));

        if ($name === '' || $argv === []) {
            return ['name' => $name, 'status' => self::STATUS_DENIED, 'reason' => 'malformed_command'];
        }
        foreach ($labels as $label) {
            if (in_array(strtolower($label), self::FORBIDDEN_LABELS, true)) {
                return ['name' => $name, 'status' => self::STATUS_DENIED_LABEL, 'reason' => 'forbidden_label:'.$label];
            }
        }
        if (! in_array($name, $allowlist, true)) {
            return ['name' => $name, 'status' => self::STATUS_DENIED, 'reason' => 'not_in_envelope_allowlist'];
        }
        if ($dryRun) {
            return [
                'name' => $name,
                'status' => self::STATUS_DRY_RUN,
                'would_run' => ['argv' => $argv, 'cwd' => $cwd, 'env_redacted' => $env, 'timeout_seconds' => $timeout],
            ];
        }

        try {
            $proc = new Process($argv, $cwd, $env);
            $proc->setTimeout($timeout);
            $proc->run();

            return [
                'name' => $name,
                'status' => self::STATUS_OK,
                'exit_code' => $proc->getExitCode(),
                'stdout_len' => strlen($proc->getOutput()),
                'stderr_len' => strlen($proc->getErrorOutput()),
                'timeout_seconds' => $timeout,
            ];
        } catch (ProcessTimedOutException) {
            return ['name' => $name, 'status' => self::STATUS_TIMEOUT, 'timeout_seconds' => $timeout];
        } catch (Throwable $e) {
            return ['name' => $name, 'status' => 'error', 'message' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return list<string>
     */
    private function extractAllowlist(array $envelope): array
    {
        $explicit = is_array($envelope['command_allowlist'] ?? null) ? array_map('strval', $envelope['command_allowlist']) : [];
        $gates = is_array($envelope['gates'] ?? null) ? array_map('strval', $envelope['gates']) : [];

        return array_values(array_unique(array_filter(array_merge($explicit, $gates), static fn (string $s): bool => $s !== '')));
    }

    /**
     * @param  array<string,mixed>  $env
     * @return array<string,string>
     */
    private function redactEnv(array $env): array
    {
        $out = [];
        foreach ($env as $k => $v) {
            $key = (string) $k;
            $out[$key] = preg_match('/SECRET|TOKEN|API_KEY|PASSWORD/i', $key) ? '***' : (string) $v;
        }

        return $out;
    }
}
