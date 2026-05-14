<?php

namespace App\Services\Ai\Programming\Sdd\Pipeline;

/**
 * Outcome of RuntimeExecutor.
 */
final class ExecutionResult
{
    /**
     * @param  list<string>  $writtenFiles
     * @param  list<string>  $rejectedFiles
     * @param  list<string>  $commandsExecuted
     * @param  list<string>  $evidenceRefs
     * @param  list<array<string,mixed>>  $issues
     */
    public function __construct(
        public readonly string $status,
        public readonly array $writtenFiles = [],
        public readonly array $rejectedFiles = [],
        public readonly array $commandsExecuted = [],
        public readonly array $evidenceRefs = [],
        public readonly array $issues = [],
        public readonly ?string $outputHash = null,
    ) {}

    public function ok(): bool
    {
        return $this->status === 'ok' || $this->status === 'partial';
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 'atlas.sdd_execution_result.v1',
            'status' => $this->status,
            'written_files' => $this->writtenFiles,
            'rejected_files' => $this->rejectedFiles,
            'commands_executed' => $this->commandsExecuted,
            'evidence_refs' => $this->evidenceRefs,
            'issues' => $this->issues,
            'output_hash' => $this->outputHash,
        ];
    }
}
