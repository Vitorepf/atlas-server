<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * MiniMax-First 24h Agentic Engineering Flow — policy decider.
 *
 * Pure, deterministic implementation of the canonical flow contract: it decides,
 * for one 24/7 work cycle, (a) whether a sharded work plan is required before any
 * provider call, (b) whether a writer is admitted, (c) whether DeepSeek may be
 * escalated, and (d) whether the cycle must HARD-STOP. It never opens a provider
 * call, schedules a worker, mutates a repo, or touches the database — it emits a
 * decision plus an audit receipt; callers act on it.
 *
 * Concrete rules materialized from the doc:
 *
 *   "Deficit Translation Table" — five deficit-vs-DeepSeek patterns, each with its
 *   canonical Atlas implementation. When a task requests one of these patterns the
 *   flow MUST produce a sharded work plan before any provider call.
 *
 *   "Worker Model" — three concepts that can never be confused:
 *     logical agent  → may exist in great quantity (UNBOUNDED).
 *     LLM worker     → active provider call, bounded by budget/quota/utility.
 *     writer         → may edit files, always scarce, requires lease + allowed_files.
 *   "MiniMax-first significa rodar muitos agentes logicos em fila, com poucos LLM
 *   workers ativos. Nao significa abrir dezenas de chamadas MiniMax concorrentes."
 *
 *   "Work Plan Obrigatorio" — "Sem ownership_map, allowed_files e output_contract,
 *   nenhum writer pode editar."
 *
 *   "DeepSeek Escalation" — DeepSeek enters ONLY for an explicit technical reason
 *   (must-keep above MiniMax practical context, 500k-1M single-window analysis,
 *   many independent shards at once, brutal multi-repo audit, or AP-99 proving
 *   MiniMax failed on context/load — NOT on a bad prompt). "DeepSeek for usado
 *   porque 'parece mais facil', sem escalation reason" is a Hard Stop.
 *
 *   "Provider Roles" — "Codex e Claude premium nao sao workers 24/7."
 *
 *   "Hard Stops" — block the cycle on: writer editing outside allowed_files;
 *   monolithic giant output; more than one writer on the same ownership scope;
 *   mandatory context that does not fit with no shard plan; a retry repeating the
 *   same failure without new evidence; MiniMax quota/credits/on-demand overflow;
 *   DeepSeek used without an escalation reason.
 *
 * @see docs/engineering-knowledge-base/atlas-minimax-first-24h-flow-v1.md
 */
final class AtlasMinimaxFirst24hFlowService
{
    /** Stable receipt schema id for the work-plan gate this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.minimax_first.work_plan.v1';

    /** Canonical providers and their fixed role in this flow ("Provider Roles"). */
    public const ROLE_WORKER_24H = 'worker_24h';
    public const ROLE_PATCH_EXECUTOR = 'patch_executor';
    public const ROLE_LONG_CONTEXT = 'long_context';
    public const ROLE_SCALE_ESCALATION = 'scale_escalation';
    public const ROLE_PREMIUM_JUDGE = 'premium_judge';

    /**
     * Provider role table from the doc. The map is the single source of truth for
     * "who may be a cheap 24/7 worker" vs "who is a premium judge, never a 24/7
     * worker" ("Codex e Claude premium nao sao workers 24/7.").
     *
     * @var array<string,string>
     */
    private const PROVIDER_ROLES = [
        'minimax-m2.7' => self::ROLE_WORKER_24H,
        'composer-2.5' => self::ROLE_PATCH_EXECUTOR,
        'gemini' => self::ROLE_LONG_CONTEXT,
        'deepseek-v4' => self::ROLE_SCALE_ESCALATION,
        'codex-gpt-5.5' => self::ROLE_PREMIUM_JUDGE,
        'claude' => self::ROLE_PREMIUM_JUDGE,
    ];

    /** Roles that may NOT run as a continuous 24/7 worker. */
    private const NON_24H_WORKER_ROLES = [
        self::ROLE_PREMIUM_JUDGE,
    ];

    /**
     * Deficit Translation Table — the five patterns that, when requested, force a
     * sharded work plan before any provider call. Key is the stable deficit id;
     * value is the canonical Atlas implementation the doc mandates.
     *
     * @var array<string,string>
     */
    private const DEFICIT_TRANSLATION = [
        'huge_repo_single_call' => 'index_shards_summaries_ownership_map_context_pack',
        'analysis_500k_1m_tokens' => 'hierarchical_map_reduce_cross_check_final',
        'many_parallel_agents' => 'many_logical_agents_few_llm_workers',
        'giant_long_output' => 'small_patch_small_spec_verifiable_diff',
        'brutal_multi_repo_scan' => 'prioritized_queue_with_evidence',
    ];

    /**
     * The ordered 24/7 flow ("Fluxo", steps 1..10). Returned so callers and tests
     * can pin the pipeline order; Map precedes Reduce precedes Cross-check
     * precedes Write-lease precedes Verify.
     *
     * @var list<string>
     */
    private const FLOW_STEPS = [
        'intake',
        'index',
        'shard',
        'map',
        'reduce',
        'cross_check',
        'write_lease',
        'verify',
        'repair',
        'evidence',
    ];

    /**
     * Work-plan fields the doc requires ("atlas.minimax_first.work_plan.v1").
     *
     * @var list<string>
     */
    private const WORK_PLAN_REQUIRED_FIELDS = [
        'objective',
        'repo_scope',
        'source_refs',
        'shards',
        'ownership_map',
        'must_keep',
        'context_pack_hash',
        'logical_agents',
        'llm_worker_budget',
        'writer_lease_plan',
        'output_contract',
        'tests_required',
        'deepseek_escalation_conditions',
        'rollback_ref',
    ];

    /**
     * The three fields without which NO writer may edit ("Sem ownership_map,
     * allowed_files e output_contract, nenhum writer pode editar.").
     *
     * @var list<string>
     */
    private const WRITER_GATE_FIELDS = [
        'ownership_map',
        'allowed_files',
        'output_contract',
    ];

    /**
     * The closed set of valid DeepSeek escalation reasons ("DeepSeek Escalation").
     * Anything outside this set — most notably "it seems easier" — is invalid.
     *
     * @var list<string>
     */
    private const VALID_DEEPSEEK_REASONS = [
        'must_keep_above_minimax_context',
        'single_window_500k_1m_tokens',
        'many_independent_shards_simultaneous',
        'multi_repo_audit_brute_parallelism',
        'ap99_minimax_failed_on_context_or_load',
    ];

    /**
     * Decide the work-plan / writer gate for one 24/7 cycle.
     *
     * A cycle that touches code, a canonical doc, or multiple repos must carry a
     * complete work plan; without ownership_map + allowed_files + output_contract
     * no writer is admitted.
     *
     * @param array<string,mixed> $cycle
     *        touches_code         : bool   cycle edits code/canonical-doc/multi-repo (default true)
     *        deficit_patterns     : list   requested Deficit Translation Table ids (default [])
     *        work_plan            : array  the work_plan.v1 payload (fields above)
     *        wants_writer         : bool   a writer is requested this cycle (default true)
     *        repos                : list   repos in scope (>1 = multi-repo) (default [])
     *
     * @return array<string,mixed> the decision + audit receipt
     */
    public function decideWorkPlan(array $cycle): array
    {
        $touchesCode = (bool) ($cycle['touches_code'] ?? true);
        $deficits = $this->normalizeList($cycle['deficit_patterns'] ?? []);
        $repos = $this->normalizeList($cycle['repos'] ?? []);
        $wantsWriter = (bool) ($cycle['wants_writer'] ?? true);
        $plan = is_array($cycle['work_plan'] ?? null) ? $cycle['work_plan'] : [];

        // A requested deficit pattern, or a multi-repo scope, forces a sharded
        // work plan before any provider call ("Esta tabela e regra de
        // implementacao... criar work plan fatiado antes de provider call.").
        $matchedDeficits = array_values(array_intersect($deficits, array_keys(self::DEFICIT_TRANSLATION)));
        $multiRepo = count($repos) > 1;
        $workPlanRequired = $touchesCode || $matchedDeficits !== [] || $multiRepo;

        $missingFields = $this->missingWorkPlanFields($plan);
        $missingWriterGate = $this->missingWriterGateFields($plan);

        $reasons = [];

        // Writer admission: the doc's hard precondition.
        $writerAdmitted = $wantsWriter;
        if ($wantsWriter && $missingWriterGate !== []) {
            $writerAdmitted = false;
            $reasons[] = 'writer_blocked_missing:' . implode('+', $missingWriterGate);
        }

        // Work-plan completeness gate.
        $workPlanComplete = $missingFields === [];
        if ($workPlanRequired && ! $workPlanComplete) {
            $reasons[] = 'work_plan_incomplete:' . implode('+', $missingFields);
        }

        if ($matchedDeficits !== []) {
            $reasons[] = 'deficit_requires_shard_plan:' . implode('+', $matchedDeficits);
        }
        if ($multiRepo) {
            $reasons[] = 'multi_repo_requires_queue_and_shards';
        }

        // Canonical implementation each requested deficit must materialize.
        $deficitImplementations = [];
        foreach ($matchedDeficits as $deficit) {
            $deficitImplementations[$deficit] = self::DEFICIT_TRANSLATION[$deficit];
        }

        // The cycle may only proceed to provider calls when, if a plan is
        // required, it is complete; and when a requested writer is admitted.
        $canProceed = (! $workPlanRequired || $workPlanComplete)
            && (! $wantsWriter || $writerAdmitted);
        if ($canProceed && $reasons === []) {
            $reasons[] = 'cycle_admitted';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'work_plan_required' => $workPlanRequired,
            'work_plan_complete' => $workPlanComplete,
            'missing_fields' => $missingFields,
            'writer_requested' => $wantsWriter,
            'writer_admitted' => $writerAdmitted,
            'missing_writer_gate_fields' => $missingWriterGate,
            'multi_repo' => $multiRepo,
            'matched_deficits' => $matchedDeficits,
            'deficit_implementations' => $deficitImplementations,
            'can_proceed' => $canProceed,
            'auditable' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * Classify how many simultaneous LLM workers / writers a cycle may run, given
     * its pool. Logical agents are unbounded; LLM workers are clamped to the
     * available budget; writers are clamped to one lease per ownership scope.
     *
     * "MiniMax-first significa rodar muitos agentes logicos em fila, com poucos
     * LLM workers ativos." A writer count above the distinct ownership scopes is
     * a scope collision and must be rejected.
     *
     * @param array<string,mixed> $pool
     *        logical_agents      : int   number of logical agents queued (default 0)
     *        llm_worker_budget   : int   max simultaneous active provider calls (default 2)
     *        requested_workers   : int   LLM workers the cycle wants active (default 0)
     *        ownership_scopes    : list  distinct writer ownership scopes (default [])
     *        requested_writers   : int   writers the cycle wants active (default 0)
     *
     * @return array<string,mixed>
     */
    public function classifyWorkerPool(array $pool): array
    {
        $logicalAgents = max(0, $this->toInt($pool['logical_agents'] ?? 0));
        $budget = max(0, $this->toInt($pool['llm_worker_budget'] ?? 2));
        $requestedWorkers = max(0, $this->toInt($pool['requested_workers'] ?? 0));
        $scopes = $this->normalizeList($pool['ownership_scopes'] ?? []);
        $distinctScopes = count(array_unique($scopes));
        $requestedWriters = max(0, $this->toInt($pool['requested_writers'] ?? 0));

        // Logical agents are explicitly unbounded — never clamped.
        $admittedWorkers = min($requestedWorkers, $budget);
        $workerThrottled = $requestedWorkers > $budget;

        // One writer lease per distinct ownership scope; more than that is a
        // scope collision (>1 writer on the same scope), which the doc forbids.
        $admittedWriters = min($requestedWriters, $distinctScopes);
        $writerScopeCollision = $requestedWriters > $distinctScopes;

        $reasons = [];
        if ($workerThrottled) {
            $reasons[] = "llm_worker_throttled_to_budget:{$admittedWorkers}/{$budget}";
        }
        if ($writerScopeCollision) {
            $reasons[] = "writer_scope_collision:{$requestedWriters}>{$distinctScopes}";
        }
        if ($reasons === []) {
            $reasons[] = 'pool_within_limits';
        }

        return [
            'logical_agents' => $logicalAgents,
            'logical_agents_bounded' => false,
            'llm_worker_budget' => $budget,
            'admitted_llm_workers' => $admittedWorkers,
            'llm_worker_throttled' => $workerThrottled,
            'distinct_ownership_scopes' => $distinctScopes,
            'admitted_writers' => $admittedWriters,
            'writer_scope_collision' => $writerScopeCollision,
            'reasons' => $reasons,
        ];
    }

    /**
     * Decide whether DeepSeek may be escalated for this cycle.
     *
     * DeepSeek is scale-after-discipline, never the default and never "because it
     * seems easier". It is admitted only when the supplied reason is one of the
     * canonical technical reasons. Even when admitted it must consume Atlas
     * summaries/ownership-map/context-packs — never a raw dump.
     *
     * @param array<string,mixed> $request
     *        reason            : string  proposed escalation reason
     *        consumes_context_pack : bool DeepSeek will consume Atlas context pack (default false)
     *
     * @return array<string,mixed>
     */
    public function decideDeepSeekEscalation(array $request): array
    {
        $reasonRaw = is_string($request['reason'] ?? null) ? strtolower(trim($request['reason'])) : '';
        $consumesContextPack = (bool) ($request['consumes_context_pack'] ?? false);

        $reasonValid = in_array($reasonRaw, self::VALID_DEEPSEEK_REASONS, true);

        $reasons = [];
        $allowed = true;

        if (! $reasonValid) {
            $allowed = false;
            // The doc's hard stop: "DeepSeek for usado porque 'parece mais facil',
            // sem escalation reason."
            $reasons[] = $reasonRaw === ''
                ? 'deepseek_blocked_no_reason'
                : "deepseek_blocked_invalid_reason:{$reasonRaw}";
        }

        // "Ele nao deve receber dump bruto como padrao." Escalation without
        // committing to consume the Atlas context pack is rejected.
        if ($allowed && ! $consumesContextPack) {
            $allowed = false;
            $reasons[] = 'deepseek_blocked_raw_dump_requires_context_pack';
        }

        if ($allowed) {
            $reasons[] = "deepseek_escalation_admitted:{$reasonRaw}";
        }

        return [
            'allowed' => $allowed,
            'reason' => $reasonRaw,
            'reason_valid' => $reasonValid,
            'consumes_context_pack' => $consumesContextPack,
            'must_consume_atlas_artifacts' => true,
            'reasons' => $reasons,
        ];
    }

    /**
     * The single role a provider may play in this flow, and whether it is allowed
     * to run as a continuous 24/7 worker. Premium judges (Codex / Claude) may
     * never be a 24/7 worker.
     *
     * @return array<string,mixed>
     */
    public function providerRole(string $provider): array
    {
        $key = strtolower(trim($provider));
        $role = self::PROVIDER_ROLES[$key] ?? 'unknown';
        $may24hWorker = $role !== 'unknown' && ! in_array($role, self::NON_24H_WORKER_ROLES, true);

        return [
            'provider' => $key,
            'role' => $role,
            'known' => $role !== 'unknown',
            'may_run_as_24h_worker' => $may24hWorker,
        ];
    }

    /**
     * Evaluate the cycle's "Hard Stops": any single triggered condition blocks
     * the whole cycle. Mirrors the doc's "Hard Stops" list plus the AI rule
     * "Encerrar retry quando a mesma falha reaparecer sem nova evidencia."
     *
     * @param array<string,mixed> $state
     *        writer_edits_outside_allowed_files : bool (default false)
     *        monolithic_giant_output            : bool (default false)
     *        multiple_writers_same_scope        : bool (default false)
     *        mandatory_context_overflow_no_shard_plan : bool (default false)
     *        retry_same_failure_no_new_evidence : bool (default false)
     *        minimax_quota_overflow_unauthorized : bool (default false)
     *        deepseek_without_reason             : bool (default false)
     *
     * @return array<string,mixed>
     */
    public function evaluateHardStops(array $state): array
    {
        $checks = [
            'writer_edits_outside_allowed_files',
            'monolithic_giant_output',
            'multiple_writers_same_scope',
            'mandatory_context_overflow_no_shard_plan',
            'retry_same_failure_no_new_evidence',
            'minimax_quota_overflow_unauthorized',
            'deepseek_without_reason',
        ];

        $triggered = [];
        foreach ($checks as $check) {
            if ((bool) ($state[$check] ?? false)) {
                $triggered[] = $check;
            }
        }

        $blocked = $triggered !== [];

        return [
            'blocked' => $blocked,
            'triggered' => $triggered,
            'checked' => $checks,
            'reasons' => $blocked
                ? array_map(static fn (string $t): string => "hard_stop:{$t}", $triggered)
                : ['no_hard_stop'],
        ];
    }

    /**
     * Decide whether another repair retry may run. The AI rule is explicit:
     * "Encerrar retry quando a mesma falha reaparecer sem nova evidencia." A
     * repeated failure signature with no new evidence terminates the loop;
     * exhausting the attempt budget also terminates it.
     *
     * @param array<string,mixed> $retry
     *        attempt              : int  1-based attempt about to run (default 1)
     *        max_attempts         : int  attempt cap (default 3)
     *        same_failure_signature : bool same failure as last attempt (default false)
     *        new_evidence         : bool fresh evidence since last attempt (default false)
     *
     * @return array<string,mixed>
     */
    public function decideRetry(array $retry): array
    {
        $attempt = max(1, $this->toInt($retry['attempt'] ?? 1));
        $maxAttempts = max(1, $this->toInt($retry['max_attempts'] ?? 3));
        $sameSignature = (bool) ($retry['same_failure_signature'] ?? false);
        $newEvidence = (bool) ($retry['new_evidence'] ?? false);

        $reasons = [];
        $mayRetry = true;

        // Same failure, no new evidence => stop the loop.
        if ($sameSignature && ! $newEvidence) {
            $mayRetry = false;
            $reasons[] = 'repeated_failure_without_new_evidence';
        }

        // Attempt budget exhausted => stop.
        if ($mayRetry && $attempt > $maxAttempts) {
            $mayRetry = false;
            $reasons[] = "attempts_exhausted:{$attempt}/{$maxAttempts}";
        }

        if ($mayRetry) {
            $reasons[] = 'retry_admitted';
        }

        return [
            'may_retry' => $mayRetry,
            'attempt' => $attempt,
            'max_attempts' => $maxAttempts,
            'remaining_attempts' => max(0, $maxAttempts - $attempt),
            'same_failure_signature' => $sameSignature,
            'new_evidence' => $newEvidence,
            'reasons' => $reasons,
        ];
    }

    /**
     * Full read-only snapshot of the flow contract — the deficit table, the
     * worker model, the ordered flow, the work-plan fields and the provider
     * roles. Used by the CLI default invocation and by callers that want the
     * canonical contract without supplying a live cycle.
     *
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'schema' => self::RECEIPT_SCHEMA,
            'policy' => 'minimax_first_then_deepseek_as_scale',
            'deficit_translation' => self::DEFICIT_TRANSLATION,
            'flow_steps' => self::FLOW_STEPS,
            'work_plan_required_fields' => self::WORK_PLAN_REQUIRED_FIELDS,
            'writer_gate_fields' => self::WRITER_GATE_FIELDS,
            'valid_deepseek_reasons' => self::VALID_DEEPSEEK_REASONS,
            'provider_roles' => self::PROVIDER_ROLES,
            'worker_model' => [
                'logical_agent' => 'unbounded',
                'llm_worker' => 'bounded_by_budget_quota_utility',
                'writer' => 'scarce_lease_per_ownership_scope',
            ],
        ];
    }

    /** Ordered flow steps (steps 1..10 of "Fluxo"). @return list<string> */
    public function flowSteps(): array
    {
        return self::FLOW_STEPS;
    }

    /**
     * @param array<string,mixed> $plan
     * @return list<string>
     */
    private function missingWorkPlanFields(array $plan): array
    {
        $missing = [];
        foreach (self::WORK_PLAN_REQUIRED_FIELDS as $field) {
            if (! $this->fieldPresent($plan, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * @param array<string,mixed> $plan
     * @return list<string>
     */
    private function missingWriterGateFields(array $plan): array
    {
        $missing = [];
        foreach (self::WRITER_GATE_FIELDS as $field) {
            if (! $this->fieldPresent($plan, $field)) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    /**
     * A field counts as present when it is set and not an empty string / empty
     * array / null — a work plan with a blank ownership_map does not gate a writer.
     *
     * @param array<string,mixed> $plan
     */
    private function fieldPresent(array $plan, string $field): bool
    {
        if (! array_key_exists($field, $plan)) {
            return false;
        }

        $value = $plan[$field];
        if ($value === null) {
            return false;
        }
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    /**
     * @param mixed $value
     * @return list<string>
     */
    private function normalizeList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (is_string($item) && trim($item) !== '') {
                $out[] = trim($item);
            }
        }

        return array_values($out);
    }

    private function toInt(mixed $value): int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && ctype_digit(ltrim($value, '-')) && $value !== '-') {
            return (int) $value;
        }
        if (is_float($value)) {
            return (int) $value;
        }

        return 0;
    }
}
