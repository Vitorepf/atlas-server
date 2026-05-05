<?php

namespace App\Services\Ai\Kernel\Repair;

final readonly class RepairAttempt
{
    /**
     * @param  array<int,string>  $evidenceRefs
     * @param  array<int,string>  $reasons
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public int $attemptNumber,
        public ?string $strategy,
        public bool $executed,
        public bool $dryRun,
        public array $evidenceRefs = [],
        public array $reasons = [],
        public array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'attempt_number' => $this->attemptNumber,
            'strategy' => $this->strategy,
            'executed' => $this->executed,
            'dry_run' => $this->dryRun,
            'evidence_refs' => $this->evidenceRefs,
            'reasons' => $this->reasons,
            'metadata' => $this->metadata,
        ];
    }
}
