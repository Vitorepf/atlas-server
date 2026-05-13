<?php

namespace App\Services\Ai\Programming;

class ProgrammingResumeService
{
    public function __construct(
        private readonly ProgrammingStageReceiptValidator $validator,
        private readonly ProgrammingStageReceiptStore $stageReceipts,
    ) {}

    /**
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @return array<string,mixed>
     */
    public function state(string $planId, ?string $parentPlanId, array $previousReceipts = []): array
    {
        $loadedFromStore = false;
        if ($previousReceipts === []) {
            $previousReceipts = $this->stageReceipts->timeline($parentPlanId ?: $planId);
            $loadedFromStore = $previousReceipts !== [];
        }

        $validation = $this->validator->validateTimeline($previousReceipts);
        $latest = collect($previousReceipts)->last();

        return [
            'schema_version' => 'atlas.programming.resume_state.v1',
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'resumed' => $parentPlanId !== null,
            'previous_stage_receipt_count' => count($previousReceipts),
            'previous_stage_receipt_source' => $loadedFromStore ? 'stage_receipt_store' : 'provided_payload',
            'latest_stage' => is_array($latest) ? ($latest['stage'] ?? null) : null,
            'latest_status' => is_array($latest) ? ($latest['status'] ?? null) : null,
            'timeline_validation' => $validation,
            'resume_allowed' => $parentPlanId === null || $validation['valid'],
            'blocks_when_invalid' => true,
            'must_load_open_brain' => true,
            'must_preserve_prior_decisions' => true,
        ];
    }
}
