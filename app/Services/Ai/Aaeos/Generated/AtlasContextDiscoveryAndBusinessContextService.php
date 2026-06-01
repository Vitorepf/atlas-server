<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Atlas SDD Context Discovery And Business Context doc.
 *
 * Turns the doc's load-bearing contract into pure, deterministic decision logic
 * (no IO, no clock, no DB). Same input -> same output. The doc is the authoring
 * boundary; this code never declares execution-readiness unless every documented
 * precondition holds, and a single blocking ambiguity always forces a clarify.
 *
 *   1. The context-discovery Inputs checklist: the eleven sources Atlas must
 *      discover before it has grounded context. -> requiredInputs()
 *
 *   2. The pre-spec Business Questions: the seven questions that must be answered
 *      so Atlas is grounded in a business object and not just a code generator.
 *      -> businessQuestions()
 *
 *   3. The four Confidence Classes, ranked, with the documented rule that only
 *      `blocking_ambiguity` blocks safe execution. -> confidenceClasses(),
 *      classifyItem(), isExecutionGrade()
 *
 *   4. The execution gate, read verbatim from the doc: "Atlas may execute without
 *      asking only when required fields are confirmed or strongly inferred and
 *      risk is low/medium with available gates." A single blocking ambiguity,
 *      an unconfirmed required field, a high/critical risk band, or missing gates
 *      each independently forces clarify. -> evaluateReadiness()
 *
 * @see docs/engineering-knowledge-base/spec-operating-system/context-discovery-and-business-context.md
 */
final class AtlasContextDiscoveryAndBusinessContextService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA_VERSION = 'atlas.sdd_context_discovery_business_context.v1';

    /** The two documented verdicts of the readiness gate. */
    public const VERDICT_EXECUTE = 'execute_without_asking';
    public const VERDICT_CLARIFY = 'clarify';

    /** The four documented confidence classes. */
    public const CONFIDENCE_CONFIRMED_FACT = 'confirmed_fact';
    public const CONFIDENCE_STRONG_INFERENCE = 'strong_inference';
    public const CONFIDENCE_HYPOTHESIS = 'hypothesis';
    public const CONFIDENCE_BLOCKING_AMBIGUITY = 'blocking_ambiguity';

    /** Risk bands the doc permits autonomous execution on. */
    public const EXECUTABLE_RISK_BANDS = ['low', 'medium'];

    /**
     * The eleven context-discovery Inputs, verbatim intent from the doc body
     * ("Inputs" -> "Atlas should discover").
     *
     * @var list<array{key: string, label: string}>
     */
    private const REQUIRED_INPUTS = [
        ['key' => 'repo_branch_active_files', 'label' => 'repo, branch and active files'],
        ['key' => 'current_route_screen_selection_issue', 'label' => 'current route/screen/selection/issue'],
        ['key' => 'canonical_docs_and_aps', 'label' => 'canonical docs and APs'],
        ['key' => 'product_business_docs', 'label' => 'product/business docs'],
        ['key' => 'design_system_and_ui_tokens', 'label' => 'design system and UI tokens'],
        ['key' => 'api_contracts_and_routes', 'label' => 'API contracts and routes'],
        ['key' => 'database_schema_and_migrations', 'label' => 'database schema and migrations'],
        ['key' => 'tests_and_fixtures', 'label' => 'tests and fixtures'],
        ['key' => 'agents_claude_projections', 'label' => 'AGENTS/CLAUDE as auxiliary projections only'],
        ['key' => 'code_intelligence_and_knowledge_db', 'label' => 'Code Intelligence and Knowledge DB'],
        ['key' => 'prior_specs_receipts_evidence', 'label' => 'prior specs, receipts and evidence'],
    ];

    /**
     * The seven pre-spec Business Questions ("Business Questions" -> "Before spec").
     * `required` marks the questions whose answers must be execution-grade for the
     * gate to allow acting without asking (the business object, the action, the
     * governing rule). Risk and "what should not change" are handled by the gate's
     * dedicated risk path; "who" and "what has been decided" inform but do not by
     * themselves block.
     *
     * @var list<array{key: string, question: string, required: bool}>
     */
    private const BUSINESS_QUESTIONS = [
        ['key' => 'user_problem', 'question' => 'What user/problem does this serve?', 'required' => false],
        ['key' => 'business_object', 'question' => 'What business object is affected?', 'required' => true],
        ['key' => 'action_requested', 'question' => 'What action is requested?', 'required' => true],
        ['key' => 'governing_rule', 'question' => 'What rule governs it?', 'required' => true],
        ['key' => 'risk', 'question' => 'What risk exists?', 'required' => false],
        ['key' => 'already_decided', 'question' => 'What has already been decided?', 'required' => false],
        ['key' => 'must_not_change', 'question' => 'What should not change?', 'required' => false],
    ];

    /**
     * The Confidence Classes table, ranked high -> low. `execution_grade` flags
     * the two classes the doc accepts for acting without asking (confirmed_fact,
     * strong_inference). `blocking` flags the single class that forces a clarify.
     *
     * @var array<string, array{rank: int, execution_grade: bool, blocking: bool, meaning: string}>
     */
    private const CONFIDENCE_SPEC = [
        self::CONFIDENCE_CONFIRMED_FACT => [
            'rank' => 4, 'execution_grade' => true, 'blocking' => false,
            'meaning' => 'derived from canonical docs / code / tests',
        ],
        self::CONFIDENCE_STRONG_INFERENCE => [
            'rank' => 3, 'execution_grade' => true, 'blocking' => false,
            'meaning' => 'high-confidence inference from related context',
        ],
        self::CONFIDENCE_HYPOTHESIS => [
            'rank' => 2, 'execution_grade' => false, 'blocking' => false,
            'meaning' => 'plausible but unverified',
        ],
        self::CONFIDENCE_BLOCKING_AMBIGUITY => [
            'rank' => 1, 'execution_grade' => false, 'blocking' => true,
            'meaning' => 'ambiguous and blocks safe execution -> must clarify',
        ],
    ];

    /**
     * The eleven context-discovery Inputs the doc requires Atlas to discover.
     *
     * @return array{schema_version: string, count: int, inputs: list<array{key: string, label: string}>}
     */
    public function requiredInputs(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count(self::REQUIRED_INPUTS),
            'inputs' => array_map(static fn (array $i): array => $i, self::REQUIRED_INPUTS),
        ];
    }

    /**
     * The seven pre-spec Business Questions.
     *
     * @return array{schema_version: string, count: int, required_count: int, questions: list<array{key: string, question: string, required: bool}>}
     */
    public function businessQuestions(): array
    {
        $required = array_values(array_filter(
            self::BUSINESS_QUESTIONS,
            static fn (array $q): bool => $q['required'] === true,
        ));

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count(self::BUSINESS_QUESTIONS),
            'required_count' => count($required),
            'questions' => array_map(static fn (array $q): array => $q, self::BUSINESS_QUESTIONS),
        ];
    }

    /**
     * The four Confidence Classes, ranked high -> low.
     *
     * @return array{schema_version: string, count: int, classes: list<array{class: string, rank: int, execution_grade: bool, blocking: bool, meaning: string}>}
     */
    public function confidenceClasses(): array
    {
        $rows = [];
        foreach (self::CONFIDENCE_SPEC as $class => $spec) {
            $rows[] = [
                'class' => $class,
                'rank' => $spec['rank'],
                'execution_grade' => $spec['execution_grade'],
                'blocking' => $spec['blocking'],
                'meaning' => $spec['meaning'],
            ];
        }
        // Stable order: highest confidence first.
        usort($rows, static fn (array $a, array $b): int => $b['rank'] <=> $a['rank']);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'count' => count($rows),
            'classes' => $rows,
        ];
    }

    /**
     * Normalise/classify a single confidence label. Unknown or empty labels are
     * treated as the safest interpretation -> blocking_ambiguity (the doc forbids
     * acting on an unclassified item).
     *
     * @return array{class: string, known: bool, rank: int, execution_grade: bool, blocking: bool, meaning: string}
     */
    public function classifyItem(string $confidence): array
    {
        $class = strtolower(trim($confidence));
        if (! array_key_exists($class, self::CONFIDENCE_SPEC)) {
            $spec = self::CONFIDENCE_SPEC[self::CONFIDENCE_BLOCKING_AMBIGUITY];

            return [
                'class' => self::CONFIDENCE_BLOCKING_AMBIGUITY,
                'known' => false,
                'rank' => $spec['rank'],
                'execution_grade' => $spec['execution_grade'],
                'blocking' => $spec['blocking'],
                'meaning' => $spec['meaning'],
            ];
        }
        $spec = self::CONFIDENCE_SPEC[$class];

        return [
            'class' => $class,
            'known' => true,
            'rank' => $spec['rank'],
            'execution_grade' => $spec['execution_grade'],
            'blocking' => $spec['blocking'],
            'meaning' => $spec['meaning'],
        ];
    }

    /**
     * True only for the two confidence classes the doc accepts for acting without
     * asking: confirmed_fact or strong_inference.
     */
    public function isExecutionGrade(string $confidence): bool
    {
        return $this->classifyItem($confidence)['execution_grade'];
    }

    /**
     * The core readiness gate.
     *
     * Doc rule (verbatim): "Atlas may execute without asking only when required
     * fields are confirmed or strongly inferred and risk is low/medium with
     * available gates."
     *
     * This is a conjunction of four independent guards; failing any one forces a
     * clarify verdict:
     *   - no required field carries a blocking_ambiguity;
     *   - every required field is execution-grade (confirmed_fact|strong_inference);
     *   - the risk band is low or medium (high/critical/unknown -> clarify);
     *   - gates are available.
     *
     * Required fields default to the doc's three load-bearing business questions
     * (business object, action, governing rule). Pass an associative
     * field => confidence map; any required field absent from the map is treated
     * as a blocking_ambiguity (unknown context is never assumed safe).
     *
     * @param  array<string, string>  $requiredFieldConfidence  field key => confidence label
     * @param  string  $risk  the risk band (low|medium|high|critical|...)
     * @param  bool  $gatesAvailable  whether quality gates can run
     * @param  list<string>|null  $requiredFields  override the default required field set
     * @return array{
     *     schema_version: string,
     *     verdict: string,
     *     may_execute_without_asking: bool,
     *     risk: string,
     *     risk_executable: bool,
     *     gates_available: bool,
     *     all_required_confirmed_or_inferred: bool,
     *     blocking_fields: list<string>,
     *     unconfirmed_fields: list<string>,
     *     reasons: list<string>,
     *     field_classification: array<string, array{class: string, known: bool, execution_grade: bool, blocking: bool}>
     * }
     */
    public function evaluateReadiness(
        array $requiredFieldConfidence,
        string $risk,
        bool $gatesAvailable,
        ?array $requiredFields = null,
    ): array {
        $fields = $requiredFields ?? $this->defaultRequiredFields();
        $risk = strtolower(trim($risk));
        $riskExecutable = in_array($risk, self::EXECUTABLE_RISK_BANDS, true);

        $blocking = [];
        $unconfirmed = [];
        $classification = [];
        foreach ($fields as $field) {
            $label = $requiredFieldConfidence[$field] ?? self::CONFIDENCE_BLOCKING_AMBIGUITY;
            $info = $this->classifyItem($label);
            $classification[$field] = [
                'class' => $info['class'],
                'known' => $info['known'],
                'execution_grade' => $info['execution_grade'],
                'blocking' => $info['blocking'],
            ];
            if ($info['blocking']) {
                $blocking[] = $field;
            }
            if (! $info['execution_grade']) {
                $unconfirmed[] = $field;
            }
        }

        $allConfirmedOrInferred = $unconfirmed === [];

        $reasons = [];
        if ($blocking !== []) {
            $reasons[] = 'blocking_ambiguity on required field(s): '.implode(', ', $blocking);
        }
        if (! $allConfirmedOrInferred) {
            // Non-blocking but still not execution-grade (e.g. hypothesis).
            $nonBlockingUnconfirmed = array_values(array_diff($unconfirmed, $blocking));
            if ($nonBlockingUnconfirmed !== []) {
                $reasons[] = 'required field(s) not confirmed or strongly inferred: '.implode(', ', $nonBlockingUnconfirmed);
            }
        }
        if (! $riskExecutable) {
            $reasons[] = "risk band '{$risk}' is not low/medium";
        }
        if (! $gatesAvailable) {
            $reasons[] = 'quality gates are not available';
        }

        $mayExecute = $allConfirmedOrInferred && $riskExecutable && $gatesAvailable;
        if ($mayExecute) {
            $reasons = ['all required fields confirmed/strongly inferred; risk low/medium; gates available'];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'verdict' => $mayExecute ? self::VERDICT_EXECUTE : self::VERDICT_CLARIFY,
            'may_execute_without_asking' => $mayExecute,
            'risk' => $risk,
            'risk_executable' => $riskExecutable,
            'gates_available' => $gatesAvailable,
            'all_required_confirmed_or_inferred' => $allConfirmedOrInferred,
            'blocking_fields' => array_values($blocking),
            'unconfirmed_fields' => array_values($unconfirmed),
            'reasons' => array_values($reasons),
            'field_classification' => $classification,
        ];
    }

    /**
     * The default required-field set for the readiness gate: the three
     * load-bearing pre-spec Business Questions.
     *
     * @return list<string>
     */
    public function defaultRequiredFields(): array
    {
        return array_values(array_map(
            static fn (array $q): string => $q['key'],
            array_filter(self::BUSINESS_QUESTIONS, static fn (array $q): bool => $q['required'] === true),
        ));
    }

    /**
     * Governance snapshot: the discovery checklist, the business questions, the
     * confidence classes and the gate's executable risk bands in one envelope.
     *
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'required_inputs' => $this->requiredInputs()['inputs'],
            'business_questions' => $this->businessQuestions()['questions'],
            'confidence_classes' => $this->confidenceClasses()['classes'],
            'executable_risk_bands' => self::EXECUTABLE_RISK_BANDS,
            'gate_rule' => 'execute without asking only when required fields are confirmed or strongly inferred and risk is low/medium with available gates',
        ];
    }
}
