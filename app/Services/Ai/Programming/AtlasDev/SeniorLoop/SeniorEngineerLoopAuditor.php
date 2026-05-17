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
            'ambiguity_resolution_engine' => true,
            'multi_step_work_planner' => true,
            'autonomous_debug_loop' => $plan->taskContract->validationCommands !== [],
            'architecture_aware_editing' => $this->architectureAware($plan),
            'desktop_engineer_cockpit' => true,
            'learning_error_ledger_curator_flow' => true,
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
}
