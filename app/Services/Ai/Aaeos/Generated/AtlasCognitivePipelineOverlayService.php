<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Cognitive Plane — Pipeline Overlay runtime.
 *
 * Turns the cognitive pipeline-overlay doc into deterministic, pure decision
 * logic. The doc is an additive overlay on the canonical 17-stage pipeline:
 * the stages stay unchanged, each gains a cognitive hook, and the `learning`
 * domain expands into a versioned flow catalog. Rather than restating the
 * prose, this service enforces exactly the decidable contracts the doc states:
 *
 *  - Authority ordering (doc "Authority"): on conflict the winner is
 *    atlas-ai-pipeline > atlas-ai-flow-visual-map > este doc > domains/learning.
 *    resolveAuthority() returns the higher-ranked of two contenders.
 *  - Flow catalog (doc "Domain `learning` v2"): 24 catalogued flows, each with a
 *    cadence and a maturity. AP status is the source of truth, so a flow at
 *    `scaffold`/`base` maturity is NOT runtime-ready; only `read_model`,
 *    `implemented_partial` and `runtime` flows may be claimed beyond design.
 *    classifyFlow() resolves a flow id to its documented runtime-readiness.
 *  - Mandatory gates (doc "Gates obrigatorios"): a learning flow only COMPLETES
 *    when every documented gate holds (the three legacy gates plus seven
 *    cognitive gates incl. Dreyfus pedagogy + transfer_proof). evaluateGates()
 *    caps on the full set and lists what is missing.
 *  - Forbidden actions (doc "Forbidden actions"): concrete prohibitions —
 *    notably "marcar dominado sem transfer_proof" and "promover learning result
 *    a Core Memory sem review". guardMasteryClaim() and guardCoreMemoryPromotion()
 *    block these regardless of any other signal.
 *  - Stage-14 repair/escalation (doc pipeline row 14): high cognitive load
 *    reschedules; a failed transfer test opens a NEW block with a probe — it
 *    never marks the concept mastered. resolveRepair() decides this transition.
 *  - Temporal loops (doc "Loops Temporais Cognitivos"): six stacked loops, each
 *    bound to a time window. selectLoop() maps an available-seconds budget to
 *    the single documented loop whose window it falls in.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/cognitive/pipeline-overlay.md
 */
final class AtlasCognitivePipelineOverlayService
{
    public const SCHEMA_VERSION = 'atlas.cognitive.pipeline_overlay.v1';

    /**
     * Authority chain, highest first (doc "Authority":
     * atlas-ai-pipeline > atlas-ai-flow-visual-map > este doc > domains/learning).
     * Index 0 is the strongest authority.
     *
     * @var list<string>
     */
    public const AUTHORITY_CHAIN = [
        'atlas-ai-pipeline',
        'atlas-ai-flow-visual-map',
        'pipeline-overlay',
        'domains/learning',
    ];

    /**
     * Documented `learning` v2 flow catalog (doc table "Domain `learning` v2").
     * Each entry: cadence + maturity exactly as written in the doc. Maturity
     * drives runtime-readiness via MATURITY_READY.
     *
     * @var array<string,array{cadence:string,maturity:string}>
     */
    public const FLOW_CATALOG = [
        'learning.objective_design' => ['cadence' => 'on_demand', 'maturity' => 'base'],
        'learning.curriculum_design' => ['cadence' => 'on_demand', 'maturity' => 'base'],
        'learning.predictive_curriculum' => ['cadence' => 'weekly_curator', 'maturity' => 'scaffold'],
        'learning.daily_plan' => ['cadence' => 'daily', 'maturity' => 'base'],
        'learning.deep_work' => ['cadence' => 'on_demand', 'maturity' => 'base'],
        'learning.micro_session' => ['cadence' => 'on_demand', 'maturity' => 'base'],
        'learning.active_recall' => ['cadence' => 'in_session', 'maturity' => 'scaffold'],
        'learning.feynman_explain' => ['cadence' => 'on_demand', 'maturity' => 'scaffold'],
        'learning.case_study' => ['cadence' => 'on_demand', 'maturity' => 'scaffold'],
        'learning.game_session' => ['cadence' => 'on_demand', 'maturity' => 'scaffold'],
        'learning.spaced_review' => ['cadence' => 'daily', 'maturity' => 'scaffold'],
        'learning.consolidation' => ['cadence' => 'post_session', 'maturity' => 'scaffold'],
        'learning.gap_detection' => ['cadence' => 'weekly_curator', 'maturity' => 'base'],
        'learning.transfer_test' => ['cadence' => 'fortnightly', 'maturity' => 'scaffold'],
        'learning.mastery_review' => ['cadence' => 'monthly', 'maturity' => 'scaffold'],
        'learning.knowledge_graph_review' => ['cadence' => 'fortnightly', 'maturity' => 'scaffold'],
        'learning.forgetting_review' => ['cadence' => 'monthly', 'maturity' => 'scaffold'],
        'learning.forge' => ['cadence' => 'on_demand_approval', 'maturity' => 'scaffold'],
        'learning.worked_example' => ['cadence' => 'on_demand_auto_deep_work', 'maturity' => 'read_model'],
        'learning.process_optimization' => ['cadence' => 'on_demand', 'maturity' => 'scaffold'],
        'learning.pattern_extraction' => ['cadence' => 'weekly_curator', 'maturity' => 'read_model'],
        'learning.failure_review' => ['cadence' => 'weekly', 'maturity' => 'read_model'],
        'learning.srl_episode' => ['cadence' => 'per_session_opt_in', 'maturity' => 'read_model'],
        'learning.productive_failure' => ['cadence' => 'on_demand', 'maturity' => 'implemented_partial'],
    ];

    /**
     * Maturity levels the doc treats as "implemented beyond design" — anything
     * here may be claimed as a real read-model/runtime. `base`/`scaffold` are
     * design-only (AP status is the source of truth; doc preamble of the table).
     *
     * @var list<string>
     */
    public const MATURITY_READY = [
        'read_model',
        'implemented_partial',
        'runtime',
    ];

    /**
     * Mandatory gates (doc "Gates obrigatorios"): the three legacy gates plus the
     * seven additive cognitive gates. A learning flow only completes when ALL of
     * these hold.
     *
     * @var list<string>
     */
    public const MANDATORY_GATES = [
        'learning_objective',
        'practice_loop',
        'mastery_rubric',
        'cognitive_load_check',
        'non_clinical_safety',
        'provider_safety_redaction',
        'evidence_attribution',
        'transfer_proof',
        'forgetting_curve_respected',
        'pedagogy_matches_stage',
    ];

    /**
     * Temporal loops (doc "Loops Temporais Cognitivos"), each bound to the
     * documented time window in seconds. `max_seconds = null` means open-ended
     * (the longest cadence). Ordered shortest-first so selectLoop() picks the
     * tightest loop the budget fits.
     *
     * @var list<array{loop:string,min_seconds:int,max_seconds:?int,activity:string}>
     */
    public const LOOPS = [
        ['loop' => 'reflex', 'min_seconds' => 0, 'max_seconds' => 30, 'activity' => 'flashcard_on_idle'],
        ['loop' => 'reaction', 'min_seconds' => 300, 'max_seconds' => 900, 'activity' => 'micro_session'],
        ['loop' => 'deliberation', 'min_seconds' => 3600, 'max_seconds' => 7200, 'activity' => 'deep_work'],
        ['loop' => 'heartbeat', 'min_seconds' => 86400, 'max_seconds' => 86400, 'activity' => 'daily_plan_spaced_review'],
        ['loop' => 'contemplation', 'min_seconds' => 604800, 'max_seconds' => 604800, 'activity' => 'gap_detection_kg_review'],
        ['loop' => 'cycle', 'min_seconds' => 2592000, 'max_seconds' => null, 'activity' => 'mastery_review_forgetting_review'],
    ];

    /**
     * The hook field that stage 4 (Operation Envelope) must carry for a
     * cognitive input (doc pipeline row 4: input_kind=cognitive carries
     * study_session_id + cognitive_load_snapshot + dreyfus_stage_target).
     *
     * @var list<string>
     */
    public const ENVELOPE_COGNITIVE_FIELDS = [
        'study_session_id',
        'cognitive_load_snapshot',
        'dreyfus_stage_target',
    ];

    /**
     * Resolve which of two documented sources wins on conflict (doc "Authority").
     * Unknown sources rank below every known source.
     *
     * @return array{
     *   schema_version:string,
     *   left:string,
     *   right:string,
     *   winner:string,
     *   reason:string
     * }
     */
    public function resolveAuthority(string $left, string $right): array
    {
        $l = $this->normalizeSource($left);
        $r = $this->normalizeSource($right);

        $li = array_search($l, self::AUTHORITY_CHAIN, true);
        $ri = array_search($r, self::AUTHORITY_CHAIN, true);

        // Unknown => weakest. Smaller index == stronger authority.
        $lRank = $li === false ? PHP_INT_MAX : $li;
        $rRank = $ri === false ? PHP_INT_MAX : $ri;

        if ($lRank === $rRank) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'left' => $l,
                'right' => $r,
                'winner' => $l,
                'reason' => 'same_authority_rank',
            ];
        }

        $winner = $lRank < $rRank ? $l : $r;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'left' => $l,
            'right' => $r,
            'winner' => $winner,
            'reason' => 'higher_authority_in_chain',
        ];
    }

    /**
     * Resolve a flow id to its documented cadence + maturity + runtime-readiness
     * (doc "Domain `learning` v2"). Unknown flows are reported, never guessed.
     *
     * @return array{
     *   schema_version:string,
     *   flow:string,
     *   known:bool,
     *   cadence:?string,
     *   maturity:?string,
     *   runtime_ready:bool,
     *   reason:string
     * }
     */
    public function classifyFlow(string $flowId): array
    {
        $flow = strtolower(trim($flowId));

        if (! array_key_exists($flow, self::FLOW_CATALOG)) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'flow' => $flow,
                'known' => false,
                'cadence' => null,
                'maturity' => null,
                'runtime_ready' => false,
                'reason' => 'flow_not_in_catalog',
            ];
        }

        $entry = self::FLOW_CATALOG[$flow];
        $ready = in_array($entry['maturity'], self::MATURITY_READY, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'flow' => $flow,
            'known' => true,
            'cadence' => $entry['cadence'],
            'maturity' => $entry['maturity'],
            'runtime_ready' => $ready,
            'reason' => $ready
                ? 'maturity_at_or_above_read_model'
                : 'design_only_ap_status_governs',
        ];
    }

    /**
     * Mandatory-gate evaluation (doc "Gates obrigatorios"): a learning flow only
     * COMPLETES when all ten documented gates hold. Returns satisfied count, the
     * missing gates and whether the flow may complete.
     *
     * @param  array<string,bool>  $gates  subset of MANDATORY_GATES -> satisfied
     * @return array{
     *   schema_version:string,
     *   total_gates:int,
     *   satisfied:int,
     *   missing:list<string>,
     *   may_complete:bool,
     *   reason:string
     * }
     */
    public function evaluateGates(array $gates): array
    {
        $missing = [];
        $satisfied = 0;

        foreach (self::MANDATORY_GATES as $gate) {
            if (($gates[$gate] ?? false) === true) {
                $satisfied++;
            } else {
                $missing[] = $gate;
            }
        }

        $mayComplete = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'total_gates' => count(self::MANDATORY_GATES),
            'satisfied' => $satisfied,
            'missing' => $missing,
            'may_complete' => $mayComplete,
            'reason' => $mayComplete
                ? 'all_mandatory_gates_satisfied'
                : 'mandatory_gates_missing',
        ];
    }

    /**
     * Forbidden action (doc "Forbidden actions": "Marcar dominado sem
     * transfer_proof"). A concept may NEVER be marked mastered unless a transfer
     * proof exists. This holds regardless of mastery score or any other signal.
     *
     * @return array{
     *   schema_version:string,
     *   concept:string,
     *   transfer_proof:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function guardMasteryClaim(string $concept, bool $transferProof): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'concept' => trim($concept),
            'transfer_proof' => $transferProof,
            'allowed' => $transferProof,
            'reason' => $transferProof
                ? 'transfer_proof_present_mastery_may_be_marked'
                : 'forbidden_mark_mastered_without_transfer_proof',
        ];
    }

    /**
     * Forbidden action (doc "Forbidden actions": "Promover learning result a Core
     * Memory sem review"). A learning result may only be promoted to Core Memory
     * after an explicit human/governed review. Without review the promotion is
     * blocked even if the result looks high quality.
     *
     * @return array{
     *   schema_version:string,
     *   reviewed:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function guardCoreMemoryPromotion(bool $reviewed): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'reviewed' => $reviewed,
            'allowed' => $reviewed,
            'reason' => $reviewed
                ? 'review_present_promotion_may_proceed'
                : 'forbidden_promote_learning_result_to_core_memory_without_review',
        ];
    }

    /**
     * Stage-14 Repair / Escalation (doc pipeline row 14: "load alto -> reagendar;
     * transfer fail -> bloco novo com probe"). Decides the repair transition from
     * a load signal and the transfer-test outcome. High load always reschedules
     * (and takes precedence). A failed transfer opens a NEW block with a probe and
     * NEVER marks mastered. Otherwise the session proceeds.
     *
     * @param  string  $transferOutcome  one of: passed|failed|not_run
     * @return array{
     *   schema_version:string,
     *   load_high:bool,
     *   transfer_outcome:string,
     *   action:string,
     *   open_new_block:bool,
     *   attach_probe:bool,
     *   mark_mastered:bool,
     *   reason:string
     * }
     */
    public function resolveRepair(bool $loadHigh, string $transferOutcome): array
    {
        $outcome = strtolower(trim($transferOutcome));
        if (! in_array($outcome, ['passed', 'failed', 'not_run'], true)) {
            $outcome = 'not_run';
        }

        if ($loadHigh) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'load_high' => true,
                'transfer_outcome' => $outcome,
                'action' => 'reschedule',
                'open_new_block' => false,
                'attach_probe' => false,
                'mark_mastered' => false,
                'reason' => 'cognitive_load_high_reschedule',
            ];
        }

        if ($outcome === 'failed') {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'load_high' => false,
                'transfer_outcome' => 'failed',
                'action' => 'open_new_block_with_probe',
                'open_new_block' => true,
                'attach_probe' => true,
                'mark_mastered' => false,
                'reason' => 'transfer_failed_new_block_with_probe',
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'load_high' => false,
            'transfer_outcome' => $outcome,
            'action' => 'proceed',
            'open_new_block' => false,
            'attach_probe' => false,
            'mark_mastered' => false,
            'reason' => 'no_repair_trigger_proceed',
        ];
    }

    /**
     * Temporal-loop selection (doc "Loops Temporais Cognitivos"). Maps an
     * available-seconds budget to the single documented loop whose window it
     * falls in. Picks the tightest loop the budget fully fits; a budget below
     * every minimum still maps to the reflex loop (the floor). Negative budgets
     * are clamped to 0.
     *
     * @return array{
     *   schema_version:string,
     *   available_seconds:int,
     *   loop:string,
     *   activity:string,
     *   reason:string
     * }
     */
    public function selectLoop(int $availableSeconds): array
    {
        $budget = max(0, $availableSeconds);
        $selected = self::LOOPS[0]; // reflex floor

        foreach (self::LOOPS as $loop) {
            if ($budget < $loop['min_seconds']) {
                break;
            }
            $selected = $loop;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'available_seconds' => $budget,
            'loop' => $selected['loop'],
            'activity' => $selected['activity'],
            'reason' => 'budget_fits_loop_window',
        ];
    }

    /**
     * Stage-4 Operation Envelope check (doc pipeline row 4). A cognitive input
     * MUST carry study_session_id + cognitive_load_snapshot + dreyfus_stage_target.
     * Non-cognitive inputs are exempt (the overlay is additive — other input
     * kinds are unchanged).
     *
     * @param  array<string,mixed>  $envelope
     * @return array{
     *   schema_version:string,
     *   input_kind:string,
     *   is_cognitive:bool,
     *   missing_fields:list<string>,
     *   valid:bool,
     *   reason:string
     * }
     */
    public function validateEnvelope(array $envelope): array
    {
        $kind = strtolower(trim((string) ($envelope['input_kind'] ?? '')));
        $isCognitive = $kind === 'cognitive';

        if (! $isCognitive) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'input_kind' => $kind,
                'is_cognitive' => false,
                'missing_fields' => [],
                'valid' => true,
                'reason' => 'non_cognitive_input_overlay_not_required',
            ];
        }

        $missing = [];
        foreach (self::ENVELOPE_COGNITIVE_FIELDS as $field) {
            $value = $envelope[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $field;
            }
        }

        $valid = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'input_kind' => $kind,
            'is_cognitive' => true,
            'missing_fields' => $missing,
            'valid' => $valid,
            'reason' => $valid
                ? 'cognitive_envelope_complete'
                : 'cognitive_envelope_missing_required_hooks',
        ];
    }

    /**
     * Read-model snapshot of the overlay contract for the CLI / inspection.
     *
     * @return array{
     *   schema_version:string,
     *   authority_chain:list<string>,
     *   flow_count:int,
     *   runtime_ready_flows:list<string>,
     *   mandatory_gates:list<string>,
     *   loops:list<string>
     * }
     */
    public function snapshot(): array
    {
        $ready = [];
        foreach (self::FLOW_CATALOG as $flow => $entry) {
            if (in_array($entry['maturity'], self::MATURITY_READY, true)) {
                $ready[] = $flow;
            }
        }

        $loops = array_map(static fn (array $l): string => $l['loop'], self::LOOPS);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'authority_chain' => self::AUTHORITY_CHAIN,
            'flow_count' => count(self::FLOW_CATALOG),
            'runtime_ready_flows' => $ready,
            'mandatory_gates' => self::MANDATORY_GATES,
            'loops' => $loops,
        ];
    }

    private function normalizeSource(string $source): string
    {
        $s = strtolower(trim($source));
        // tolerate ".md" suffix and path-y inputs.
        $s = preg_replace('/\.md$/', '', $s) ?? $s;
        $s = str_replace('docs/engineering-knowledge-base/', '', $s);

        // map known aliases to canonical chain ids.
        return match (true) {
            str_contains($s, 'atlas-ai-pipeline') => 'atlas-ai-pipeline',
            str_contains($s, 'flow-visual-map') => 'atlas-ai-flow-visual-map',
            str_contains($s, 'pipeline-overlay') => 'pipeline-overlay',
            str_contains($s, 'learning') => 'domains/learning',
            default => $s,
        };
    }
}
