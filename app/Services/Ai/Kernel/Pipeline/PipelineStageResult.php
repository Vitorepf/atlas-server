<?php

namespace App\Services\Ai\Kernel\Pipeline;

final readonly class PipelineStageResult
{
    /**
     * @param  array<int,string>  $reads
     * @param  array<int,string>  $writes
     * @param  array<int,string>  $evidenceRefs
     * @param  array<int,string>  $traceRefs
     * @param  array<string,mixed>  $metadata
     */
    public function __construct(
        public string $stage,
        public string $status,
        public array $reads,
        public array $writes,
        public array $evidenceRefs,
        public array $traceRefs,
        public array $metadata = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'stage' => $this->stage,
            'status' => $this->status,
            'reads' => $this->reads,
            'writes' => $this->writes,
            'evidence_refs' => $this->evidenceRefs,
            'trace_refs' => $this->traceRefs,
            'metadata' => $this->metadata,
        ];
    }
}
