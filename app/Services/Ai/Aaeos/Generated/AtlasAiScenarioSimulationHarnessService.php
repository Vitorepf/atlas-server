<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas AI Scenario Simulation Harness — runtime.
 *
 * Turns the governed scenario-simulation doc into deterministic, pure decision
 * logic. The doc's "Regra Mae" is verbatim: "Simulacao nao e profecia.
 * Simulacao e ensaio estruturado de cenarios com incerteza, evidencia e
 * calibracao." So this service NEVER predicts and NEVER authorizes a real
 * action — it only grades a simulation request against the documented
 * contracts and tells the caller what is still missing.
 *
 * Concrete contracts implemented (from the doc body):
 *
 *  - Seed Pack gate ({@see gradeSeedPack()}). The "Seed Pack" section lists the
 *    required fields and states: "Sem criterio de sucesso ou plano de
 *    observacao, a simulacao vira exploration, nao prediction-grade." The grader
 *    flags a Seed Pack missing `success_criteria` or `observation_plan` as
 *    grade `exploration`; a complete one is `prediction_grade`.
 *
 *  - Required simulation runs ({@see runCoverage()}). "Simulation Runs" says:
 *    "Rodar pelo menos: baseline, optimistic, pessimistic, contrarian/rival e
 *    stress." The harness is not run-complete until all five archetypes are
 *    present, and a single-narrative run set is rejected because the doc
 *    requires showing dispersion, not "apenas uma narrativa bonita".
 *
 *  - Output Contract ({@see gradeReport()}). The "Output Contract" section
 *    enumerates the mandatory report fields and bans certainty language
 *    ("Nao usar linguagem de certeza como 'vai acontecer'"). The grader reports
 *    any missing field AND any forbidden certainty phrase found in the text.
 *
 *  - Outcome Tracking Plan ({@see gradeOutcomeTrackingPlan()}). The table lists
 *    seven fields every prediction-grade simulation must declare. "Sem esses
 *    campos, o Atlas nao aprende; apenas gera storytelling." Missing any field
 *    downgrades the plan to `storytelling_only`.
 *
 *  - Lifecycle / Evidence Ledger ({@see lifecycle()}, {@see mustWriteEvidence()}).
 *    The "Evidence Ledger Events" section lists the ordered event stream, and
 *    "Cada etapa escreve Evidence Ledger quando a simulacao for medium/high
 *    risk." Evidence writing is required iff risk is medium or high.
 *
 *  - Error taxonomy ({@see classifyError()}). The "Error Analysis" section names
 *    a closed set of failure categories that feed Self-Improvement; unknown
 *    inputs are refused rather than silently bucketed.
 *
 *  - Safety gate ({@see actionGate()}). "Safety": "Simulacao nunca autoriza
 *    ordem, transacao, deploy, gasto publicitario ou mudanca operacional sem
 *    Decision Receipt, Policy/Profile e approval." A simulation result can never
 *    by itself authorize a real action; this method always returns blocked for
 *    real-world side effects.
 *
 *  - Readiness rollup ({@see assess()}). Folds Seed Pack + runs + report +
 *    outcome plan into a single Definition-Of-Done verdict, never claiming
 *    `prediction_grade` unless every gate is satisfied.
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service NEVER mutates production, calls a provider, places an order/trade,
 * spends ad budget, deploys or writes the database — it only grades and reports.
 *
 * @see docs/engineering-knowledge-base/atlas-ai-scenario-simulation-harness.md
 */
final class AtlasAiScenarioSimulationHarnessService
{
    public const SCHEMA_VERSION = 'atlas.ai.scenario_simulation_harness.v1';

    /** Seed Pack grades. */
    public const GRADE_PREDICTION = 'prediction_grade';
    public const GRADE_EXPLORATION = 'exploration';

    /** Outcome-tracking-plan grades. */
    public const PLAN_PREDICTION = 'prediction_grade';
    public const PLAN_STORYTELLING = 'storytelling_only';

    /**
     * Seed Pack required fields, from the "Seed Pack" section. Order preserved.
     *
     * @var list<string>
     */
    public const SEED_PACK_FIELDS = [
        'question',
        'business_context',
        'consumer_domain',
        'documents',
        'fixed_facts',
        'assumptions',
        'variables',
        'time_window',
        'success_criteria',
        'observation_plan',
        'privacy',
        'provider_policy',
    ];

    /**
     * The two fields whose absence forces the Seed Pack down to `exploration`:
     * "Sem criterio de sucesso ou plano de observacao, a simulacao vira
     * exploration, nao prediction-grade."
     *
     * @var list<string>
     */
    public const SEED_PACK_PREDICTION_GATES = [
        'success_criteria',
        'observation_plan',
    ];

    /**
     * The minimum simulation run archetypes from "Simulation Runs":
     * "baseline, optimistic, pessimistic, contrarian/rival e stress".
     * Modelled as `contrarian` (the doc's contrarian run) so the run set still
     * carries the dissenting view the doc demands.
     *
     * @var list<string>
     */
    public const REQUIRED_RUNS = [
        'baseline',
        'optimistic',
        'pessimistic',
        'contrarian',
        'stress',
    ];

    /**
     * Mandatory report fields from the "Output Contract" section, in order.
     *
     * @var list<string>
     */
    public const REPORT_FIELDS = [
        'executive_summary',
        'top_scenarios',
        'turning_points',
        'objections',
        'stakeholder_map',
        'leading_indicators',
        'confidence',
        'uncertainty',
        'assumptions',
        'falsification_criteria',
        'next_experiment',
        'follow_up_questions',
        'outcome_tracking_plan',
    ];

    /**
     * Forbidden certainty phrases. The doc bans certainty language and pins one
     * example verbatim ("vai acontecer"); these are the disallowed surface forms
     * a compliant report must NOT contain.
     *
     * @var list<string>
     */
    public const FORBIDDEN_CERTAINTY_PHRASES = [
        'vai acontecer',
        'with certainty',
        'guaranteed',
        'will definitely',
        'sem duvida',
        'com certeza',
    ];

    /**
     * Outcome Tracking Plan required fields from the table in "Outcome Tracking
     * Plan", in documented order.
     *
     * @var list<string>
     */
    public const OUTCOME_TRACKING_FIELDS = [
        'outcome_window',
        'observable_metrics',
        'expected_ranges',
        'leading_indicators',
        'ground_truth_sources',
        'review_date',
        'owner',
    ];

    /**
     * The ordered Evidence Ledger lifecycle from "Evidence Ledger Events".
     *
     * @var list<string>
     */
    public const LIFECYCLE_EVENTS = [
        'SCENARIO_SIMULATION_REQUESTED',
        'SIMULATION_SEED_PACK_CREATED',
        'SIMULATION_GRAPH_BUILT',
        'SIMULATION_PERSONAS_CREATED',
        'SIMULATION_RUN_STARTED',
        'SIMULATION_RUN_COMPLETED',
        'SIMULATION_REPORT_GENERATED',
        'SIMULATION_OUTCOME_WINDOW_OPENED',
        'SIMULATION_REAL_OUTCOME_RECORDED',
        'SIMULATION_CALIBRATED',
        'SIMULATION_IMPROVEMENT_PROPOSED',
    ];

    /**
     * Closed set of error categories from "Error Analysis". This taxonomy feeds
     * Self-Improvement; an input outside it is refused, never silently bucketed.
     *
     * @var list<string>
     */
    public const ERROR_CATEGORIES = [
        'seed_incomplete',
        'wrong_entity_graph',
        'weak_persona',
        'missing_external_event',
        'model_provider_weakness',
        'overfit_narrative',
        'metric_mismatch',
        'outcome_window_wrong',
        'human_execution_differed',
        'random_irreducible_uncertainty',
    ];

    /**
     * Real-world side effects that a simulation may NEVER authorize on its own,
     * from the "Safety" section: "ordem, transacao, deploy, gasto publicitario
     * ou mudanca operacional".
     *
     * @var list<string>
     */
    public const REAL_WORLD_ACTIONS = [
        'place_order',
        'execute_transaction',
        'deploy',
        'spend_ad_budget',
        'operational_change',
    ];

    /**
     * Grade a Seed Pack. Returns the present/missing fields and the resulting
     * grade. A pack missing either prediction gate (success_criteria,
     * observation_plan) is `exploration`; a complete pack is `prediction_grade`.
     *
     * @param  array<string,mixed>  $seedPack
     * @return array{schema_version:string,grade:string,is_prediction_grade:bool,present:list<string>,missing:list<string>,missing_prediction_gates:list<string>,reason:string}
     */
    public function gradeSeedPack(array $seedPack): array
    {
        $present = [];
        $missing = [];
        foreach (self::SEED_PACK_FIELDS as $field) {
            if ($this->filled($seedPack, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $missingGates = [];
        foreach (self::SEED_PACK_PREDICTION_GATES as $gate) {
            if (! $this->filled($seedPack, $gate)) {
                $missingGates[] = $gate;
            }
        }

        $isPrediction = $missingGates === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'grade' => $isPrediction ? self::GRADE_PREDICTION : self::GRADE_EXPLORATION,
            'is_prediction_grade' => $isPrediction,
            'present' => $present,
            'missing' => $missing,
            'missing_prediction_gates' => $missingGates,
            'reason' => $isPrediction
                ? 'seed pack carries success_criteria and observation_plan'
                : 'missing success_criteria/observation_plan downgrades to exploration',
        ];
    }

    /**
     * Check that the simulation run set covers every required archetype and
     * shows dispersion (more than one distinct outcome narrative).
     *
     * @param  list<string>  $runs  archetype names that were actually executed
     * @return array{schema_version:string,complete:bool,present:list<string>,missing:list<string>,shows_dispersion:bool,reason:string}
     */
    public function runCoverage(array $runs): array
    {
        $normalized = array_values(array_unique(array_map('strtolower', $runs)));

        $present = [];
        $missing = [];
        foreach (self::REQUIRED_RUNS as $required) {
            if (in_array($required, $normalized, true)) {
                $present[] = $required;
            } else {
                $missing[] = $required;
            }
        }

        // Dispersion: the doc rejects "apenas uma narrativa bonita".
        $showsDispersion = count($normalized) >= 2;
        $complete = $missing === [] && $showsDispersion;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'complete' => $complete,
            'present' => $present,
            'missing' => $missing,
            'shows_dispersion' => $showsDispersion,
            'reason' => $complete
                ? 'all required archetypes present and dispersion shown'
                : ($missing !== []
                    ? 'missing required run archetypes'
                    : 'single-narrative run set does not show dispersion'),
        ];
    }

    /**
     * Grade a report against the Output Contract: every mandatory field present
     * and zero forbidden certainty phrases.
     *
     * @param  array<string,mixed>  $report
     * @return array{schema_version:string,compliant:bool,present:list<string>,missing:list<string>,certainty_violations:list<string>,reason:string}
     */
    public function gradeReport(array $report): array
    {
        $present = [];
        $missing = [];
        foreach (self::REPORT_FIELDS as $field) {
            if ($this->filled($report, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $violations = $this->findCertaintyViolations($report);

        $compliant = $missing === [] && $violations === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'compliant' => $compliant,
            'present' => $present,
            'missing' => $missing,
            'certainty_violations' => $violations,
            'reason' => $compliant
                ? 'all output-contract fields present and no certainty language'
                : ($violations !== []
                    ? 'report uses forbidden certainty language'
                    : 'report missing required output-contract fields'),
        ];
    }

    /**
     * Scan an arbitrary text blob for forbidden certainty phrases (case
     * insensitive). Pure helper exposed for callers that only have prose.
     *
     * @return list<string> the phrases that were found
     */
    public function findCertaintyViolations(mixed $value): array
    {
        $haystack = strtolower($this->flatten($value));
        $found = [];
        foreach (self::FORBIDDEN_CERTAINTY_PHRASES as $phrase) {
            if ($haystack !== '' && str_contains($haystack, $phrase)) {
                $found[] = $phrase;
            }
        }

        return $found;
    }

    /**
     * Grade an Outcome Tracking Plan. Missing any of the seven required fields
     * downgrades it to `storytelling_only`.
     *
     * @param  array<string,mixed>  $plan
     * @return array{schema_version:string,grade:string,is_prediction_grade:bool,present:list<string>,missing:list<string>,reason:string}
     */
    public function gradeOutcomeTrackingPlan(array $plan): array
    {
        $present = [];
        $missing = [];
        foreach (self::OUTCOME_TRACKING_FIELDS as $field) {
            if ($this->filled($plan, $field)) {
                $present[] = $field;
            } else {
                $missing[] = $field;
            }
        }

        $isPrediction = $missing === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'grade' => $isPrediction ? self::PLAN_PREDICTION : self::PLAN_STORYTELLING,
            'is_prediction_grade' => $isPrediction,
            'present' => $present,
            'missing' => $missing,
            'reason' => $isPrediction
                ? 'all outcome-tracking fields declared'
                : 'missing outcome-tracking fields: simulation cannot learn, only storytelling',
        ];
    }

    /**
     * The ordered Evidence Ledger lifecycle.
     *
     * @return array{schema_version:string,events:list<string>,count:int}
     */
    public function lifecycle(): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'events' => self::LIFECYCLE_EVENTS,
            'count' => count(self::LIFECYCLE_EVENTS),
        ];
    }

    /**
     * Whether Evidence Ledger must be written for a simulation of the given
     * risk. The doc: "Cada etapa escreve Evidence Ledger quando a simulacao for
     * medium/high risk." Unknown/low risk does not force evidence.
     *
     * @return array{schema_version:string,risk:string,must_write_evidence:bool,reason:string}
     */
    public function mustWriteEvidence(string $risk): array
    {
        $normalized = strtolower(trim($risk));
        $must = in_array($normalized, ['medium', 'high'], true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'risk' => $normalized,
            'must_write_evidence' => $must,
            'reason' => $must
                ? 'medium/high risk requires Evidence Ledger at each stage'
                : 'low/unknown risk does not force per-stage evidence',
        ];
    }

    /**
     * Classify an error category from the closed Error Analysis taxonomy.
     * Refuses anything outside the documented set instead of guessing.
     *
     * @return array{schema_version:string,known:bool,category:?string,categories:list<string>,reason:string}
     */
    public function classifyError(string $category): array
    {
        $normalized = strtolower(trim($category));
        $known = in_array($normalized, self::ERROR_CATEGORIES, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'known' => $known,
            'category' => $known ? $normalized : null,
            'categories' => self::ERROR_CATEGORIES,
            'reason' => $known
                ? 'category belongs to the documented error taxonomy'
                : 'unknown category refused: do not force the story to look correct',
        ];
    }

    /**
     * Safety gate. A simulation can NEVER authorize a real-world action on its
     * own; any such action is blocked and requires a Decision Receipt + policy
     * + approval. Anything not in the real-world set is treated as analysis-only
     * (allowed, no side effect).
     *
     * @return array{schema_version:string,action:string,is_real_world:bool,authorized:bool,requires:list<string>,reason:string}
     */
    public function actionGate(string $action): array
    {
        $normalized = strtolower(trim($action));
        $isRealWorld = in_array($normalized, self::REAL_WORLD_ACTIONS, true);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'action' => $normalized,
            'is_real_world' => $isRealWorld,
            // Simulation alone never authorizes a real-world side effect.
            'authorized' => false,
            'requires' => $isRealWorld
                ? ['decision_receipt', 'policy_profile', 'human_approval']
                : ['analysis_only'],
            'reason' => $isRealWorld
                ? 'simulation never authorizes order/transaction/deploy/ad-spend/operational change without Decision Receipt + approval'
                : 'analysis/review-only output: no real-world side effect and still no autonomous authorization',
        ];
    }

    /**
     * Definition-Of-Done rollup. Folds every gate into one verdict. The overall
     * simulation is `prediction_grade` only when the Seed Pack, run coverage,
     * report and outcome-tracking plan all pass.
     *
     * @param  array<string,mixed>  $seedPack
     * @param  list<string>  $runs
     * @param  array<string,mixed>  $report
     * @param  array<string,mixed>  $outcomeTrackingPlan
     * @param  string  $risk
     * @return array{schema_version:string,grade:string,is_prediction_grade:bool,seed_pack:array<string,mixed>,run_coverage:array<string,mixed>,report:array<string,mixed>,outcome_tracking_plan:array<string,mixed>,evidence:array<string,mixed>,blockers:list<string>}
     */
    public function assess(
        array $seedPack,
        array $runs,
        array $report,
        array $outcomeTrackingPlan,
        string $risk = 'medium'
    ): array {
        $seed = $this->gradeSeedPack($seedPack);
        $coverage = $this->runCoverage($runs);
        $reportGrade = $this->gradeReport($report);
        $plan = $this->gradeOutcomeTrackingPlan($outcomeTrackingPlan);
        $evidence = $this->mustWriteEvidence($risk);

        $blockers = [];
        if (! $seed['is_prediction_grade']) {
            $blockers[] = 'seed_pack_not_prediction_grade';
        }
        if (! $coverage['complete']) {
            $blockers[] = 'run_coverage_incomplete';
        }
        if (! $reportGrade['compliant']) {
            $blockers[] = 'report_not_output_contract_compliant';
        }
        if (! $plan['is_prediction_grade']) {
            $blockers[] = 'outcome_tracking_plan_incomplete';
        }

        $isPrediction = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'grade' => $isPrediction ? self::GRADE_PREDICTION : self::GRADE_EXPLORATION,
            'is_prediction_grade' => $isPrediction,
            'seed_pack' => $seed,
            'run_coverage' => $coverage,
            'report' => $reportGrade,
            'outcome_tracking_plan' => $plan,
            'evidence' => $evidence,
            'blockers' => $blockers,
        ];
    }

    /**
     * A field is "filled" when present and not an empty string / empty array /
     * null. Whitespace-only strings count as empty.
     *
     * @param  array<string,mixed>  $data
     */
    private function filled(array $data, string $field): bool
    {
        if (! array_key_exists($field, $data)) {
            return false;
        }

        $value = $data[$field];
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
     * Flatten a scalar/array value into a single searchable string.
     */
    private function flatten(mixed $value): string
    {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value)) {
            $parts = [];
            foreach ($value as $item) {
                $parts[] = $this->flatten($item);
            }

            return implode(' ', $parts);
        }

        return '';
    }
}
