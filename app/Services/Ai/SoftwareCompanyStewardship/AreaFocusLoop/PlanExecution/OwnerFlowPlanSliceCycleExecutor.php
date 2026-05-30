<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowRunner;

/**
 * Pilar 1 · Plan Execution · REAL slice executor.
 *
 * Bridges a decomposed-plan slice to the loop's AP-786 owner flow (atlas_dev senior loop
 * for owner=atlas_dev; forge dispatch for owner=forge), which is the same engine the 24h
 * loop runs each cycle. The owner-flow report IS the cycle: it is returned unchanged so the
 * PlanCompletionTrackerService can DERIVE delivery/provider-proof/acceptance from the real
 * runtime signals.
 *
 * Honesty: this executor never sets merge/provider flags itself. If the owner flow blocks
 * (no provider capacity, no governed Obra, AWIS gate, ...) the report carries no merge and
 * the tracker correctly records the slice as not delivered. Real delivery requires a real
 * owner-flow cycle with allowed changed files + provider proof — exactly as in every other
 * loop cycle.
 */
final class OwnerFlowPlanSliceCycleExecutor implements PlanSliceCycleExecutor
{
    public function __construct(
        private readonly Ap786OwnerFlowRunner $ownerFlow,
    ) {}

    public function isSimulated(): bool
    {
        return false;
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
        $owner = (string) ($slice['owner'] ?? 'atlas_dev');

        $finding = [
            'finding_id' => $findingId,
            'id' => $findingId,
            'title' => (string) ($slice['title'] ?? ('slice '.$sliceId)),
            'owner_candidate' => $owner,
            'acceptance_criteria' => $slice['acceptance_criteria'] ?? [],
            'affected_files' => $slice['affected_files'] ?? [],
            'spec_seed' => $slice['spec_seed'] ?? [],
        ];

        $ownerFlowInput = [
            'finding' => $finding,
            'owner' => $owner,
            'area_id' => (string) ($context['area_id'] ?? 'agentic_engineering_os'),
            'scope_profile' => (string) ($context['scope_profile'] ?? ''),
            'cycle_index' => (int) ($context['cycle_index'] ?? 0),
        ];
        // Pass through any caller-supplied real authority (forge topology/decision/AWIS,
        // worktree, sandbox). We never fabricate these; absent them the owner flow blocks.
        foreach (['forge_obra', 'forge_live_topology', 'forge_live_decision', 'forge_awis_ready',
            'forge_provider_authorization', 'forge_budget_approved', 'worktree_path', 'sandbox_id',
            'allowed_files', 'session_id'] as $passthrough) {
            if (array_key_exists($passthrough, $context)) {
                $ownerFlowInput[$passthrough] = $context[$passthrough];
            }
        }

        $report = $this->ownerFlow->execute($ownerFlowInput);

        if (! is_array($report)) {
            $report = [];
        }

        // The owner-flow report is the cycle. Tag it with the slice so the tracker's
        // finding_id->slice_id join is unambiguous; never mutate merge/provider signals.
        if (! isset($report['selected_finding']) || ! is_array($report['selected_finding'])) {
            $report['selected_finding'] = ['finding_id' => $findingId, 'title' => $finding['title']];
        }
        $report['plan_slice_id'] = $sliceId;
        $report['simulated'] = false;

        return $report;
    }
}
