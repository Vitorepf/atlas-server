<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

/**
 * Pilar 1 · Plan Execution · SIMULATION executor.
 *
 * Produces a deterministic, honestly-shaped loop cycle for a slice so the plan-driven
 * runner's wiring (select -> execute -> record -> rollup) can be proven end-to-end
 * WITHOUT a live provider. It is NOT a real build: isSimulated() is always true and the
 * runner stamps `simulated=true` on its report, so nothing downstream can mistake a
 * simulated run for real delivery.
 *
 * Per-slice outcomes can be scripted to exercise honest non-advance:
 *  - 'merged'           : honest merged cycle (the tracker will derive delivered).
 *  - 'router_used'      : merged but provider_router_used=true (tracker: never delivered).
 *  - 'validation_failed': merged but validation failed (tracker: acceptance not met).
 *  - 'no_merge'         : cycle did not merge (tracker: in_progress / not delivered).
 */
final class DeterministicPlanSliceCycleExecutor implements PlanSliceCycleExecutor
{
    public const OUTCOME_MERGED = 'merged';

    public const OUTCOME_ROUTER_USED = 'router_used';

    public const OUTCOME_VALIDATION_FAILED = 'validation_failed';

    public const OUTCOME_NO_MERGE = 'no_merge';

    /** @var array<string,string> slice_id => outcome */
    private array $sliceOutcomes;

    private int $seq = 0;

    /**
     * @param  array<string,string>  $sliceOutcomes  slice_id => outcome (default OUTCOME_MERGED)
     */
    public function __construct(array $sliceOutcomes = [])
    {
        $this->sliceOutcomes = $sliceOutcomes;
    }

    public function isSimulated(): bool
    {
        return true;
    }

    /**
     * @param  array<string,mixed>  $slice
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function executeSlice(array $slice, array $context): array
    {
        $sliceId = (string) ($slice['slice_id'] ?? '');
        $findingId = (string) ($slice['finding_id'] ?? $sliceId);
        $title = (string) ($slice['title'] ?? ('slice '.$sliceId));
        $outcome = $this->sliceOutcomes[$sliceId] ?? self::OUTCOME_MERGED;
        $this->seq++;

        $merged = $outcome !== self::OUTCOME_NO_MERGE;
        $routerUsed = $outcome === self::OUTCOME_ROUTER_USED;
        $validationPassed = $outcome !== self::OUTCOME_VALIDATION_FAILED;

        $cycle = [
            'cycle_id' => 'sim_'.$sliceId.'_'.$this->seq,
            'cycle_index' => (int) ($context['cycle_index'] ?? $this->seq),
            'final_status' => $merged ? 'cycle_completed' : 'owner_flow_result_failed',
            'merge_performed' => $merged,
            'blockers' => $merged ? [] : ['simulated_no_merge'],
            'owner' => (string) ($slice['owner'] ?? 'atlas_dev'),
            'selected_finding' => ['finding_id' => $findingId, 'title' => $title],
            'changed_files' => $merged ? ['app/Generated/'.$sliceId.'.php'] : [],
            'validation' => [
                'passed' => $validationPassed,
                'commands' => ['php artisan test'],
                'results' => [['ok' => $validationPassed]],
            ],
            'merge_governance' => $merged
                ? ['status' => 'merged', 'merge_commit' => 'sim'.substr(md5($sliceId.$this->seq), 0, 10)]
                : ['status' => 'not_evaluated'],
            'result_bridge_id' => 'sim_rb_'.$sliceId,
            'inbox_item_id' => 'sim_inbox_'.$sliceId,
            'owner_flow' => ['provider_router_used' => $routerUsed],
            'owner_result' => ['runtime_invocation' => ['command_result' => ['owner_cli_provider_calls' => $routerUsed ? 0 : 1]]],
            'simulated' => true,
            'plan_slice_id' => $sliceId,
        ];

        return $cycle;
    }
}
