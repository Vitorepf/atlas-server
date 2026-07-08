<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use InvalidArgumentException;

/**
 * Pure, deterministic decider for the Atlas Current Provider Stack policy.
 *
 * This is NOT a provider client and it never spends tokens or calls a model. It
 * encodes the concrete operator policy the doc fixes — the allowed "current
 * stack", subsidy-first spend ordering, per-rail roles, the ordered work flow,
 * and the hard stops — over plain typed arrays. No database, no models, no side
 * effects, so the policy contract can be pinned and reused independently of any
 * live routing / invocation driver.
 *
 * Rules implemented (mapped to the doc sections):
 *
 *   - "Stack Atual": exactly five rails are part of the current stack
 *     (codex_gpt55, cursor_cli_composer, minimax_m27, antigravity_sdk,
 *     gemini_subsidized). Every rail carries a primary role and an explicit
 *     "do not use for" list. Any provider not in this set is NOT an active
 *     route — `routeProvider` blocks it and emits an exclusion receipt
 *     (`atlas.provider_stack.exclusion_receipt.v1`).
 *
 *   - "Regra curta" / "Regras De Gasto": subsidy (subscription/account) first;
 *     API/paygo is BLOCKED by default and only allowed with an explicit human
 *     decision AND a spend cap AND evidence. "Parece barato" is never a reason.
 *
 *   - "Fluxo": the documented 6-step work flow runs in order (local context ->
 *     scout -> minimax packets -> cursor cli patch -> codex critical review ->
 *     antigravity sdk agentic). `flowProgress` enforces the order and reports
 *     the next required step.
 *
 *   - "Hard Stops": six documented block conditions (paygo without cap, provider
 *     outside stack as active route, Cursor SDK replacing Cursor CLI, Antigravity
 *     CLI as hot path, Codex escalated to cheap scout/retry, MiniMax writing
 *     without lease + allowed_files). `evaluateHardStops` flips to `block` when
 *     any fires.
 *
 *   - "Regras para IA" (Codex leverage): Codex premium is reserved for high-
 *     leverage decisions (architecture, final review, judge, hard repair) and
 *     must NOT be spent on cheap scouts, broad sweeps or repeated retries.
 *     `codexSpendDecision` enforces that boundary.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/atlas-current-provider-stack-v1.md
 */
final class AtlasCurrentProviderStackService
{
    public const SCHEMA_VERSION = 'atlas.provider_stack.current.v1';

    public const EXCLUSION_SCHEMA = 'atlas.provider_stack.exclusion_receipt.v1';

    public const ROUTE_REASON_SCHEMA = 'atlas.provider_stack.route_reason.v1';

    public const PAYGO_SCHEMA = 'atlas.provider_stack.paygo_policy.v1';

    /**
     * The current stack: exactly the five rails the doc's "Stack Atual" table
     * lists. Each rail is keyed by a stable id and carries its auth mode, the
     * primary role, and the explicit "Nao usar para" boundary. Anything not in
     * this map is NOT part of the current stack.
     *
     * @var array<string,array{
     *   label:string,
     *   auth_mode:string,
     *   primary_role:string,
     *   do_not_use_for:list<string>
     * }>
     */
    public const STACK = [
        'codex_gpt55' => [
            'label' => 'Codex GPT-5.5',
            'auth_mode' => 'account_subscription',
            'primary_role' => 'premium_architect_judge_final_reviewer_hard_repair',
            'do_not_use_for' => ['cheap_scout', 'broad_sweep', 'repeated_retry', 'worker_24_7'],
        ],
        'cursor_cli_composer' => [
            'label' => 'Cursor CLI / Composer',
            'auth_mode' => 'account_subscription',
            'primary_role' => 'primary_code_executor_patch_refactor_forge_builder',
            'do_not_use_for' => ['current_api_sdk', 'automatic_completion_claim', 'edit_outside_allowed_files'],
        ],
        'minimax_m27' => [
            'label' => 'MiniMax M2.7 Token Plan',
            'auth_mode' => 'account_token_plan',
            'primary_role' => 'cheap_24_7_worker_scout_spec_map_reduce_cross_check_small_repair_tts',
            'do_not_use_for' => ['giant_dump', 'parallel_writer_without_lease', 'replace_premium_judge'],
        ],
        'antigravity_sdk' => [
            'label' => 'Antigravity SDK',
            'auth_mode' => 'governed_sdk_subsidy',
            'primary_role' => 'meta_provider_agent_harness_governed_agent_loop',
            'do_not_use_for' => ['cli_hot_path', 'model_command_as_authority', 'bypass_atlas_decide'],
        ],
        'gemini_subsidized' => [
            'label' => 'Gemini subsidiado',
            'auth_mode' => 'free_or_subsidized_quota',
            'primary_role' => 'long_context_scout_repo_sweep_context_compression_broad_research',
            'do_not_use_for' => ['permanent_paid_dependency_without_budget_review'],
        ],
    ];

    /**
     * The six documented work-flow steps, in the doc's exact order ("Fluxo").
     *
     * @var list<string>
     */
    public const FLOW_STEPS = [
        'local_context_index_shards_ownership', // 1. context/index/shards/ownership local
        'subsidized_scout',                     // 2. Gemini/MiniMax scout for broad work
        'minimax_work_packets',                 // 3. MiniMax small cheap packets in 24/7 loop
        'cursor_cli_patch',                     // 4. Cursor CLI/Composer patches & refactors
        'codex_critical_review',                // 5. Codex for critical architecture / final review / judge
        'antigravity_sdk_agentic',              // 6. Antigravity SDK for governed agentic flows
    ];

    /**
     * The six documented Hard Stops. Each maps an input flag to the block reason
     * the doc states ("Hard Stops" section).
     *
     * @var array<string,string>
     */
    public const HARD_STOP_REASONS = [
        'paygo_without_cap' => 'A flow requires API/paygo without an explicit spend cap.',
        'provider_outside_stack_as_active_route' => 'A provider outside the current stack appeared as an active route.',
        'cursor_sdk_replacing_cli' => 'Cursor SDK is being used to replace Cursor CLI without a human decision.',
        'antigravity_cli_as_hot_path' => 'Antigravity CLI is being used as a runtime hot path instead of the governed SDK.',
        'codex_escalated_to_cheap_work' => 'Codex is being escalated to a cheap scout or repeated retry.',
        'minimax_edit_without_lease_or_allowed_files' => 'MiniMax is trying to edit without a lease and allowed_files.',
    ];

    /**
     * Whether a provider id is part of the current stack.
     */
    public function isInStack(string $providerId): bool
    {
        return array_key_exists($this->normalize($providerId), self::STACK);
    }

    /**
     * Resolve the current-stack descriptor for a rail (role + boundary).
     *
     * @return array{
     *   schema_version:string,
     *   provider_id:string,
     *   in_stack:bool,
     *   label:?string,
     *   auth_mode:?string,
     *   primary_role:?string,
     *   do_not_use_for:list<string>
     * }
     */
    public function describeRail(string $providerId): array
    {
        $id = $this->normalize($providerId);
        if ($id === '') {
            throw new InvalidArgumentException('Provider id must not be empty.');
        }

        $rail = self::STACK[$id] ?? null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'provider_id' => $id,
            'in_stack' => $rail !== null,
            'label' => $rail['label'] ?? null,
            'auth_mode' => $rail['auth_mode'] ?? null,
            'primary_role' => $rail['primary_role'] ?? null,
            'do_not_use_for' => $rail['do_not_use_for'] ?? [],
        ];
    }

    /**
     * Route decision for a provider request.
     *
     * Doc rule: a provider NOT in the current stack is never an active route —
     * it is allowed only after a NEW human decision. Without that decision the
     * route is blocked and an exclusion receipt
     * (`atlas.provider_stack.exclusion_receipt.v1`) is emitted. A rail that IS
     * in the stack is allowed (subject to the separate paygo policy).
     *
     * @return array{
     *   schema_version:string,
     *   provider_id:string,
     *   in_stack:bool,
     *   decision:string,
     *   allowed:bool,
     *   blocked_reason:?string,
     *   exclusion_receipt:?array{
     *     schema_version:string,
     *     provider_id:string,
     *     reason:string,
     *     requires_new_human_decision:bool
     *   }
     * }
     */
    public function routeProvider(string $providerId, bool $hasNewHumanDecision = false): array
    {
        $id = $this->normalize($providerId);
        if ($id === '') {
            throw new InvalidArgumentException('Provider id must not be empty.');
        }

        $inStack = array_key_exists($id, self::STACK);

        if ($inStack) {
            return [
                'schema_version' => self::ROUTE_REASON_SCHEMA,
                'provider_id' => $id,
                'in_stack' => true,
                'decision' => 'allow',
                'allowed' => true,
                'blocked_reason' => null,
                'exclusion_receipt' => null,
            ];
        }

        // Outside the stack: only a NEW human decision can route it.
        if ($hasNewHumanDecision) {
            return [
                'schema_version' => self::ROUTE_REASON_SCHEMA,
                'provider_id' => $id,
                'in_stack' => false,
                'decision' => 'allow_with_new_human_decision',
                'allowed' => true,
                'blocked_reason' => null,
                'exclusion_receipt' => null,
            ];
        }

        return [
            'schema_version' => self::ROUTE_REASON_SCHEMA,
            'provider_id' => $id,
            'in_stack' => false,
            'decision' => 'block_outside_current_stack',
            'allowed' => false,
            'blocked_reason' => 'provider_outside_current_stack',
            'exclusion_receipt' => [
                'schema_version' => self::EXCLUSION_SCHEMA,
                'provider_id' => $id,
                'reason' => 'Not part of the current stack; not an active route without a new human decision.',
                'requires_new_human_decision' => true,
            ],
        ];
    }

    /**
     * Spend-mode decision (subsidy-first / paygo policy).
     *
     * Doc "Regra curta" + "Regras De Gasto": use subsidy (subscription/account)
     * first; API/paygo is BLOCKED by default and only allowed when there is an
     * explicit human decision AND a spend cap AND evidence. All three are
     * required — missing any one keeps paygo blocked. "Parece barato" is never a
     * justification.
     *
     * @return array{
     *   schema_version:string,
     *   requested_mode:string,
     *   resolved_mode:string,
     *   allowed:bool,
     *   missing:list<string>,
     *   reason:string
     * }
     */
    public function spendDecision(
        string $requestedMode,
        bool $hasHumanDecision = false,
        bool $hasSpendCap = false,
        bool $hasEvidence = false,
    ): array {
        $mode = $this->normalize($requestedMode);
        if ($mode === '') {
            throw new InvalidArgumentException('Requested spend mode must not be empty.');
        }

        // Subsidy / subscription / account is the default-allowed path.
        if (in_array($mode, ['subsidy', 'subscription', 'account', 'token_plan'], true)) {
            return [
                'schema_version' => self::PAYGO_SCHEMA,
                'requested_mode' => $mode,
                'resolved_mode' => 'subsidy_first',
                'allowed' => true,
                'missing' => [],
                'reason' => 'Subsidy/account is the default spend mode and is allowed.',
            ];
        }

        // Anything else is treated as paygo/API and is blocked by default.
        $missing = [];
        if (! $hasHumanDecision) {
            $missing[] = 'human_decision';
        }
        if (! $hasSpendCap) {
            $missing[] = 'spend_cap';
        }
        if (! $hasEvidence) {
            $missing[] = 'evidence';
        }

        $allowed = $missing === [];

        return [
            'schema_version' => self::PAYGO_SCHEMA,
            'requested_mode' => $mode,
            'resolved_mode' => $allowed ? 'paygo_allowed_with_controls' : 'paygo_blocked',
            'allowed' => $allowed,
            'missing' => $missing,
            'reason' => $allowed
                ? 'API/paygo allowed: explicit human decision, spend cap and evidence are all present.'
                : 'API/paygo blocked by default: requires human decision, spend cap and evidence — "parece barato" is not a reason.',
        ];
    }

    /**
     * Codex premium leverage boundary ("Regras para IA" + Stack "Nao usar para").
     *
     * Doc: Codex GPT-5.5 is the premium architect / judge / final reviewer / hard
     * repair — NOT a cheap scout, broad sweep, repeated retry or 24/7 worker.
     * This returns `allow` only for high-leverage task kinds and `block` (with the
     * cheaper rail to use instead) for the forbidden cheap work.
     *
     * @return array{
     *   schema_version:string,
     *   task_kind:string,
     *   decision:string,
     *   allowed:bool,
     *   route_instead:?string,
     *   reason:string
     * }
     */
    public function codexSpendDecision(string $taskKind): array
    {
        $kind = $this->normalize($taskKind);
        if ($kind === '') {
            throw new InvalidArgumentException('Task kind must not be empty.');
        }

        $highLeverage = [
            'architecture',
            'critical_decision',
            'final_review',
            'judge',
            'hard_repair',
        ];

        $cheapForbidden = [
            'cheap_scout',
            'scout',
            'broad_sweep',
            'repeated_retry',
            'retry',
            'worker_24_7',
        ];

        if (in_array($kind, $highLeverage, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'task_kind' => $kind,
                'decision' => 'allow_codex_premium',
                'allowed' => true,
                'route_instead' => null,
                'reason' => "Codex premium is allowed for high-leverage task '{$kind}'.",
            ];
        }

        if (in_array($kind, $cheapForbidden, true)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'task_kind' => $kind,
                'decision' => 'block_codex_for_cheap_work',
                'allowed' => false,
                // Doc: do not spend Codex on work MiniMax/Gemini/Cursor CLI can prepare.
                'route_instead' => in_array($kind, ['broad_sweep', 'scout', 'cheap_scout'], true)
                    ? 'gemini_subsidized'
                    : 'minimax_m27',
                'reason' => "Codex must not be spent on cheap work '{$kind}'; route it to a cheaper stack rail.",
            ];
        }

        // Unknown kind: default to protecting the premium budget.
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'task_kind' => $kind,
            'decision' => 'defer_to_cheaper_rail',
            'allowed' => false,
            'route_instead' => 'minimax_m27',
            'reason' => "Task '{$kind}' is not a documented high-leverage Codex case; prepare it on a cheaper rail first.",
        ];
    }

    /**
     * Work-flow progress check ("Fluxo").
     *
     * The six steps MUST run in the documented order. A caller may run a prefix
     * (e.g. stop after the scout). Reports valid order, completeness, and the next
     * required step.
     *
     * @param  list<string>  $completedSteps
     * @return array{
     *   schema_version:string,
     *   ordered_steps:list<string>,
     *   completed:list<string>,
     *   valid_order:bool,
     *   complete:bool,
     *   next_step:?string,
     *   reason:string
     * }
     */
    public function flowProgress(array $completedSteps): array
    {
        $completed = array_values($completedSteps);
        $expectedPrefix = array_slice(self::FLOW_STEPS, 0, count($completed));
        $validOrder = $completed === $expectedPrefix;

        $complete = $validOrder && count($completed) === count(self::FLOW_STEPS);
        $nextStep = $validOrder && ! $complete
            ? self::FLOW_STEPS[count($completed)]
            : null;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'ordered_steps' => self::FLOW_STEPS,
            'completed' => $completed,
            'valid_order' => $validOrder,
            'complete' => $complete,
            'next_step' => $nextStep,
            'reason' => match (true) {
                ! $validOrder => 'Flow steps must run in the documented order: local context -> scout -> minimax packets -> cursor cli -> codex review -> antigravity sdk.',
                $complete => 'Flow complete: all six stack steps ran in order.',
                default => "Flow in progress: next required step is '{$nextStep}'.",
            },
        ];
    }

    /**
     * Hard-stop evaluation ("Hard Stops").
     *
     * Each documented condition is a boolean flag; if ANY is true the overall
     * decision flips to `block` and the triggered reasons are listed. With no
     * condition set the decision is `proceed`.
     *
     * @param  array<string,bool>  $conditions
     * @return array{
     *   schema_version:string,
     *   decision:string,
     *   blocked:bool,
     *   triggered:list<string>,
     *   reasons:list<string>,
     *   unknown_conditions:list<string>
     * }
     */
    public function evaluateHardStops(array $conditions): array
    {
        $triggered = [];
        $reasons = [];
        $unknown = [];

        foreach ($conditions as $name => $value) {
            $key = $this->normalize((string) $name);
            if (! array_key_exists($key, self::HARD_STOP_REASONS)) {
                $unknown[] = $key;

                continue;
            }
            if ($value === true) {
                $triggered[] = $key;
                $reasons[] = self::HARD_STOP_REASONS[$key];
            }
        }

        $blocked = $triggered !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $blocked ? 'block' : 'proceed',
            'blocked' => $blocked,
            'triggered' => $triggered,
            'reasons' => $reasons,
            'unknown_conditions' => $unknown,
        ];
    }

    /**
     * Normalize an identifier to lowercase, trimmed form.
     */
    private function normalize(string $value): string
    {
        return strtolower(trim($value));
    }
}
