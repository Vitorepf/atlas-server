<?php

namespace App\Services\Ai\Programming\Frontend;

use App\Services\Ai\Mission\MissionCanonicalHash;
use Illuminate\Support\Str;

final class AtlasFrontendRepairPlannerService
{
    public const SCHEMA_VERSION = 'atlas.frontend.repair_plan.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function plan(array $input): array
    {
        $task = trim((string) ($input['task'] ?? ''));
        $blockers = $this->slugs((array) ($input['blockers'] ?? []));
        $warnings = $this->slugs((array) ($input['warnings'] ?? []));
        $failedGates = $this->slugs((array) ($input['failed_gates'] ?? []));
        $taskSpecHash = strtolower(trim((string) ($input['task_spec_hash'] ?? '')));
        $signals = array_values(array_unique(array_merge($blockers, $warnings, $failedGates)));
        $steps = $this->steps($signals);

        $plan = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $steps === [] && $task === '' ? 'blocked' : 'ready',
            'source' => self::class,
            'task_hash' => $task !== '' ? hash('sha256', $task) : null,
            'task_spec_hash' => preg_match('/^[a-f0-9]{64}$/', $taskSpecHash) ? $taskSpecHash : null,
            'raw_task_returned' => false,
            'severity' => $this->severity($signals),
            'repair_strategy' => $this->strategy($signals),
            'inputs' => [
                'blockers' => $blockers,
                'warnings' => $warnings,
                'failed_gates' => $failedGates,
            ],
            'repair_steps' => $steps,
            'rerun_gates' => $this->rerunGates($steps),
            'evidence_required' => $this->evidenceRequired($steps),
            'stop_conditions' => [
                'same_blocker_repeats_twice',
                'source_patch_boundary_conflict',
                'visual_score_regresses',
                'security_or_billing_gate_blocks',
            ],
            'claim_policy' => [
                'repair_plan_is_not_completion_evidence' => true,
                'completion_requires_rerun_gates_passed' => true,
                'raw_customer_source_returned' => false,
            ],
            'blockers' => $steps === [] ? ['no_repair_signal_provided'] : [],
        ];
        $plan['repair_plan_hash'] = MissionCanonicalHash::sha256($plan);

        return $plan;
    }

    /**
     * @return array<int,string>
     */
    public function knownSignals(): array
    {
        return [
            'task_spec_acceptance_context_required',
            'task_spec_hash_mismatch',
            'visual_quality_gate',
            'design_review_score_below_threshold',
            'anti_ai_slop_detector',
            'text_overlap',
            'missing_viewport_mobile',
            'design_system_drift_gate',
            'asset_pack_verifier',
            'performance_budget_gate',
            'live_source_patch_boundary_gate',
            'company_design_profile_required',
            'evidence_pack_verifier',
        ];
    }

    /**
     * @param  array<int,string>  $signals
     * @return array<int,array<string,mixed>>
     */
    private function steps(array $signals): array
    {
        $steps = [];
        foreach ($this->knownSignals() as $signal) {
            if (! $this->matches($signals, $signal)) {
                continue;
            }
            $steps[] = $this->stepFor($signal);
        }

        if ($steps === [] && $signals !== []) {
            $steps[] = [
                'id' => 'repair_unknown_frontend_failure',
                'priority' => 90,
                'target' => 'unknown_frontend_failure',
                'action' => 'Collect task spec, visual quality report, design review, console/a11y/performance receipts and rerun gate.',
                'rerun_gates' => ['frontend_execution_gate', 'visual_quality_gate', 'evidence_pack_verifier'],
                'evidence' => ['frontend_repair_receipt'],
            ];
        }

        return collect($steps)->sortBy('priority')->values()->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function stepFor(string $signal): array
    {
        return match ($signal) {
            'task_spec_acceptance_context_required' => $this->step(10, 'repair_task_spec_acceptance', 'task_spec', 'Add explicit acceptance context, routes, states and viewports before execution.', ['frontend_execution_gate'], ['task_spec_hash']),
            'task_spec_hash_mismatch' => $this->step(11, 'repair_task_spec_hash', 'task_spec', 'Recompile task spec and attach the matching canonical hash to the gate.', ['frontend_execution_gate'], ['task_spec_hash']),
            'company_design_profile_required' => $this->step(15, 'repair_company_profile', 'company_profile', 'Inspect or attach a ready company design profile before broad/multi-company visual work.', ['frontend_execution_gate', 'design_system_drift_gate'], ['company_profile_hash']),
            'asset_pack_verifier' => $this->step(20, 'repair_asset_pack', 'assets', 'Replace placeholder/unlicensed assets or add provenance, dimensions and rights metadata.', ['asset_pack_verifier', 'visual_quality_gate'], ['frontend_asset_pack']),
            'design_system_drift_gate' => $this->step(25, 'repair_design_system_drift', 'design_system', 'Reuse approved tokens/components or attach explicit approval for new tokens/components.', ['design_system_drift_gate', 'visual_quality_gate'], ['design_system_drift_report']),
            'text_overlap' => $this->step(30, 'repair_text_overlap', 'layout', 'Fix stable dimensions, wrapping and responsive constraints for affected UI.', ['visual_quality_gate'], ['screenshots_by_viewport']),
            'missing_viewport_mobile' => $this->step(35, 'repair_mobile_viewport', 'responsive', 'Add mobile viewport verification and fix layout regressions before visual claim.', ['visual_quality_gate'], ['mobile_screenshot_set']),
            'anti_ai_slop_detector' => $this->step(40, 'repair_ai_slop', 'visual_craft', 'Remove generic AI tropes, one-note palettes, nested cards or weak visual hierarchy.', ['anti_ai_slop_detector', 'design_5d_review', 'visual_quality_gate'], ['anti_slop_report']),
            'design_review_score_below_threshold' => $this->step(45, 'repair_5d_design_score', 'design_review', 'Improve weakest 5D dimensions and rerun design review with evidence refs.', ['design_5d_review', 'visual_quality_gate'], ['design_review_report']),
            'performance_budget_gate' => $this->step(50, 'repair_frontend_performance', 'performance', 'Reduce bundle/render cost or document budget exception with receipt.', ['performance_budget_gate', 'visual_quality_gate'], ['performance_receipt']),
            'live_source_patch_boundary_gate' => $this->step(55, 'repair_live_patch_boundary', 'live_mode', 'Recreate live patch from clean source boundary and verify accept/discard/recover.', ['live_source_patch_boundary_gate', 'visual_quality_gate'], ['live_iteration_journal']),
            'visual_quality_gate' => $this->step(60, 'repair_visual_quality', 'visual_quality', 'Address visual quality blockers, regenerate artifacts and rerun multi-viewport checks.', ['visual_quality_gate', 'evidence_pack_verifier'], ['visual_quality_report']),
            'evidence_pack_verifier' => $this->step(70, 'repair_evidence_pack', 'evidence', 'Attach missing artifact files with matching hashes and provider-safe refs.', ['evidence_pack_verifier'], ['evidence_pack']),
        };
    }

    /**
     * @param  array<int,string>  $rerunGates
     * @param  array<int,string>  $evidence
     * @return array<string,mixed>
     */
    private function step(int $priority, string $id, string $target, string $action, array $rerunGates, array $evidence): array
    {
        return compact('priority', 'id', 'target', 'action', 'rerunGates', 'evidence');
    }

    /**
     * @param  array<int,string>  $signals
     */
    private function matches(array $signals, string $needle): bool
    {
        return collect($signals)->contains(fn (string $signal): bool => $signal === $needle || str_contains($signal, $needle));
    }

    /**
     * @param  array<int,array<string,mixed>>  $steps
     * @return array<int,string>
     */
    private function rerunGates(array $steps): array
    {
        return collect($steps)->flatMap(fn (array $step): array => (array) ($step['rerunGates'] ?? []))->unique()->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $steps
     * @return array<int,string>
     */
    private function evidenceRequired(array $steps): array
    {
        return collect($steps)->flatMap(fn (array $step): array => (array) ($step['evidence'] ?? []))->unique()->values()->all();
    }

    /**
     * @param  array<int,string>  $signals
     */
    private function severity(array $signals): string
    {
        if ($this->matches($signals, 'company_design_profile_required') || $this->matches($signals, 'task_spec_hash_mismatch')) {
            return 'high';
        }
        if ($this->matches($signals, 'visual_quality_gate') || $this->matches($signals, 'design_review_score_below_threshold') || $this->matches($signals, 'design_system_drift_gate')) {
            return 'medium';
        }

        return $signals === [] ? 'unknown' : 'low';
    }

    /**
     * @param  array<int,string>  $signals
     */
    private function strategy(array $signals): string
    {
        if ($this->matches($signals, 'live_source_patch_boundary_gate')) {
            return 'recover_then_patch';
        }
        if ($this->matches($signals, 'company_design_profile_required') || $this->matches($signals, 'task_spec')) {
            return 'context_first';
        }
        if ($this->matches($signals, 'visual_quality_gate') || $this->matches($signals, 'design_review')) {
            return 'visual_quality_loop';
        }

        return 'evidence_first';
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function slugs(array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => Str::of($value)->lower()->replaceMatches('/[^a-z0-9_.-]+/', '_')->trim('_')->toString())
            ->filter()
            ->unique()
            ->values()
            ->all();
    }
}
