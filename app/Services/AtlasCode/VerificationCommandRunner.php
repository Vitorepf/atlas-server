<?php

declare(strict_types=1);

namespace App\Services\AtlasCode;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Code · Verification Command Runner.
 *
 * Canon: docs/engineering-knowledge-base/atlas-code-interactive-observed-provider-workflow-v1.md
 *
 * Modes:
 *   - dry_run (default): NO execution. Returns canonical "what would happen"
 *     packet with allowlist check + workspace check + timeout + governance.
 *   - execute (gated): requires execute_enabled=true AND operator_override
 *     token AND command matches allowlist. Spawns subprocess with timeout,
 *     captures stdout/stderr/exit_code, signs the run with the decision
 *     receipt signer, persists evidence packet under evidence_dir.
 *
 * Hard rules:
 *   - NEVER executes shell strings (array args; no shell interpolation).
 *   - NEVER executes outside `workspace_path`.
 *   - NEVER bypasses allowlist.
 *   - Timeout always set; subprocess killed if exceeded.
 *   - Honest empty state when command not allowed / workspace missing.
 *
 * Schema: atlas.code.verification_run.v1
 */
final class VerificationCommandRunner
{
    public const SCHEMA_VERSION = 'atlas.code.verification_run.v1';

    public function __construct(
        private readonly HumanDecisionReceiptSigner $signer = new HumanDecisionReceiptSigner()
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function run(
        string $obraId,
        string $sessionId,
        string $command,
        string $workspacePath,
        string $mode = 'dry_run',
        ?string $operatorOverrideToken = null
    ): array {
        $runId = 'vr_'.Str::ulid()->toBase32();
        $startedAt = now()->toJSON();
        $command = trim($command);
        $base = [
            'schema_version' => self::SCHEMA_VERSION,
            'run_id' => $runId,
            'obra_id' => $obraId,
            'session_id' => $sessionId,
            'command' => $command,
            'workspace_path' => $workspacePath,
            'mode' => $mode,
            'started_at' => $startedAt,
            'finished_at' => null,
            'duration_ms' => null,
            'status' => 'pending',
            'exit_code' => null,
            'stdout_excerpt' => null,
            'stderr_excerpt' => null,
            'allowlist_match' => null,
            'reason' => null,
            'evidence_path' => null,
            'receipt' => null,
        ];

        // 1. Validate workspace.
        if ($workspacePath === '' || ! @is_dir($workspacePath)) {
            return $this->finalize(array_merge($base, [
                'status' => 'blocked',
                'reason' => 'workspace_path_missing_or_unreadable',
            ]));
        }

        // 2. Validate command against allowlist.
        $allowlistMatch = $this->matchAllowlist($command);
        $base['allowlist_match'] = $allowlistMatch;
        if ($allowlistMatch === null) {
            return $this->finalize(array_merge($base, [
                'status' => 'blocked',
                'reason' => 'command_not_in_allowlist',
            ]));
        }

        // 3. Validate mode.
        if ($mode !== 'dry_run' && $mode !== 'execute') {
            return $this->finalize(array_merge($base, [
                'status' => 'blocked',
                'reason' => 'mode_invalid:'.$mode,
            ]));
        }

        // 4. Dry-run: return the canonical "what would happen" packet.
        if ($mode === 'dry_run') {
            return $this->finalize(array_merge($base, [
                'status' => 'dry_run',
                'reason' => 'dry_run · execute=false (operator must request execute mode explicitly)',
            ]));
        }

        // 5. Execute mode — gated by config flag + operator token.
        $executeEnabled = (bool) config('atlas_code_verification.execute_enabled', false);
        if (! $executeEnabled) {
            return $this->finalize(array_merge($base, [
                'status' => 'blocked',
                'reason' => 'execute_disabled_by_config',
            ]));
        }
        $envVar = (string) config('atlas_code_verification.operator_override_env', 'ATLAS_VERIFICATION_OPERATOR_TOKEN');
        $envToken = env($envVar);
        $tokenOk = ($operatorOverrideToken !== null && trim($operatorOverrideToken) !== '')
            || ($envToken !== null && $envToken !== '' && $envToken === $operatorOverrideToken);
        if (! $tokenOk) {
            return $this->finalize(array_merge($base, [
                'status' => 'blocked',
                'reason' => 'operator_override_token_required',
            ]));
        }

        // 6. Sign the intent BEFORE executing.
        $receipt = $this->signer->signDecision([
            'kind' => 'verification_command_execute',
            'run_id' => $runId,
            'session_id' => $sessionId,
            'obra_id' => $obraId,
            'workspace_path' => $workspacePath,
            'command' => $command,
            'allowlist_pattern' => $allowlistMatch,
            'started_at' => $startedAt,
        ]);
        $base['receipt'] = $receipt;

        // 7. Execute.
        $timeout = (int) config('atlas_code_verification.timeout_seconds', 600);
        $args = $this->splitToArgv($command);
        $tStart = microtime(true);
        try {
            $process = new Process($args, $workspacePath, null, null, $timeout);
            $process->run();
        } catch (Throwable $e) {
            return $this->finalize(array_merge($base, [
                'status' => 'error',
                'reason' => 'process_failed:'.substr($e->getMessage(), 0, 200),
            ]));
        }
        $duration = (int) round((microtime(true) - $tStart) * 1000);
        $exitCode = $process->getExitCode() ?? -1;
        $status = $process->isSuccessful() ? 'passed' : 'failed';
        $stdout = $this->truncate($process->getOutput(), 16384);
        $stderr = $this->truncate($process->getErrorOutput(), 16384);

        return $this->finalize(array_merge($base, [
            'status' => $status,
            'reason' => $status === 'passed' ? 'subprocess_exited_zero' : 'subprocess_exited_nonzero',
            'exit_code' => $exitCode,
            'stdout_excerpt' => $stdout,
            'stderr_excerpt' => $stderr,
            'duration_ms' => $duration,
        ]));
    }

    public function listForSession(string $obraId, string $sessionId): array
    {
        $dir = (string) config('atlas_code_verification.evidence_dir');
        if (! is_dir($dir)) {
            return [];
        }
        $runs = [];
        foreach ((array) glob($dir.'/*.json') as $file) {
            if (! is_string($file) || ! is_file($file)) {
                continue;
            }
            $raw = @file_get_contents($file);
            if (! is_string($raw) || $raw === '') {
                continue;
            }
            $decoded = json_decode($raw, true);
            if (is_array($decoded) && (string) ($decoded['obra_id'] ?? '') === $obraId
                && (string) ($decoded['session_id'] ?? '') === $sessionId) {
                $runs[] = $decoded;
            }
        }
        usort($runs, static fn (array $a, array $b): int => strcmp(
            (string) ($b['started_at'] ?? ''),
            (string) ($a['started_at'] ?? '')
        ));
        return $runs;
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finalize(array $result): array
    {
        $result['finished_at'] = now()->toJSON();
        $this->persistEvidence($result);
        return $result;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function persistEvidence(array $result): void
    {
        $dir = (string) config('atlas_code_verification.evidence_dir');
        if ($dir === '') {
            return;
        }
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $runId = (string) ($result['run_id'] ?? Str::ulid()->toBase32());
        $path = $dir.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', $runId).'.json';
        @file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $result['evidence_path'] = $path;
    }

    private function matchAllowlist(string $command): ?string
    {
        $patterns = (array) config('atlas_code_verification.allowed_command_patterns', []);
        foreach ($patterns as $pattern) {
            if (! is_string($pattern) || $pattern === '') {
                continue;
            }
            if (@preg_match($pattern, $command) === 1) {
                return $pattern;
            }
        }
        return null;
    }

    /**
     * Split command line into argv WITHOUT shell. Supports basic
     * whitespace-separated tokens; quoted args are handled by PHP's
     * `str_getcsv` shim. Always returns at least one token or `[]`.
     *
     * @return array<int, string>
     */
    private function splitToArgv(string $command): array
    {
        $tokens = str_getcsv($command, ' ', '"', '\\');
        return array_values(array_filter(array_map(
            static fn ($t): string => is_string($t) ? trim($t) : '',
            $tokens
        ), static fn (string $t): bool => $t !== ''));
    }

    private function truncate(string $s, int $max): string
    {
        if (strlen($s) <= $max) {
            return $s;
        }
        return mb_substr($s, 0, $max)."\n…[truncated]";
    }
}
