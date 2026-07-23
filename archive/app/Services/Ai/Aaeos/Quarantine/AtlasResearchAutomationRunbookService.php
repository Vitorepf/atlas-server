<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Research Self-Improvement Automation Runbook decider.
 *
 * Pure, deterministic implementation of the safety runbook that governs HOW
 * research / self-improvement automation is allowed to come online. Nothing
 * here touches the database, a provider, the shell or the filesystem: every
 * method returns a typed verdict array that a caller may act on — or, when a
 * gate is closed, must refuse to act on.
 *
 * The doc declares four closed, enforceable contracts; all four are encoded
 * verbatim and default-deny:
 *
 *   1. Activation Order (9 ordered stages, read-only first):
 *      automation must light up strictly in order. A stage may only activate
 *      when EVERY lower-numbered stage is already active — no skipping ahead.
 *      Stage 1 ("read-only schema/packet renderer") must be the first thing on.
 *
 *   2. Required Guards Before Scheduler (10 named guards):
 *      any scheduled / background stage (Activation Order step 7 onward) is
 *      blocked unless ALL ten guards are present. A single missing guard closes
 *      the gate.
 *
 *   3. Forbidden First Versions (6 closed patterns):
 *      a proposed first version that matches ANY forbidden pattern is rejected
 *      outright — e.g. "crawler that writes docs directly", "scheduler that
 *      changes code".
 *
 *   4. First-implementation mode is plan-only / read-only:
 *      the documented command shape only allows --plan-only / --classify-only /
 *      --promotion-preview, and the doc states "First implementation must return
 *      packets and review signals only." Any apply/write mode in the first
 *      implementation is refused.
 *
 * The service NEVER activates a stage, schedules a job, applies code or runs a
 * tool. It returns the verdict plus the closed reason list; the caller decides.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/automation-runbook.md
 */
final class AtlasResearchAutomationRunbookService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.automation_runbook.v1';

    /**
     * The Activation Order, verbatim and ordered (1-indexed in the doc).
     * Index here is the doc step number; value is the stage key.
     *
     * @var array<int,string>
     */
    private const ACTIVATION_ORDER = [
        1 => 'read_only_schema_packet_renderer',
        2 => 'source_quality_scorer_no_network',
        3 => 'manual_research_packet_import',
        4 => 'docs_promotion_preview',
        5 => 'ap_plan_generator',
        6 => 'self_improvement_proposal_emission',
        7 => 'scheduled_read_only_review',
        8 => 'background_source_discovery_dedicated_ap',
        9 => 'approved_apply_low_risk_docs_only',
    ];

    /**
     * The first Activation Order step that introduces scheduling / background
     * execution. From this step on, the full guard set is mandatory.
     */
    public const FIRST_SCHEDULED_STEP = 7;

    /**
     * Required Guards Before Scheduler, verbatim closed set.
     *
     * @var array<int,string>
     */
    private const REQUIRED_GUARDS = [
        'source_registry',
        'allowed_source_list',
        'rate_limits',
        'canonical_url_hash',
        'duplicate_suppression',
        'evidence_ledger_event',
        'proposal_inbox_integration',
        'docs_health_validation',
        'architecture_validation_for_structural_proposals',
        'human_review_for_medium_high_risk',
    ];

    /**
     * Forbidden First Versions, verbatim closed set.
     *
     * @var array<int,string>
     */
    private const FORBIDDEN_FIRST_VERSIONS = [
        'crawler_that_writes_docs_directly',
        'scheduler_that_changes_code',
        'provider_release_that_changes_decide_routing',
        'memory_write_from_unverified_research',
        'autonomous_deletion_of_docs_or_source_records',
        'hidden_background_daemon_without_observability',
    ];

    /**
     * The only command modes the doc permits for the first implementation.
     * "First implementation must return packets and review signals only."
     *
     * @var array<int,string>
     */
    private const ALLOWED_FIRST_IMPLEMENTATION_MODES = [
        'plan_only',
        'classify_only',
        'promotion_preview',
    ];

    /**
     * Full read-only snapshot of the runbook contract, used as the command's
     * safe default. Everything reflects the doc's "start read-only" posture:
     * only stage 1 is presumed active, the scheduler gate is closed, and no
     * forbidden version is in play.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'ok' => true,
            'schema_version' => self::RECEIPT_SCHEMA,
            'activation_order' => self::ACTIVATION_ORDER,
            'stage_count' => count(self::ACTIVATION_ORDER),
            'first_scheduled_step' => self::FIRST_SCHEDULED_STEP,
            'required_guards' => array_values(self::REQUIRED_GUARDS),
            'required_guard_count' => count(self::REQUIRED_GUARDS),
            'forbidden_first_versions' => array_values(self::FORBIDDEN_FIRST_VERSIONS),
            'allowed_first_implementation_modes' => array_values(self::ALLOWED_FIRST_IMPLEMENTATION_MODES),
            // Default posture: nothing activated yet -> only stage 1 may turn on.
            'next_activatable_step' => 1,
            'scheduler_gate' => $this->evaluateSchedulerGate([])['scheduler_allowed'],
        ];
    }

    /**
     * Activation Order gate. Given the set of already-active stage keys, decide
     * whether the requested stage may be activated.
     *
     * Rule (from the doc's ordered list): a stage may only activate when EVERY
     * lower-numbered stage is already active. Stage 1 must be first. Activating
     * an already-active stage is a no-op success. Unknown stage => closed.
     *
     * @param string             $requestedStage one of the ACTIVATION_ORDER values
     * @param array<int,string>  $activeStages   stage keys already active
     * @return array{stage:string,step:int|null,activation_allowed:bool,reason:string,missing_prerequisites:array<int,string>}
     */
    public function evaluateActivation(string $requestedStage, array $activeStages): array
    {
        $step = $this->stepForStage($requestedStage);

        if ($step === null) {
            return [
                'stage' => $requestedStage,
                'step' => null,
                'activation_allowed' => false,
                'reason' => 'unknown_stage_not_in_activation_order',
                'missing_prerequisites' => [],
            ];
        }

        $activeSet = array_flip($activeStages);

        // Already active -> idempotent success, no prerequisites recomputed.
        if (isset($activeSet[$requestedStage])) {
            return [
                'stage' => $requestedStage,
                'step' => $step,
                'activation_allowed' => true,
                'reason' => 'stage_already_active',
                'missing_prerequisites' => [],
            ];
        }

        $missing = [];
        for ($prior = 1; $prior < $step; $prior++) {
            $priorStage = self::ACTIVATION_ORDER[$prior];
            if (! isset($activeSet[$priorStage])) {
                $missing[] = $priorStage;
            }
        }

        if ($missing !== []) {
            return [
                'stage' => $requestedStage,
                'step' => $step,
                'activation_allowed' => false,
                'reason' => 'prior_stages_not_active_no_skipping',
                'missing_prerequisites' => $missing,
            ];
        }

        return [
            'stage' => $requestedStage,
            'step' => $step,
            'activation_allowed' => true,
            'reason' => 'all_prior_stages_active',
            'missing_prerequisites' => [],
        ];
    }

    /**
     * Required Guards Before Scheduler gate. A scheduled / background stage
     * (Activation Order step >= FIRST_SCHEDULED_STEP) is blocked unless ALL ten
     * guards are present. Any missing guard closes the gate.
     *
     * @param array<int,string> $presentGuards guard keys the operator asserts are in place
     * @return array{scheduler_allowed:bool,reason:string,missing_guards:array<int,string>,present_guard_count:int,required_guard_count:int}
     */
    public function evaluateSchedulerGate(array $presentGuards): array
    {
        $presentSet = array_flip($presentGuards);

        $missing = [];
        foreach (self::REQUIRED_GUARDS as $guard) {
            if (! isset($presentSet[$guard])) {
                $missing[] = $guard;
            }
        }

        $allowed = $missing === [];

        return [
            'scheduler_allowed' => $allowed,
            'reason' => $allowed
                ? 'all_required_guards_present'
                : 'missing_required_guards_scheduler_blocked',
            'missing_guards' => $missing,
            'present_guard_count' => count(self::REQUIRED_GUARDS) - count($missing),
            'required_guard_count' => count(self::REQUIRED_GUARDS),
        ];
    }

    /**
     * Forbidden First Versions gate. A proposed first version is rejected if it
     * matches ANY forbidden pattern.
     *
     * @param array<int,string> $proposedCapabilities capability keys the proposal would ship
     * @return array{first_version_allowed:bool,reason:string,violations:array<int,string>}
     */
    public function evaluateFirstVersion(array $proposedCapabilities): array
    {
        $forbiddenSet = array_flip(self::FORBIDDEN_FIRST_VERSIONS);

        $violations = [];
        foreach ($proposedCapabilities as $cap) {
            if (isset($forbiddenSet[$cap])) {
                $violations[] = $cap;
            }
        }

        $allowed = $violations === [];

        return [
            'first_version_allowed' => $allowed,
            'reason' => $allowed
                ? 'no_forbidden_first_version_pattern'
                : 'matches_forbidden_first_version_fail_closed',
            'violations' => $violations,
        ];
    }

    /**
     * First-implementation mode gate. The doc allows only plan-only /
     * classify-only / promotion-preview for the first implementation
     * ("First implementation must return packets and review signals only.").
     * Any other mode (e.g. an apply/write mode) is refused.
     *
     * @param string $mode requested command mode (e.g. 'plan_only', 'apply')
     * @return array{mode:string,mode_allowed:bool,reason:string,allowed_modes:array<int,string>}
     */
    public function evaluateFirstImplementationMode(string $mode): array
    {
        $allowed = in_array($mode, self::ALLOWED_FIRST_IMPLEMENTATION_MODES, true);

        return [
            'mode' => $mode,
            'mode_allowed' => $allowed,
            'reason' => $allowed
                ? 'read_only_mode_allowed_for_first_implementation'
                : 'write_mode_blocked_first_implementation_must_be_read_only',
            'allowed_modes' => array_values(self::ALLOWED_FIRST_IMPLEMENTATION_MODES),
        ];
    }

    /**
     * Map a stage key to its Activation Order step number (1-indexed), or null
     * if it is not a known stage.
     */
    private function stepForStage(string $stage): ?int
    {
        foreach (self::ACTIVATION_ORDER as $step => $key) {
            if ($key === $stage) {
                return $step;
            }
        }

        return null;
    }
}
