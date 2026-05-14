<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Atlas Forge Safe Provider Process Runner.
 *
 * Spawns governed CLI processes (claude/codex/gemini) using Symfony Process
 * with explicit argv arrays. NEVER uses shell parsing. Captures stdout/stderr,
 * enforces timeout, computes sha256 hashes, redacts env from excerpts and
 * caps output length.
 *
 * Schema: atlas.forge.provider_process_result.v1
 *
 * Hard rules:
 *   - argv must be an array (no shell strings);
 *   - cwd must be inside the workspace passed by the driver (validated by
 *     `AtlasForgeProviderCommandAllowlistService`);
 *   - input (prompt) is piped via stdin or argv; never via echo + pipe;
 *   - timeout kills the process and returns `status=timed_out`;
 *   - output excerpts are truncated and stripped of obvious secrets.
 *
 * Tests can inject a fake `processFactory` to simulate provider behaviour
 * without spawning a real binary.
 */
class AtlasForgeProviderProcessRunner
{
    public const SCHEMA_VERSION = 'atlas.forge.provider_process_result.v1';

    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_TIMED_OUT = 'timed_out';
    public const STATUS_BLOCKED = 'blocked';

    /** @var callable|null */
    private $processFactory;

    /**
     * @param  array{argv:array<int,string>, cwd?:?string, stdin?:?string, timeout_seconds?:int, max_output_chars?:int, env?:array<string,string>}  $request
     * @return array<string,mixed>
     */
    public function run(array $request): array
    {
        $argv = is_array($request['argv'] ?? null) ? $request['argv'] : [];
        $cwd = $request['cwd'] ?? null;
        $stdin = is_string($request['stdin'] ?? null) ? $request['stdin'] : null;
        $timeout = max(1, min(3600, (int) ($request['timeout_seconds'] ?? 120)));
        $maxOutputChars = max(200, min(200000, (int) ($request['max_output_chars'] ?? 12000)));
        $env = $request['env'] ?? null;
        $env = is_array($env) ? array_filter($env, 'is_string') : null;

        if ($argv === []) {
            return $this->blockedResult($argv, $cwd, $timeout, 'argv_empty');
        }

        $started = microtime(true);
        $process = $this->makeProcess($argv, $cwd, $env, $timeout);

        $stdout = '';
        $stderr = '';
        $exitCode = null;
        $status = self::STATUS_COMPLETED;
        $errorReason = null;

        try {
            if ($stdin !== null && $stdin !== '') {
                $process->setInput($stdin);
            }
            $process->run();
            $stdout = (string) $process->getOutput();
            $stderr = (string) $process->getErrorOutput();
            $exitCode = $process->getExitCode();
            if (! $process->isSuccessful()) {
                $status = self::STATUS_FAILED;
            }
        } catch (ProcessTimedOutException $e) {
            $status = self::STATUS_TIMED_OUT;
            $stderr = $e->getMessage();
            $errorReason = 'timeout';
            try {
                $process->stop(2);
            } catch (Throwable) {
            }
        } catch (Throwable $e) {
            $status = self::STATUS_FAILED;
            $stderr = $e->getMessage();
            $errorReason = 'process_threw_exception';
        }

        $duration = (int) round((microtime(true) - $started) * 1000);

        $stdoutRedacted = $this->redact($stdout);
        $stderrRedacted = $this->redact($stderr);
        $stdoutExcerpt = $this->excerpt($stdoutRedacted, $maxOutputChars);
        $stderrExcerpt = $this->excerpt($stderrRedacted, $maxOutputChars);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'argv' => array_values($argv),
            'cwd' => $cwd,
            'provider_called' => true,
            'external_provider_call' => true,
            'provider_tokens_spent' => 'unknown',
            'duration_ms' => $duration,
            'timeout_seconds' => $timeout,
            'exit_code' => is_int($exitCode) ? $exitCode : null,
            'stdout_hash' => hash('sha256', $stdout),
            'stderr_hash' => hash('sha256', $stderr),
            'stdout_excerpt' => $stdoutExcerpt,
            'stderr_excerpt' => $stderrExcerpt,
            'error_reason' => $errorReason,
            'blockers' => [],
            'note' => 'Safe process runner: argv array only; sem shell; sem env leak no excerpt.',
        ];
    }

    /**
     * Replace the process factory with a test stub.
     */
    public function setProcessFactory(?callable $factory): void
    {
        $this->processFactory = $factory;
    }

    /**
     * @param  array<int,string>  $argv
     * @param  array<string,string>|null  $env
     */
    private function makeProcess(array $argv, ?string $cwd, ?array $env, int $timeout): Process
    {
        if ($this->processFactory !== null) {
            $product = call_user_func($this->processFactory, $argv, $cwd, $env, $timeout);
            if ($product instanceof Process) {
                return $product;
            }
        }

        $process = new Process($argv, $cwd, $env, null, (float) $timeout);
        $process->setTimeout((float) $timeout);

        return $process;
    }

    /**
     * @param  array<int,string>  $argv
     * @return array<string,mixed>
     */
    private function blockedResult(array $argv, ?string $cwd, int $timeout, string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => self::STATUS_BLOCKED,
            'argv' => array_values($argv),
            'cwd' => $cwd,
            'provider_called' => false,
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'duration_ms' => 0,
            'timeout_seconds' => $timeout,
            'exit_code' => null,
            'stdout_hash' => hash('sha256', ''),
            'stderr_hash' => hash('sha256', ''),
            'stdout_excerpt' => '',
            'stderr_excerpt' => '',
            'error_reason' => $reason,
            'blockers' => [$reason],
            'note' => 'Process runner refused to start: '.$reason,
        ];
    }

    private function redact(string $value): string
    {
        if ($value === '') {
            return $value;
        }
        // Redact obvious env tokens and bearer headers from the captured
        // output so excerpts do not leak secrets into receipts/UI.
        $patterns = [
            '/(sk-[a-zA-Z0-9_\-]{8,})/' => 'sk-***redacted***',
            '/(api[_-]?key["\']?\s*[:=]\s*["\']?)[^"\'\s,]+/i' => '$1***redacted***',
            '/(bearer\s+)[A-Za-z0-9._\-]+/i' => '$1***redacted***',
            '/(authorization:\s*)[^\r\n]+/i' => '$1***redacted***',
        ];
        foreach ($patterns as $regex => $replacement) {
            $value = (string) preg_replace($regex, $replacement, $value);
        }

        return $value;
    }

    private function excerpt(string $value, int $maxLength): string
    {
        if ($value === '') {
            return '';
        }
        if (strlen($value) <= $maxLength) {
            return $value;
        }

        return substr($value, 0, $maxLength).'…';
    }
}
