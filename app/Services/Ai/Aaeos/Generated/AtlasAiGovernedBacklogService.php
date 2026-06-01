<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Governed Backlog — runtime.
 *
 * Turns the umbrella governed-backlog doc into deterministic, pure decision
 * logic. The doc exists so Atlas "nao perca bons gaps, mas tambem nao transforme
 * lista antiga em roadmap automatico": legacy ideas are *source material*, never
 * an implementation commitment. This service enforces the governance the doc
 * imposes on every backlog item instead of letting raw notes, sensors or
 * personal data leak into runtime, provider context or an automatic roadmap.
 *
 * Concrete contracts implemented (from the doc body):
 *
 *  - State machine (the "Estados" table). Exactly five states —
 *    source_material -> candidate -> promoted | rejected | archived — with a
 *    closed set of legal transitions. {@see states()} is the read model and
 *    {@see transition()} refuses any move not drawn in the table (e.g. you can
 *    never jump source_material straight to promoted, and terminal states are
 *    terminal). A backlog item starts as source_material by default.
 *
 *  - Promotion gate (the five "Regras"). The doc states verbatim:
 *      "Todo item promovido precisa declarar domain, flow, safety boundary,
 *       evidence e owner."
 *    {@see evaluatePromotion()} requires all five declarations AND every safety
 *    constraint that the item's class triggers; a single missing field blocks
 *    promotion and is reported.
 *
 *  - Privacy / capability constraints per class. Three documented hard rules:
 *      "Personal Development continua privado, non-clinical e plan-only."
 *      "HealthKit, atividade digital, relacoes, estado mental e sensores
 *       pessoais exigem redaction e privacy review."
 *      "Executive action, Calendar, Reminders e mutacoes externas exigem
 *       confirmacao humana e capability gate."
 *    {@see classifyItem()} maps an item class to exactly these obligations and
 *    {@see evaluatePromotion()} enforces them. An unknown class is treated as
 *    the most restrictive (privacy review + human confirmation + capability
 *    gate) so a novel sensitive idea can never slip through unnamed.
 *
 *  - ROI never bypasses the spine. "Backlog de ROI nao pode bypassar Kernel,
 *    Master Architecture ou Domain Specs." {@see evaluatePromotion()} treats a
 *    high legacy-ROI item that lacks Kernel / Master Architecture / Domain Spec
 *    alignment as NOT promotable, and {@see roiBypassesSpine()} reports it.
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service NEVER mutates anything, calls a provider, touches money/privacy or
 * writes the database — it only classifies, gates and reports.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-governed-backlog.md
 */
final class AtlasAiGovernedBacklogService
{
    public const SCHEMA_VERSION = 'atlas.ai.governed_backlog.v1';

    /** The five states of the "Estados" table (closed set). */
    public const STATE_SOURCE_MATERIAL = 'source_material';
    public const STATE_CANDIDATE = 'candidate';
    public const STATE_PROMOTED = 'promoted';
    public const STATE_REJECTED = 'rejected';
    public const STATE_ARCHIVED = 'archived';

    /**
     * Human meaning for each state, copied from the "Estados" table.
     *
     * @var array<string,string>
     */
    private const STATE_MEANING = [
        self::STATE_SOURCE_MATERIAL => 'Ideia preservada, ainda nao triada.',
        self::STATE_CANDIDATE => 'Parece valiosa, mas precisa owner/safety/evidence.',
        self::STATE_PROMOTED => 'Virou doc canonico, ADR, plan ativo ou issue governada.',
        self::STATE_REJECTED => 'Nao vale agora ou viola safety/strategy.',
        self::STATE_ARCHIVED => 'Preservada apenas por historia.',
    ];

    /**
     * Legal transitions of the backlog state machine. A raw idea is preserved as
     * source_material, triaged into a candidate, then either promoted, rejected
     * or archived. A candidate that is rejected/archived can still be revived
     * back to candidate; promoted/rejected/archived are otherwise terminal.
     *
     * @var array<string,list<string>>
     */
    private const TRANSITIONS = [
        self::STATE_SOURCE_MATERIAL => [self::STATE_CANDIDATE, self::STATE_ARCHIVED, self::STATE_REJECTED],
        self::STATE_CANDIDATE => [self::STATE_PROMOTED, self::STATE_REJECTED, self::STATE_ARCHIVED],
        self::STATE_PROMOTED => [],
        self::STATE_REJECTED => [self::STATE_CANDIDATE, self::STATE_ARCHIVED],
        self::STATE_ARCHIVED => [self::STATE_CANDIDATE],
    ];

    /**
     * The five declarations every promoted item must carry, from the first rule:
     * "Todo item promovido precisa declarar domain, flow, safety boundary,
     *  evidence e owner."
     *
     * @var list<string>
     */
    public const PROMOTION_DECLARATIONS = [
        'domain',
        'flow',
        'safety_boundary',
        'evidence',
        'owner',
    ];

    /** Item classes (closed set) that trigger the doc's privacy/capability rules. */
    public const CLASS_GENERAL = 'general';
    public const CLASS_PERSONAL_DEVELOPMENT = 'personal_development';
    public const CLASS_PERSONAL_SENSOR = 'personal_sensor';
    public const CLASS_EXECUTIVE_ACTION = 'executive_action';

    /**
     * Per-class obligations, copied from the three documented hard rules:
     *
     *  - personal_development: "privado, non-clinical e plan-only por default".
     *  - personal_sensor (HealthKit, atividade digital, relacoes, estado mental,
     *    sensores pessoais): "exigem redaction e privacy review".
     *  - executive_action (Executive action, Calendar, Reminders, mutacoes
     *    externas): "exigem confirmacao humana e capability gate".
     *
     * @var array<string,array{
     *   private_by_default:bool,
     *   non_clinical:bool,
     *   plan_only:bool,
     *   requires_redaction:bool,
     *   requires_privacy_review:bool,
     *   requires_human_confirmation:bool,
     *   requires_capability_gate:bool
     * }>
     */
    private const CLASS_OBLIGATIONS = [
        self::CLASS_GENERAL => [
            'private_by_default' => false,
            'non_clinical' => false,
            'plan_only' => false,
            'requires_redaction' => false,
            'requires_privacy_review' => false,
            'requires_human_confirmation' => false,
            'requires_capability_gate' => false,
        ],
        self::CLASS_PERSONAL_DEVELOPMENT => [
            'private_by_default' => true,
            'non_clinical' => true,
            'plan_only' => true,
            'requires_redaction' => false,
            'requires_privacy_review' => false,
            'requires_human_confirmation' => false,
            'requires_capability_gate' => false,
        ],
        self::CLASS_PERSONAL_SENSOR => [
            'private_by_default' => true,
            'non_clinical' => true,
            'plan_only' => true,
            'requires_redaction' => true,
            'requires_privacy_review' => true,
            'requires_human_confirmation' => false,
            'requires_capability_gate' => false,
        ],
        self::CLASS_EXECUTIVE_ACTION => [
            'private_by_default' => false,
            'non_clinical' => false,
            'plan_only' => false,
            'requires_redaction' => false,
            'requires_privacy_review' => false,
            'requires_human_confirmation' => true,
            'requires_capability_gate' => true,
        ],
    ];

    /**
     * The three spine documents legacy ROI may never bypass:
     * "Backlog de ROI nao pode bypassar Kernel, Master Architecture ou Domain
     *  Specs."
     *
     * @var list<string>
     */
    public const SPINE_ALIGNMENTS = [
        'kernel',
        'master_architecture',
        'domain_specs',
    ];

    /**
     * The "Estados" read model, sorted by lifecycle order, each with its meaning
     * and its legal next states.
     *
     * @return array{schema_version:string,count:int,states:list<array{state:string,meaning:string,terminal:bool,next:list<string>}>}
     */
    public function states(): array
    {
        $order = [
            self::STATE_SOURCE_MATERIAL,
            self::STATE_CANDIDATE,
            self::STATE_PROMOTED,
            self::STATE_REJECTED,
            self::STATE_ARCHIVED,
        ];

        $states = [];
        foreach ($order as $state) {
            $next = self::TRANSITIONS[$state];
            $states[] = [
                'state' => $state,
                'meaning' => self::STATE_MEANING[$state],
                'terminal' => $next === [],
                'next' => $next,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($states),
            'states' => $states,
        ];
    }

    /**
     * Decide whether a backlog item may move from one state to another. Only
     * transitions drawn in the state machine are allowed; everything else is
     * refused (unknown state, terminal state, or an illegal jump such as
     * source_material -> promoted that skips triage).
     *
     * @return array{
     *   schema_version:string,
     *   from:string,
     *   to:string,
     *   from_known:bool,
     *   to_known:bool,
     *   allowed:bool,
     *   reason:string
     * }
     */
    public function transition(string $from, string $to): array
    {
        $fromKey = strtolower(trim($from));
        $toKey = strtolower(trim($to));

        $fromKnown = array_key_exists($fromKey, self::TRANSITIONS);
        $toKnown = array_key_exists($toKey, self::TRANSITIONS);

        if (! $fromKnown || ! $toKnown) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from' => $fromKey,
                'to' => $toKey,
                'from_known' => $fromKnown,
                'to_known' => $toKnown,
                'allowed' => false,
                'reason' => 'unknown_state',
            ];
        }

        $allowed = in_array($toKey, self::TRANSITIONS[$fromKey], true);

        $reason = match (true) {
            $allowed => 'transition_allowed',
            self::TRANSITIONS[$fromKey] === [] => 'state_is_terminal',
            default => 'illegal_transition_not_in_state_machine',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from' => $fromKey,
            'to' => $toKey,
            'from_known' => true,
            'to_known' => true,
            'allowed' => $allowed,
            'reason' => $reason,
        ];
    }

    /**
     * Classify a backlog item by its sensitivity class and report the documented
     * obligations that class triggers.
     *
     * An unknown / unrecognized class is treated as the most restrictive
     * profile — private, plan-only, redaction + privacy review, human
     * confirmation and capability gate — so a novel sensitive idea can never
     * leak past the gate by being unnamed.
     *
     * @return array{
     *   schema_version:string,
     *   class:string,
     *   class_known:bool,
     *   private_by_default:bool,
     *   non_clinical:bool,
     *   plan_only:bool,
     *   requires_redaction:bool,
     *   requires_privacy_review:bool,
     *   requires_human_confirmation:bool,
     *   requires_capability_gate:bool,
     *   obligations:list<string>
     * }
     */
    public function classifyItem(string $class): array
    {
        $key = strtolower(trim($class));
        $known = $key !== '' && array_key_exists($key, self::CLASS_OBLIGATIONS);

        // Unknown class -> most restrictive obligations (fail closed).
        $obligations = $known ? self::CLASS_OBLIGATIONS[$key] : [
            'private_by_default' => true,
            'non_clinical' => true,
            'plan_only' => true,
            'requires_redaction' => true,
            'requires_privacy_review' => true,
            'requires_human_confirmation' => true,
            'requires_capability_gate' => true,
        ];

        $flags = [];
        foreach ($obligations as $name => $on) {
            if ($on === true) {
                $flags[] = $name;
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'class' => $key,
            'class_known' => $known,
            'private_by_default' => $obligations['private_by_default'],
            'non_clinical' => $obligations['non_clinical'],
            'plan_only' => $obligations['plan_only'],
            'requires_redaction' => $obligations['requires_redaction'],
            'requires_privacy_review' => $obligations['requires_privacy_review'],
            'requires_human_confirmation' => $obligations['requires_human_confirmation'],
            'requires_capability_gate' => $obligations['requires_capability_gate'],
            'obligations' => $flags,
        ];
    }

    /**
     * Report whether a legacy-ROI item would bypass the spine. ROI only helps
     * triage; a high-ROI item that is NOT aligned with Kernel, Master
     * Architecture and Domain Specs may not be promoted on ROI alone.
     *
     * @param  array<string,bool>  $spineAlignment  spine key -> aligned
     * @return array{
     *   schema_version:string,
     *   high_roi:bool,
     *   aligned:list<string>,
     *   missing_alignment:list<string>,
     *   bypasses_spine:bool,
     *   reason:?string
     * }
     */
    public function roiBypassesSpine(bool $highRoi, array $spineAlignment): array
    {
        $aligned = [];
        $missing = [];
        foreach (self::SPINE_ALIGNMENTS as $spine) {
            if (($spineAlignment[$spine] ?? false) === true) {
                $aligned[] = $spine;
            } else {
                $missing[] = $spine;
            }
        }

        // ROI may help triage, but a high-ROI item missing any spine alignment
        // is an attempted bypass and must be blocked.
        $bypasses = $highRoi && $missing !== [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'high_roi' => $highRoi,
            'aligned' => $aligned,
            'missing_alignment' => $missing,
            'bypasses_spine' => $bypasses,
            'reason' => $bypasses ? 'roi_cannot_bypass_kernel_master_architecture_or_domain_specs' : null,
        ];
    }

    /**
     * Evaluate the full promotion gate for one candidate item. To be promoted an
     * item must (1) declare all five fields (domain, flow, safety boundary,
     * evidence, owner); (2) satisfy every privacy/capability obligation its
     * class triggers; and (3) not bypass the spine on ROI. Any failing condition
     * blocks promotion and is reported precisely.
     *
     * @param  array<string,bool>  $declarations    declaration key -> present
     * @param  array<string,bool>  $controls        obligation key -> satisfied
     *                                               (e.g. privacy_review, redaction,
     *                                               human_confirmation, capability_gate,
     *                                               plan_only, non_clinical)
     * @param  array<string,bool>  $spineAlignment  spine key -> aligned
     * @return array{
     *   schema_version:string,
     *   class:string,
     *   class_known:bool,
     *   promotable:bool,
     *   missing_declarations:list<string>,
     *   unmet_obligations:list<string>,
     *   roi:array<string,mixed>,
     *   blockers:list<string>,
     *   reason:?string
     * }
     */
    public function evaluatePromotion(
        string $class,
        array $declarations,
        array $controls = [],
        bool $highRoi = false,
        array $spineAlignment = [],
    ): array {
        $classification = $this->classifyItem($class);

        // (1) The five mandatory declarations.
        $missingDeclarations = [];
        foreach (self::PROMOTION_DECLARATIONS as $field) {
            if (($declarations[$field] ?? false) !== true) {
                $missingDeclarations[] = $field;
            }
        }

        // (2) Every obligation the item's class triggers must be satisfied.
        // Obligation flag name -> the control key the operator must provide.
        $controlFor = [
            'plan_only' => 'plan_only',
            'non_clinical' => 'non_clinical',
            'requires_redaction' => 'redaction',
            'requires_privacy_review' => 'privacy_review',
            'requires_human_confirmation' => 'human_confirmation',
            'requires_capability_gate' => 'capability_gate',
        ];
        $unmetObligations = [];
        foreach ($controlFor as $flag => $controlKey) {
            if (($classification[$flag] ?? false) === true && ($controls[$controlKey] ?? false) !== true) {
                $unmetObligations[] = $controlKey;
            }
        }

        // (3) ROI may not bypass the spine.
        $roi = $this->roiBypassesSpine($highRoi, $spineAlignment);

        $blockers = [];
        if ($missingDeclarations !== []) {
            $blockers[] = 'missing_declarations';
        }
        if ($unmetObligations !== []) {
            $blockers[] = 'unmet_privacy_or_capability_obligations';
        }
        if ($roi['bypasses_spine'] === true) {
            $blockers[] = 'roi_bypasses_spine';
        }

        $promotable = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'class' => $classification['class'],
            'class_known' => $classification['class_known'],
            'promotable' => $promotable,
            'missing_declarations' => $missingDeclarations,
            'unmet_obligations' => $unmetObligations,
            'roi' => $roi,
            'blockers' => $blockers,
            'reason' => $promotable ? null : 'promotion_blocked_by_governance_gate',
        ];
    }

    /**
     * Primary entry point: produce the full governed-backlog governance snapshot
     * used by the command and as a single source of the doc's contract.
     *
     * @return array{
     *   schema_version:string,
     *   states:array<string,mixed>,
     *   promotion_declarations:list<string>,
     *   spine_alignments:list<string>,
     *   classes:list<string>,
     *   illegal_transition_example:array<string,mixed>,
     *   sensor_classification_example:array<string,mixed>,
     *   promotion_example:array<string,mixed>
     * }
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'states' => $this->states(),
            'promotion_declarations' => self::PROMOTION_DECLARATIONS,
            'spine_alignments' => self::SPINE_ALIGNMENTS,
            'classes' => [
                self::CLASS_GENERAL,
                self::CLASS_PERSONAL_DEVELOPMENT,
                self::CLASS_PERSONAL_SENSOR,
                self::CLASS_EXECUTIVE_ACTION,
            ],
            // Worked example: triage cannot be skipped.
            'illegal_transition_example' => $this->transition(self::STATE_SOURCE_MATERIAL, self::STATE_PROMOTED),
            // Worked example: a personal sensor demands redaction + privacy review.
            'sensor_classification_example' => $this->classifyItem(self::CLASS_PERSONAL_SENSOR),
            // Worked example: declarations present but the sensor obligations are
            // unmet -> promotion blocked.
            'promotion_example' => $this->evaluatePromotion(
                self::CLASS_PERSONAL_SENSOR,
                [
                    'domain' => true,
                    'flow' => true,
                    'safety_boundary' => true,
                    'evidence' => true,
                    'owner' => true,
                ],
                [],
            ),
        ];
    }
}
