<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\TraceReplay;

use Closure;
use RuntimeException;
use Symfony\Component\Process\Process;

final class AtlasAaelExecutionTraceRecorder
{
    private ?string $traceId = null;

    private int $stepCount = 0;

    private bool $finished = false;

    private ?string $rootCommit = null;

    /** @var array<int|string,mixed>|null */
    private ?array $scope = null;

    /**
     * @param  ?Closure():string  $clock
     * @param  ?Closure():string  $traceIdGenerator
     * @param  ?Closure():string  $workingTreeHashResolver
     */
    public function __construct(
        private readonly ?string $traceRoot = null,
        private readonly ?Closure $clock = null,
        private readonly ?Closure $traceIdGenerator = null,
        private readonly ?Closure $workingTreeHashResolver = null,
    ) {
    }

    /**
     * @param  array<int|string,mixed>  $scope
     */
    public function begin(array $scope, string $rootCommit): string
    {
        if ($this->traceId !== null) {
            return $this->traceId;
        }

        $this->traceId = $this->generateTraceId();
        $this->rootCommit = $rootCommit;
        $this->scope = $scope;

        $this->appendRow([
            'record_type' => 'manifest',
            'step_index' => 0,
            'trace_id' => $this->traceId,
            'started_at' => $this->now(),
            'scope' => $this->sortRecursive($scope),
            'root_commit' => $rootCommit,
            'recorder_version' => 1,
        ]);

        return $this->traceId;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function recordStep(array $payload): TraceStepRecord
    {
        if ($this->traceId === null) {
            throw new RuntimeException('Trace must be started before recordStep().');
        }

        if ($this->finished) {
            throw new RuntimeException('Cannot recordStep() after finish().');
        }

        $input = is_array($payload['input'] ?? null) ? $payload['input'] : [];
        $output = is_array($payload['output'] ?? null) ? $payload['output'] : [];
        $stdout = (string) ($payload['stdout'] ?? '');
        $stderr = (string) ($payload['stderr'] ?? '');

        $record = new TraceStepRecord(
            stepIndex: $this->stepCount + 1,
            monotonicTimestamp: $this->now(),
            actionName: (string) ($payload['action_name'] ?? ''),
            inputFingerprint: $this->fingerprint($input),
            outputFingerprint: $this->fingerprint($output),
            providerId: (string) ($payload['provider_id'] ?? ''),
            exitCode: (int) ($payload['exit_code'] ?? 0),
            stdoutByteLength: strlen($stdout),
            stderrByteLength: strlen($stderr),
            workingTreeHash: $this->workingTreeHash(),
            decisionContextId: (string) ($payload['decision_context_id'] ?? ''),
        );

        $this->appendRow($record->toArray());
        $this->stepCount++;

        return $record;
    }

    /**
     * @return array<string,mixed>
     */
    public function finish(string $terminalStatus): array
    {
        if ($this->traceId === null) {
            throw new RuntimeException('Trace must be started before finish().');
        }

        if ($this->finished) {
            throw new RuntimeException('finish() already called for trace '.$this->traceId);
        }

        $this->finished = true;
        $row = [
            'record_type' => 'finish',
            'step_index' => $this->stepCount + 1,
            'trace_id' => $this->traceId,
            'finished_at' => $this->now(),
            'total_steps' => $this->stepCount,
            'terminal_status' => $terminalStatus,
        ];
        $this->appendRow($row);

        return $row;
    }

    public function traceId(): string
    {
        if ($this->traceId === null) {
            throw new RuntimeException('Trace has not been started yet.');
        }

        return $this->traceId;
    }

    /**
     * @return array<string,mixed>
     */
    private function rowEnvelope(array $row): array
    {
        return $this->sortRecursive($row);
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function appendRow(array $row): void
    {
        $path = $this->traceFilePath();
        $dir = dirname($path);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('Unable to create trace directory: '.$dir);
        }

        $handle = fopen($path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('Unable to open trace file: '.$path);
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock trace file: '.$path);
            }

            $bytes = fwrite($handle, json_encode($this->rowEnvelope($row), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
            if ($bytes === false) {
                throw new RuntimeException('Unable to append trace row to: '.$path);
            }
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function traceRoot(): string
    {
        return $this->traceRoot ?? storage_path('atlas/aael/traces');
    }

    private function traceFilePath(): string
    {
        if ($this->traceId === null) {
            throw new RuntimeException('Trace has not been started yet.');
        }

        return rtrim($this->traceRoot(), '/').'/'.$this->traceId.'.jsonl';
    }

    private function generateTraceId(): string
    {
        if ($this->traceIdGenerator !== null) {
            return ($this->traceIdGenerator)();
        }

        return bin2hex(random_bytes(16));
    }

    private function now(): string
    {
        return $this->clock !== null
            ? ($this->clock)()
            : date(DATE_ATOM);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function fingerprint(array $payload): string
    {
        return hash('sha256', (string) json_encode($this->sortRecursive($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function workingTreeHash(): string
    {
        if ($this->workingTreeHashResolver !== null) {
            return ($this->workingTreeHashResolver)();
        }

        $head = $this->gitOutput(['rev-parse', 'HEAD']) ?? 'unknown';
        $dirty = $this->gitOutput(['status', '--porcelain']);

        return trim($head).($dirty !== null && trim($dirty) !== '' ? '+dirty' : '');
    }

    private function gitOutput(array $args): ?string
    {
        $process = new Process(array_merge(['git'], $args), base_path());
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        return trim($process->getOutput());
    }

    /**
     * @return mixed
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->sortRecursive($item), $value);
        }

        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $key => $item) {
            $sorted[$key] = $this->sortRecursive($item);
        }

        return $sorted;
    }
}
