<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\SeniorLoop;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalHasher;
use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use InvalidArgumentException;

final class SeniorEngineerLoopExecution implements AtlasDevSchemaContract
{
    public const SCHEMA_VERSION = 'atlas.dev.senior_engineer_loop_execution.v1';

    /**
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,mixed>  $runSummary
     * @param  array<string,mixed>  $debugLoop
     * @param  array<string,mixed>  $learning
     * @param  list<string>  $blockers
     */
    public function __construct(
        public readonly string $runId,
        public readonly string $status,
        public readonly array $steps,
        public readonly array $runSummary,
        public readonly array $debugLoop,
        public readonly array $learning,
        public readonly array $blockers,
        public readonly string $executionHash = '',
    ) {
        if ($this->runId === '') {
            throw new InvalidArgumentException('SeniorEngineerLoopExecution.run_id must not be empty.');
        }
        if (! in_array($this->status, ['passed', 'blocked', 'failed', 'needs_review'], true)) {
            throw new InvalidArgumentException('SeniorEngineerLoopExecution.status is invalid.');
        }
    }

    public function withHash(): self
    {
        return new self(
            runId: $this->runId,
            status: $this->status,
            steps: $this->steps,
            runSummary: $this->runSummary,
            debugLoop: $this->debugLoop,
            learning: $this->learning,
            blockers: $this->blockers,
            executionHash: CanonicalHasher::hashWithout($this->toCanonicalArray(), 'execution_hash'),
        );
    }

    public function schemaVersion(): string
    {
        return self::SCHEMA_VERSION;
    }

    public function toCanonicalArray(): array
    {
        return CanonicalJson::canonicalize([
            'blockers' => array_values($this->blockers),
            'debug_loop' => $this->debugLoop,
            'execution_hash' => $this->executionHash,
            'learning' => $this->learning,
            'provider_safe' => true,
            'run_id' => $this->runId,
            'run_summary' => $this->runSummary,
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $this->status,
            'steps' => array_values($this->steps),
        ]);
    }

    public function toProviderSafeArray(): array
    {
        return $this->toCanonicalArray();
    }

    public function toJson(): string
    {
        return CanonicalJson::encode($this->toCanonicalArray());
    }

    public function hash(): string
    {
        return CanonicalHasher::hash($this->toCanonicalArray());
    }

    public function isProviderSafe(): bool
    {
        return true;
    }
}
