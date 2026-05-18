<?php

namespace App\Services\Ai\Strategy;

use App\Models\AiExperimentPlan;
use Illuminate\Support\Str;

class ExperimentPlanService
{
    public const KIND_VALUE = 'value';

    public const KIND_DEMAND = 'demand';

    public const KIND_PRICING = 'pricing';

    public const KIND_RETENTION = 'retention';

    public const KIND_CHANNEL = 'channel';

    public const KIND_FEASIBILITY = 'feasibility';

    public const ALLOWED_KINDS = [
        self::KIND_VALUE,
        self::KIND_DEMAND,
        self::KIND_PRICING,
        self::KIND_RETENTION,
        self::KIND_CHANNEL,
        self::KIND_FEASIBILITY,
    ];

    public const STATUS_PLANNED = 'planned';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DECIDED = 'decided';

    public const STATUS_INCONCLUSIVE = 'inconclusive';

    public const STATUS_BLOCKED = 'blocked';

    public const ALLOWED_STATUSES = [
        self::STATUS_PLANNED,
        self::STATUS_RUNNING,
        self::STATUS_DECIDED,
        self::STATUS_INCONCLUSIVE,
        self::STATUS_BLOCKED,
    ];

    public const DECISION_CONTINUE = 'continue';

    public const DECISION_PIVOT = 'pivot';

    public const DECISION_SCALE = 'scale';

    public const DECISION_STOP = 'stop';

    public const ALLOWED_DECISIONS = [
        self::DECISION_CONTINUE,
        self::DECISION_PIVOT,
        self::DECISION_SCALE,
        self::DECISION_STOP,
    ];

    /**
     * Create a new experiment plan. Enforces a hypothesis text, success
     * metric and design.
     *
     * @param  array<string,mixed>  $args
     */
    public function create(array $args): AiExperimentPlan
    {
        $hypothesis = (string) ($args['hypothesis'] ?? '');
        if ($hypothesis === '') {
            throw StrategyDomainException::missingField('experiment_plan', 'hypothesis');
        }
        $kind = (string) ($args['hypothesis_kind'] ?? self::KIND_VALUE);
        if (! in_array($kind, self::ALLOWED_KINDS, true)) {
            throw StrategyDomainException::invalidValue('experiment_plan', 'hypothesis_kind', 'must be one of ['.implode(',', self::ALLOWED_KINDS).']');
        }

        $metric = (array) ($args['success_metric'] ?? []);
        if ($metric === []) {
            throw StrategyDomainException::missingField('experiment_plan', 'success_metric');
        }
        if (! isset($metric['name'], $metric['target'])) {
            throw StrategyDomainException::invalidValue('experiment_plan', 'success_metric', 'must include name and target');
        }

        $design = (array) ($args['design'] ?? []);
        if ($design === []) {
            throw StrategyDomainException::missingField('experiment_plan', 'design');
        }

        $budget = $args['budget_max'] ?? null;
        if ($budget !== null) {
            $budget = (float) $budget;
            if ($budget < 0) {
                throw StrategyDomainException::invalidValue('experiment_plan', 'budget_max', 'cannot be negative');
            }
        }

        $experimentId = (string) ($args['experiment_id'] ?? 'exp-'.Str::random(8));

        $hashInput = [
            'experiment_id' => $experimentId,
            'hypothesis' => $hypothesis,
            'hypothesis_kind' => $kind,
            'success_metric' => $metric,
            'design' => $design,
            'sample' => $args['sample'] ?? null,
            'budget_max' => $budget,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'duration' => $args['duration'] ?? null,
            'safety_gates' => $args['safety_gates'] ?? null,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'venture_blueprint_id' => $args['venture_blueprint_id'] ?? null,
        ];

        return AiExperimentPlan::query()->create([
            'uuid' => (string) Str::uuid(),
            'strategy_run_id' => $args['strategy_run_id'] ?? null,
            'opportunity_id' => $args['opportunity_id'] ?? null,
            'venture_blueprint_id' => $args['venture_blueprint_id'] ?? null,
            'experiment_id' => $experimentId,
            'hypothesis' => $hypothesis,
            'hypothesis_kind' => $kind,
            'success_metric' => $metric,
            'design' => $design,
            'sample' => $args['sample'] ?? null,
            'budget_max' => $budget,
            'currency' => (string) ($args['currency'] ?? 'USD'),
            'duration' => $args['duration'] ?? null,
            'safety_gates' => $args['safety_gates'] ?? null,
            'status' => self::STATUS_PLANNED,
            'experiment_hash' => StrategyCanonicalHash::sha256($hashInput),
        ]);
    }

    /**
     * Record the result + decision of an experiment. Transitions status to
     * decided or inconclusive. Strategy must turn experiments into decisions.
     *
     * @param  array<string,mixed>  $result
     * @param  array<string,mixed>  $decision
     */
    public function recordResult(AiExperimentPlan $plan, array $result, array $decision): AiExperimentPlan
    {
        if (! isset($result['outcome'])) {
            throw StrategyDomainException::missingField('experiment_plan.result', 'outcome');
        }
        $decisionKind = (string) ($decision['kind'] ?? '');
        if (! in_array($decisionKind, self::ALLOWED_DECISIONS, true)) {
            throw StrategyDomainException::invalidValue(
                'experiment_plan.decision',
                'kind',
                'must be one of ['.implode(',', self::ALLOWED_DECISIONS).']'
            );
        }

        $outcome = (string) $result['outcome'];
        $status = $outcome === 'inconclusive' ? self::STATUS_INCONCLUSIVE : self::STATUS_DECIDED;

        $plan->status = $status;
        $plan->result = $result;
        $plan->decision = $decision;
        $plan->save();

        return $plan;
    }
}
