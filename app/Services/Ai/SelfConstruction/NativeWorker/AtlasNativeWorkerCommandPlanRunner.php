<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\NativeWorker;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Safe local command-plan executor for Atlas-native worker gates. STRICTLY allowlisted: only commands
 * present in the execution-envelope's `gates`/`command_allowlist` may run. Supports dry_run that VALIDATES
 * without executing. Enforces per-command timeout, cwd, env allowlist/redaction, and REFUSES any command
 * whose metadata or parsed argv declares shell/network/external-provider intent.
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

    public const STATUS_FAILED = 'failed';

    public const STATUS_DRY_RUN = 'dry_run';

    public const FORBIDDEN_LABELS = ['network', 'external_provider', 'shell_escape', 'unrestricted'];

    /**
     * @param  array<string,mixed>  $envelope  AtlasNativeWorkerExecutionEnvelopeBuilder envelope
     * @param  list<array<string,mixed>>  $commandPlan  list of {name, argv, cwd?, timeout_s?, env_allowlist?, labels?}
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
        $envAllowlist = $this->envAllowlist($cmd);
        $env = is_array($cmd['env'] ?? null) ? $this->redactEnv($this->allowlistedEnv($cmd['env'], $envAllowlist)) : [];
        $labels = is_array($cmd['labels'] ?? null) ? array_map('strval', $cmd['labels']) : [];
        $timeout = max(1, (int) ($cmd['timeout_seconds'] ?? $cmd['timeout_s'] ?? 30));

        if ($name === '' || $argv === []) {
            return ['name' => $name, 'status' => self::STATUS_DENIED, 'reason' => 'malformed_command'];
        }
        if ($this->isShellEscapeArgv($argv)) {
            return ['name' => $name, 'status' => self::STATUS_DENIED, 'reason' => 'shell_escape_argv'];
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
                'would_run' => array_filter([
                    'argv' => $argv,
                    'cwd' => $cwd,
                    'env_redacted' => $env,
                    'env_allowlist' => $envAllowlist,
                    'timeout_seconds' => $timeout,
                ], static fn (mixed $value): bool => $value !== null),
            ];
        }

        try {
            $proc = new Process($argv, $cwd, $env);
            $proc->setTimeout($timeout);
            $proc->run();

            $exitCode = $proc->getExitCode();

            return [
                'name' => $name,
                'status' => $exitCode === 0 ? self::STATUS_OK : self::STATUS_FAILED,
                'exit_code' => $exitCode,
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

    public const FORBIDDEN_GIT_SUBCOMMANDS = ['commit', 'push', 'merge', 'reset', 'rebase', 'force-push'];

    public const FORBIDDEN_PROVIDER_BINARIES = ['claude', 'codex', 'openai', 'anthropic-cli', 'gemini', 'cursor'];

    /**
     * Facts-only plan validator. Does NOT execute. Returns {passed, rejections, accepted, dry_run}.
     * Rejects: missing_timeout, git_mutation_command, provider_command_detected, not_acceptance_command,
     * and — when the envelope opts in — scope_outside_allowed_roots, timeout_exceeds_ceiling,
     * command_family_not_allowed. The 3 opt-in checks are no-ops unless the envelope declares the
     * matching key (allowed_scope_roots / max_timeout_seconds / allowed_command_families), so every
     * envelope that never declared them validates byte-identically to before.
     *
     * @param  list<array<string,mixed>>  $commandPlan
     * @return array{schema:string, passed:bool, rejections:list<array<string,mixed>>, accepted:list<array<string,mixed>>, dry_run:bool}
     */
    public function validate(array $envelope, array $commandPlan): array
    {
        $acceptanceCommands = is_array($envelope['acceptance_commands'] ?? null)
            ? array_values(array_map('strval', $envelope['acceptance_commands']))
            : null;
        $allowedScopeRoots = is_array($envelope['allowed_scope_roots'] ?? null)
            ? array_values(array_map('strval', $envelope['allowed_scope_roots']))
            : null;
        $maxTimeoutSeconds = array_key_exists('max_timeout_seconds', $envelope) && $envelope['max_timeout_seconds'] !== null
            ? (int) $envelope['max_timeout_seconds']
            : null;
        $allowedFamilies = is_array($envelope['allowed_command_families'] ?? null)
            ? array_values(array_map('strval', $envelope['allowed_command_families']))
            : null;

        $rejections = [];
        $accepted = [];
        $legacyCommandCount = 0;
        $structuredCommandEnforce = ($envelope['structured_command_enforce'] ?? false) === true;

        foreach ($commandPlan as $cmd) {
            if (! is_array($cmd)) {
                continue;
            }
            $name = trim((string) ($cmd['name'] ?? ''));
            $argv = is_array($cmd['argv'] ?? null) ? array_values(array_map('strval', $cmd['argv'])) : [];
            $legacyShellString = trim((string) ($cmd['command'] ?? '')) !== '' && $argv === [];
            $timeoutValue = $cmd['timeout_seconds'] ?? $cmd['timeout_s'] ?? null;

            if ($timeoutValue === null || (int) $timeoutValue <= 0) {
                $rejections[] = ['name' => $name, 'reason' => 'missing_timeout'];

                continue;
            }
            if ($legacyShellString) {
                $legacyCommandCount++;
                if ($structuredCommandEnforce) {
                    $rejections[] = ['name' => $name, 'reason' => 'legacy_shell_string'];

                    continue;
                }
                if ($acceptanceCommands !== null && ! in_array($name, $acceptanceCommands, true)) {
                    $rejections[] = ['name' => $name, 'reason' => 'not_acceptance_command'];

                    continue;
                }
                $accepted[] = [
                    'name' => $name,
                    'status' => 'legacy_observe',
                    'command_family' => trim((string) ($cmd['family'] ?? '')),
                    'requires_evidence_capture' => $acceptanceCommands !== null && in_array($name, $acceptanceCommands, true),
                ];

                continue;
            }
            if ($this->isShellEscapeArgv($argv)) {
                $rejections[] = ['name' => $name, 'reason' => 'shell_escape_argv'];

                continue;
            }
            if ($this->isGitMutation($argv)) {
                $rejections[] = ['name' => $name, 'reason' => 'git_mutation_command'];

                continue;
            }
            if ($this->isProviderBinary($argv)) {
                $rejections[] = ['name' => $name, 'reason' => 'provider_command_detected'];

                continue;
            }
            if ($allowedScopeRoots !== null) {
                $cwd = trim((string) ($cmd['cwd'] ?? ''));
                if ($cwd !== '' && ! $this->withinScopeRoots($cwd, $allowedScopeRoots)) {
                    $rejections[] = ['name' => $name, 'reason' => 'scope_outside_allowed_roots'];

                    continue;
                }
            }
            if ($maxTimeoutSeconds !== null && (int) $timeoutValue > $maxTimeoutSeconds) {
                $rejections[] = ['name' => $name, 'reason' => 'timeout_exceeds_ceiling'];

                continue;
            }
            $family = trim((string) ($cmd['family'] ?? ''));
            if ($allowedFamilies !== null && $family !== '' && ! in_array($family, $allowedFamilies, true)) {
                $rejections[] = ['name' => $name, 'reason' => 'command_family_not_allowed'];

                continue;
            }
            if ($acceptanceCommands !== null && ! in_array($name, $acceptanceCommands, true)) {
                $rejections[] = ['name' => $name, 'reason' => 'not_acceptance_command'];

                continue;
            }
            $accepted[] = [
                'name' => $name,
                'status' => 'accepted',
                'command_family' => $family,
                'requires_evidence_capture' => $acceptanceCommands !== null && in_array($name, $acceptanceCommands, true),
            ];
        }

        return [
            'schema' => self::SCHEMA,
            'passed' => $rejections === [],
            'rejections' => $rejections,
            'accepted' => $accepted,
            'dry_run' => true,
            'legacy_command_count' => $legacyCommandCount,
        ];
    }

    /** @param  list<string>  $roots */
    private function withinScopeRoots(string $cwd, array $roots): bool
    {
        $normalizedCwd = rtrim(str_replace('\\', '/', $cwd), '/');
        foreach ($roots as $root) {
            $normalizedRoot = rtrim(str_replace('\\', '/', $root), '/');
            if ($normalizedRoot !== '' && ($normalizedCwd === $normalizedRoot || str_starts_with($normalizedCwd.'/', $normalizedRoot.'/'))) {
                return true;
            }
        }

        return false;
    }

    private function isGitMutation(array $argv): bool
    {
        return array_key_exists(0, $argv) && array_key_exists(1, $argv)
            && is_string($argv[0]) && is_string($argv[1])
            && basename($argv[0]) === 'git'
            && in_array(strtolower($argv[1]), self::FORBIDDEN_GIT_SUBCOMMANDS, true);
    }

    private function isProviderBinary(array $argv): bool
    {
        return isset($argv[0]) && in_array(basename($argv[0]), self::FORBIDDEN_PROVIDER_BINARIES, true);
    }

    private function isShellEscapeArgv(array $argv): bool
    {
        $shells = ['sh', 'bash', 'zsh', 'dash', 'fish', 'ksh'];
        $count = count($argv);
        for ($i = 0; $i < $count - 1; $i++) {
            $binary = strtolower(basename((string) $argv[$i]));
            $next = strtolower((string) $argv[$i + 1]);
            if (in_array($binary, $shells, true) && str_starts_with($next, '-') && str_contains($next, 'c')) {
                return true;
            }
        }

        return false;
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

    /** @param array<string,mixed> $cmd @return list<string>|null */
    private function envAllowlist(array $cmd): ?array
    {
        if (! is_array($cmd['env_allowlist'] ?? null)) {
            return null;
        }

        return array_values(array_filter(array_map('strval', $cmd['env_allowlist']), static fn (string $key): bool => $key !== ''));
    }

    /** @param array<string,mixed> $env @param list<string>|null $allowlist @return array<string,mixed> */
    private function allowlistedEnv(array $env, ?array $allowlist): array
    {
        if ($allowlist === null) {
            return $env;
        }

        $allowed = array_fill_keys($allowlist, true);
        $out = [];
        foreach ($env as $key => $value) {
            if (isset($allowed[(string) $key])) {
                $out[(string) $key] = $value;
            }
        }

        return $out;
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
