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
        $latestStage = is_array($latest) ? ($latest['stage'] ?? null) : null;
        $latestStatus = is_array($latest) ? ($latest['status'] ?? null) : null;
        $resumeAllowed = $parentPlanId === null || $validation['valid'];

        return [
            'schema_version' => 'atlas.programming.resume_state.v1',
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'resumed' => $parentPlanId !== null,
            'previous_stage_receipt_count' => count($previousReceipts),
            'previous_stage_receipt_source' => $loadedFromStore ? 'stage_receipt_store' : 'provided_payload',
            'latest_stage' => $latestStage,
            'latest_status' => $latestStatus,
            'timeline_validation' => $validation,
            'resume_allowed' => $resumeAllowed,
            'continuation_packet' => $this->continuationPacket($planId, $parentPlanId, $previousReceipts, $latestStage, $latestStatus, $resumeAllowed),
            'blocks_when_invalid' => true,
            'must_load_open_brain' => true,
            'must_preserve_prior_decisions' => true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $previousReceipts
     * @return array<string,mixed>
     */
    private function continuationPacket(string $planId, ?string $parentPlanId, array $previousReceipts, mixed $latestStage, mixed $latestStatus, bool $resumeAllowed): array
    {
        $completedStages = collect($previousReceipts)
            ->filter(fn (array $receipt): bool => in_array((string) ($receipt['status'] ?? ''), ['passed', 'completed'], true))
            ->pluck('stage')
            ->filter()
            ->map(fn (mixed $stage): string => (string) $stage)
            ->unique()
            ->values()
            ->all();
        $stageOrder = ['plan', 'review', 'patch', 'test', 'repair'];
        $missingStages = array_values(array_diff($stageOrder, $completedStages));
        $nextStage = $resumeAllowed
            ? $this->nextStage($latestStage, $latestStatus, $missingStages)
            : 'human_review';

        return [
            'schema_version' => 'atlas.programming.continuation_packet.v1',
            'status' => $resumeAllowed ? 'ready' : 'blocked',
            'plan_id' => $planId,
            'parent_plan_id' => $parentPlanId,
            'latest_stage' => $latestStage,
            'latest_status' => $latestStatus,
            'completed_stages' => $completedStages,
            'missing_stage_receipts' => $missingStages,
            'next_stage' => $nextStage,
            'resume_command' => $parentPlanId === null
                ? null
                : 'php artisan atlas:programming:resume '.$parentPlanId.' --plan-id='.$planId.' --json',
            'required_before_next_provider_call' => [
                'load_stage_receipts',
                'load_open_brain_context',
                'preserve_prior_decision_receipts',
                'attach_action_manifest_for_next_write',
            ],
            'stop_rules' => [
                'invalid_timeline_requires_human_review' => true,
                'missing_prior_decision_blocks_write' => true,
                'failed_latest_stage_routes_to_repair' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $missingStages
     */
    private function nextStage(mixed $latestStage, mixed $latestStatus, array $missingStages): string
    {
        if (in_array((string) $latestStatus, ['failed', 'blocked'], true)) {
            return 'repair';
        }

        if ($missingStages !== []) {
            return $missingStages[0];
        }

        return match ((string) $latestStage) {
            'plan' => 'review',
            'review' => 'patch',
            'patch' => 'test',
            'test' => 'finalize',
            'repair' => 'test',
            default => 'plan',
        };
    }
}
