<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Migration\Runner;

final class AtlasLoopSchemaMigrationDryRunReporter
{
    /**
     * @var array{
     *   is_file: callable(string):bool,
     *   read: callable(string):string|false
     * }
     */
    private array $fs;

    /**
     * @param  array{
     *   is_file?: callable(string):bool,
     *   read?: callable(string):string|false
     * }|null  $fs
     */
    public function __construct(?array $fs = null)
    {
        $this->fs = $fs ?? [
            'is_file' => static fn (string $path): bool => is_file($path),
            'read' => static fn (string $path): string|false => @file_get_contents($path),
        ];
    }

    public function dryRun(object $step): DryRunReport
    {
        $targets = $this->targets($step);
        $checkpoint = $this->checkpointFor($step, $targets);
        $preview = method_exists($step, 'renderPreview') ? $step->renderPreview($checkpoint) : [];
        $preview = is_array($preview) ? $preview : [];

        $reportTargets = [];
        $bytesAdded = 0;
        $bytesRemoved = 0;

        foreach ($targets as $path) {
            $preBytes = $checkpoint['targets'][$path]['bytes'];
            $postBytes = is_string($preview[$path] ?? null) ? $preview[$path] : $preBytes;
            $diff = $this->unifiedDiff($path, $preBytes, $postBytes);
            $delta = $this->byteDelta($preBytes, $postBytes);

            $bytesAdded += $delta['bytes_added'];
            $bytesRemoved += $delta['bytes_removed'];
            $reportTargets[] = [
                'path' => $path,
                'pre_sha256' => hash('sha256', $preBytes),
                'post_sha256' => hash('sha256', $postBytes),
                'unified_diff' => $diff,
            ];
        }

        return new DryRunReport(
            stepId: $this->stepId($step),
            checkpointId: $checkpoint['checkpoint_id'],
            targets: $reportTargets,
            bytesAdded: $bytesAdded,
            bytesRemoved: $bytesRemoved,
        );
    }

    /**
     * @param  list<string>  $targets
     * @return array{
     *   checkpoint_id:string,
     *   step_id:string,
     *   targets:array<string,array{path:string,bytes:string}>
     * }
     */
    private function checkpointFor(object $step, array $targets): array
    {
        $snapshots = [];
        foreach ($targets as $path) {
            $bytes = ($this->fs['is_file'])($path) ? ($this->fs['read'])($path) : false;
            $snapshots[$path] = [
                'path' => $path,
                'bytes' => is_string($bytes) ? $bytes : '',
            ];
        }

        return [
            'checkpoint_id' => 'checkpoint-'.substr(hash('sha256', $this->stepId($step).'|'.json_encode($targets, JSON_THROW_ON_ERROR)), 0, 16),
            'step_id' => $this->stepId($step),
            'targets' => $snapshots,
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
     * @return array{bytes_added:int,bytes_removed:int}
     */
    private function byteDelta(string $preBytes, string $postBytes): array
    {
        $preLength = strlen($preBytes);
        $postLength = strlen($postBytes);

        return [
            'bytes_added' => max(0, $postLength - $preLength),
            'bytes_removed' => max(0, $preLength - $postLength),
        ];
    }

    private function unifiedDiff(string $path, string $preBytes, string $postBytes): string
    {
        if ($preBytes === $postBytes) {
            return "--- {$path}\n+++ {$path}\n";
        }

        $preLines = preg_split("/\r\n|\n|\r/", $preBytes) ?: [];
        $postLines = preg_split("/\r\n|\n|\r/", $postBytes) ?: [];
        $diff = ["--- {$path}", "+++ {$path}", '@@'];

        foreach ($preLines as $line) {
            $diff[] = '-'.$line;
        }
        foreach ($postLines as $line) {
            $diff[] = '+'.$line;
        }

        return implode("\n", $diff)."\n";
    }
}

final readonly class DryRunReport
{
    /**
     * @param  list<array{
     *   path:string,
     *   pre_sha256:string,
     *   post_sha256:string,
     *   unified_diff:string
     * }>  $targets
     */
    public function __construct(
        public string $stepId,
        public string $checkpointId,
        public array $targets,
        public int $bytesAdded,
        public int $bytesRemoved,
    ) {}

    /**
     * @return array{
     *   step_id:string,
     *   checkpoint_id:string,
     *   targets:list<array{
     *     path:string,
     *     pre_sha256:string,
     *     post_sha256:string,
     *     unified_diff:string
     *   }>,
     *   bytes_added:int,
     *   bytes_removed:int
     * }
     */
    public function toArray(): array
    {
        return [
            'step_id' => $this->stepId,
            'checkpoint_id' => $this->checkpointId,
            'targets' => $this->targets,
            'bytes_added' => $this->bytesAdded,
            'bytes_removed' => $this->bytesRemoved,
        ];
    }
}
