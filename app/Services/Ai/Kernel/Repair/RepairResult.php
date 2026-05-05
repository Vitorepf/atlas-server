<?php

namespace App\Services\Ai\Kernel\Repair;

final readonly class RepairResult
{
    /**
     * @param  array<string,mixed>  $evidencePayload
     */
    public function __construct(
        public RepairRequest $request,
        public RepairDecision $decision,
        public ?RepairAttempt $attempt,
        public bool $executed,
        public array $evidencePayload,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'request' => $this->request->toArray(),
            'decision' => $this->decision->toArray(),
            'attempt' => $this->attempt?->toArray(),
            'executed' => $this->executed,
            'evidence_payload' => $this->evidencePayload,
        ];
    }
}
