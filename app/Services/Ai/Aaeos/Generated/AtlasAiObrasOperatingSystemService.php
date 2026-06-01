<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas AI Obras Operating System parent specification.
 *
 * The doc is the authoring boundary. This service turns the parent-OS concrete
 * contracts into pure, deterministic decision logic — no IO, no clock, no DB.
 * It does NOT re-implement the child-doc surfaces (those are owned by
 * AtlasPatamaresL0L5Service and AtlasObrasContractsAndInvariantsService); it
 * implements the load-bearing logic that lives only in the parent doc:
 *
 *   - Lifecycle State Machine ("Lifecycle States"): the ordered 13-state lifecycle
 *       Idea -> Intake -> Specified -> Planned -> In Construction -> In Review
 *       -> Blocked -> Awaiting Approval -> Approved -> Published -> Archived
 *       -> Reopened -> Replaced, with the 8 documented "important transitions"
 *       that each require a minimum evidence (e.g. "In Review -> Approved requires
 *       gates"). A transition is allowed only when it is a documented edge AND its
 *       required evidence is present. -> evaluateTransition(), states()
 *   - Universal Quality Gates: the 12 questions every relevant Obra must answer.
 *       The Obra is gate-complete only when ALL 12 are answered. -> evaluateQualityGates()
 *   - Non-Negotiable Product Law: the 9 "If it has no ... it is not ..." rules.
 *       Any breach blocks the Obra from being a runtime Obra. -> evaluateProductLaw()
 *   - Final Criteria ladder ("Maximum Principle"): delivery -> asset -> autonomy.
 *       Classifies how far an Obra reached (incomplete | delivery | foundry |
 *       sovereign) and refuses to over-claim a stage whose precondition is unmet.
 *       -> classifyFinalCriteria()
 *
 * Same input -> same output. The code never mutates Obra state, never advances a
 * state on UI presence, and never relaxes a gate or a transition requirement.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-obras-operating-system.md
 */
final class AtlasAiObrasOperatingSystemService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA = 'atlas.ai.obras_operating_system.v1';

    /** Closed verdict set. */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /**
     * The ordered lifecycle states (doc -> "Lifecycle States"), in documented
     * sequence. This is the Obra STATE lifecycle, distinct from the L0..L5
     * maturity ladder.
     *
     * @var list<string>
     */
    public const STATES = [
        'Idea',
        'Intake',
        'Specified',
        'Planned',
        'In Construction',
        'In Review',
        'Blocked',
        'Awaiting Approval',
        'Approved',
        'Published',
        'Archived',
        'Reopened',
        'Replaced',
    ];

    /**
     * The documented "important transitions" and their minimum evidence key.
     * Each entry: "From->To" => required evidence token. A transition listed here
     * is gated: it is only allowed when the named evidence is present (true).
     *
     * Verbatim intent from the doc:
     *   - Idea -> Intake requires minimum objective.
     *   - Intake -> Specified requires spec.
     *   - Specified -> Planned requires phases and deliverables.
     *   - Planned -> In Construction requires next action.
     *   - In Construction -> In Review requires draft or version.
     *   - In Review -> Approved requires gates.
     *   - Approved -> Published requires output.
     *   - Published -> Archived requires learning.
     *
     * @var array<string, string>
     */
    public const GATED_TRANSITIONS = [
        'Idea->Intake' => 'minimum_objective',
        'Intake->Specified' => 'spec',
        'Specified->Planned' => 'phases_and_deliverables',
        'Planned->In Construction' => 'next_action',
        'In Construction->In Review' => 'draft_or_version',
        'In Review->Approved' => 'gates',
        'Approved->Published' => 'output',
        'Published->Archived' => 'learning',
    ];

    /**
     * Recovery / non-linear transitions the doc's lifecycle implies but does not
     * gate with extra evidence. These are allowed edges (no evidence token); they
     * exist so the state machine recognises Blocked recovery, reopen and replace
     * as legal moves rather than rejecting them as unknown.
     *
     * @var list<array{0: string, 1: string}>
     */
    private const UNGATED_TRANSITIONS = [
        // any active production state can stall into Blocked
        ['In Construction', 'Blocked'],
        ['In Review', 'Blocked'],
        ['Awaiting Approval', 'Blocked'],
        // Blocked recovers back to the work surfaces
        ['Blocked', 'In Construction'],
        ['Blocked', 'In Review'],
        // review can route to approval queue before approval
        ['In Review', 'Awaiting Approval'],
        ['Awaiting Approval', 'Approved'],
        // a published or archived Obra can be reopened, then re-enter construction
        ['Published', 'Reopened'],
        ['Archived', 'Reopened'],
        ['Reopened', 'In Construction'],
        // a published Obra can be superseded
        ['Published', 'Replaced'],
        ['Approved', 'Replaced'],
    ];

    /**
     * The 12 Universal Quality Gates (doc -> "Universal Quality Gates"), keyed by
     * a stable token to the documented question. Every relevant Obra must answer
     * ALL of them to be gate-complete.
     *
     * @var array<string, string>
     */
    public const QUALITY_GATES = [
        'objective_clear' => 'Is the objective clear?',
        'definition_of_done_defined' => 'Is the definition of done defined?',
        'structure_coherent' => 'Is the structure coherent?',
        'next_step_exists' => 'Does a next step exist?',
        'decisions_registered' => 'Are important decisions registered?',
        'sources_linked_when_needed' => 'Are sources/evidence linked when necessary?',
        'risks_explicit' => 'Are risks explicit?',
        'tradeoffs_clear' => 'Are tradeoffs clear?',
        'current_version_identified' => 'Is the current version identified?',
        'final_output_defined' => 'Is the final output defined?',
        'learning_captured' => 'Was learning captured?',
        'relation_to_greater_objective_registered' => 'Is the relation to a greater objective registered?',
    ];

    /**
     * Non-Negotiable Product Law (doc -> "Non-Negotiable Product Law"). Each rule
     * maps a stable token to the documented requirement label. A caller asserts a
     * boolean per token; a false (or absent) token means the rule is breached.
     *
     * @var array<string, string>
     */
    public const PRODUCT_LAW = [
        'has_objective' => 'If it has no objective, it is not an Obra.',
        'has_next_step' => 'If it has no next step, it is an idea, not an active Obra.',
        'has_structure' => 'If it has no structure, it cannot become a high-quality deliverable.',
        'has_quality_gates' => 'If it has no quality gates, it cannot claim high quality.',
        'has_output' => 'If it has no output, it is incomplete.',
        'state_outside_folder' => 'If its state exists only in a folder, it is not runtime Obras.',
        'ai_activity_traceable' => 'If AI activity cannot be traced to Obra id, node, context and output, it is loose chat.',
        'output_becomes_asset' => 'If the output does not become an asset, it has not reached Foundry.',
        'asset_increases_autonomy' => 'If the asset does not increase autonomy, it has not reached Sovereign OS.',
    ];

    /** Final-criteria ladder stages, low -> high (doc -> "Maximum Principle"). */
    public const STAGE_INCOMPLETE = 'incomplete';
    public const STAGE_DELIVERY = 'delivery';
    public const STAGE_FOUNDRY = 'foundry';
    public const STAGE_SOVEREIGN = 'sovereign';

    /**
     * The ordered lifecycle as an evidence list (state + index + whether it is the
     * target of a gated transition).
     *
     * @return list<array{state: string, index: int}>
     */
    public function states(): array
    {
        $out = [];
        foreach (self::STATES as $i => $state) {
            $out[] = ['state' => $state, 'index' => $i];
        }

        return $out;
    }

    /**
     * Lifecycle transition gate. Decides whether an Obra may move $from -> $to.
     *
     * Rules enforced:
     *   - both states must be known lifecycle states;
     *   - the edge must be documented: either one of the 8 gated "important
     *     transitions" OR one of the recognised recovery/non-linear edges;
     *   - if the edge is a gated transition, its documented minimum evidence must
     *     be present (true). Evidence — not UI presence — authorises the move.
     *
     * @param array<string, bool> $evidence evidence tokens the caller asserts
     * @return array{
     *     schema: string, surface: string, status: string, transition: string,
     *     allowed: bool, known_states: bool, documented_edge: bool,
     *     gated: bool, required_evidence: ?string,
     *     blocking_reasons: list<string>
     * }
     */
    public function evaluateTransition(string $from, string $to, array $evidence = []): array
    {
        $from = trim($from);
        $to = trim($to);
        $transition = $from . '->' . $to;
        $reasons = [];

        $fromKnown = in_array($from, self::STATES, true);
        $toKnown = in_array($to, self::STATES, true);
        $knownStates = $fromKnown && $toKnown;

        if (! $fromKnown) {
            $reasons[] = "unknown_source_state: {$from}";
        }
        if (! $toKnown) {
            $reasons[] = "unknown_target_state: {$to}";
        }

        $isGated = array_key_exists($transition, self::GATED_TRANSITIONS);
        $required = $isGated ? self::GATED_TRANSITIONS[$transition] : null;
        $isUngated = $this->isUngatedEdge($from, $to);
        $documentedEdge = $isGated || $isUngated;

        if ($knownStates && ! $documentedEdge) {
            $reasons[] = "undocumented_transition: {$transition} is not a lifecycle edge";
        }

        if ($isGated && $required !== null && ($evidence[$required] ?? false) !== true) {
            $reasons[] = "missing_required_evidence: {$transition} requires {$required}";
        }

        $allowed = $reasons === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'lifecycle_transition',
            'status' => $allowed ? self::STATUS_PASS : self::STATUS_FAIL,
            'transition' => $transition,
            'allowed' => $allowed,
            'known_states' => $knownStates,
            'documented_edge' => $documentedEdge,
            'gated' => $isGated,
            'required_evidence' => $required,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Universal Quality Gates. An Obra is gate-complete only when ALL 12 documented
     * questions are answered (true). Absent or false tokens are unanswered gates.
     *
     * @param array<string, bool> $answers gate tokens the caller asserts
     * @return array{
     *     schema: string, surface: string, status: string, complete: bool,
     *     total: int, answered_count: int, answered: list<string>,
     *     unanswered: list<string>, blocking_reasons: list<string>
     * }
     */
    public function evaluateQualityGates(array $answers): array
    {
        $answered = [];
        $unanswered = [];
        $reasons = [];

        foreach (self::QUALITY_GATES as $key => $question) {
            if (($answers[$key] ?? false) === true) {
                $answered[] = $key;
            } else {
                $unanswered[] = $key;
                $reasons[] = 'quality_gate_unanswered: ' . $question;
            }
        }

        $complete = $unanswered === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'universal_quality_gates',
            'status' => $complete ? self::STATUS_PASS : self::STATUS_FAIL,
            'complete' => $complete,
            'total' => count(self::QUALITY_GATES),
            'answered_count' => count($answered),
            'answered' => $answered,
            'unanswered' => $unanswered,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Non-Negotiable Product Law. Passes only when NONE of the 9 rules is breached.
     * A rule is breached when its token is false or absent.
     *
     * @param array<string, bool> $claims product-law tokens the caller asserts
     * @return array{
     *     schema: string, surface: string, status: string, compliant: bool,
     *     breached: list<string>, blocking_reasons: list<string>
     * }
     */
    public function evaluateProductLaw(array $claims): array
    {
        $breached = [];
        $reasons = [];

        foreach (self::PRODUCT_LAW as $key => $label) {
            if (($claims[$key] ?? false) !== true) {
                $breached[] = $key;
                $reasons[] = 'product_law_breached: ' . $label;
            }
        }

        $compliant = $breached === [];

        return [
            'schema' => self::SCHEMA,
            'surface' => 'non_negotiable_product_law',
            'status' => $compliant ? self::STATUS_PASS : self::STATUS_FAIL,
            'compliant' => $compliant,
            'breached' => $breached,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Final Criteria ladder (doc -> "Maximum Principle"):
     *   - If the Obra has no delivery -> incomplete.
     *   - delivery without the asset becoming an asset -> stops at delivery
     *     (has NOT reached Foundry).
     *   - asset present but autonomy not increased -> reaches foundry, NOT sovereign.
     *   - asset present AND autonomy increased -> reaches sovereign.
     *
     * The ladder is monotonic: a higher stage cannot be claimed while a lower
     * precondition is unmet (asset requires delivery; autonomy requires asset).
     *
     * @param array{has_delivery?: bool, became_asset?: bool, increased_autonomy?: bool} $signals
     * @return array{
     *     schema: string, surface: string, stage: string,
     *     reached_foundry: bool, reached_sovereign: bool,
     *     blocking_reasons: list<string>
     * }
     */
    public function classifyFinalCriteria(array $signals): array
    {
        $hasDelivery = ($signals['has_delivery'] ?? false) === true;
        $becameAsset = ($signals['became_asset'] ?? false) === true;
        $increasedAutonomy = ($signals['increased_autonomy'] ?? false) === true;

        $reasons = [];

        if (! $hasDelivery) {
            $reasons[] = 'incomplete: if the Obra does not become a delivery, it is not complete.';
            $stage = self::STAGE_INCOMPLETE;

            return [
                'schema' => self::SCHEMA,
                'surface' => 'final_criteria',
                'stage' => $stage,
                'reached_foundry' => false,
                'reached_sovereign' => false,
                'blocking_reasons' => $reasons,
            ];
        }

        // Delivery exists. Asset is the precondition for Foundry.
        if (! $becameAsset) {
            $reasons[] = 'if the delivery does not become an asset, it has not reached Foundry.';
            $stage = self::STAGE_DELIVERY;
        } elseif (! $increasedAutonomy) {
            // Reached Foundry; autonomy is the precondition for Sovereign OS.
            $reasons[] = 'if the asset does not increase autonomy, it has not reached Sovereign OS.';
            $stage = self::STAGE_FOUNDRY;
        } else {
            $stage = self::STAGE_SOVEREIGN;
        }

        return [
            'schema' => self::SCHEMA,
            'surface' => 'final_criteria',
            'stage' => $stage,
            'reached_foundry' => $becameAsset,
            'reached_sovereign' => $becameAsset && $increasedAutonomy,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-OS audit: folds every parent-doc surface over a candidate Obra bundle
     * into one pass|fail document. Status is pass only when every blocking surface
     * passes. The final-criteria stage is reported for context but only blocks the
     * aggregate when the Obra claims completeness yet is classified incomplete.
     *
     * @param array{
     *     transition?: array{from: string, to: string, evidence?: array<string, bool>},
     *     quality_gates?: array<string, bool>,
     *     product_law?: array<string, bool>,
     *     final_criteria?: array{has_delivery?: bool, became_asset?: bool, increased_autonomy?: bool},
     *     claims_complete?: bool
     * } $bundle
     * @return array{
     *     schema: string, status: string, states: list<array{state: string, index: int}>,
     *     surfaces: array<string, mixed>, blocking_reasons: list<string>
     * }
     */
    public function audit(array $bundle): array
    {
        $surfaces = [];
        $reasons = [];
        $allPass = true;

        if (isset($bundle['transition'])) {
            $t = $bundle['transition'];
            $surfaces['transition'] = $this->evaluateTransition(
                (string) ($t['from'] ?? ''),
                (string) ($t['to'] ?? ''),
                $t['evidence'] ?? [],
            );
        }

        $surfaces['quality_gates'] = $this->evaluateQualityGates($bundle['quality_gates'] ?? []);
        $surfaces['product_law'] = $this->evaluateProductLaw($bundle['product_law'] ?? []);
        $surfaces['final_criteria'] = $this->classifyFinalCriteria($bundle['final_criteria'] ?? []);

        foreach (['transition', 'quality_gates', 'product_law'] as $blocking) {
            if (! isset($surfaces[$blocking])) {
                continue;
            }
            if (($surfaces[$blocking]['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $allPass = false;
            }
            foreach (($surfaces[$blocking]['blocking_reasons'] ?? []) as $reason) {
                $reasons[] = $reason;
            }
        }

        // Final-criteria only blocks when the caller asserts completeness but the
        // ladder classifies the Obra as incomplete (no delivery).
        if (($bundle['claims_complete'] ?? false) === true
            && $surfaces['final_criteria']['stage'] === self::STAGE_INCOMPLETE) {
            $allPass = false;
            foreach ($surfaces['final_criteria']['blocking_reasons'] as $reason) {
                $reasons[] = $reason;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $allPass ? self::STATUS_PASS : self::STATUS_FAIL,
            'states' => $this->states(),
            'surfaces' => $surfaces,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whether $from -> $to is a recognised recovery / non-linear lifecycle edge.
     */
    private function isUngatedEdge(string $from, string $to): bool
    {
        foreach (self::UNGATED_TRANSITIONS as [$edgeFrom, $edgeTo]) {
            if ($edgeFrom === $from && $edgeTo === $to) {
                return true;
            }
        }

        return false;
    }
}
