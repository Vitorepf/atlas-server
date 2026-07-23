<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;
use InvalidArgumentException;

/**
 * Deterministic runtime for the Atlas Experimentation Engine.
 *
 * Turns the documented governance contract into pure, testable decision logic
 * for the experiment lifecycle Objective -> Hypothesis -> Experiment ->
 * Measurement -> Decision -> Learning:
 *
 *   - the 4 Quality Gates (hypothesis-written, metric-selected, sample-defined,
 *     result-recorded) that gate an experiment before it may report a result;
 *   - "Nao chamar opiniao de validacao": an experiment without a metric and a
 *     defined sample is opinion, never validation;
 *   - "Nao otimizar metrica de vaidade quando metrica de negocio existe": when a
 *     business metric is available, optimizing a vanity metric is blocked;
 *   - "Nao escalar campanha sem resultado minimo e budget aprovado": the escalate
 *     decision requires BOTH a met minimum result AND an approved budget;
 *   - "Nao esconder experimento inconclusivo" + failure mode "Conclusao sem
 *     amostra suficiente": an underpowered sample yields an inconclusive result
 *     that must be surfaced, never a conclusion;
 *   - the documented decision policy (step 8): continue / pivot / escalate / stop.
 *
 * Pure functions only: no database, no IO, no side effects. Every method returns
 * a strict typed array shape.
 *
 * @see docs/engineering-knowledge-base/atlas-experimentation-engine.md
 */
class AtlasExperimentationEngineService
{
    public const SCHEMA_VERSION = 'atlas.ai.experiment.v1';

    /**
     * The 4 documented quality gates, in lifecycle order. An experiment may only
     * report a trustworthy result once every gate is satisfied.
     *
     * @var list<string>
     */
    public const QUALITY_GATES = [
        'hypothesis-written',
        'metric-selected',
        'sample-defined',
        'result-recorded',
    ];

    /**
     * The 9 ordered steps of the documented Fluxo.
     *
     * @var list<string>
     */
    public const FLOW_STEPS = [
        'receive_objective_and_metric',
        'write_hypothesis',
        'define_minimal_test',
        'define_sample_channel_duration',
        'run_safety_budget_gate',
        'execute',
        'measure',
        'decide',
        'record_learning',
    ];

    public const DECISION_CONTINUE = 'continue';

    public const DECISION_PIVOT = 'pivot';

    public const DECISION_ESCALATE = 'escalate';

    public const DECISION_STOP = 'stop';

    /**
     * Minimum observations below which any conclusion is statistically unsafe
     * (doc failure mode: "Dados pequenos geram conclusao falsa" /
     * "Conclusao sem amostra suficiente"). A sample under this floor can never
     * yield a conclusion, only an inconclusive result that must be surfaced.
     */
    public const MIN_SAMPLE_FLOOR = 30;

    /**
     * Validate an experiment design against the 4 quality gates.
     *
     * A gate counts as satisfied only from concrete fields:
     *   - hypothesis-written: a non-empty hypothesis string;
     *   - metric-selected: a primary metric with a name AND a numeric target;
     *   - sample-defined: a sample size at or above the safe floor;
     *   - result-recorded: an observed value for the primary metric is present.
     *
     * @param  array<string,mixed>  $experiment
     * @return array{schema:string,ready_to_report:bool,satisfied_gates:list<string>,missing_gates:list<string>,is_validation:bool,decision:string,reason:string}
     */
    public function evaluateReadiness(array $experiment): array
    {
        $satisfied = [];
        $missing = [];

        $hasHypothesis = AtlasAaeosValueNormalizer::isNonBlankString($experiment['hypothesis'] ?? null);
        $this->push($satisfied, $missing, 'hypothesis-written', $hasHypothesis);

        $metric = is_array($experiment['primary_metric'] ?? null) ? $experiment['primary_metric'] : [];
        $hasMetric = AtlasAaeosValueNormalizer::isNonBlankString($metric['name'] ?? null) && is_numeric($metric['target'] ?? null);
        $this->push($satisfied, $missing, 'metric-selected', $hasMetric);

        $sample = (int) ($experiment['sample_size'] ?? 0);
        $hasSample = $sample >= self::MIN_SAMPLE_FLOOR;
        $this->push($satisfied, $missing, 'sample-defined', $hasSample);

        $hasResult = is_numeric($metric['observed'] ?? null);
        $this->push($satisfied, $missing, 'result-recorded', $hasResult);

        $readyToReport = $missing === [];

        // "Nao chamar opiniao de validacao": only a metric AND a defined sample
        // make an experiment validation rather than opinion.
        $isValidation = $hasMetric && $hasSample;

        return [
            'schema' => self::SCHEMA_VERSION,
            'ready_to_report' => $readyToReport,
            'satisfied_gates' => $satisfied,
            'missing_gates' => $missing,
            'is_validation' => $isValidation,
            'decision' => $readyToReport ? 'gates_passed_may_report' : 'blocked_incomplete_gates',
            'reason' => $readyToReport
                ? 'all_4_quality_gates_satisfied'
                : 'missing_quality_gates:'.implode(',', $missing),
        ];
    }

    /**
     * Guard against optimizing a vanity metric.
     *
     * Doc rule: "Nao otimizar metrica de vaidade quando metrica de negocio
     * existe." If a business metric is declared, the experiment must optimize it;
     * choosing a vanity metric while a business metric exists is blocked. When no
     * business metric exists, a proxy metric is allowed but flagged.
     *
     * @param  array<string,mixed>  $metric
     * @return array{schema:string,allowed:bool,is_vanity:bool,business_metric_available:bool,must_optimize:?string,reason:string}
     */
    public function evaluateMetricChoice(array $metric): array
    {
        $isVanity = (bool) ($metric['is_vanity'] ?? false);
        $businessMetric = AtlasAaeosValueNormalizer::isNonBlankString($metric['business_metric'] ?? null)
            ? (string) $metric['business_metric']
            : null;
        $businessAvailable = $businessMetric !== null;

        $blocked = $isVanity && $businessAvailable;
        $allowed = ! $blocked;

        return [
            'schema' => self::SCHEMA_VERSION,
            'allowed' => $allowed,
            'is_vanity' => $isVanity,
            'business_metric_available' => $businessAvailable,
            'must_optimize' => $businessMetric,
            'reason' => $blocked
                ? 'vanity_metric_blocked:business_metric_exists:'.(string) $businessMetric
                : ($isVanity
                    ? 'proxy_metric_allowed_no_business_metric_available'
                    : 'business_metric_selected'),
        ];
    }

    /**
     * Decide whether a sample is sufficient to draw a conclusion.
     *
     * Doc failure mode "Conclusao sem amostra suficiente": a sample below the
     * safe floor, OR below a documented required sample, cannot conclude. An
     * underpowered experiment is INCONCLUSIVE, and the result must be surfaced,
     * never hidden ("Nao esconder experimento inconclusivo").
     *
     * @return array{schema:string,sufficient:bool,sample_size:int,required:int,shortfall:int,verdict:string,must_surface:bool,reason:string}
     */
    public function evaluateSampleSufficiency(int $sampleSize, int $requiredSample = self::MIN_SAMPLE_FLOOR): array
    {
        $sample = max(0, $sampleSize);
        $required = max(self::MIN_SAMPLE_FLOOR, $requiredSample);
        $sufficient = $sample >= $required;
        $shortfall = $sufficient ? 0 : $required - $sample;

        return [
            'schema' => self::SCHEMA_VERSION,
            'sufficient' => $sufficient,
            'sample_size' => $sample,
            'required' => $required,
            'shortfall' => $shortfall,
            'verdict' => $sufficient ? 'conclusive_sample' : 'inconclusive',
            // An inconclusive experiment must still be surfaced, not buried.
            'must_surface' => true,
            'reason' => $sufficient
                ? 'sample_meets_required_floor'
                : 'underpowered_sample_cannot_conclude:shortfall_'.$shortfall,
        ];
    }

    /**
     * Resolve the documented step-8 decision: continue / pivot / escalate / stop.
     *
     * Order of precedence enforced from the doc:
     *   1. Insufficient sample => STOP with verdict inconclusive (no conclusion
     *      may be drawn; the inconclusive outcome is surfaced).
     *   2. ESCALATE requires BOTH the minimum result met AND an approved budget
     *      ("Nao escalar campanha sem resultado minimo e budget aprovado"). A met
     *      result without approved budget may only continue, never escalate.
     *   3. Result met (but not escalatable) => CONTINUE.
     *   4. Result not met on a sufficient sample => PIVOT.
     *
     * Direction: an observed value reaches the target either >= target ("higher
     * is better", default) or <= target when minimize is true.
     *
     * @param  array<string,mixed>  $result  {observed:number,target:number,minimize?:bool,sample_size:int,required_sample?:int}
     * @param  array<string,mixed>  $budget  {approved?:bool}
     * @return array{schema:string,decision:string,result_met:bool,sample_sufficient:bool,budget_approved:bool,inconclusive:bool,must_surface:bool,reason:string}
     */
    public function decide(array $result, array $budget = []): array
    {
        if (! is_numeric($result['observed'] ?? null) || ! is_numeric($result['target'] ?? null)) {
            throw new InvalidArgumentException('decide() requires numeric observed and target values');
        }

        $observed = (float) $result['observed'];
        $target = (float) $result['target'];
        $minimize = (bool) ($result['minimize'] ?? false);

        $resultMet = $minimize ? $observed <= $target : $observed >= $target;

        $sample = $this->evaluateSampleSufficiency(
            (int) ($result['sample_size'] ?? 0),
            (int) ($result['required_sample'] ?? self::MIN_SAMPLE_FLOOR),
        );
        $sampleSufficient = $sample['sufficient'];
        $budgetApproved = (bool) ($budget['approved'] ?? false);

        if (! $sampleSufficient) {
            $decision = self::DECISION_STOP;
            $reason = 'inconclusive_insufficient_sample:'.$sample['reason'];
        } elseif ($resultMet && $budgetApproved) {
            $decision = self::DECISION_ESCALATE;
            $reason = 'min_result_met_and_budget_approved:escalate';
        } elseif ($resultMet) {
            // Result met but budget not approved: may keep running, must not escalate.
            $decision = self::DECISION_CONTINUE;
            $reason = 'min_result_met_but_budget_not_approved:cannot_escalate';
        } else {
            $decision = self::DECISION_PIVOT;
            $reason = 'sufficient_sample_but_result_not_met:pivot';
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'decision' => $decision,
            'result_met' => $resultMet,
            'sample_sufficient' => $sampleSufficient,
            'budget_approved' => $budgetApproved,
            'inconclusive' => ! $sampleSufficient,
            // Inconclusive experiments are surfaced, never hidden.
            'must_surface' => ! $sampleSufficient ? true : false,
            'reason' => $reason,
        ];
    }

    /**
     * Run the full documented loop as a single deterministic decision: validate
     * the 4 gates, guard the metric choice, then resolve the step-8 decision.
     * The loop refuses to report a decision from an experiment that is only
     * opinion (gates not passed) or whose metric choice is blocked.
     *
     * @param  array<string,mixed>  $experiment
     * @param  array<string,mixed>  $budget
     * @return array{schema:string,flow_steps:list<string>,readiness:array<string,mixed>,metric_choice:array<string,mixed>,decision:string,reportable:bool,human_review_required:bool,reason:string}
     */
    public function runExperiment(array $experiment, array $budget = []): array
    {
        $readiness = $this->evaluateReadiness($experiment);
        $metricChoice = $this->evaluateMetricChoice(
            is_array($experiment['primary_metric'] ?? null) ? $experiment['primary_metric'] : []
        );

        if (! $metricChoice['allowed']) {
            $decision = self::DECISION_STOP;
            $reportable = false;
            $reason = 'metric_choice_blocked:'.$metricChoice['reason'];
        } elseif (! $readiness['ready_to_report']) {
            $decision = self::DECISION_STOP;
            $reportable = false;
            $reason = 'gates_not_passed_result_is_opinion_not_validation';
        } else {
            $metric = $experiment['primary_metric'];
            $resolved = $this->decide([
                'observed' => $metric['observed'],
                'target' => $metric['target'],
                'minimize' => (bool) ($metric['minimize'] ?? false),
                'sample_size' => (int) ($experiment['sample_size'] ?? 0),
                'required_sample' => (int) ($experiment['required_sample'] ?? self::MIN_SAMPLE_FLOOR),
            ], $budget);
            $decision = $resolved['decision'];
            $reportable = true;
            $reason = $resolved['reason'];
        }

        // Escalation always keeps a human in the loop (budget commitment), and a
        // STOP that came from an inconclusive sample needs review of next steps.
        $humanReviewRequired = $decision === self::DECISION_ESCALATE
            || ($decision === self::DECISION_STOP && $reportable);

        return [
            'schema' => self::SCHEMA_VERSION,
            'flow_steps' => self::FLOW_STEPS,
            'readiness' => $readiness,
            'metric_choice' => $metricChoice,
            'decision' => $decision,
            'reportable' => $reportable,
            'human_review_required' => $humanReviewRequired,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $satisfied
     * @param  list<string>  $missing
     */
    private function push(array &$satisfied, array &$missing, string $gate, bool $ok): void
    {
        if ($ok) {
            $satisfied[] = $gate;
        } else {
            $missing[] = $gate;
        }
    }

}
