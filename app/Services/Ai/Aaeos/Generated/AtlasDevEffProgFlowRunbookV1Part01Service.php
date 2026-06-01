<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 1 — pure, deterministic
 * decider for the *orchestration* contract documented in sections 1 (Resumo),
 * 1.1 (Entrega Vertical Desktop-First), 3 (Reuso Obrigatorio) and 6.1 (Objetivo).
 *
 * Where Parte 2 governs the per-slice PR invariants (CompactSdd risk/mode,
 * VerificationReceipt, FailureCapsule…), THIS part governs the rules that sit
 * *above* the slices: in what order the slices may be built, when the efficient
 * flow is even allowed to supersede the classic `programming.dev`, which delivery
 * milestone a given slice set unlocks, and which existing services may never be
 * duplicated. It executes nothing and never touches a provider, the filesystem
 * or a DB; it just turns the runbook's prose into a closed set of typed verdicts.
 *
 * Documented rules this code actually enforces (one-to-one with the doc):
 *   - §1 rigid slice order (doc lines 127-135): the only legal build order is
 *     [0, 1, 1.5, 2, 3, 4, 5]. nextSlice() advances exactly one step and refuses
 *     to skip; a slice is buildable only when the previous slice's DoD is green
 *     ("Fatia nao comeca sem DoD anterior verde"). Fatia 0 has no predecessor.
 *   - §1 team boundary (doc line 137): after Fatia 5 this team *encerra*; there
 *     is no Fatia 6 and "Medicao" is explicitly out of scope. nextSlice() on the
 *     last slice returns done, never invents a further slice.
 *   - §1 activation gate (doc line 141): the efficient flow supersedes the
 *     classic `programming.dev` ONLY when (plan_enabled OR run_enabled) AND a
 *     workspace is present AND the surface is supported; otherwise the classic
 *     flow continues. The chosen efficient mode is plan-only when only plan is
 *     on, and run (plan→token→run) when run is on. run REQUIRES plan
 *     (line 141: "ligar Plan primeiro, depois Run").
 *   - §1 production rollout order (doc line 141): in production both flags start
 *     false; the only legal first toggle is plan_enabled, and run_enabled may be
 *     turned on only once plan_enabled is already on. Enabling run before plan is
 *     an illegal rollout step.
 *   - §1.1 vertical delivery milestones (doc lines 147-153): each Marco unlocks
 *     exactly its documented slice set — Marco 1=[0,1,1.5], 2 adds [2],
 *     3 adds [3], 4 adds [4], 5 adds [5]. A milestone is reachable only when all
 *     slices it requires (cumulative) are green.
 *   - §1.1 surface-agnostic core (doc lines 155): the core consumes an
 *     OperationEnvelope and returns PlanOnlyResult|PatchResult; only the
 *     `AtlasDev/Surface/` layer may know Desktop/CLI/App/API. coreResultType()
 *     maps a mode to its only legal result type and never leaks a surface name.
 *   - §3 reuse-mandatory (doc lines 187-191): the listed services must be
 *     evolved, never replaced. A proposed new service is rejected when it would
 *     substitute a reuse-target, when its name ends in a version-bump suffix
 *     (…V2 / …ServiceV2), or when it re-introduces the workflow driver under a
 *     different name (a "second driver with the same function").
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md
 */
final class AtlasDevEffProgFlowRunbookV1Part01Service
{
    /** Stable decision kind this decider emits. */
    public const DECISION_KIND = 'atlas_dev.efficient_programming_flow.runbook.v1.part_01';

    /**
     * §1 — the rigid, ordered slice sequence (doc lines 127-135). Strings so the
     * "1.5" half-step is represented faithfully. This order is the ONLY legal one.
     */
    public const SLICE_ORDER = ['0', '1', '1.5', '2', '3', '4', '5'];

    /**
     * §1.1 — vertical delivery milestones. Each Marco => the CUMULATIVE set of
     * slices that must be green for that milestone to be reachable (doc lines
     * 147-153). Marco 1 = Foundation (0+1+1.5); each later Marco adds one slice.
     *
     * @var array<int, list<string>>
     */
    public const MILESTONE_SLICES = [
        1 => ['0', '1', '1.5'],
        2 => ['0', '1', '1.5', '2'],
        3 => ['0', '1', '1.5', '2', '3'],
        4 => ['0', '1', '1.5', '2', '3', '4'],
        5 => ['0', '1', '1.5', '2', '3', '4', '5'],
    ];

    /**
     * §3 — the reuse-mandatory map (doc lines 174-185): existing services that
     * must be evolved, never duplicated or replaced.
     */
    public const REUSE_TARGETS = [
        'AtlasCliDevWorkflowService',
        'AtlasProgrammingOrchestrator',
        'KernelPipelineDevPlanBuilder',
        'AtlasDevRuntimeService',
        'AtlasDesktopAiSurfaceAdapter',
    ];

    /**
     * §3 — the canonical workflow driver. A second service that performs the same
     * driver role under a different name is a forbidden "second driver".
     */
    public const WORKFLOW_DRIVER = 'AtlasCliDevWorkflowService';

    /** §1.1 — the only two result types the surface-agnostic core may return (doc line 155). */
    public const CORE_RESULT_TYPES = [
        'plan_only' => 'PlanOnlyResult',
        'run' => 'PatchResult',
    ];

    /**
     * §1 — given a finished slice and whether its DoD is green, what is the next
     * legal slice to build? Advances exactly one position in SLICE_ORDER and
     * refuses to advance while the current DoD is red ("Fatia nao comeca sem DoD
     * anterior verde"). Pass currentSlice=null to ask for the first slice.
     *
     * @return array{
     *   current_slice:?string, dod_green:bool, next_slice:?string,
     *   done:bool, can_advance:bool, reason:string
     * }
     */
    public function nextSlice(?string $currentSlice, bool $dodGreen): array
    {
        // No current slice -> the build starts at Fatia 0 (no predecessor DoD).
        if ($currentSlice === null) {
            return [
                'current_slice' => null,
                'dod_green' => $dodGreen,
                'next_slice' => self::SLICE_ORDER[0],
                'done' => false,
                'can_advance' => true,
                'reason' => 'start_at_fatia_0',
            ];
        }

        $idx = array_search($currentSlice, self::SLICE_ORDER, true);
        if ($idx === false) {
            return [
                'current_slice' => $currentSlice,
                'dod_green' => $dodGreen,
                'next_slice' => null,
                'done' => false,
                'can_advance' => false,
                'reason' => 'unknown_slice',
            ];
        }

        // §1 — cannot start the next slice until the current DoD is green.
        if (! $dodGreen) {
            return [
                'current_slice' => $currentSlice,
                'dod_green' => false,
                'next_slice' => null,
                'done' => false,
                'can_advance' => false,
                'reason' => 'previous_dod_not_green',
            ];
        }

        // §1 — after the last slice (Fatia 5) the team encerra; no Fatia 6.
        if ($idx === count(self::SLICE_ORDER) - 1) {
            return [
                'current_slice' => $currentSlice,
                'dod_green' => true,
                'next_slice' => null,
                'done' => true,
                'can_advance' => false,
                'reason' => 'fatia_5_complete_team_encerra',
            ];
        }

        return [
            'current_slice' => $currentSlice,
            'dod_green' => true,
            'next_slice' => self::SLICE_ORDER[$idx + 1],
            'done' => false,
            'can_advance' => true,
            'reason' => 'advance_one_slice',
        ];
    }

    /**
     * §1 activation gate (doc line 141) — does a given request use the efficient
     * flow, and in which mode? Efficient supersedes classic only when at least
     * one efficient flag is on AND a workspace is present AND the surface is
     * supported. run implies plan (plan→token→run), so plan-only is chosen unless
     * run is enabled.
     *
     * @return array{
     *   flow:string, efficient_mode:?string, core_result_type:?string,
     *   reason:string, blocked_by:list<string>
     * }
     */
    public function resolveFlow(
        bool $planEnabled,
        bool $runEnabled,
        bool $workspacePresent,
        bool $surfaceSupported
    ): array {
        $blocked = [];
        if (! $workspacePresent) {
            $blocked[] = 'workspace_missing';
        }
        if (! $surfaceSupported) {
            $blocked[] = 'surface_unsupported';
        }
        if (! $planEnabled && ! $runEnabled) {
            $blocked[] = 'no_efficient_flag_enabled';
        }

        if ($blocked !== []) {
            return [
                'flow' => 'programming.dev',
                'efficient_mode' => null,
                'core_result_type' => null,
                'reason' => 'classic_flow_conditions_not_met',
                'blocked_by' => $blocked,
            ];
        }

        // run implies the full plan→token→run path; plan-only otherwise.
        $mode = $runEnabled ? 'run' : 'plan_only';

        return [
            'flow' => 'atlas_dev',
            'efficient_mode' => $mode,
            'core_result_type' => self::CORE_RESULT_TYPES[$mode],
            'reason' => $runEnabled ? 'efficient_run_path' : 'efficient_plan_only_path',
            'blocked_by' => [],
        ];
    }

    /**
     * §1 production rollout order (doc line 141) — given the CURRENT prod flag
     * state, is a proposed toggle a legal rollout step? Both start false; the
     * only legal first move is to turn plan on; run may be turned on only when
     * plan is already on ("ligar Plan primeiro, depois Run").
     *
     * @param  array{plan_enabled:bool,run_enabled:bool}  $current
     * @return array{
     *   target:string, to:bool, allowed:bool, resulting_state:array{plan_enabled:bool,run_enabled:bool}, reason:string
     * }
     */
    public function evaluateRolloutToggle(array $current, string $target, bool $to): array
    {
        $plan = (bool) ($current['plan_enabled'] ?? false);
        $run = (bool) ($current['run_enabled'] ?? false);

        $allowed = true;
        $reason = 'toggle_allowed';

        if ($target === 'run_enabled' && $to === true && $plan === false) {
            // Cannot enable run before plan.
            $allowed = false;
            $reason = 'run_requires_plan_enabled_first';
        } elseif ($target !== 'plan_enabled' && $target !== 'run_enabled') {
            $allowed = false;
            $reason = 'unknown_flag';
        }

        $resulting = ['plan_enabled' => $plan, 'run_enabled' => $run];
        if ($allowed) {
            $resulting[$target] = $to;
        }

        return [
            'target' => $target,
            'to' => $to,
            'allowed' => $allowed,
            'resulting_state' => $resulting,
            'reason' => $reason,
        ];
    }

    /**
     * §1.1 milestones (doc lines 147-153) — is a Marco reachable given the set of
     * slices currently green? Reachable only when every slice the milestone
     * requires (cumulative) is green; names the missing slices otherwise.
     *
     * @param  list<string>  $greenSlices  slice ids whose DoD is green
     * @return array{
     *   milestone:int, reachable:bool, required:list<string>,
     *   missing:list<string>, reason:string
     * }
     */
    public function evaluateMilestone(int $milestone, array $greenSlices): array
    {
        $required = self::MILESTONE_SLICES[$milestone] ?? null;
        if ($required === null) {
            return [
                'milestone' => $milestone,
                'reachable' => false,
                'required' => [],
                'missing' => [],
                'reason' => 'unknown_milestone',
            ];
        }

        $missing = array_values(array_diff($required, $greenSlices));
        $reachable = $missing === [];

        return [
            'milestone' => $milestone,
            'reachable' => $reachable,
            'required' => $required,
            'missing' => $missing,
            'reason' => $reachable ? 'all_required_slices_green' : 'required_slices_missing',
        ];
    }

    /**
     * §1.1 surface-agnostic core (doc line 155) — the single legal result type
     * for a given efficient mode. Never returns a surface name.
     *
     * @return array{mode:string, result_type:?string, surface_agnostic:bool, reason:string}
     */
    public function coreResultType(string $mode): array
    {
        $type = self::CORE_RESULT_TYPES[$mode] ?? null;

        return [
            'mode' => $mode,
            'result_type' => $type,
            'surface_agnostic' => true,
            'reason' => $type !== null ? 'core_returns_only_this_type' : 'unknown_mode',
        ];
    }

    /**
     * §3 reuse-mandatory (doc lines 187-191) — may a proposed new service be
     * created, or does it violate the reuse contract? Rejects when it would
     * substitute a reuse-target, when its name is a version-bump of an existing
     * service (…V2 / …ServiceV2), or when it duplicates the workflow driver under
     * a new name (a forbidden "second driver").
     *
     * @param  string  $proposedName  the proposed new class name (no namespace)
     * @param  ?string  $replacesTarget  an existing service this would replace, if any
     * @param  bool  $isWorkflowDriver  whether the proposal performs the driver role
     * @return array{
     *   proposed_name:string, allowed:bool, violations:list<string>, reason:string
     * }
     */
    public function evaluateNewService(
        string $proposedName,
        ?string $replacesTarget = null,
        bool $isWorkflowDriver = false
    ): array {
        $violations = [];

        // Cannot substitute any reuse-target.
        if ($replacesTarget !== null && in_array($replacesTarget, self::REUSE_TARGETS, true)) {
            $violations[] = 'substitutes_reuse_target';
        }

        // Cannot create a version-bump twin (…V2, …ServiceV2) of an existing service.
        if ($this->looksLikeVersionBump($proposedName)) {
            $violations[] = 'version_bump_of_existing_service';
        }

        // Cannot introduce a second driver under a different name.
        if ($isWorkflowDriver && $proposedName !== self::WORKFLOW_DRIVER) {
            $violations[] = 'second_driver_same_function';
        }

        $allowed = $violations === [];

        return [
            'proposed_name' => $proposedName,
            'allowed' => $allowed,
            'violations' => $violations,
            'reason' => $allowed ? 'evolves_without_duplicating' : 'reuse_contract_violated',
        ];
    }

    /**
     * Stable manifest of the orchestration contract this decider governs.
     *
     * @return array<string, mixed>
     */
    public function manifest(): array
    {
        return [
            'decision_kind' => self::DECISION_KIND,
            'doc' => 'docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-01.md',
            'sections' => [
                '1_resumo',
                '1_1_entrega_vertical_desktop_first',
                '3_reuso_obrigatorio',
                '6_1_objetivo',
            ],
            'slice_order' => self::SLICE_ORDER,
            'milestone_slices' => self::MILESTONE_SLICES,
            'reuse_targets' => self::REUSE_TARGETS,
            'workflow_driver' => self::WORKFLOW_DRIVER,
            'core_result_types' => self::CORE_RESULT_TYPES,
        ];
    }

    /**
     * Does a proposed class name look like a version-bump twin of an existing
     * service (e.g. FooServiceV2, FooV2Service, FooServiceV3)? Such names are the
     * doc's explicit anti-pattern ("Criar AtlasDevWorkflowServiceV2 ou similar").
     */
    private function looksLikeVersionBump(string $name): bool
    {
        return preg_match('/(Service)?V\d+(Service)?$/', $name) === 1;
    }
}
