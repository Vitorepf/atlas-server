<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\SeniorLoop;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;

final class SeniorEngineerLoopAuditor
{
    /**
     * @param  array<string,mixed>|null  $desktopGoalAudit
     */
    public function audit(PlanOnlyResult $plan, ?array $desktopGoalAudit = null): SeniorEngineerLoopAudit
    {
        $capabilities = [
            'ambiguity_resolution_engine' => $this->ambiguityResolutionCovered($plan),
            'multi_step_work_planner' => $this->multiStepPlanCovered($plan),
            'autonomous_debug_loop' => $plan->taskContract->validationCommands !== [],
            'architecture_aware_editing' => $this->architectureAware($plan),
            'desktop_engineer_cockpit' => $this->desktopCockpitCovered($plan),
            'learning_error_ledger_curator_flow' => $this->learningHandoffCovered($plan),
            'enterprise_hardening' => $this->enterpriseHardening($plan, $desktopGoalAudit),
        ];

        $blockers = [];
        foreach ($capabilities as $capability => $passed) {
            if (! $passed) {
                $blockers[] = $capability;
            }
        }

        $audit = new SeniorEngineerLoopAudit(
            runId: $plan->envelope->runId,
            status: $blockers === [] ? 'passed' : 'blocked',
            ambiguityResolution: $this->ambiguityResolution($plan),
            multiStepPlan: $this->multiStepPlan($plan),
            debugLoop: $this->debugLoop($plan),
            architectureReview: $this->architectureReview($plan),
            desktopCockpit: $this->desktopCockpit($plan),
            learningHandoff: $this->learningHandoff($plan),
            capabilities: $capabilities,
            blockers: $blockers,
        );

        return $audit->withHash();
    }

    private function architectureAware(PlanOnlyResult $plan): bool
    {
        if (! $plan->classification->writeImplied) {
            return true;
        }

        return $plan->miniSpec->allowedFiles !== []
            && $plan->miniSpec->forbiddenFiles !== []
            && $plan->taskContract->maxFilesChanged >= count($plan->miniSpec->allowedFiles)
            && $plan->promptProjection->isSendable();
    }

    private function ambiguityResolutionCovered(PlanOnlyResult $plan): bool
    {
        if ($plan->discovery->isBlocking()) {
            return $plan->discovery->missingRefs !== []
                && in_array('discovery_blocking_ambiguity', $plan->routing->blockers, true);
        }

        return $plan->discovery->likelyFiles !== []
            && in_array($plan->discovery->confidence, [
                'confirmed_fact',
                'strong_inference',
                'hypothesis',
            ], true);
    }

    private function multiStepPlanCovered(PlanOnlyResult $plan): bool
    {
        $refs = $plan->persistedArtifactRefs();

        foreach ($this->multiStepPlan($plan) as $step) {
            $evidenceRef = (string) ($step['evidence_ref'] ?? '');
            if ($evidenceRef === '' || ! in_array($evidenceRef, $refs, true)) {
                return false;
            }
            if (! in_array((string) ($step['status'] ?? ''), ['completed', 'ready', 'manual_review'], true)) {
                return false;
            }
        }

        return true;
    }

    private function desktopCockpitCovered(PlanOnlyResult $plan): bool
    {
        $cockpit = $this->desktopCockpit($plan);
        $panels = array_values((array) ($cockpit['panels'] ?? []));

        foreach (['intent', 'ambiguity', 'plan', 'scope', 'verification', 'receipt', 'learning'] as $requiredPanel) {
            if (! in_array($requiredPanel, $panels, true)) {
                return false;
            }
        }

        return ($cockpit['run_id'] ?? null) === $plan->envelope->runId
            && ($cockpit['supports_cancel_retry_resume'] ?? null) === true;
    }

    private function learningHandoffCovered(PlanOnlyResult $plan): bool
    {
        $handoff = $this->learningHandoff($plan);

        return ($handoff['auto_apply'] ?? null) === false
            && ($handoff['curator'] ?? null) === 'programming_curator'
            && ($handoff['proposal_inbox_required'] ?? null) === true
            && str_contains((string) ($handoff['error_ledger_ref'] ?? ''), $plan->envelope->runId)
            && (array) ($handoff['signals'] ?? []) !== [];
    }

    /**
     * @param  array<string,mixed>|null  $desktopGoalAudit
     */
    private function enterpriseHardening(PlanOnlyResult $plan, ?array $desktopGoalAudit): bool
    {
        return $plan->persistedArtifactPaths !== []
            && $plan->promptProjection->isProviderSafe()
            && $plan->taskContract->providerLock->fallbackAllowed === false
            && ($desktopGoalAudit === null || ($desktopGoalAudit['status'] ?? null) === 'passed');
    }

    /**
     * @return array<string,mixed>
     */
    private function ambiguityResolution(PlanOnlyResult $plan): array
    {
        $hypotheses = [];
        foreach ($plan->discovery->likelyFiles as $candidate) {
            $relativePath = $this->workspaceRelative($plan, $candidate->path);
            $hypotheses[] = [
                'confidence' => $candidate->confidence,
                'evidence_ref' => 'workspace://'.$relativePath,
                'interpretation' => 'edit_or_inspect:'.$relativePath,
                'reason' => $candidate->reason,
            ];
        }

        usort(
            $hypotheses,
            fn (array $a, array $b): int => $this->hypothesisSortTier($plan, $a) <=> $this->hypothesisSortTier($plan, $b)
                ?: ((float) ($b['confidence'] ?? 0.0) <=> (float) ($a['confidence'] ?? 0.0))
                ?: strcmp((string) ($a['evidence_ref'] ?? ''), (string) ($b['evidence_ref'] ?? '')),
        );

        $decision = $plan->routingKind() === 'blocked'
            ? 'ask_clarifying_question'
            : 'selected_highest_confidence_workspace_interpretation';

        return [
            'decision' => $decision,
            'discovery_confidence' => $plan->discovery->confidence,
            'hypotheses' => $hypotheses,
            'material_uncertainty' => $plan->blockers !== [],
            'selected_interpretation' => $hypotheses[0]['interpretation'] ?? 'repo_bound_task',
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function multiStepPlan(PlanOnlyResult $plan): array
    {
        return [
            [
                'id' => 'step_1',
                'name' => 'resolve_intent_and_scope',
                'status' => 'completed',
                'evidence_ref' => 'receipts/'.$plan->envelope->runId.'/code_discovery_manifest.json',
            ],
            [
                'id' => 'step_2',
                'name' => 'compose_mini_spec_and_task_contract',
                'status' => 'completed',
                'evidence_ref' => 'receipts/'.$plan->envelope->runId.'/task_contract.json',
            ],
            [
                'id' => 'step_3',
                'name' => 'execute_smallest_safe_change',
                'status' => $plan->isFastPath() ? 'ready' : 'blocked',
                'evidence_ref' => 'receipts/'.$plan->envelope->runId.'/routing_decision.json',
            ],
            [
                'id' => 'step_4',
                'name' => 'verify_scope_tests_and_receipt',
                'status' => $plan->taskContract->validationCommands !== [] ? 'ready' : 'manual_review',
                'evidence_ref' => 'receipts/'.$plan->envelope->runId.'/mini_programming_spec.json',
            ],
            [
                'id' => 'step_5',
                'name' => 'project_learning_candidate_without_auto_apply',
                'status' => 'ready',
                'evidence_ref' => 'receipts/'.$plan->envelope->runId.'/routing_decision.json',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function debugLoop(PlanOnlyResult $plan): array
    {
        return [
            'mode' => $plan->classification->taskKind === 'repair' ? 'autonomous_repair_loop' : 'verification_driven_debug_loop',
            'max_attempts' => $plan->taskContract->repairPolicy->maxAttempts,
            'stop_signals' => [
                'same_signature_twice',
                'scope_violation',
                'risk_level_at_or_above_r4',
            ],
            'verification_commands' => array_values($plan->taskContract->validationCommands),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function architectureReview(PlanOnlyResult $plan): array
    {
        return [
            'allowed_files' => array_values($plan->miniSpec->allowedFiles),
            'forbidden_files' => array_values($plan->miniSpec->forbiddenFiles),
            'max_files_changed' => $plan->taskContract->maxFilesChanged,
            'non_goals' => array_values($plan->miniSpec->nonGoals),
            'provider_fallback_allowed' => $plan->taskContract->providerLock->fallbackAllowed,
            'verdict' => $this->architectureAware($plan) ? 'bounded_change' : 'blocked_unbounded_change',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function desktopCockpit(PlanOnlyResult $plan): array
    {
        return [
            'panels' => [
                'intent',
                'ambiguity',
                'plan',
                'scope',
                'verification',
                'receipt',
                'learning',
            ],
            'run_id' => $plan->envelope->runId,
            'routing_decision' => $plan->routingKind(),
            'supports_cancel_retry_resume' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningHandoff(PlanOnlyResult $plan): array
    {
        return [
            'auto_apply' => false,
            'curator' => 'programming_curator',
            'error_ledger_ref' => 'receipts/'.$plan->envelope->runId.'/error_ledger_v*.json',
            'proposal_inbox_required' => true,
            'signals' => [
                'routing_decision='.$plan->routingKind(),
                'task_kind='.$plan->classification->taskKind,
                'risk_level='.$plan->riskLevel,
            ],
        ];
    }

    private function workspaceRelative(PlanOnlyResult $plan, string $path): string
    {
        $workspace = rtrim($plan->envelope->workspace, '/').'/';
        if (str_starts_with($path, $workspace)) {
            return substr($path, strlen($workspace));
        }

        return basename($path);
    }

    /**
     * @param  array<string,mixed>  $hypothesis
     */
    private function hypothesisSortTier(PlanOnlyResult $plan, array $hypothesis): int
    {
        $interpretation = (string) ($hypothesis['interpretation'] ?? '');
        $path = str_starts_with($interpretation, 'edit_or_inspect:')
            ? substr($interpretation, strlen('edit_or_inspect:'))
            : $interpretation;

        return in_array($path, $plan->taskContract->allowedFiles, true) ? 0 : 1;
    }
}
