<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

use Closure;
use Throwable;

/**
 * Between-steps pause gate for the AAEL executor.
 *
 * The executor calls shouldPause(runId, stepIndex) before dispatching a step. When a pause is
 * armed (file-backed under storage/app/atlas/aael/debugger/<run_id>.pause.json), the executor must
 * call awaitResume() and block until disarm() OR timeout.
 *
 * Fail-closed safe: storage errors on read ⇒ shouldPause returns false (do NOT freeze prod runs on
 * infra error), and a canonical FACT-only log line is emitted via the injected logger.
 *
 * arm() is durable: writing the file atomically (tmp + rename) survives process restart.
 */
final class AtlasAaelExecutionStepwisePauseGate
{
    public const SCHEMA = 'atlas.aael.execution.debugger.pause_gate.v1';

    /** @var Closure(string):void */
    private Closure $logFact;

    public function __construct(
        private readonly string $storageRoot,
        ?callable $factLogger = null,
        private readonly int $pollIntervalMs = 25,
    ) {
        $this->logFact = $factLogger instanceof Closure ? $factLogger : Closure::fromCallable($factLogger ?? static function (string $line): void { /* no-op */ });
        if (! is_dir($this->storageRoot)) {
            @mkdir($this->storageRoot, 0o755, true);
        }
    }

    public function arm(string $runId, ?int $atStepIndex = null): void
    {
        $payload = [
            'schema' => self::SCHEMA,
            'run_id' => $runId,
            'at_step_index' => $atStepIndex,
            'armed_at_iso' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        $path = $this->pausePath($runId);
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(4));
        if (@file_put_contents($tmp, json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '') === false) {
            throw new \RuntimeException('pause_gate_arm_write_failed:'.$tmp);
        }
        if (! @rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('pause_gate_arm_rename_failed:'.$path);
        }
    }

    public function disarm(string $runId): bool
    {
        $path = $this->pausePath($runId);
        if (! is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    public function shouldPause(string $runId, int $stepIndex): bool
    {
        try {
            $state = $this->readState($runId);
        } catch (Throwable $e) {
            ($this->logFact)(sprintf(
                'pause_gate.fail_open run_id=%s step_index=%d error_class=%s',
                $runId,
                $stepIndex,
                $e::class,
            ));

            return false;
        }
        if ($state === null) {
            return false;
        }
        $at = $state['at_step_index'] ?? null;

        return $at === null || (int) $at === $stepIndex;
    }

    public function awaitResume(string $runId, int $stepIndex, int $timeoutMs): PauseGateDecision
    {
        $start = $this->currentMillis();
        while (true) {
            try {
                $state = $this->readState($runId);
            } catch (Throwable $e) {
                ($this->logFact)(sprintf(
                    'pause_gate.fail_open run_id=%s step_index=%d error_class=%s',
                    $runId,
                    $stepIndex,
                    $e::class,
                ));

                return PauseGateDecision::aborted;
            }
            if ($state === null) {
                return PauseGateDecision::resumed;
            }
            $elapsed = $this->currentMillis() - $start;
            if ($elapsed >= $timeoutMs) {
                return PauseGateDecision::timed_out;
            }
            usleep($this->pollIntervalMs * 1000);
        }
    }

    /**
     * @return array<string,mixed>|null
     */
    private function readState(string $runId): ?array
    {
        $path = $this->pausePath($runId);
        if (! is_file($path)) {
            return null;
        }
        $bytes = @file_get_contents($path);
        if ($bytes === false) {
            throw new \RuntimeException('pause_gate_read_failed:'.$path);
        }
        $decoded = json_decode($bytes, true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('pause_gate_bad_payload:'.$path);
        }

        return $decoded;
    }

    private function pausePath(string $runId): string
    {
        $safe = preg_replace('/[^A-Za-z0-9_\-]/', '_', $runId) ?? $runId;

        return rtrim($this->storageRoot, '/').'/'.$safe.'.pause.json';
    }

    private function currentMillis(): int
    {
        return (int) round(microtime(true) * 1000);
    }
}
