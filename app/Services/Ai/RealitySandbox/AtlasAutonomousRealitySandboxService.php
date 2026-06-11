<?php

namespace App\Services\Ai\RealitySandbox;

use App\Models\AtlasAarsCertification;
use App\Models\AtlasAarsCounterfactual;
use App\Models\AtlasAarsRiskProjection;
use App\Models\AtlasAarsScenario;
use App\Models\AtlasAarsSimulation;
use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\StrategicReality\AtlasStrategicRealityRuntimeService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AtlasAutonomousRealitySandboxService
{
    public const SCENARIO_SCHEMA = 'atlas.aars.scenario.v1';

    public const SIMULATION_SCHEMA = 'atlas.aars.simulation.v1';

    public const COUNTERFACTUAL_SCHEMA = 'atlas.aars.counterfactual.v1';

    public const RISK_SCHEMA = 'atlas.aars.risk_projection.v1';

    public const CERTIFICATION_RESULT_SCHEMA = 'atlas.aars.certification_result.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.aars.control_plane.v1';

    public const CLAIM_POLICY_SCHEMA = 'atlas.aars.claim_policy.v1';

    public const LEVEL_MAX = 'AARS-L10 Autonomous Reality Sandbox';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_PASSED = 'passed';

    public function __construct(
        private readonly ?AtlasStrategicRealityRuntimeService $strategicReality = null,
        private readonly ?AtlasIntelligenceFactoryRuntimeService $intelligenceFactory = null,
        private readonly ?AtlasAutonomousEvolutionLoopService $autonomousEvolution = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function run(array $input): array
    {
        $scenario = $this->createScenario($input);
        $simulation = $this->simulate($scenario, $input);
        $counterfactual = $this->counterfactual($simulation, $input);
        $risk = $this->projectRisk($simulation, $counterfactual, $input);
        $certification = $this->certifySimulation($simulation, $risk, $counterfactual, $input);
        $status = ($risk['status'] ?? null) === self::STATUS_BLOCKED || ($certification['status'] ?? null) === self::STATUS_BLOCKED
            ? self::STATUS_BLOCKED
            : (($risk['risk_level'] ?? null) === 'high' ? self::STATUS_WATCH : self::STATUS_READY);

        $payload = [
            'schema_version' => 'atlas.aars.run.v1',
            'status' => $status,
            'maturity_level' => self::LEVEL_MAX,
            'scenario' => $scenario,
            'simulation' => $simulation,
            'counterfactual' => $counterfactual,
            'risk_projection' => $risk,
            'certification' => $certification,
            'release_recommendation' => $this->releaseRecommendation($risk, $certification),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['run_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function createScenario(array $input): array
    {
        $objective = $this->objective($input);
        $evidenceRefs = AiStringListNormalizer::truthyTrimmedScalarValues($input['evidence_refs'] ?? []);
        $domain = AiValueNormalizer::trimmedScalarStringOrNull($input['domain'] ?? null) ?? $this->classifyDomain($objective);
        $flowId = AiValueNormalizer::trimmedScalarStringOrNull($input['flow_id'] ?? null) ?? $this->flowFor($domain);
        $worldState = $this->worldState($input, $domain, $flowId);
        $assumptions = $this->assumptions($input, $objective, $domain);
        $constraints = $this->constraints($input, $objective);

        $payload = [
            'schema_version' => self::SCENARIO_SCHEMA,
            'status' => $evidenceRefs === [] ? self::STATUS_WATCH : self::STATUS_READY,
            'surface_id' => AiValueNormalizer::trimmedScalarStringOrNull($input['surface_id'] ?? null) ?? 'atlas_simulation_chamber',
            'domain' => $domain,
            'flow_id' => $flowId,
            'scenario_type' => $this->scenarioType($objective, $domain),
            'scope_hash' => MissionCanonicalHash::sha256(AiValueNormalizer::trimmedScalarStringOrNull($input['scope_id'] ?? $input['workspace'] ?? null) ?? 'atlas'),
            'objective_hash' => MissionCanonicalHash::sha256($objective),
            'objective' => $objective,
            'world_state' => $worldState,
            'assumptions' => $assumptions,
            'constraints' => $constraints,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['scenario_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aars_scenarios')) {
            $record = AtlasAarsScenario::query()->create($payload);
        }

        return $this->withRecord($payload, 'scenario_id', $record?->id);
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function simulate(array $scenario, array $input = []): array
    {
        $objective = (string) ($scenario['objective'] ?? $this->objective($input));
        $evidenceRefs = AiStringListNormalizer::truthyTrimmedScalarValues($scenario['evidence_refs'] ?? $input['evidence_refs'] ?? []);
        $options = $this->options($objective, $scenario, $input);
        $predictedOutcomes = $this->predictedOutcomes($options, $scenario);
        $impactModel = $this->impactModel($options, $predictedOutcomes, $scenario);
        $uncertainty = $this->uncertainty($scenario, $input);
        $requiredValidation = $this->requiredValidation($scenario, $uncertainty);
        $status = ($uncertainty['context_sufficiency'] ?? null) === 'insufficient' ? self::STATUS_WATCH : self::STATUS_PASSED;

        $payload = [
            'scenario_id' => $this->uuidOrNull($scenario['scenario_id'] ?? null),
            'schema_version' => self::SIMULATION_SCHEMA,
            'status' => $status,
            'mode' => AiValueNormalizer::trimmedScalarStringOrNull($input['mode'] ?? null) ?? 'dry_run_reality_twin',
            'options' => $options,
            'predicted_outcomes' => $predictedOutcomes,
            'impact_model' => $impactModel,
            'uncertainty' => $uncertainty,
            'required_validation' => $requiredValidation,
            'claim_policy' => $this->claimPolicy(),
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['simulation_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aars_simulations')) {
            $record = AtlasAarsSimulation::query()->create($payload);
        }

        return $this->withRecord($payload, 'simulation_id', $record?->id);
    }

    /**
     * @param  array<string,mixed>  $simulation
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function counterfactual(array $simulation, array $input = []): array
    {
        $options = is_array($simulation['options'] ?? null) ? $simulation['options'] : [];
        $baseline = $options[0] ?? ['option_id' => 'baseline', 'action' => 'do_nothing'];
        $alternatives = array_values(array_slice($options, 1));
        $deltaAnalysis = $this->deltaAnalysis($baseline, $alternatives);
        $decisionEffects = $this->decisionEffects($deltaAnalysis, $simulation);
        $payload = [
            'simulation_id' => $this->uuidOrNull($simulation['simulation_id'] ?? null),
            'schema_version' => self::COUNTERFACTUAL_SCHEMA,
            'status' => $alternatives === [] ? self::STATUS_WATCH : self::STATUS_READY,
            'baseline' => $baseline,
            'alternatives' => $alternatives,
            'delta_analysis' => $deltaAnalysis,
            'decision_effects' => $decisionEffects,
            'evidence_refs' => AiStringListNormalizer::truthyTrimmedScalarValues($simulation['evidence_refs'] ?? $input['evidence_refs'] ?? []),
        ];
        $payload['counterfactual_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aars_counterfactuals')) {
            $record = AtlasAarsCounterfactual::query()->create($payload);
        }

        return $this->withRecord($payload, 'counterfactual_id', $record?->id);
    }

    /**
     * @param  array<string,mixed>  $simulation
     * @param  array<string,mixed>  $counterfactual
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function projectRisk(array $simulation, array $counterfactual, array $input = []): array
    {
        $risks = $this->risks($simulation, $counterfactual, $input);
        $riskLevel = $this->riskLevel($risks);
        $payload = [
            'simulation_id' => $this->uuidOrNull($simulation['simulation_id'] ?? null),
            'schema_version' => self::RISK_SCHEMA,
            'status' => $riskLevel === 'critical' ? self::STATUS_BLOCKED : self::STATUS_READY,
            'risk_level' => $riskLevel,
            'risks' => $risks,
            'mitigations' => $this->mitigations($risks),
            'rollback_requirements' => $this->rollbackRequirements($risks),
            'operator_gates' => $this->operatorGates($risks),
            'evidence_refs' => AiStringListNormalizer::truthyTrimmedScalarValues($simulation['evidence_refs'] ?? $input['evidence_refs'] ?? []),
        ];
        $payload['risk_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aars_risk_projections')) {
            $record = AtlasAarsRiskProjection::query()->create($payload);
        }

        return $this->withRecord($payload, 'risk_projection_id', $record?->id);
    }

    /**
     * @param  array<string,mixed>  $simulation
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $counterfactual
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function certifySimulation(array $simulation, array $risk, array $counterfactual, array $input = []): array
    {
        $checks = [
            $this->check('simulation_hash_present', filled($simulation['simulation_hash'] ?? null), [$simulation['simulation_hash'] ?? null]),
            $this->check('counterfactual_present', filled($counterfactual['counterfactual_hash'] ?? null), [$counterfactual['counterfactual_hash'] ?? null]),
            $this->check('risk_projection_present', filled($risk['risk_hash'] ?? null), [$risk['risk_hash'] ?? null]),
            $this->check('no_external_execution', $this->claimPolicy()['external_execution_performed'] === false, []),
            $this->check('no_provider_invocation', $this->claimPolicy()['provider_invoked'] === false, []),
            $this->check('evidence_refs_present', AiStringListNormalizer::truthyTrimmedScalarValues($simulation['evidence_refs'] ?? []) !== [], AiStringListNormalizer::truthyTrimmedScalarValues($simulation['evidence_refs'] ?? []), severity: 'warn'),
            $this->check('high_risk_requires_operator_gate', ($risk['risk_level'] ?? null) !== 'high' || $this->operatorGates((array) ($risk['risks'] ?? [])) !== [], []),
        ];
        $failedCritical = collect($checks)->contains(fn (array $check): bool => $check['status'] === 'fail' && $check['severity'] === 'critical');
        $promotionGate = [
            'status' => $failedCritical || ($risk['status'] ?? null) === self::STATUS_BLOCKED ? self::STATUS_BLOCKED : (($risk['risk_level'] ?? null) === 'high' ? 'operator_review_required' : 'sandbox_passed'),
            'may_execute_real_world' => false,
            'may_promote_to_forge_or_dev' => ($risk['risk_level'] ?? null) !== 'critical' && ! $failedCritical,
            'requires_aver_before_execution_claim' => true,
            'requires_asre_for_strategic_decision' => true,
            'requires_aemor_for_learning' => true,
        ];
        $payload = [
            'simulation_id' => $this->uuidOrNull($simulation['simulation_id'] ?? null),
            'schema_version' => self::CERTIFICATION_RESULT_SCHEMA,
            'status' => $promotionGate['status'] === self::STATUS_BLOCKED ? self::STATUS_BLOCKED : self::STATUS_PASSED,
            'checks' => $checks,
            'promotion_gate' => $promotionGate,
            'claim_policy' => $this->claimPolicy(),
            'evidence_refs' => AiStringListNormalizer::truthyTrimmedScalarValues($simulation['evidence_refs'] ?? $input['evidence_refs'] ?? []),
        ];
        $payload['certification_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        $record = null;
        if (DatabaseTableAvailability::has('atlas_aars_certifications')) {
            $record = AtlasAarsCertification::query()->create($payload);
        }

        return $this->withRecord($payload, 'certification_id', $record?->id);
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        if (! DatabaseTableAvailability::has('atlas_aars_scenarios')) {
            return [
                'schema_version' => self::CONTROL_PLANE_SCHEMA,
                'status' => 'missing',
                'summary' => ['scenarios_total' => 0],
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        $scenarios = $this->window(AtlasAarsScenario::class, 'atlas_aars_scenarios', $since);
        $simulations = $this->window(AtlasAarsSimulation::class, 'atlas_aars_simulations', $since);
        $risks = $this->window(AtlasAarsRiskProjection::class, 'atlas_aars_risk_projections', $since);
        $certifications = $this->window(AtlasAarsCertification::class, 'atlas_aars_certifications', $since);
        $blocked = $risks->where('status', self::STATUS_BLOCKED)->count() + $certifications->where('status', self::STATUS_BLOCKED)->count();
        $highRisk = $risks->whereIn('risk_level', ['high', 'critical'])->count();
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => $blocked > 0 ? self::STATUS_BLOCKED : ($highRisk > 0 ? self::STATUS_WATCH : self::STATUS_READY),
            'window' => ['hours' => max(1, $hours), 'since' => $since->toJSON()],
            'summary' => [
                'scenarios_total' => $scenarios->count(),
                'simulations_total' => $simulations->count(),
                'risk_projections_total' => $risks->count(),
                'certifications_total' => $certifications->count(),
                'blocked' => $blocked,
                'high_risk' => $highRisk,
            ],
            'recent_scenarios' => $scenarios->take(10)->map(fn (AtlasAarsScenario $scenario): array => [
                'scenario_id' => (string) $scenario->id,
                'status' => (string) $scenario->status,
                'domain' => (string) $scenario->domain,
                'flow_id' => (string) $scenario->flow_id,
                'objective_hash' => (string) $scenario->objective_hash,
                'scenario_hash' => (string) $scenario->scenario_hash,
                'created_at' => $scenario->created_at?->toJSON(),
            ])->values()->all(),
            'recent_certifications' => $certifications->take(10)->map(fn (AtlasAarsCertification $certification): array => [
                'certification_id' => (string) $certification->id,
                'status' => (string) $certification->status,
                'certification_hash' => (string) $certification->certification_hash,
                'created_at' => $certification->created_at?->toJSON(),
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($this->hashable($payload));

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'schema_version' => self::CLAIM_POLICY_SCHEMA,
            'external_execution_performed' => false,
            'provider_invoked' => false,
            'benchmark_not_run' => true,
            'simulation_is_not_truth' => true,
            'requires_evidence_refs_for_strong_claim' => true,
            'requires_operator_approval_for_high_risk' => true,
            'requires_aver_before_real_execution' => true,
            'requires_asre_before_strategic_decision' => true,
            'requires_aemor_before_learning_promotion' => true,
            'does_not_replace_dev_forge_or_asre' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function objective(array $input): string
    {
        return trim((string) ($input['objective'] ?? $input['question'] ?? $input['prompt'] ?? 'simulate next best action'));
    }

    private function classifyDomain(string $objective): string
    {
        $text = Str::lower($objective);

        return match (true) {
            str_contains($text, 'codigo') || str_contains($text, 'code') || str_contains($text, 'forge') || str_contains($text, 'dev') => 'programming',
            str_contains($text, 'finance') || str_contains($text, 'invest') || str_contains($text, 'carteira') => 'finance',
            str_contains($text, 'marketing') || str_contains($text, 'campanha') || str_contains($text, 'vendas') => 'marketing',
            str_contains($text, 'estrateg') || str_contains($text, 'empresa') || str_contains($text, 'decis') => 'strategy',
            default => 'general',
        };
    }

    private function flowFor(string $domain): string
    {
        return match ($domain) {
            'programming' => 'atlas_forge',
            'finance' => 'atlas_finance',
            'marketing' => 'atlas_marketing',
            'strategy' => 'atlas_strategy',
            default => 'atlas_conversation',
        };
    }

    private function scenarioType(string $objective, string $domain): string
    {
        $text = Str::lower($objective);

        return match (true) {
            str_contains($text, 'evol') || str_contains($text, 'melhor') => 'system_evolution',
            str_contains($text, 'patch') || str_contains($text, 'implement') || str_contains($text, 'codigo') => 'engineering_change',
            str_contains($text, 'mercado') || str_contains($text, 'venda') || str_contains($text, 'campanha') => 'business_strategy',
            $domain === 'finance' => 'financial_decision',
            default => 'decision_sandbox',
        };
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function worldState(array $input, string $domain, string $flowId): array
    {
        $strategic = $this->sidecarControlPlane($this->strategicReality, AtlasStrategicRealityRuntimeService::class);
        $factory = $this->sidecarControlPlane($this->intelligenceFactory, AtlasIntelligenceFactoryRuntimeService::class);
        $aael = $this->sidecarControlPlane($this->autonomousEvolution, AtlasAutonomousEvolutionLoopService::class);

        return [
            'schema_version' => 'atlas.aars.world_state.v1',
            'domain' => $domain,
            'flow_id' => $flowId,
            'reality_refs' => [
                'asre_status' => is_array($strategic) ? ($strategic['status'] ?? 'available') : 'unavailable',
                'aseif_status' => is_array($factory) ? ($factory['status'] ?? 'available') : 'unavailable',
                'aael_status' => is_array($aael) ? ($aael['status'] ?? 'available') : 'unavailable',
            ],
            'known_constraints' => AiStringListNormalizer::truthyTrimmedScalarValues($input['constraints'] ?? []),
            'operator_supplied_context_hash' => MissionCanonicalHash::sha256($input['context'] ?? []),
        ];
    }

    /**
     * @template T of object
     *
     * @param  T|null  $service
     * @param  class-string<T>  $class
     * @return array<string,mixed>|null
     */
    private function sidecarControlPlane(?object $service, string $class): ?array
    {
        try {
            $runtime = $service ?? app($class);
            if (! method_exists($runtime, 'controlPlane')) {
                return null;
            }

            $payload = $runtime->controlPlane(24);

            return is_array($payload) ? $payload : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function assumptions(array $input, string $objective, string $domain): array
    {
        $items = AiStringListNormalizer::truthyTrimmedScalarValues($input['assumptions'] ?? []);
        if ($items === []) {
            $items = [
                'available evidence is incomplete until validated by runtime receipts',
                'simulation output is a decision aid, not proof of real-world outcome',
                'domain '.$domain.' requires specialist validation before execution',
            ];
        }

        return collect($items)->values()->map(fn (string $statement, int $index): array => [
            'id' => 'assumption_'.($index + 1),
            'statement' => $statement,
            'confidence' => str_contains(Str::lower($objective), 'sem evidencia') ? 'low' : 'medium',
            'invalidators' => ['contradicting evidence', 'failed focused test', 'operator correction'],
        ])->all();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function constraints(array $input, string $objective): array
    {
        return [
            'no_real_execution' => true,
            'no_provider_call' => true,
            'no_benchmark_claim' => true,
            'preserve_user_data' => true,
            'high_risk_requires_operator' => $this->isHighRisk($objective),
            'operator_constraints' => AiStringListNormalizer::truthyTrimmedScalarValues($input['constraints'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function options(string $objective, array $scenario, array $input): array
    {
        $provided = is_array($input['options'] ?? null) ? $input['options'] : [];
        if ($provided !== []) {
            return array_values(array_filter($provided, 'is_array'));
        }

        $domain = (string) ($scenario['domain'] ?? 'general');

        return [
            [
                'option_id' => 'baseline',
                'action' => 'do_nothing_yet',
                'description' => 'Preserve current state and gather stronger evidence.',
                'expected_value' => 0.35,
                'risk' => 0.12,
            ],
            [
                'option_id' => 'sandbox_first',
                'action' => 'simulate_then_execute_with_gates',
                'description' => 'Run controlled sandbox, require evidence, then route to '.($scenario['flow_id'] ?? $this->flowFor($domain)).'.',
                'expected_value' => 0.78,
                'risk' => $this->isHighRisk($objective) ? 0.55 : 0.24,
            ],
            [
                'option_id' => 'operator_review',
                'action' => 'ask_human_for_high_leverage_decision',
                'description' => 'Escalate only the decision summary, risks and required signature to the operator.',
                'expected_value' => 0.62,
                'risk' => 0.18,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @param  array<string,mixed>  $scenario
     * @return array<int,array<string,mixed>>
     */
    private function predictedOutcomes(array $options, array $scenario): array
    {
        return collect($options)->map(function (array $option) use ($scenario): array {
            $expected = (float) ($option['expected_value'] ?? 0.5);
            $risk = (float) ($option['risk'] ?? 0.25);

            return [
                'option_id' => (string) ($option['option_id'] ?? 'option'),
                'outcome' => (string) ($option['action'] ?? 'unknown'),
                'quality_delta' => round($expected - ($risk * 0.35), 3),
                'speed_delta' => (string) ($option['option_id'] ?? '') === 'baseline' ? 'slow' : 'medium',
                'evidence_strength' => ((array) ($scenario['evidence_refs'] ?? [])) === [] ? 'weak' : 'moderate',
                'failure_modes' => $risk > 0.5 ? ['false_confidence', 'insufficient_validation', 'operator_gate_required'] : ['stale_context', 'missed_edge_case'],
            ];
        })->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @param  array<int,array<string,mixed>>  $outcomes
     * @param  array<string,mixed>  $scenario
     * @return array<string,mixed>
     */
    private function impactModel(array $options, array $outcomes, array $scenario): array
    {
        $best = collect($outcomes)->sortByDesc('quality_delta')->first() ?: [];

        return [
            'recommended_option_id' => $best['option_id'] ?? 'baseline',
            'confidence' => ((array) ($scenario['evidence_refs'] ?? [])) === [] ? 0.54 : 0.73,
            'estimated_roi' => round((float) ($best['quality_delta'] ?? 0.4) * 100, 1),
            'blast_radius' => $this->blastRadius((string) ($scenario['scenario_type'] ?? 'decision_sandbox')),
            'option_count' => count($options),
        ];
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function uncertainty(array $scenario, array $input): array
    {
        $evidenceRefs = AiStringListNormalizer::truthyTrimmedScalarValues($scenario['evidence_refs'] ?? $input['evidence_refs'] ?? []);
        $assumptions = is_array($scenario['assumptions'] ?? null) ? $scenario['assumptions'] : [];

        return [
            'context_sufficiency' => $evidenceRefs === [] ? 'insufficient' : 'sufficient_for_simulation',
            'evidence_ref_count' => count($evidenceRefs),
            'assumption_count' => count($assumptions),
            'unknowns' => $evidenceRefs === [] ? ['missing evidence refs', 'no runtime receipts attached'] : ['real-world outcome remains unproven until execution'],
            'must_not_claim' => ['real outcome', 'benchmark superiority', 'external completion'],
        ];
    }

    /**
     * @param  array<string,mixed>  $scenario
     * @param  array<string,mixed>  $uncertainty
     * @return array<int,string>
     */
    private function requiredValidation(array $scenario, array $uncertainty): array
    {
        $validation = ['aars_simulation_receipt', 'counterfactual_receipt', 'risk_projection_receipt'];
        if (($uncertainty['context_sufficiency'] ?? null) === 'insufficient') {
            $validation[] = 'stronger_evidence_retrieval';
        }
        if (($scenario['scenario_type'] ?? null) === 'engineering_change') {
            $validation[] = 'aver_verified_execution_before_real_patch';
        }
        if (($scenario['scenario_type'] ?? null) === 'system_evolution') {
            $validation[] = 'aael_promotion_decision_before_runtime_change';
        }

        return $validation;
    }

    /**
     * @param  array<string,mixed>  $baseline
     * @param  array<int,array<string,mixed>>  $alternatives
     * @return array<int,array<string,mixed>>
     */
    private function deltaAnalysis(array $baseline, array $alternatives): array
    {
        $baselineValue = (float) ($baseline['expected_value'] ?? 0.35);

        return collect($alternatives)->map(fn (array $option): array => [
            'option_id' => (string) ($option['option_id'] ?? 'option'),
            'expected_value_delta' => round((float) ($option['expected_value'] ?? 0.5) - $baselineValue, 3),
            'risk_delta' => round((float) ($option['risk'] ?? 0.25) - (float) ($baseline['risk'] ?? 0.12), 3),
            'dominates_baseline' => ((float) ($option['expected_value'] ?? 0.5) - (float) ($option['risk'] ?? 0.25)) > ($baselineValue - (float) ($baseline['risk'] ?? 0.12)),
        ])->values()->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $deltaAnalysis
     * @param  array<string,mixed>  $simulation
     * @return array<string,mixed>
     */
    private function decisionEffects(array $deltaAnalysis, array $simulation): array
    {
        $dominant = collect($deltaAnalysis)->where('dominates_baseline', true)->values();

        return [
            'dominant_alternatives' => $dominant->pluck('option_id')->all(),
            'recommended_option_id' => data_get($simulation, 'impact_model.recommended_option_id'),
            'if_wrong_then' => ['revert to baseline', 'request operator review', 'rerun simulation with stronger evidence'],
        ];
    }

    /**
     * @param  array<string,mixed>  $simulation
     * @param  array<string,mixed>  $counterfactual
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function risks(array $simulation, array $counterfactual, array $input): array
    {
        $risks = [
            [
                'kind' => 'simulation_error',
                'severity' => data_get($simulation, 'uncertainty.context_sufficiency') === 'insufficient' ? 'high' : 'medium',
                'reason' => 'simulation can miss real-world variables',
            ],
        ];
        if ($this->isHighRisk(json_encode($simulation, JSON_THROW_ON_ERROR))) {
            $risks[] = ['kind' => 'high_risk_domain', 'severity' => 'high', 'reason' => 'objective touches high-risk or irreversible area'];
        }
        if (collect((array) ($counterfactual['delta_analysis'] ?? []))->where('risk_delta', '>', 0.35)->isNotEmpty()) {
            $risks[] = ['kind' => 'counterfactual_risk_delta', 'severity' => 'high', 'reason' => 'alternative raises risk materially above baseline'];
        }
        if ((bool) ($input['force_critical'] ?? false)) {
            $risks[] = ['kind' => 'operator_forced_critical', 'severity' => 'critical', 'reason' => 'test or operator marked scenario critical'];
        }

        return $risks;
    }

    /**
     * @param  array<int,array<string,mixed>>  $risks
     */
    private function riskLevel(array $risks): string
    {
        $severities = collect($risks)->pluck('severity')->all();

        return in_array('critical', $severities, true) ? 'critical' : (in_array('high', $severities, true) ? 'high' : 'medium');
    }

    /**
     * @param  array<int,array<string,mixed>>  $risks
     * @return array<int,string>
     */
    private function mitigations(array $risks): array
    {
        $items = ['rerun with stronger evidence', 'attach receipts', 'start in sandbox only'];
        if ($this->riskLevel($risks) !== 'medium') {
            $items[] = 'require operator approval';
            $items[] = 'route execution through AVER';
        }

        return $items;
    }

    /**
     * @param  array<int,array<string,mixed>>  $risks
     * @return array<string,mixed>
     */
    private function rollbackRequirements(array $risks): array
    {
        return [
            'rollback_required' => true,
            'rollback_depth' => $this->riskLevel($risks) === 'medium' ? 'focused' : 'full',
            'must_preserve_user_changes' => true,
            'requires_action_manifest' => true,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $risks
     * @return array<int,string>
     */
    private function operatorGates(array $risks): array
    {
        return $this->riskLevel($risks) === 'medium' ? [] : ['operator_signature', 'risk_summary_review', 'rollback_plan_review'];
    }

    /**
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $certification
     * @return array<string,mixed>
     */
    private function releaseRecommendation(array $risk, array $certification): array
    {
        $blocked = ($risk['status'] ?? null) === self::STATUS_BLOCKED || ($certification['status'] ?? null) === self::STATUS_BLOCKED;

        return [
            'status' => $blocked ? self::STATUS_BLOCKED : (($risk['risk_level'] ?? null) === 'high' ? 'operator_review_required' : 'sandbox_passed'),
            'next_runtime' => $blocked ? 'operator_review' : (($risk['risk_level'] ?? null) === 'high' ? 'asre_operator_decision' : 'aweos_or_forge_with_aver'),
            'real_execution_allowed' => false,
            'reason' => 'AARS certifies simulated readiness only; real execution requires downstream gates.',
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function hashable(array $payload): array
    {
        unset($payload['created_at'], $payload['updated_at']);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withRecord(array $payload, string $key, mixed $id): array
    {
        $payload[$key] = $id ? (string) $id : null;
        $payload['writes'] = $id !== null;

        return $payload;
    }

    /**
     * @param  array<int,string>  $evidence
     * @return array<string,mixed>
     */
    private function check(string $id, bool $passed, array $evidence, string $severity = 'critical'): array
    {
        return [
            'id' => $id,
            'status' => $passed ? 'pass' : 'fail',
            'severity' => $severity,
            'evidence_refs' => array_values(array_filter($evidence)),
        ];
    }

    /**
     * @param  class-string  $model
     * @return Collection<int,mixed>
     */
    private function window(string $model, string $table, CarbonImmutable $since): Collection
    {
        if (! DatabaseTableAvailability::has($table)) {
            return collect();
        }

        return $model::query()->where('created_at', '>=', $since)->latest()->limit(200)->get();
    }

    private function uuidOrNull(mixed $value): ?string
    {
        $string = AiValueNormalizer::trimmedScalarStringOrNull($value);

        return $string !== null && Str::isUuid($string) ? $string : null;
    }

    private function isHighRisk(string $text): bool
    {
        $lower = Str::lower($text);

        return str_contains($lower, 'pagamento')
            || str_contains($lower, 'payment')
            || str_contains($lower, 'producao')
            || str_contains($lower, 'production')
            || str_contains($lower, 'security')
            || str_contains($lower, 'seguranca')
            || str_contains($lower, 'invest')
            || str_contains($lower, 'delete')
            || str_contains($lower, 'irrevers');
    }

    private function blastRadius(string $scenarioType): string
    {
        return match ($scenarioType) {
            'system_evolution' => 'runtime_wide',
            'engineering_change' => 'codebase_scoped',
            'financial_decision' => 'capital_risk',
            'business_strategy' => 'business_unit',
            default => 'local',
        };
    }
}
