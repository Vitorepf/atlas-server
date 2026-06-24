<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\ReentrySafety;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;
use RuntimeException;

final class AtlasLoopCycleCheckpointReader
{
    /**
     * @var list<string>
     */
    private array $phaseOrder;

    /**
     * @var array{
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     glob: callable(string):array|false,
     *     mtime: callable(string):int|false
     * }
     */
    private array $fs;

    /**
     * @param  list<string>|null  $phaseOrder
     * @param  array{
     *     is_file?: callable(string):bool,
     *     read?: callable(string):string|false,
     *     glob?: callable(string):array|false,
     *     mtime?: callable(string):int|false
     * }|null  $fs
     */
    public function __construct(
        private readonly string $baseDir,
        ?array $phaseOrder = null,
        ?array $fs = null,
    ) {
        if ($this->baseDir === '') {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointReader.baseDir must not be empty.');
        }

        $this->phaseOrder = $phaseOrder ?? ['selected', 'planned', 'executed', 'verified', 'resolved'];
        $this->assertUniquePhaseOrder($this->phaseOrder);
        $this->fs = $fs ?? $this->defaultFilesystem();
    }

    public function recoveryState(?string $cycleId = null): AtlasLoopCycleRecoveryFact
    {
        $resolvedCycleId = $this->resolveCycleId($cycleId);
        if ($resolvedCycleId === null) {
            return $this->notStartedFact(null);
        }

        $path = $this->pathForCycle($resolvedCycleId);
        if (! ($this->fs['is_file'])($path)) {
            return $this->notStartedFact($resolvedCycleId);
        }

        $state = $this->readState($path);
        $records = $this->extractRecords($resolvedCycleId, $state);
        if ($records === []) {
            return $this->notStartedFact($resolvedCycleId);
        }

        $lastIndex = array_key_last($records);
        $lastRecord = $records[$lastIndex];
        if (! $this->recordHashValid($lastRecord)) {
            $priorIntact = $this->lastIntactRecord(array_slice($records, 0, -1));

            return new AtlasLoopCycleRecoveryFact(
                cycleId: $resolvedCycleId,
                lastCompletedPhase: $priorIntact['phase'] ?? null,
                nextPhaseToRun: is_string($lastRecord['phase'] ?? null) ? $lastRecord['phase'] : null,
                baseCommitSha: is_string($priorIntact['commit_sha_base'] ?? null) ? $priorIntact['commit_sha_base'] : null,
                mergedSha: array_key_exists('merged_sha', $priorIntact) && is_string($priorIntact['merged_sha']) ? $priorIntact['merged_sha'] : null,
                emittedReceiptIds: $this->readStringList($priorIntact['emitted_receipt_ids'] ?? []),
                heldTaskClaimIds: $this->readStringList($priorIntact['held_task_claim_ids'] ?? []),
                tornTail: true,
            );
        }

        /** @var array<string, mixed> $lastRecord */
        return new AtlasLoopCycleRecoveryFact(
            cycleId: $resolvedCycleId,
            lastCompletedPhase: is_string($lastRecord['phase'] ?? null) ? $lastRecord['phase'] : null,
            nextPhaseToRun: $this->nextPhaseAfter($lastRecord['phase'] ?? null),
            baseCommitSha: is_string($lastRecord['commit_sha_base'] ?? null) ? $lastRecord['commit_sha_base'] : null,
            mergedSha: array_key_exists('merged_sha', $lastRecord) && is_string($lastRecord['merged_sha']) ? $lastRecord['merged_sha'] : null,
            emittedReceiptIds: $this->readStringList($lastRecord['emitted_receipt_ids'] ?? []),
            heldTaskClaimIds: $this->readStringList($lastRecord['held_task_claim_ids'] ?? []),
            tornTail: false,
        );
    }

    public function pathForCycle(string $cycleId): string
    {
        $this->assertCycleId($cycleId);

        return rtrim($this->baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$cycleId.'.json';
    }

    /**
     * @return array{
     *     is_file: callable(string):bool,
     *     read: callable(string):string|false,
     *     glob: callable(string):array|false,
     *     mtime: callable(string):int|false
     * }
     */
    private function defaultFilesystem(): array
    {
        return [
            'is_file' => static fn (string $path): bool => is_file($path),
            'read' => static fn (string $path): string|false => @file_get_contents($path),
            'glob' => static fn (string $pattern): array|false => glob($pattern),
            'mtime' => static fn (string $path): int|false => @filemtime($path),
        ];
    }

    private function resolveCycleId(?string $cycleId): ?string
    {
        if ($cycleId !== null) {
            $this->assertCycleId($cycleId);

            return $cycleId;
        }

        $paths = ($this->fs['glob'])(rtrim($this->baseDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.json');
        if (! is_array($paths) || $paths === []) {
            return null;
        }

        usort($paths, function (string $left, string $right): int {
            $leftMtime = ($this->fs['mtime'])($left);
            $rightMtime = ($this->fs['mtime'])($right);
            $leftScore = is_int($leftMtime) ? $leftMtime : 0;
            $rightScore = is_int($rightMtime) ? $rightMtime : 0;

            return $rightScore <=> $leftScore ?: strcmp($right, $left);
        });

        $basename = pathinfo($paths[0], PATHINFO_FILENAME);
        $this->assertCycleId($basename);

        return $basename;
    }

    /**
     * @return array<string, mixed>
     */
    private function readState(string $path): array
    {
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
     * @param  array<string, mixed>  $record
     */
    private function recordHashValid(array $record): bool
    {
        $contentHash = $record['content_hash'] ?? null;
        if (! is_string($contentHash) || $contentHash === '') {
            return false;
        }

        return hash('sha256', CanonicalJson::encodeWithout($record, 'content_hash')) === $contentHash;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function lastIntactRecord(array $records): array
    {
        for ($i = count($records) - 1; $i >= 0; $i--) {
            if ($this->recordHashValid($records[$i])) {
                return $records[$i];
            }
        }

        return [];
    }

    private function nextPhaseAfter(mixed $phase): ?string
    {
        if (! is_string($phase)) {
            return null;
        }

        $index = array_search($phase, $this->phaseOrder, true);
        if ($index === false) {
            return null;
        }

        return $this->phaseOrder[$index + 1] ?? null;
    }

    private function notStartedFact(?string $cycleId): AtlasLoopCycleRecoveryFact
    {
        return new AtlasLoopCycleRecoveryFact(
            cycleId: $cycleId,
            lastCompletedPhase: null,
            nextPhaseToRun: $this->phaseOrder[0] ?? null,
            baseCommitSha: null,
            mergedSha: null,
            emittedReceiptIds: [],
            heldTaskClaimIds: [],
            tornTail: false,
        );
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function readStringList(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $entry) {
            if (is_string($entry)) {
                $result[] = $entry;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $phaseOrder
     */
    private function assertUniquePhaseOrder(array $phaseOrder): void
    {
        if ($phaseOrder === []) {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointReader.phaseOrder must not be empty.');
        }

        foreach ($phaseOrder as $phase) {
            if (! is_string($phase) || $phase === '') {
                throw new InvalidArgumentException('AtlasLoopCycleCheckpointReader.phaseOrder must contain non-empty strings.');
            }
        }

        if (count(array_unique($phaseOrder)) !== count($phaseOrder)) {
            throw new InvalidArgumentException('AtlasLoopCycleCheckpointReader.phaseOrder must not contain duplicates.');
        }
    }

    private function assertCycleId(string $cycleId): void
    {
        if ($cycleId === '' || preg_match('/^[A-Za-z0-9._-]{1,160}$/', $cycleId) !== 1) {
            throw new InvalidArgumentException("Invalid cycle_id \"{$cycleId}\".");
        }
    }
}

final readonly class AtlasLoopCycleRecoveryFact
{
    /**
     * @param  list<string>  $emittedReceiptIds
     * @param  list<string>  $heldTaskClaimIds
     */
    public function __construct(
        public ?string $cycleId,
        public ?string $lastCompletedPhase,
        public ?string $nextPhaseToRun,
        public ?string $baseCommitSha,
        public ?string $mergedSha,
        public array $emittedReceiptIds,
        public array $heldTaskClaimIds,
        public bool $tornTail,
    ) {}
}
