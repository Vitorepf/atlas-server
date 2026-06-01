<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Context Builder Evolution Roadmap — runtime.
 *
 * Turns the governed Context Builder roadmap doc into deterministic, pure
 * decision logic. The doc is canonical for HYBRID retrieval routing, Graph RAG
 * maturity and the gates that protect high-risk context assembly. This service
 * enforces exactly what the doc states (no parallel memory store, no new
 * subsystem name for the same function):
 *
 *  - Target Shape: Context Builder chooses among five sources — vector
 *    retrieval, graph retrieval, evidence replay, code intelligence and memory
 *    signals.
 *  - Routing Rules: a fixed question-type -> (primary, secondary) source table.
 *    "Factual lookup" -> vector + KB docs; "Why did we decide X?" -> Evidence
 *    Replay + Decision Receipt chain; "What breaks if this changes?" -> Graph
 *    RAG + Code Intelligence; "What should Atlas improve?" -> Learning signals +
 *    Curator proposals; "What does Vitor need now?" -> Personal memory
 *    projection + Policy/Profile.
 *  - Graph Maturity: the Explicit -> Observed -> Inferred -> Approved ladder.
 *    HARD RULE from the doc: "Inferred relations never become default context
 *    without approval or strong evidence policy." So an Inferred relation is
 *    admitted as default context ONLY if it is promoted to Approved, OR a strong
 *    evidence policy is satisfied; otherwise it is held out of default context.
 *  - Gates: required-source gate for high-risk tasks (a high-risk run missing a
 *    required source is BLOCKED); provider-safe redaction before external model
 *    calls; context budget accounting in the Decision Receipt; an evidence event
 *    for the retrieval plan and source usage.
 *  - Next APs: AP-101 Retrieval Router, AP-102 Retrieval Plan Summary, AP-103
 *    Required Source Availability, AP-105 Open Brain Retrieval Self-Improvement.
 *
 * Stateless and DB-free: every method is a pure function of its arguments.
 *
 * @see docs/engineering-knowledge-base/evolution/context-builder-roadmap.md
 */
final class AtlasContextBuilderRoadmapService
{
    public const SCHEMA_VERSION = 'atlas.context.builder_roadmap.v1';

    /**
     * The five context sources the Context Builder chooses among (Target Shape).
     *
     * @var list<string>
     */
    public const SOURCES = [
        'vector_retrieval',
        'graph_retrieval',
        'evidence_replay',
        'code_intelligence',
        'memory_signals',
    ];

    /**
     * Routing Rules table from the doc: question type -> {primary, secondary}.
     * Keyed by a stable question-type id.
     *
     * @var array<string,array{label:string,primary:string,secondary:string}>
     */
    private const ROUTING = [
        'factual_lookup' => [
            'label' => 'Factual lookup',
            'primary' => 'vector_retrieval',
            'secondary' => 'kb_docs',
        ],
        'why_did_we_decide' => [
            'label' => 'Why did we decide X?',
            'primary' => 'evidence_replay',
            'secondary' => 'decision_receipt_chain',
        ],
        'what_breaks_if_changed' => [
            'label' => 'What breaks if this changes?',
            'primary' => 'graph_retrieval',
            'secondary' => 'code_intelligence',
        ],
        'what_should_atlas_improve' => [
            'label' => 'What should Atlas improve?',
            'primary' => 'learning_signals',
            'secondary' => 'curator_proposals',
        ],
        'what_does_vitor_need_now' => [
            'label' => 'What does Vitor need now?',
            'primary' => 'personal_memory_projection',
            'secondary' => 'policy_profile',
        ],
    ];

    /**
     * Graph Maturity ladder, in documented promotion order. Each stage carries
     * its documented meaning and whether a relation at that stage may serve as
     * DEFAULT context with no further qualification.
     *
     * Per the doc only "Approved" (proposal accepted and promoted) is a
     * default-context stage. Explicit/Observed precede the inference frontier;
     * Inferred is gated.
     *
     * @var list<array{key:string,meaning:string,default_context:bool}>
     */
    public const MATURITY_LADDER = [
        ['key' => 'explicit', 'meaning' => 'human or service declares relationship', 'default_context' => false],
        ['key' => 'observed', 'meaning' => 'repeated evidence implies relationship', 'default_context' => false],
        ['key' => 'inferred', 'meaning' => 'model proposes relationship with confidence', 'default_context' => false],
        ['key' => 'approved', 'meaning' => 'proposal accepted and promoted', 'default_context' => true],
    ];

    /**
     * Next APs (Retrieval Router program) from the doc.
     *
     * @var array<string,string>
     */
    public const NEXT_APS = [
        'AP-101' => 'Retrieval Router: chooses vector/graph/evidence/code/memory per task',
        'AP-102' => 'Retrieval Plan Summary: explain why each context source was used',
        'AP-103' => 'Required Source Availability: block low-confidence runs missing required sources',
        'AP-105' => 'Open Brain Retrieval Self-Improvement: proposes retrieval improvements',
    ];

    /** Risk levels the doc treats as "high-risk tasks" for the required-source gate. */
    public const HIGH_RISK_LEVELS = [
        'high',
        'irreversible',
    ];

    /**
     * Route one question type to its primary and secondary context sources.
     *
     * Unknown question types fall back to a safe vector + KB pairing and are
     * flagged as not-routed so the caller can request disambiguation rather than
     * silently assume an intent.
     *
     * @return array{schema_version:string,question_type:string,label:string,primary:string,secondary:string,routed:bool}
     */
    public function route(string $questionType): array
    {
        $key = $this->resolveQuestionType($questionType);
        $row = self::ROUTING[$key] ?? null;

        if ($row === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'question_type' => $key,
                'label' => 'unknown',
                'primary' => 'vector_retrieval',
                'secondary' => 'kb_docs',
                'routed' => false,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'question_type' => $key,
            'label' => $row['label'],
            'primary' => $row['primary'],
            'secondary' => $row['secondary'],
            'routed' => true,
        ];
    }

    /**
     * Full Routing Rules read model (the doc table).
     *
     * @return array{schema_version:string,routes:list<array{question_type:string,label:string,primary:string,secondary:string}>}
     */
    public function routingTable(): array
    {
        $routes = [];
        foreach (self::ROUTING as $key => $row) {
            $routes[] = [
                'question_type' => $key,
                'label' => $row['label'],
                'primary' => $row['primary'],
                'secondary' => $row['secondary'],
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'routes' => $routes,
        ];
    }

    /**
     * Graph Maturity decision: may a relation at the given stage be admitted as
     * DEFAULT context?
     *
     * Enforces the doc's hard rule: "Inferred relations never become default
     * context without approval or strong evidence policy." An Inferred relation
     * is admitted only when approval has been granted OR a strong evidence
     * policy is satisfied. Explicit/Observed are below the default-context bar
     * (they still require promotion to Approved). Approved is always admitted.
     *
     * @return array{schema_version:string,stage:string,stage_known:bool,default_context:bool,reasons:list<string>,requires_approval:bool}
     */
    public function mayBecomeDefaultContext(
        string $stage,
        bool $approvalGranted = false,
        bool $strongEvidencePolicy = false,
    ): array {
        $key = $this->normalize($stage);
        $known = $this->stageIndex($key) !== null;
        $reasons = [];
        $default = false;
        $requiresApproval = false;

        switch ($key) {
            case 'approved':
                $default = true;
                $reasons[] = 'approved_relation_is_default_context';
                break;

            case 'inferred':
                $requiresApproval = true;
                if ($approvalGranted) {
                    $default = true;
                    $reasons[] = 'inferred_admitted_via_approval';
                } elseif ($strongEvidencePolicy) {
                    $default = true;
                    $reasons[] = 'inferred_admitted_via_strong_evidence_policy';
                } else {
                    $reasons[] = 'inferred_never_default_without_approval_or_strong_evidence';
                }
                break;

            case 'explicit':
            case 'observed':
                $requiresApproval = true;
                $reasons[] = "{$key}_below_default_context_bar_promote_to_approved";
                break;

            default:
                $reasons[] = 'unknown_stage_held_out_of_default_context';
                break;
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'stage' => $key,
            'stage_known' => $known,
            'default_context' => $default,
            'reasons' => $reasons,
            'requires_approval' => $requiresApproval,
        ];
    }

    /**
     * Promote a relation one step along the maturity ladder
     * (explicit -> observed -> inferred -> approved). Approved is terminal.
     *
     * @return array{schema_version:string,from:string,to:string,promoted:bool,terminal:bool,reason:string}
     */
    public function promote(string $stage): array
    {
        $key = $this->normalize($stage);
        $index = $this->stageIndex($key);

        if ($index === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from' => $key,
                'to' => $key,
                'promoted' => false,
                'terminal' => false,
                'reason' => 'unknown_stage',
            ];
        }

        $last = count(self::MATURITY_LADDER) - 1;
        if ($index >= $last) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'from' => $key,
                'to' => $key,
                'promoted' => false,
                'terminal' => true,
                'reason' => 'already_approved_terminal',
            ];
        }

        $to = self::MATURITY_LADDER[$index + 1]['key'];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'from' => $key,
            'to' => $to,
            'promoted' => true,
            'terminal' => $index + 1 >= $last,
            'reason' => "promoted_{$key}_to_{$to}",
        ];
    }

    /**
     * Gate decision for a retrieval/context-assembly run.
     *
     * Implements the doc's "Gates" section:
     *  - Required-source gate for high-risk tasks: if the task is high risk and a
     *    required source is missing/unavailable, the run is BLOCKED.
     *  - Provider-safe redaction before external model calls: an external call
     *    that is not provider-safe is BLOCKED.
     *  - Context budget accounting in the Decision Receipt: refs over budget are
     *    BLOCKED (budget must be accounted, not silently exceeded).
     *  - Evidence event for retrieval plan and source usage: every decision emits
     *    a retrieval-plan evidence event.
     *
     * @param  array{risk_level?:string,required_sources?:list<string>,available_sources?:list<string>,external_call?:bool,provider_safe?:bool,context_refs?:int,context_budget?:int}  $input
     * @return array{schema_version:string,decision:string,blocked:bool,reasons:list<string>,missing_required_sources:list<string>,evidence_event:string,receipt:array{risk_level:string,high_risk:bool,context_refs:int,context_budget:int,budget_ok:bool,provider_safe:bool}}
     */
    public function gateCheck(array $input): array
    {
        $risk = $this->normalize((string) ($input['risk_level'] ?? 'low'));
        $highRisk = in_array($risk, self::HIGH_RISK_LEVELS, true);

        $required = array_values(array_unique(array_map(
            fn ($s): string => $this->normalize((string) $s),
            $input['required_sources'] ?? [],
        )));
        $available = array_map(
            fn ($s): string => $this->normalize((string) $s),
            $input['available_sources'] ?? [],
        );
        $missing = array_values(array_diff($required, $available));

        $externalCall = (bool) ($input['external_call'] ?? false);
        $providerSafe = (bool) ($input['provider_safe'] ?? false);

        $refs = max(0, (int) ($input['context_refs'] ?? 0));
        $budget = max(0, (int) ($input['context_budget'] ?? 0));
        $budgetOk = $budget === 0 ? true : $refs <= $budget;

        $reasons = [];
        $blocked = false;

        // Required-source gate for high-risk tasks.
        if ($highRisk && $missing !== []) {
            $blocked = true;
            $reasons[] = 'high_risk_missing_required_source';
        }

        // Provider-safe redaction before external model calls.
        if ($externalCall && ! $providerSafe) {
            $blocked = true;
            $reasons[] = 'external_call_not_provider_safe';
        }

        // Context budget accounting in the Decision Receipt.
        if (! $budgetOk) {
            $blocked = true;
            $reasons[] = "context_budget_exceeded:{$refs}/{$budget}";
        }

        if (! $blocked) {
            $reasons[] = 'gates_passed';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'decision' => $blocked ? 'block' : 'allow',
            'blocked' => $blocked,
            'reasons' => $reasons,
            'missing_required_sources' => $missing,
            // Evidence event for retrieval plan and source usage (always emitted).
            'evidence_event' => 'context_builder.retrieval_plan',
            'receipt' => [
                'risk_level' => $risk,
                'high_risk' => $highRisk,
                'context_refs' => $refs,
                'context_budget' => $budget,
                'budget_ok' => $budgetOk,
                'provider_safe' => $providerSafe,
            ],
        ];
    }

    /**
     * Next APs read model (the Retrieval Router program).
     *
     * @return array{schema_version:string,next_aps:list<array{ap:string,purpose:string}>}
     */
    public function nextAps(): array
    {
        $rows = [];
        foreach (self::NEXT_APS as $ap => $purpose) {
            $rows[] = ['ap' => $ap, 'purpose' => $purpose];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'next_aps' => $rows,
        ];
    }

    /**
     * Full governance snapshot: routing table, maturity ladder, next APs and the
     * documented context sources. Used as the command's default projection.
     *
     * @return array<string,mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'doc' => 'docs/engineering-knowledge-base/evolution/context-builder-roadmap.md',
            'sources' => self::SOURCES,
            'routing' => $this->routingTable()['routes'],
            'maturity_ladder' => self::MATURITY_LADDER,
            'next_aps' => $this->nextAps()['next_aps'],
            'gates' => [
                'required_source_gate_for_high_risk' => true,
                'provider_safe_redaction_before_external_calls' => true,
                'context_budget_accounting_in_receipt' => true,
                'evidence_event_for_retrieval_plan' => true,
            ],
        ];
    }

    /**
     * Resolve an operator-supplied question type to a stable routing key. Accepts
     * either the stable key (e.g. "why_did_we_decide") or the documented label
     * from the routing table (e.g. "Why did we decide X?").
     */
    private function resolveQuestionType(string $questionType): string
    {
        $normalized = $this->normalize($questionType);

        if (isset(self::ROUTING[$normalized])) {
            return $normalized;
        }

        foreach (self::ROUTING as $key => $row) {
            if ($this->normalize($row['label']) === $normalized) {
                return $key;
            }
        }

        return $normalized;
    }

    private function normalize(string $value): string
    {
        $value = strtolower(trim($value));

        return (string) preg_replace('/[^a-z0-9]+/', '_', $value);
    }

    private function stageIndex(string $key): ?int
    {
        foreach (self::MATURITY_LADDER as $index => $row) {
            if ($row['key'] === $key) {
                return $index;
            }
        }

        return null;
    }
}
