<?php

namespace App\Services\Ai\Programming\Sdd\Pipeline;


/**
 * Immutable envelope describing a request the SDD pipeline must process.
 *
 * Mirrors the OperationEnvelope concept from data-model-and-services.md:227.
 */
final class SddPipelineOperationEnvelope
{
    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $hints
     */
    public function __construct(
        public readonly string $rawInput,
        public readonly ?string $tenantId = null,
        public readonly ?string $userId = null,
        public readonly ?string $projectId = null,
        public readonly ?string $workItemId = null,
        public readonly ?string $workspace = null,
        public readonly array $context = [],
        public readonly array $hints = [],
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'raw_input' => $this->rawInput,
            'tenant_id' => $this->tenantId,
            'user_id' => $this->userId,
            'project_id' => $this->projectId,
            'work_item_id' => $this->workItemId,
            'workspace' => $this->workspace,
            'context' => $this->context,
            'hints' => $this->hints,
        ];
    }
}
