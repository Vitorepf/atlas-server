<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

final class AtlasLoopCycleCheckpointWriter
{
    /**
     * @var array<int, string>
     */
    private array $phaseOrder;

    /**
     * @var array{
     *     ensure_directory: callable(string):void,
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     sync: callable(string):void,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
     * }
     */
    private array $fs;

    /**
     * @param  list<string>|null  $phaseOrder
     * @param  array{
     *     ensure_directory?: callable(string):void,
     *     is_file?: callable(string):bool,
     *     read?: callable(string):string|false,
     *     write?: callable(string,string):int|false,
     *     sync?: callable(string):void,
     *     rename?: callable(string,string):bool,
     *     unlink?: callable(string):void
     * }|null  $fs
     */
    public function __construct(
        private readonly string $baseDir,
        ?array $phaseOrder = null,
        ?array $fs = null,
    ) {
        if ($this->baseDir === '') {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointWriter.baseDir must not be empty.');
        }

        $this->phaseOrder = $phaseOrder ?? ['selected', 'planned', 'executed', 'verified', 'resolved'];
        $this->assertUniquePhaseOrder($this->phaseOrder);
        $this->fs = $fs ?? $this->defaultFilesystem();
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function recordPhase(string $cycleId, string $phase, array $facts): array
    {
        $this->assertCycleId($cycleId);
        $this->assertKnownPhase($phase);
        ($this->fs['ensure_directory'])($this->baseDir);

        $path = $this->pathForCycle($cycleId);
        $state = $this->readState($path);
        $records = $this->extractRecords($cycleId, $state);
        $seq = count($records) + 1;

        if ($records !== []) {
            $previousPhase = (string) ($records[array_key_last($records)]['phase'] ?? '');
            $expectedIndex = $this->phaseIndex($previousPhase) + 1;
            $incomingIndex = $this->phaseIndex($phase);

            if ($incomingIndex !== $expectedIndex) {
                throw new RuntimeException(sprintf(
                    'Out-of-order phase for cycle "%s": expected "%s", got "%s".',
                    $cycleId,
                    $this->phaseOrder[$expectedIndex] ?? 'end-of-cycle',
                    $phase,
                ));
            }
        }

        $record = [
            'cycle_id' => $cycleId,
            'phase' => $phase,
            'seq' => $seq,
            'commit_sha_base' => $this->requireString($facts, 'commit_sha_base'),
            'merged_sha' => $this->nullableString($facts, 'merged_sha'),
            'emitted_receipt_ids' => $this->stringList($facts, 'emitted_receipt_ids'),
            'held_task_claim_ids' => $this->stringList($facts, 'held_task_claim_ids'),
        ];
        $record['content_hash'] = hash('sha256', CanonicalJson::encodeWithout($record, 'content_hash'));

        $records[] = $record;
        $nextState = [
            'cycle_id' => $cycleId,
            'records' => $records,
        ];

        $encoded = CanonicalJson::encode($nextState);
        $tmp = $path.'.tmp.'.bin2hex(random_bytes(6));
        $written = ($this->fs['write'])($tmp, $encoded);

        if ($written === false || $written !== strlen($encoded)) {
            ($this->fs['unlink'])($tmp);
            throw new RuntimeException("Failed to write checkpoint temp file for cycle \"{$cycleId}\".");
        }

        ($this->fs['sync'])($tmp);

        if (! ($this->fs['rename'])($tmp, $path)) {
            ($this->fs['unlink'])($tmp);
            throw new RuntimeException("Failed to publish checkpoint file for cycle \"{$cycleId}\".");
        }

        return $record;
    }

    public function pathForCycle(string $cycleId): string
    {
        $this->assertCycleId($cycleId);

        return rtrim($this->baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$cycleId.'.json';
    }

    /**
     * @return array{
     *     ensure_directory: callable(string):void,
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     write: callable(string,string):int|false,
     *     sync: callable(string):void,
     *     rename: callable(string,string):bool,
     *     unlink: callable(string):void
     * }
     */
    private function defaultFilesystem(): array
    {
        return [
            'ensure_directory' => function (string $dir): void {
                if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
                    throw new RuntimeException("Failed to create checkpoint directory \"{$dir}\".");
                }
            },
            'is_file' => static fn (string $path): bool => is_file($path),
            'read' => static fn (string $path): string|false => @file_get_contents($path),
            'write' => static function (string $path, string $contents): int|false {
                return @file_put_contents($path, $contents, LOCK_EX);
            },
            'sync' => static function (string $path): void {
                $fh = @fopen($path, 'r');
                if (! is_resource($fh)) {
                    throw new RuntimeException("Failed to open temp checkpoint file \"{$path}\" for sync.");
                }

                @fflush($fh);
                if (function_exists('fdatasync')) {
                    @fdatasync($fh);
                } elseif (function_exists('fsync')) {
                    @fsync($fh);
                }
                fclose($fh);
            },
            'rename' => static fn (string $from, string $to): bool => @rename($from, $to),
            'unlink' => static function (string $path): void {
                if (is_file($path)) {
                    @unlink($path);
                }
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(string $path): array
    {
        if (! ($this->fs['is_file'])($path)) {
            return [];
        }

        $raw = ($this->fs['read'])($path);
        if (! is_string($raw) || $raw === '') {
            throw new RuntimeException("Checkpoint file \"{$path}\" is unreadable.");
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            throw new RuntimeException("Checkpoint file \"{$path}\" is not a JSON object.");
        }

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return list<array<string, mixed>>
     */
    private function extractRecords(string $cycleId, array $state): array
    {
        if ($state === []) {
            return [];
        }

        if (($state['cycle_id'] ?? null) !== $cycleId) {
            throw new RuntimeException("Checkpoint file cycle mismatch for \"{$cycleId}\".");
        }

        $records = $state['records'] ?? null;
        if (! is_array($records) || ! array_is_list($records)) {
            throw new RuntimeException("Checkpoint file for \"{$cycleId}\" has invalid records payload.");
        }

        /** @var list<array<string, mixed>> $records */
        return $records;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function requireString(array $facts, string $key): string
    {
        $value = $facts[$key] ?? null;
        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Checkpoint fact \"{$key}\" must be a non-empty string.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $facts
     */
    private function nullableString(array $facts, string $key): ?string
    {
        $value = $facts[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new InvalidArgumentException("Checkpoint fact \"{$key}\" must be a string or null.");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private function stringList(array $facts, string $key): array
    {
        $value = $facts[$key] ?? null;
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException("Checkpoint fact \"{$key}\" must be a list of strings.");
        }

        foreach ($value as $entry) {
            if (! is_string($entry)) {
                throw new InvalidArgumentException("Checkpoint fact \"{$key}\" must contain only strings.");
            }
        }

        /** @var list<string> $value */
        return $value;
    }

    /**
     * @param  list<string>  $phaseOrder
     */
    private function assertUniquePhaseOrder(array $phaseOrder): void
    {
        if ($phaseOrder === []) {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointWriter.phaseOrder must not be empty.');
        }

        foreach ($phaseOrder as $phase) {
            if (! is_string($phase) || $phase === '') {
                throw new InvalidArgumentException('AtlasLoopCycleCheckpointWriter.phaseOrder must contain non-empty strings.');
            }
        }

        if (count(array_unique($phaseOrder)) !== count($phaseOrder)) {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointWriter.phaseOrder must not contain duplicates.');
        }
    }

    private function assertCycleId(string $cycleId): void
    {
        if ($cycleId === '' || preg_match('/^[A-Za-z0-9._-]{1,160}$/', $cycleId) !== 1) {
            throw new InvalidArgumentException("Invalid cycle_id \"{$cycleId}\".");
        }
    }

    private function assertKnownPhase(string $phase): void
    {
        if (! in_array($phase, $this->phaseOrder, true)) {
            throw new InvalidArgumentException("Unknown checkpoint phase \"{$phase}\".");
        }
    }

    private function phaseIndex(string $phase): int
    {
        $index = array_search($phase, $this->phaseOrder, true);
        if ($index === false) {
            throw new InvalidArgumentException("Unknown checkpoint phase \"{$phase}\".");
        }

        return $index;
    }
}
