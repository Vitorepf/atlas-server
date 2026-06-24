<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration\Runner;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use RuntimeException;
use Throwable;

final class AtlasLoopSchemaMigrationExecutableRunner
{
    /**
     * @var array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false,
     *   write: callable(string,string):int|false,
     *   mkdir: callable(string):bool
     * }
     */
    private array $fs;

    /**
     * @param  array{
     *   is_file?: callable(string):bool,
     *   read?: callable(string):string|false,
     *   write?: callable(string,string):int|false,
     *   mkdir?: callable(string):bool
     * }|null  $fs
     */
    public function __construct(
        ?array $fs = null,
        private readonly mixed $loopBus = null,
        private readonly ?string $lockPath = null,
    ) {
        $this->fs = $fs ?? $this->defaultFilesystem();
    }

    /**
     * @return array{
     *   status:string,
     *   checkpoint_id:string,
     *   step_id:string,
     *   applied_at:string,
     *   facts:array<string,mixed>
     * }
     */
    public function run(object $step): array
    {
        $stepId = $this->stepId($step);
        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->outcome(
                status: 'refused_master_off',
                checkpointId: '',
                stepId: $stepId,
                appliedAt: '',
                facts: ['master_switch' => 'off', 'apply_called' => false],
            );
        }

        $checkpoint = $this->checkpointFor($step);
        $checkpointId = $checkpoint['checkpoint_id'];
        $lockHandle = fopen($this->lockPath(), 'c+');
        if ($lockHandle === false) {
            throw new RuntimeException('Failed to open schema migration executable runner lock.');
        }

        try {
            if (! flock($lockHandle, LOCK_EX)) {
                throw new RuntimeException('Failed to acquire schema migration executable runner lock.');
            }

            try {
                $raw = $step->apply($checkpoint);
            } catch (Throwable $throwable) {
                $failed = $this->outcome(
                    status: 'failed',
                    checkpointId: $checkpointId,
                    stepId: $stepId,
                    appliedAt: gmdate('c'),
                    facts: [
                        'target_count' => count($checkpoint['targets']),
                        'checkpoint_targets' => array_keys($checkpoint['targets']),
                        'error' => $throwable->getMessage(),
                    ],
                );
                $this->emitToLoopBus($failed);

                return $failed;
            }
        } finally {
            @flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
        }

        $normalized = is_array($raw) ? $raw : [];
        $status = in_array(($normalized['status'] ?? null), ['applied', 'failed', 'skipped'], true)
            ? (string) $normalized['status']
            : 'applied';
        $appliedAt = is_string($normalized['applied_at'] ?? null) && trim((string) $normalized['applied_at']) !== ''
            ? (string) $normalized['applied_at']
            : gmdate('c');

        $facts = is_array($normalized['facts'] ?? null) ? $normalized['facts'] : [];
        $facts['target_count'] = count($checkpoint['targets']);
        $facts['checkpoint_targets'] = array_keys($checkpoint['targets']);

        $outcome = $this->outcome(
            status: $status,
            checkpointId: $checkpointId,
            stepId: $stepId,
            appliedAt: $appliedAt,
            facts: $facts,
        );
        $this->emitToLoopBus($outcome);

        return $outcome;
    }

    /**
     * @return array{
     *   checkpoint_id:string,
     *   step_id:string,
     *   targets:array<string,array{path:string,bytes:string}>
     * }
     */
    private function checkpointFor(object $step): array
    {
        $targets = [];
        foreach ($this->targets($step) as $path) {
            $bytes = ($this->fs['is_file'])($path)
                ? ($this->fs['read'])($path)
                : false;

            $targets[$path] = [
                'path' => $path,
                'bytes' => is_string($bytes) ? $bytes : '',
            ];
        }

        ksort($targets);

        return [
            'checkpoint_id' => 'checkpoint-'.substr(hash('sha256', $this->stepId($step).'|'.json_encode(array_keys($targets), JSON_THROW_ON_ERROR)), 0, 16),
            'step_id' => $this->stepId($step),
            'targets' => $targets,
        ];
    }

    /**
     * @return list<string>
     */
    private function targets(object $step): array
    {
        $targets = method_exists($step, 'targets') ? $step->targets() : [];
        if (! is_array($targets)) {
            return [];
        }

        $normalized = array_values(array_filter(
            $targets,
            static fn (mixed $path): bool => is_string($path) && trim($path) !== '',
        ));
        sort($normalized);

        return $normalized;
    }

    private function stepId(object $step): string
    {
        if (method_exists($step, 'stepId')) {
            $value = trim((string) $step->stepId());
            if ($value !== '') {
                return $value;
            }
        }

        return $step::class;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array{
     *   status:string,
     *   checkpoint_id:string,
     *   step_id:string,
     *   applied_at:string,
     *   facts:array<string,mixed>
     * }
     */
    private function outcome(string $status, string $checkpointId, string $stepId, string $appliedAt, array $facts): array
    {
        return [
            'status' => $status,
            'checkpoint_id' => $checkpointId,
            'step_id' => $stepId,
            'applied_at' => $appliedAt,
            'facts' => $facts,
        ];
    }

    /**
     * @param  array<string,mixed>  $outcome
     */
    private function emitToLoopBus(array $outcome): void
    {
        if (! is_callable($this->loopBus)) {
            return;
        }

        call_user_func($this->loopBus, [
            'event' => 'loop_schema_migration_executed',
            'payload' => $outcome,
        ]);
    }

    private function lockPath(): string
    {
        $path = $this->lockPath ?? sys_get_temp_dir().'/atlas-loop-schema-migration-executable-runner.lock';
        $directory = dirname($path);
        if (! is_dir($directory)) {
            ($this->fs['mkdir'])($directory);
        }

        return $path;
    }

    /**
     * @return array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false,
     *   write: callable(string,string):int|false,
     *   mkdir: callable(string):bool
     * }
     */
    private function defaultFilesystem(): array
    {
        return [
            'is_file' => static fn (string $path): bool => is_file($path),
            'read' => static fn (string $path): string|false => @file_get_contents($path),
            'write' => static fn (string $path, string $contents): int|false => @file_put_contents($path, $contents, LOCK_EX),
            'mkdir' => static fn (string $path): bool => is_dir($path) || @mkdir($path, 0o755, true),
        ];
    }
}
