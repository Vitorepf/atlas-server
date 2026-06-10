<?php

declare(strict_types=1);

namespace App\Services\Ai\AtlasDecide;

use Closure;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use App\Services\Ai\Support\AppendOnlyJsonlStore;
use Symfony\Component\Process\Process;

/**
 * Atlas Swarm Parallel Dispatch Service — Patamar 4 F6.
 *
 * Executes K arms of a swarm dispatch envelope in parallel via Symfony
 * Process fan-out. Each arm gets its own subprocess (timeout-bounded) and
 * the parent collects outcomes after all subprocesses settle. Falls back to
 * serial execution when the parallel flag is OFF, ensuring deterministic
 * back-compat with the existing AtlasSwarmExecutorService contract.
 *
 * The actual provider call is delegated to a builder Closure that, given an
 * `(arm, context)` pair, returns a shell command (array) which when executed
 * prints a JSON outcome to stdout in the canonical shape:
 *
 *   {"result":"success|failure|timeout","latency_ms":int,
 *    "quality_score":float|null,"output":"..."}
 *
 * Operator wires the production builder via AppServiceProvider when ready.
 * For tests the builder spawns a php inline subprocess.
 *
 * Authority doc: docs/engineering-knowledge-base/atlas-swarm-parallel-dispatch.md
 *
 * Schemas:
 *   - atlas.swarm.parallel_dispatch.v1
 *
 * Invariants:
 *   - Flag `atlas.patamar4.swarm_parallel_enabled` default OFF.
 *   - Per-arm timeout enforced via Symfony Process.
 *   - Serial fallback retains determinism when parallel disabled.
 *   - claim_policy provider-safe.
 *   - Outcomes JSONL append-only with sha256.
 */
final class AtlasSwarmParallelDispatchService
{
    public const SCHEMA = 'atlas.swarm.parallel_dispatch.v1';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILURE = 'failure';

    public const STATUS_TIMEOUT = 'timeout';

    public const DEFAULT_PER_ARM_TIMEOUT_SECONDS = 60;

    private ?Closure $commandBuilder = null;

    private ?string $logPathOverride = null;

    public function setCommandBuilder(?Closure $builder): void
    {
        $this->commandBuilder = $builder;
    }

    public function setLogPathForTesting(?string $path): void
    {
        $this->logPathOverride = $path;
    }

    public function logPath(): string
    {
        if ($this->logPathOverride !== null) {
            return $this->logPathOverride;
        }
        $base = function_exists('storage_path')
            ? storage_path('atlas/atlas_decide')
            : sys_get_temp_dir().'/atlas/atlas_decide';

        return $base.DIRECTORY_SEPARATOR.'swarm_parallel.jsonl';
    }

    /**
     * Dispatch arms in parallel (or serial fallback).
     *
     * @param  array<int,array<string,mixed>>  $arms
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function dispatch(array $arms, array $context = [], ?int $perArmTimeoutSeconds = null): array
    {
        $startedAt = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $timeoutSeconds = $perArmTimeoutSeconds ?? self::DEFAULT_PER_ARM_TIMEOUT_SECONDS;
        $parallelEnabled = (bool) config('atlas.patamar4.swarm_parallel_enabled', false);

        if ($this->commandBuilder === null) {
            return $this->envelope($startedAt, $arms, $context, [], 'unwired', 'commandBuilder not set; call setCommandBuilder(Closure(arm,ctx)->command).');
        }
        if ($arms === []) {
            return $this->envelope($startedAt, $arms, $context, [], 'noop_no_arms', 'no arms in dispatch envelope.');
        }

        $outcomes = $parallelEnabled
            ? $this->runParallel($arms, $context, $timeoutSeconds)
            : $this->runSerial($arms, $context, $timeoutSeconds);

        return $this->envelope($startedAt, $arms, $context, $outcomes, $parallelEnabled ? 'parallel' : 'serial', null);
    }

    /**
     * @param  array<int,array<string,mixed>>  $arms
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function runParallel(array $arms, array $context, int $timeoutSeconds): array
    {
        /** @var array<int, array{arm:array<string,mixed>, process:Process, started_at:float}> $running */
        $running = [];
        foreach ($arms as $i => $arm) {
            $cmd = ($this->commandBuilder)($arm, $context);
            if (! is_array($cmd) || $cmd === []) {
                $running[$i] = [
                    'arm' => $arm,
                    'process' => null,
                    'started_at' => microtime(true),
                    'invalid_command' => true,
                ];

                continue;
            }
            $proc = new Process($cmd);
            $proc->setTimeout($timeoutSeconds);
            $proc->start();
            $running[$i] = [
                'arm' => $arm,
                'process' => $proc,
                'started_at' => microtime(true),
            ];
        }

        // Wait all.
        $outcomes = [];
        foreach ($running as $i => $entry) {
            $arm = $entry['arm'];
            if (($entry['invalid_command'] ?? false) === true) {
                $outcomes[$i] = $this->failureOutcome($arm, 0, 'invalid_command');

                continue;
            }
            /** @var Process $proc */
            $proc = $entry['process'];
            try {
                $proc->wait();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                $latency = (int) ((microtime(true) - $entry['started_at']) * 1000);
                $outcomes[$i] = $this->timeoutOutcome($arm, $latency);

                continue;
            } catch (\Throwable $e) {
                $latency = (int) ((microtime(true) - $entry['started_at']) * 1000);
                $outcomes[$i] = $this->failureOutcome($arm, $latency, 'process_error: '.substr($e->getMessage(), 0, 80));

                continue;
            }

            $latency = (int) ((microtime(true) - $entry['started_at']) * 1000);
            $outcomes[$i] = $this->parseOutcome($arm, $proc, $latency);
        }

        return array_values($outcomes);
    }

    /**
     * @param  array<int,array<string,mixed>>  $arms
     * @param  array<string,mixed>  $context
     * @return array<int,array<string,mixed>>
     */
    private function runSerial(array $arms, array $context, int $timeoutSeconds): array
    {
        $outcomes = [];
        foreach ($arms as $arm) {
            $cmd = ($this->commandBuilder)($arm, $context);
            if (! is_array($cmd) || $cmd === []) {
                $outcomes[] = $this->failureOutcome($arm, 0, 'invalid_command');

                continue;
            }
            $startedAt = microtime(true);
            $proc = new Process($cmd);
            $proc->setTimeout($timeoutSeconds);
            try {
                $proc->run();
            } catch (\Symfony\Component\Process\Exception\ProcessTimedOutException $e) {
                $outcomes[] = $this->timeoutOutcome($arm, (int) ((microtime(true) - $startedAt) * 1000));

                continue;
            } catch (\Throwable $e) {
                $outcomes[] = $this->failureOutcome($arm, (int) ((microtime(true) - $startedAt) * 1000), 'process_error: '.substr($e->getMessage(), 0, 80));

                continue;
            }
            $outcomes[] = $this->parseOutcome($arm, $proc, (int) ((microtime(true) - $startedAt) * 1000));
        }

        return $outcomes;
    }

    /**
     * @param  array<string,mixed>  $arm
     * @return array<string,mixed>
     */
    private function parseOutcome(array $arm, Process $proc, int $latencyMs): array
    {
        $stdout = trim($proc->getOutput());
        if ($stdout === '') {
            return $this->failureOutcome($arm, $latencyMs, $proc->getExitCode() === 0 ? 'empty_stdout' : 'exit_'.$proc->getExitCode());
        }
        $decoded = json_decode($stdout, true);
        if (! is_array($decoded)) {
            return $this->failureOutcome($arm, $latencyMs, 'non_json_stdout: '.substr($stdout, 0, 60));
        }

        return [
            'arm_id' => (string) ($arm['arm_id'] ?? ''),
            'rank' => (int) ($arm['rank'] ?? 0),
            'origin' => (string) ($arm['origin'] ?? ''),
            'provider' => (string) ($arm['provider'] ?? ''),
            'model' => (string) ($arm['model'] ?? ''),
            'result' => (string) ($decoded['result'] ?? self::STATUS_FAILURE),
            'latency_ms' => isset($decoded['latency_ms']) ? max(0, (int) $decoded['latency_ms']) : $latencyMs,
            'quality_score' => isset($decoded['quality_score']) ? max(0.0, min(1.0, (float) $decoded['quality_score'])) : null,
            'output_hash' => isset($decoded['output']) && $decoded['output'] !== ''
                ? 'sha256:'.hash('sha256', (string) $decoded['output'])
                : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $arm
     * @return array<string,mixed>
     */
    private function failureOutcome(array $arm, int $latencyMs, string $output): array
    {
        return [
            'arm_id' => (string) ($arm['arm_id'] ?? ''),
            'rank' => (int) ($arm['rank'] ?? 0),
            'origin' => (string) ($arm['origin'] ?? ''),
            'provider' => (string) ($arm['provider'] ?? ''),
            'model' => (string) ($arm['model'] ?? ''),
            'result' => self::STATUS_FAILURE,
            'latency_ms' => max(0, $latencyMs),
            'quality_score' => null,
            'output_hash' => 'sha256:'.hash('sha256', $output),
        ];
    }

    /**
     * @param  array<string,mixed>  $arm
     * @return array<string,mixed>
     */
    private function timeoutOutcome(array $arm, int $latencyMs): array
    {
        $base = $this->failureOutcome($arm, $latencyMs, 'process_timeout');
        $base['result'] = self::STATUS_TIMEOUT;

        return $base;
    }

    /**
     * @param  array<int,array<string,mixed>>  $arms
     * @param  array<string,mixed>  $context
     * @param  array<int,array<string,mixed>>  $outcomes
     * @return array<string,mixed>
     */
    private function envelope(string $startedAt, array $arms, array $context, array $outcomes, string $mode, ?string $reason): array
    {
        $envelope = [
            'schema_version' => self::SCHEMA,
            'started_at' => $startedAt,
            'mode' => $mode,
            'reason' => $reason,
            'arm_count' => count($arms),
            'outcome_count' => count($outcomes),
            'outcomes' => $outcomes,
            'flag_enabled' => (bool) config('atlas.patamar4.swarm_parallel_enabled', false),
            'task_category' => $context['task_category'] ?? null,
            'role' => $context['role'] ?? null,
            'claim_policy' => $this->claimPolicy(),
        ];
        $envelope['dispatch_hash'] = 'sha256:'.hash('sha256', json_encode([
            'schema' => self::SCHEMA,
            'started_at' => $startedAt,
            'mode' => $mode,
            'arm_count' => count($arms),
            'outcome_count' => count($outcomes),
        ], JSON_THROW_ON_ERROR));

        if ($outcomes !== []) {
            AppendOnlyJsonlStore::append($this->logPath(), $envelope);
        }

        return $envelope;
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_claim_allowed' => false,
            'rivals_claim_allowed' => false,
            'superiority_claim_allowed' => false,
            'external_rivals_certification_touched' => false,
            'cognitive_immune_law_enforced' => true,
            'provider_safe_only_enforced' => true,
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listDispatches(int $tail = 20): array
    {
        $all = AppendOnlyJsonlStore::read($this->logPath());
        if ($tail <= 0) {
            return $all;
        }

        return array_slice($all, -$tail);
    }

    // ---------- internals ----------
}
