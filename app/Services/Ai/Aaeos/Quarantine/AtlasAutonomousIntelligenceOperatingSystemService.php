<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Autonomous Intelligence Operating System decider.
 *
 * Pure, deterministic enforcement of the top-level Autonomous Intelligence OS
 * contract. Nothing here touches the database, a provider, the network or the
 * filesystem — every method returns a typed decision array that a caller may
 * then act on (or refuse to act on). The service emits verdicts + audit
 * receipts; it never executes a mission, clones a repo, runs a tool or spends
 * external budget.
 *
 * Concrete rules grounded in the doc:
 *   - "Fluxo" step 2 → a prompt is classified into exactly one of four
 *     execution tiers (trivial, task, mission, obra). Only mission/obra create
 *     a mission record with a Definition of Done; anything from `mission` up is
 *     governed (source plan, tool plan, safety gate, evidence, certification).
 *   - "Tool Policy" → the 10 ordered questions a tool decision must answer.
 *     A tool that is needed but unavailable, with an unsafe/inactive/wrong-
 *     license repo, resolves to BUILD a proprietary tool; a clean trusted repo
 *     resolves to CLONE; a trusted existing tool resolves to USE_EXISTING; an
 *     unnecessary tool resolves to SKIP.
 *   - "Riscos" → the explicit list of conditions under which the OS must BLOCK
 *     or REQUIRE CONFIRMATION (external cost, login/credential, personal data,
 *     sensitive scraping, uncertain ToS, anti-bot automation, destructive
 *     execution, suspicious repo clone, destructive/exfil/mutating command).
 *     Destructive / exfil commands are hard-blocked; the rest gate on
 *     confirmation.
 *   - "Definition of Done" + "Regras Para IA" → a mission may only be
 *     CERTIFIED when its result is validated AND an evidence pack exists; a
 *     real blocker is a valid (not hidden) result; an external-advantage claim
 *     is never asserted as proven without real external evidence.
 *
 * @see docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
 */
final class AtlasAutonomousIntelligenceOperatingSystemService
{
    /** Stable receipt schema id for the decisions this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.ai.intelligence.os.v1';

    /**
     * The four execution tiers from "Fluxo" step 2, weakest to strongest.
     * Order is load-bearing: governance kicks in at `mission`.
     *
     * @var list<string>
     */
    public const EXECUTION_TIERS = ['trivial', 'task', 'mission', 'obra'];

    /** Tiers at or above this index become governed missions with a DoD. */
    private const GOVERNED_FROM_INDEX = 2; // 'mission'

    /**
     * The 10 ordered Tool Policy questions from the doc. Every tool decision
     * must be answerable against these; the order is the doc's order.
     *
     * @var list<string>
     */
    public const TOOL_POLICY_QUESTIONS = [
        'tool_is_necessary',
        'trusted_ready_tool_exists',
        'clone_cost_risk_acceptable',
        'license_allows_use',
        'repo_has_activity_and_reputation',
        'supply_chain_risk',
        'building_own_is_safer_or_more_efficient',
        'tool_must_persist_for_reuse',
        'how_to_validate_it_worked',
        'how_to_record_learning_for_evolution',
    ];

    /** Tool decision verdicts. */
    public const TOOL_SKIP = 'skip';
    public const TOOL_USE_EXISTING = 'use_existing';
    public const TOOL_CLONE_REPO = 'clone_repo';
    public const TOOL_BUILD_OWN = 'build_own';

    /**
     * "Riscos": conditions that must BLOCK or require confirmation. The four
     * destructive/data-loss conditions are hard blocks (cannot be waved through
     * by a confirmation flag); the rest gate on explicit confirmation.
     *
     * @var list<string>
     */
    public const RISK_CONDITIONS = [
        'external_cost',
        'login_or_credential',
        'personal_data',
        'sensitive_scraping',
        'uncertain_tos',
        'anti_bot_automation',
        'destructive_execution',
        'suspicious_repo_clone',
        'data_delete_exfil_or_mutate_command',
    ];

    /**
     * The subset of risk conditions that can NEVER be auto-approved by a
     * confirmation flag — they always require an explicit human decision and,
     * for the data-loss/exfil class, a hard stop.
     *
     * @var list<string>
     */
    public const HARD_BLOCK_CONDITIONS = [
        'destructive_execution',
        'data_delete_exfil_or_mutate_command',
    ];

    /**
     * "Definition of Done": the gates that must be true for a mission to be
     * certifiable. Mirrors the doc's DoD bullets that map to a single mission.
     *
     * @var list<string>
     */
    public const CERTIFICATION_GATES = [
        'result_validated',
        'evidence_pack_present',
        'blockers_explicit',
        'domain_selected',
    ];

    /**
     * The 9 canonical layers the Autonomous OS can hand control to next,
     * numbered in the order the doc presents them. Layer number is part of
     * the receipt contract — callers route on it, not on the name string.
     *
     * @var array<int,string>
     */
    public const CANONICAL_LAYERS = [
        1 => 'task_creation',
        2 => 'queue_self_healing',
        3 => 'outcome_learning',
        4 => 'audit_and_verification',
        5 => 'capability_expansion',
        6 => 'compounding_memory',
        7 => 'architecture_evolution',
        8 => 'provider_routing',
        9 => 'strategic_origination',
    ];

    /** Above this pressure, blocked/give-back signals override everything else. */
    private const HIGH_PRESSURE_THRESHOLD = 0.4;

    /** At/above this poison pressure the queue is no longer "low poison". */
    private const LOW_POISON_CEILING = 0.2;

    /** Default queue depth (claimable + servable) considered "sufficiently deep". */
    private const DEFAULT_SUFFICIENT_QUEUE_DEPTH = 10;

    /** Default servable count floor below which replenishment is needed. */
    private const DEFAULT_LOW_SERVABLE_FLOOR = 3;

    /**
     * Choose the next canonical layer from live queue, blocker, model and
     * simplification signals — never simply "the layer that creates the
     * most tasks". A deep, healthy queue means WAIT/AUDIT, not more task
     * creation; high blocked/give-back pressure means repair the queue or
     * learn from outcomes before originating anything new; only a
     * genuinely thin queue routes to Task Creation.
     *
     * Decision order (first match wins):
     *   1. blocked/give_back pressure over HIGH_PRESSURE_THRESHOLD routes to
     *      Queue Self-Healing (blocked-dominant) or Outcome Learning
     *      (give-back-dominant) — an unhealthy queue must be repaired
     *      before originating more work onto it.
     *   2. a queue that is already deep (claimable+servable >= target) AND
     *      low-poison abstains with a wait/audit action — raw task count is
     *      never the optimization target.
     *   3. a thin servable queue (below the floor) routes to Task Creation
     *      with a replenish action.
     *   4. otherwise the queue is in a steady, unremarkable state: default
     *      to Audit & Verification rather than originating blindly.
     *
     * @param array<string,mixed> $signals
     *        servable_count        : int    tasks currently servable to a worker
     *        claimable_count       : int    tasks currently claimable (queued)
     *        poison_pressure       : float  0..1 fraction of poisoned/contradictory packets
     *        blocked_pressure      : float  0..1 fraction of tasks stuck blocked
     *        give_back_pressure    : float  0..1 fraction of recent give-backs
     *        sufficient_queue_depth: int    optional override of the "deep enough" target
     *        low_servable_floor    : int    optional override of the "thin queue" floor
     *
     * @return array<string,mixed>
     */
    public function decideNextLayer(array $signals): array
    {
        $servable = max(0, (int) ($signals['servable_count'] ?? 0));
        $claimable = max(0, (int) ($signals['claimable_count'] ?? 0));
        $poisonPressure = (float) ($signals['poison_pressure'] ?? 0.0);
        $blockedPressure = (float) ($signals['blocked_pressure'] ?? 0.0);
        $giveBackPressure = (float) ($signals['give_back_pressure'] ?? 0.0);
        $sufficientDepth = (int) ($signals['sufficient_queue_depth'] ?? self::DEFAULT_SUFFICIENT_QUEUE_DEPTH);
        $lowServableFloor = (int) ($signals['low_servable_floor'] ?? self::DEFAULT_LOW_SERVABLE_FLOOR);

        $queueDepth = $servable + $claimable;
        $lowPoison = $poisonPressure < self::LOW_POISON_CEILING;

        $reasons = [];
        $abstain = false;

        if ($blockedPressure > self::HIGH_PRESSURE_THRESHOLD || $giveBackPressure > self::HIGH_PRESSURE_THRESHOLD) {
            if ($blockedPressure >= $giveBackPressure) {
                $layerNumber = 2;
                $action = 'repair_queue';
                $reasons[] = "blocked_pressure_{$blockedPressure}_exceeds_threshold_" . self::HIGH_PRESSURE_THRESHOLD;
            } else {
                $layerNumber = 3;
                $action = 'learn_from_outcomes';
                $reasons[] = "give_back_pressure_{$giveBackPressure}_exceeds_threshold_" . self::HIGH_PRESSURE_THRESHOLD;
            }
            $reasons[] = 'unhealthy_queue_must_be_repaired_before_origination';
        } elseif ($queueDepth >= $sufficientDepth && $lowPoison) {
            $layerNumber = 4;
            $action = 'wait_and_audit';
            $abstain = true;
            $reasons[] = "queue_depth_{$queueDepth}_meets_sufficient_depth_{$sufficientDepth}";
            $reasons[] = "poison_pressure_{$poisonPressure}_is_low";
            $reasons[] = 'no_new_task_creation_raw_count_is_not_the_target';
        } elseif ($servable < $lowServableFloor) {
            $layerNumber = 1;
            $action = 'replenish_queue';
            $reasons[] = "servable_count_{$servable}_below_floor_{$lowServableFloor}";
        } else {
            $layerNumber = 4;
            $action = 'audit_and_verify';
            $reasons[] = 'queue_in_steady_unremarkable_state_default_to_audit';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'layer_number' => $layerNumber,
            'layer_name' => self::CANONICAL_LAYERS[$layerNumber],
            'action' => $action,
            'abstain' => $abstain,
            'ranked_reasons' => $reasons,
            'queue_depth' => $queueDepth,
            'servable_count' => $servable,
            'claimable_count' => $claimable,
        ];
    }

    /**
     * Classify a prompt into exactly one execution tier ("Fluxo" step 2) and
     * decide whether it becomes a governed mission with a Definition of Done.
     *
     * Trivial = a direct answer; task = a single bounded action; mission = a
     * multi-step objective that needs source/tool plan, safety + evidence;
     * obra = a large multi-cycle / multi-domain build. Governance (mission
     * record, DoD, source plan, tool plan, safety gate, certification) is
     * required from `mission` upward.
     *
     * @param array<string,mixed> $signals
     *        requires_research        : bool  needs external sources / fresh facts
     *        multi_step               : bool  more than one bounded action
     *        needs_tools              : bool  browser/terminal/github/api/tool use
     *        multi_domain             : bool  spans 2+ domain runtimes
     *        multi_cycle              : bool  large, decomposed into cycles / Obra
     *        explicit_tier            : string optional forced tier (testing/override)
     *
     * @return array<string,mixed>
     */
    public function classifyMission(array $signals): array
    {
        $tier = $this->resolveTier($signals);
        $index = (int) array_search($tier, self::EXECUTION_TIERS, true);
        $governed = $index >= self::GOVERNED_FROM_INDEX;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'execution_tier' => $tier,
            'is_governed_mission' => $governed,
            // A mission record + Definition of Done is only created from
            // `mission` up; trivial/task answer directly without ceremony.
            'creates_mission_record' => $governed,
            'requires_definition_of_done' => $governed,
            'requires_source_plan' => $governed,
            'requires_tool_plan' => $governed,
            'requires_safety_gate' => $governed,
            'requires_evidence_pack' => $governed,
            'requires_certification' => $governed,
            // Obra is the only tier that is also promoted to Forge / multi-cycle.
            'promote_to_forge' => $tier === 'obra',
        ];
    }

    /**
     * Resolve the Tool Policy verdict from the 10 documented questions.
     *
     * Decision order mirrors the doc:
     *   1. If the tool is not necessary  -> SKIP (failure mode: "criar ferramenta
     *      quando uma confiavel ja existe" / unnecessary tool use).
     *   2. A trusted ready tool exists    -> USE_EXISTING.
     *   3. Otherwise consider a repo clone. A clone is only allowed when license
     *      permits, the repo has activity/reputation, there is no supply-chain
     *      risk, and the clone cost/risk is acceptable. Any failure there, OR an
     *      explicit "building own is safer/more efficient", routes to BUILD_OWN.
     *      ("Nao clonar repo sem avaliar licenca, atividade, seguranca e escopo.")
     *
     * @param array<string,mixed> $answers keyed by TOOL_POLICY_QUESTIONS (bool)
     *
     * @return array<string,mixed>
     */
    public function decideTool(array $answers): array
    {
        $a = $this->normalizeToolAnswers($answers);

        $verdict = self::TOOL_BUILD_OWN;
        $reason = 'no_safe_existing_path';

        if (! $a['tool_is_necessary']) {
            $verdict = self::TOOL_SKIP;
            $reason = 'tool_not_necessary';
        } elseif ($a['trusted_ready_tool_exists']) {
            $verdict = self::TOOL_USE_EXISTING;
            $reason = 'trusted_ready_tool_exists';
        } elseif ($a['building_own_is_safer_or_more_efficient']) {
            // The doc lets Atlas build when it is genuinely safer/more efficient
            // (not "por ego") — honoured before attempting a clone.
            $verdict = self::TOOL_BUILD_OWN;
            $reason = 'building_own_is_safer_or_more_efficient';
        } else {
            $cloneBlockers = $this->cloneBlockers($a);
            if ($cloneBlockers === []) {
                $verdict = self::TOOL_CLONE_REPO;
                $reason = 'clone_repo_passed_all_gates';
            } else {
                $verdict = self::TOOL_BUILD_OWN;
                $reason = 'clone_unsafe_fallback_to_build:' . implode(',', $cloneBlockers);
            }
        }

        $persist = $verdict === self::TOOL_BUILD_OWN
            ? (bool) $a['tool_must_persist_for_reuse']
            : false;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'reason' => $reason,
            'clone_blockers' => $this->cloneBlockers($a),
            // The doc requires every tool that runs to answer "how do I validate
            // it worked" and "how do I record learning". A tool that runs (any
            // verdict except SKIP) without both is non-compliant.
            'validation_planned' => (bool) $a['how_to_validate_it_worked'],
            'learning_recorded_planned' => (bool) $a['how_to_record_learning_for_evolution'],
            'policy_compliant' => $verdict === self::TOOL_SKIP
                || ((bool) $a['how_to_validate_it_worked'] && (bool) $a['how_to_record_learning_for_evolution']),
            'persist_for_reuse' => $persist,
            'questions_answered' => array_keys($a),
        ];
    }

    /**
     * Evaluate the Safety / Cost / Auth / Legal gate against the "Riscos" list.
     *
     * Any present risk condition raises the gate. Hard-block conditions
     * (destructive execution, delete/exfil/mutate command) BLOCK outright and
     * cannot be waved through by a confirmation flag. The remaining conditions
     * REQUIRE_CONFIRMATION unless the caller passes an explicit human
     * confirmation, in which case they are allowed-with-confirmation.
     *
     * @param array<string,mixed> $context keyed by RISK_CONDITIONS (bool) plus:
     *        human_confirmation : bool  explicit operator approval was given
     *
     * @return array<string,mixed>
     */
    public function evaluateSafetyGate(array $context): array
    {
        $triggered = [];
        foreach (self::RISK_CONDITIONS as $condition) {
            if (! empty($context[$condition])) {
                $triggered[] = $condition;
            }
        }

        $hardBlocks = array_values(array_intersect($triggered, self::HARD_BLOCK_CONDITIONS));
        $confirmation = ! empty($context['human_confirmation']);

        if ($triggered === []) {
            $status = 'passed';
        } elseif ($hardBlocks !== []) {
            // Destructive / exfil class: never auto-runs, even with confirmation.
            $status = 'blocked';
        } elseif ($confirmation) {
            $status = 'allowed_with_confirmation';
        } else {
            $status = 'requires_confirmation';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'safety_status' => $status,
            'triggered_conditions' => $triggered,
            'hard_block_conditions' => $hardBlocks,
            // Only `passed` and `allowed_with_confirmation` may proceed to
            // execution; `requires_confirmation` halts pending approval and
            // `blocked` is terminal.
            'may_execute' => $status === 'passed' || $status === 'allowed_with_confirmation',
            'requires_human_confirmation' => $status === 'requires_confirmation',
        ];
    }

    /**
     * Decide whether a finished mission may be CERTIFIED ("Definition of Done").
     *
     * A mission is certifiable only when its result is validated AND an evidence
     * pack is present AND a domain was selected. An UNRESOLVED real blocker is a
     * legitimate, valid result ("blocker real e resultado valido") — it is
     * surfaced explicitly as `blocked`, never hidden, and never silently
     * certified as complete.
     *
     * @param array<string,mixed> $outcome
     *        domain_selected      : bool
     *        result_validated     : bool
     *        evidence_pack_present: bool
     *        open_blockers        : list<string>|int  unresolved blockers
     *
     * @return array<string,mixed>
     */
    public function certifyMission(array $outcome): array
    {
        $domainSelected = ! empty($outcome['domain_selected']);
        $validated = ! empty($outcome['result_validated']);
        $evidence = ! empty($outcome['evidence_pack_present']);

        $blockers = $outcome['open_blockers'] ?? [];
        $openBlockers = is_array($blockers) ? count($blockers) : (int) $blockers;
        $hasOpenBlocker = $openBlockers > 0;

        $missingGates = [];
        if (! $domainSelected) {
            $missingGates[] = 'domain_selected';
        }
        if (! $validated) {
            $missingGates[] = 'result_validated';
        }
        if (! $evidence) {
            $missingGates[] = 'evidence_pack_present';
        }

        if ($hasOpenBlocker) {
            // A real, explicit blocker is a valid result — surface it, do not
            // pretend the mission completed.
            $status = 'blocked';
        } elseif ($missingGates === []) {
            $status = 'certified';
        } else {
            $status = 'incomplete';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'certification_status' => $status,
            'certified' => $status === 'certified',
            'missing_gates' => $missingGates,
            'open_blockers' => $openBlockers,
            // The doc forbids declaring a mission complete without an evidence
            // pack — make that impossible to satisfy implicitly.
            'evidence_pack_required' => true,
            'blocker_is_valid_result' => $hasOpenBlocker,
        ];
    }

    /**
     * "Nao confundir capacidade com prova de superioridade." A claim that Atlas
     * is stronger than an external system may only be asserted with real,
     * reproducible external proof; otherwise only the internal capability may be
     * stated.
     *
     * @param array<string,mixed> $claim
     *        asserts_external_advantage : bool  claim ranks Atlas above an external system
     *        external_proof_present     : bool  real reproducible comparative evidence exists
     *
     * @return array<string,mixed>
     */
    public function evaluateAdvantageClaim(array $claim): array
    {
        $assertsAdvantage = ! empty($claim['asserts_external_advantage']);
        $hasProof = ! empty($claim['external_proof_present']);

        if (! $assertsAdvantage) {
            $disposition = 'internal_capability_only';
        } elseif ($hasProof) {
            $disposition = 'external_advantage_proven';
        } else {
            $disposition = 'downgrade_to_internal_capability';
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'disposition' => $disposition,
            // Only an advantage claim backed by real external proof may be
            // asserted as such; everything else is stated as internal capability.
            'may_assert_external_advantage' => $disposition === 'external_advantage_proven',
        ];
    }

    /**
     * Resolve the execution tier from raw signals. An explicit tier override is
     * honoured when valid; otherwise tiers are derived monotonically: obra >
     * mission > task > trivial.
     *
     * @param array<string,mixed> $s
     */
    private function resolveTier(array $s): string
    {
        $explicit = $s['explicit_tier'] ?? null;
        if (is_string($explicit) && in_array($explicit, self::EXECUTION_TIERS, true)) {
            return $explicit;
        }

        $multiCycle = ! empty($s['multi_cycle']);
        $multiDomain = ! empty($s['multi_domain']);
        $multiStep = ! empty($s['multi_step']);
        $needsResearch = ! empty($s['requires_research']);
        $needsTools = ! empty($s['needs_tools']);

        // Obra: large, decomposed into cycles or spanning multiple domains.
        if ($multiCycle || $multiDomain) {
            return 'obra';
        }

        // Mission: multi-step OR requires research OR needs governed tool use.
        if ($multiStep || $needsResearch || $needsTools) {
            return 'mission';
        }

        // Task: a single bounded action (no research, no tools, not multi-step
        // but explicitly flagged as an action to perform).
        if (! empty($s['single_action'])) {
            return 'task';
        }

        return 'trivial';
    }

    /**
     * Normalize the 10 Tool Policy answers to booleans, defaulting unknowns to
     * the safe value. Safety-critical clone gates default to FALSE (i.e. "not
     * proven safe" => do not clone), while "necessary" defaults to true so an
     * under-specified request still gets evaluated rather than silently skipped.
     *
     * @param array<string,mixed> $answers
     *
     * @return array<string,bool>
     */
    private function normalizeToolAnswers(array $answers): array
    {
        $defaults = [
            'tool_is_necessary' => true,
            'trusted_ready_tool_exists' => false,
            'clone_cost_risk_acceptable' => false,
            'license_allows_use' => false,
            'repo_has_activity_and_reputation' => false,
            'supply_chain_risk' => false,
            'building_own_is_safer_or_more_efficient' => false,
            'tool_must_persist_for_reuse' => false,
            'how_to_validate_it_worked' => false,
            'how_to_record_learning_for_evolution' => false,
        ];

        $out = [];
        foreach ($defaults as $key => $default) {
            $out[$key] = array_key_exists($key, $answers) ? (bool) $answers[$key] : $default;
        }

        return $out;
    }

    /**
     * The reasons a repo clone is NOT allowed, given normalized tool answers.
     * An empty list means the clone passes every documented gate.
     *
     * @param array<string,bool> $a
     *
     * @return list<string>
     */
    private function cloneBlockers(array $a): array
    {
        $blockers = [];
        if (! $a['license_allows_use']) {
            $blockers[] = 'license_disallows_use';
        }
        if (! $a['repo_has_activity_and_reputation']) {
            $blockers[] = 'repo_lacks_activity_or_reputation';
        }
        if ($a['supply_chain_risk']) {
            $blockers[] = 'supply_chain_risk';
        }
        if (! $a['clone_cost_risk_acceptable']) {
            $blockers[] = 'clone_cost_or_risk_unacceptable';
        }

        return $blockers;
    }
}
