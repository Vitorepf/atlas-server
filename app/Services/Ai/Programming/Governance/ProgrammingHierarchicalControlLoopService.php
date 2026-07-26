<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasProgrammingGateRun;
use App\Models\AtlasProgrammingReview;
use App\Models\AtlasProgrammingWorkItem;

/**
 * Hierarchical controller for long AI programming sessions.
 *
 * H-level keeps the strategic contract coherent: intent, spec, plan, risk,
 * scope, required gates, review posture and canonical governance boundaries.
 * L-level reads the execution truth: receipts, gate runs, task progress and
 * concrete blockers. The final output is a halt decision used by gates,
 * commands, Atlas Code and Forge.
 *
 * @see docs/engineering-knowledge-base/atlas-hierarchical-control-loop.md
 */
class ProgrammingHierarchicalControlLoopService
{
    public const STATE_SCHEMA_VERSION = 'atlas.programming.hierarchical_control_state.v1';

    public const DECISION_SCHEMA_VERSION = 'atlas.programming.halt_decision.v1';

    public const ACTION_CONTINUE = 'continue';

    public const ACTION_REPAIR = 'repair';

    public const ACTION_REPLAN = 'replan';

    public const ACTION_ESCALATE = 'escalate';

    public const ACTION_SUBMIT = 'submit';

    /** @var list<string> */
    private const FINAL_GATES = ['hierarchical-control', 'completion'];

    /** @var list<string> */
    private const STRATEGIC_GATES = [
        'feature-placement',
        'code-intelligence-context',
        'spec-before-code',
    ];

    /** @var list<string> */
    private const QUALITY_GATES = [
        'docs-health',
        'implementation-truth',
        'cartography-update',
        'scope-guard',
        'evidence-required',
    ];

    /**
     * @return array<string,mixed>
     */
    public function evaluate(AtlasProgrammingWorkItem $workItem): array
    {
        $workItem = $workItem->refresh();
        $state = $this->buildState($workItem);
        $decision = $this->decide($state);

        return [
            'schema_version' => self::STATE_SCHEMA_VERSION,
            'work_item_id' => $workItem->id,
            'work_item_code' => $workItem->code,
            'controller' => [
                'name' => 'Atlas Hierarchical Control Loop',
                'abbreviation' => 'AHCL',
                'mode' => 'programming_governance_runtime',
                'claim' => 'governed_halt_decision_for_long_ai_programming_sessions',
            ],
            'h_state' => $state['h_state'],
            'l_state' => $state['l_state'],
            'halt_decision' => $decision,
            'readiness_score' => $this->readinessScore($state, $decision),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @return array{h_state: array<string,mixed>, l_state: array<string,mixed>}
     */
    private function buildState(AtlasProgrammingWorkItem $workItem): array
    {
        $mode = ProgrammingScopeMode::from($workItem->scope_mode);
        $requiredGates = $mode->requiredGates();
        $blockingGates = array_values(array_filter(
            $requiredGates,
            static fn (string $gate): bool => $mode->isBlocking($gate),
        ));
        $priorBlockingGates = array_values(array_filter(
            $blockingGates,
            static fn (string $gate): bool => ! in_array($gate, self::FINAL_GATES, true),
        ));

        $latestReview = $workItem->latestReview();
        $evidenceRefs = array_values((array) $workItem->evidence_refs_json);
        $tasks = array_values((array) $workItem->tasks_json);
        $gatePosture = $this->gatePosture($workItem, $requiredGates, $priorBlockingGates);

        return [
            'h_state' => [
                'schema_version' => 'atlas.programming.h_state.v1',
                'intent' => [
                    'text' => $workItem->intent_text,
                    'type' => $workItem->intent_type,
                    'scope_mode' => $workItem->scope_mode,
                    'risk_level' => $workItem->risk_level,
                ],
                'strategic_contract' => [
                    'status' => $workItem->status,
                    'current_stage' => $workItem->current_stage,
                    'spec_present' => $workItem->spec_hash !== null,
                    'spec_hash' => $workItem->spec_hash,
                    'plan_present' => $workItem->plan_hash !== null,
                    'plan_hash' => $workItem->plan_hash,
                    'task_contract_count' => count($tasks),
                    'review_result' => $latestReview?->result,
                    'review_id' => $latestReview?->id,
                ],
                'governance_contract' => [
                    'required_gates' => $requiredGates,
                    'blocking_gates' => $blockingGates,
                    'prior_blocking_gates' => $priorBlockingGates,
                    'advisory_gates' => $mode->advisoryGates(),
                    'completion_gate_requires_ahcl' => in_array('hierarchical-control', $requiredGates, true),
                    'canonical_runtime_boundary' => 'Programming Governance; Forge and Atlas Dev consume the same decision contract.',
                    'anti_sprawl_rule' => 'Do not create a parallel programming runtime; extend this controller or its gates.',
                ],
                'unresolved_gaps' => array_values((array) $workItem->gaps_json),
            ],
            'l_state' => [
                'schema_version' => 'atlas.programming.l_state.v1',
                'execution_truth' => [
                    'evidence_count' => count($evidenceRefs),
                    'latest_evidence' => $evidenceRefs === [] ? null : $evidenceRefs[array_key_last($evidenceRefs)],
                    'task_contracts' => $this->summarizeTasks($tasks),
                ],
                'gate_posture' => $gatePosture,
                'review' => $this->reviewPayload($latestReview),
            ],
        ];
    }

    /**
     * @param  list<string>  $requiredGates
     * @param  list<string>  $priorBlockingGates
     * @return array<string,mixed>
     */
    private function gatePosture(
        AtlasProgrammingWorkItem $workItem,
        array $requiredGates,
        array $priorBlockingGates,
    ): array {
        $latest = [];
        $missingBlocking = [];
        $failedBlocking = [];
        $waivedBlocking = [];

        foreach ($requiredGates as $gate) {
            $run = $workItem->latestGate($gate);
            $latest[$gate] = $this->gateRunPayload($run);

            if (! in_array($gate, $priorBlockingGates, true)) {
                continue;
            }

            if ($run === null) {
                $missingBlocking[] = $gate;

                continue;
            }

            if ($run->status === 'failed') {
                $failedBlocking[] = [
                    'gate' => $gate,
                    'reason' => $run->reason,
                    'payload' => $run->payload_json,
                ];

                continue;
            }

            if ($run->status === 'waived') {
                $waivedBlocking[] = [
                    'gate' => $gate,
                    'reason' => $run->waiver_reason,
                ];
            }
        }

        return [
            'latest_by_gate' => $latest,
            'missing_prior_blocking_gates' => $missingBlocking,
            'failed_prior_blocking_gates' => $failedBlocking,
            'waived_prior_blocking_gates' => $waivedBlocking,
            'prior_blocking_green' => $missingBlocking === [] && $failedBlocking === [],
        ];
    }

    /**
     * @param  array{h_state: array<string,mixed>, l_state: array<string,mixed>}  $state
     * @return array<string,mixed>
     */
    private function decide(array $state): array
    {
        $h = $state['h_state'];
        $l = $state['l_state'];
        $scopeMode = (string) data_get($h, 'intent.scope_mode');
        $currentStage = (string) data_get($h, 'strategic_contract.current_stage');
        $status = (string) data_get($h, 'strategic_contract.status');
        $specPresent = (bool) data_get($h, 'strategic_contract.spec_present', false);
        $planPresent = (bool) data_get($h, 'strategic_contract.plan_present', false);
        $taskCount = (int) data_get($h, 'strategic_contract.task_contract_count', 0);
        $evidenceCount = (int) data_get($l, 'execution_truth.evidence_count', 0);
        $reviewResult = data_get($h, 'strategic_contract.review_result');
        $missingGates = array_values((array) data_get($l, 'gate_posture.missing_prior_blocking_gates', []));
        $failedGates = array_values((array) data_get($l, 'gate_posture.failed_prior_blocking_gates', []));
        $priorBlockingGreen = (bool) data_get($l, 'gate_posture.prior_blocking_green', false);

        if ($scopeMode === ProgrammingScopeMode::Structural->value && ! $specPresent) {
            return $this->decision(
                self::ACTION_REPLAN,
                'h_level_spec_missing',
                'Create or attach the canonical spec before execution continues.',
                'php artisan atlas:programming:spec <work_item> ... --json',
                ['spec_before_code'],
                hCycleRequired: true,
            );
        }

        if ($scopeMode === ProgrammingScopeMode::Structural->value && (! $planPresent || $taskCount === 0)) {
            return $this->decision(
                self::ACTION_REPLAN,
                'h_level_plan_or_task_contract_missing',
                'Create a plan and task contracts before execution continues.',
                'php artisan atlas:programming:plan <work_item> ... --json',
                ['plan_required', 'task_contracts_required'],
                hCycleRequired: true,
            );
        }

        if ($failedGates !== []) {
            $failedNames = array_values(array_map(static fn (array $gate): string => (string) $gate['gate'], $failedGates));
            $strategicFailure = $this->intersects($failedNames, self::STRATEGIC_GATES);
            $qualityFailure = $this->intersects($failedNames, self::QUALITY_GATES);

            if ($strategicFailure) {
                return $this->decision(
                    self::ACTION_REPLAN,
                    'h_level_strategic_gate_failed',
                    'Strategic gates failed; refresh placement, code intelligence, or spec before coding continues.',
                    'php artisan atlas:programming:verify <work_item> --strict --json',
                    $failedNames,
                    hCycleRequired: true,
                );
            }

            return $this->decision(
                $qualityFailure ? self::ACTION_REPAIR : self::ACTION_ESCALATE,
                $qualityFailure ? 'l_level_quality_gate_failed' : 'l_level_unknown_gate_failed',
                'Repair the failed runtime gates before completion.',
                'php artisan atlas:programming:verify <work_item> --strict --json',
                $failedNames,
                lCycleRequired: true,
            );
        }

        if ($evidenceCount === 0) {
            return $this->decision(
                self::ACTION_CONTINUE,
                'l_level_evidence_missing',
                'Execute the plan and append at least one concrete evidence receipt.',
                'php artisan atlas:programming:receipt <work_item> --command="..." --output="..." --json',
                ['evidence_required'],
                lCycleRequired: true,
            );
        }

        if ($missingGates !== []) {
            return $this->decision(
                self::ACTION_CONTINUE,
                'l_level_required_gates_not_run',
                'Run required governance gates so the controller can trust the execution state.',
                'php artisan atlas:programming:verify <work_item> --strict --json',
                $missingGates,
                lCycleRequired: true,
            );
        }

        if ($reviewResult === 'changes_requested') {
            return $this->decision(
                self::ACTION_REPAIR,
                'h_level_review_requested_changes',
                'Apply the review repair contract, append fresh evidence, then rerun gates.',
                'php artisan atlas:programming:receipt <work_item> --command="..." --output="..." --json',
                ['review_changes_requested'],
                hCycleRequired: true,
                lCycleRequired: true,
            );
        }

        if (in_array($reviewResult, ['blocked', 'deferred'], true)) {
            return $this->decision(
                self::ACTION_ESCALATE,
                'h_level_review_not_shippable',
                'Operator review is not shippable; escalate before more execution.',
                'php artisan atlas:programming:status <work_item> --json',
                ['review_'.$reviewResult],
                hCycleRequired: true,
            );
        }

        if ($reviewResult === null) {
            return $this->decision(
                self::ACTION_ESCALATE,
                'h_level_operator_review_missing',
                'Prior gates and evidence exist; operator review is required before submit.',
                'php artisan atlas:programming:complete <work_item> --review=approved --strict --json',
                ['approved_review_required'],
                hCycleRequired: true,
            );
        }

        if ($reviewResult === 'approved' && $priorBlockingGreen && $evidenceCount > 0) {
            return $this->decision(
                self::ACTION_SUBMIT,
                'h_l_converged_submit_ready',
                'H-level contract and L-level execution evidence are aligned; completion may close.',
                'php artisan atlas:programming:complete <work_item> --review=approved --strict --json',
                [],
            );
        }

        return $this->decision(
            self::ACTION_CONTINUE,
            'controller_default_continue',
            'Continue execution until the next explicit blocker or submit condition appears.',
            $status === 'review' || $currentStage === 'completion'
                ? 'php artisan atlas:programming:verify <work_item> --strict --json'
                : 'php artisan atlas:programming:status <work_item> --json',
            [],
            lCycleRequired: true,
        );
    }

    /**
     * @param  list<string>  $requiredRepairs
     * @return array<string,mixed>
     */
    private function decision(
        string $action,
        string $reason,
        string $nextStep,
        string $nextCommand,
        array $requiredRepairs,
        bool $hCycleRequired = false,
        bool $lCycleRequired = false,
    ): array {
        return [
            'schema_version' => self::DECISION_SCHEMA_VERSION,
            'action' => $action,
            'reason' => $reason,
            'next_step' => $nextStep,
            'next_command' => $nextCommand,
            'required_repairs' => $requiredRepairs,
            'h_cycle_required' => $hCycleRequired,
            'l_cycle_required' => $lCycleRequired,
            'blocking' => $action !== self::ACTION_SUBMIT,
            'decided_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array{h_state: array<string,mixed>, l_state: array<string,mixed>}  $state
     * @param  array<string,mixed>  $decision
     */
    private function readinessScore(array $state, array $decision): array
    {
        $score = 4.0;
        $score += (bool) data_get($state, 'h_state.strategic_contract.spec_present') ? 1.0 : 0.0;
        $score += (bool) data_get($state, 'h_state.strategic_contract.plan_present') ? 1.0 : 0.0;
        $score += ((int) data_get($state, 'h_state.strategic_contract.task_contract_count', 0)) > 0 ? 0.75 : 0.0;
        $score += ((int) data_get($state, 'l_state.execution_truth.evidence_count', 0)) > 0 ? 1.0 : 0.0;
        $score += (bool) data_get($state, 'l_state.gate_posture.prior_blocking_green') ? 1.25 : 0.0;
        $score += data_get($state, 'h_state.strategic_contract.review_result') === 'approved' ? 0.5 : 0.0;

        if (($decision['action'] ?? null) === self::ACTION_SUBMIT) {
            $score = 9.5;
        }

        return [
            'scale' => '0_to_9_5',
            'score' => round(min(9.5, $score), 2),
            'meaning' => 'operational_readiness_for_governed_completion',
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     * @return array<string,mixed>
     */
    private function summarizeTasks(array $tasks): array
    {
        $validationCommands = [];
        $allowedFiles = [];
        $acceptanceCriteria = [];

        foreach ($tasks as $task) {
            if (! is_array($task)) {
                continue;
            }
            $validationCommands = array_merge($validationCommands, array_values((array) ($task['validation_commands'] ?? [])));
            $allowedFiles = array_merge($allowedFiles, array_values((array) ($task['allowed_files'] ?? [])));
            $acceptanceCriteria = array_merge($acceptanceCriteria, array_values((array) ($task['acceptance_criteria'] ?? [])));
        }

        return [
            'count' => count($tasks),
            'validation_commands' => array_values(array_unique(array_filter($validationCommands, 'is_string'))),
            'allowed_files' => array_values(array_unique(array_filter($allowedFiles, 'is_string'))),
            'acceptance_criteria' => array_values(array_unique(array_filter($acceptanceCriteria, 'is_string'))),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function gateRunPayload(?AtlasProgrammingGateRun $run): ?array
    {
        if ($run === null) {
            return null;
        }

        return [
            'id' => $run->id,
            'status' => $run->status,
            'blocking' => (bool) $run->blocking,
            'reason' => $run->reason,
            'waiver_reason' => $run->waiver_reason,
            'created_at' => $run->created_at?->toJSON(),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function reviewPayload(?AtlasProgrammingReview $review): ?array
    {
        if ($review === null) {
            return null;
        }

        return [
            'id' => $review->id,
            'result' => $review->result,
            'summary' => $review->summary,
            'risk_notes' => $review->risk_notes,
            'decided_by' => $review->decided_by,
            'created_at' => $review->created_at?->toJSON(),
        ];
    }

    /**
     * @param  list<string>  $haystack
     * @param  list<string>  $needles
     */
    private function intersects(array $haystack, array $needles): bool
    {
        return array_intersect($haystack, $needles) !== [];
    }
}
