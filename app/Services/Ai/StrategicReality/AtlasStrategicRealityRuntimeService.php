<?php

declare(strict_types=1);

namespace App\Services\Ai\StrategicReality;

use App\Models\AtlasExecutiveBriefing;
use App\Models\AtlasOpportunitySignal;
use App\Models\AtlasPriorityRanking;
use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Models\AtlasResourceAllocationPlan;
use App\Models\AtlasRiskSignal;
use App\Models\AtlasStrategicAssumption;
use App\Models\AtlasStrategicDecision;
use App\Models\AtlasStrategicSimulation;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class AtlasStrategicRealityRuntimeService
{
    public const ENTITY_SCHEMA = 'atlas.strategic_reality.entity.v1';

    public const RELATIONSHIP_SCHEMA = 'atlas.strategic_reality.relationship.v1';

    public const ASSUMPTION_SCHEMA = 'atlas.strategic_reality.assumption.v1';

    public const OPPORTUNITY_SCHEMA = 'atlas.strategic_reality.opportunity_signal.v1';

    public const RISK_SCHEMA = 'atlas.strategic_reality.risk_signal.v1';

    public const PRIORITY_SCHEMA = 'atlas.strategic_reality.priority_ranking.v1';

    public const RESOURCE_SCHEMA = 'atlas.strategic_reality.resource_allocation.v1';

    public const SIMULATION_SCHEMA = 'atlas.strategic_reality.simulation.v1';

    public const DECISION_SCHEMA = 'atlas.strategic_reality.decision.v1';

    public const BRIEFING_SCHEMA = 'atlas.strategic_reality.executive_briefing.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function scan(array $input): array
    {
        $question = $this->question($input);
        $domain = $this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($question);
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $entities = $this->seedEntities($input, $domain, $evidenceRefs);
        $opportunity = $this->recordOpportunity($question, $domain, $evidenceRefs);
        $risk = $this->recordRisk($question, $evidenceRefs);
        $freshness = $this->freshness($input, $evidenceRefs);

        $payload = [
            'schema_version' => 'atlas.strategic_reality.scan.v1',
            'status' => $freshness['status'] === 'blocked' ? 'blocked' : 'ready',
            'domain' => $domain,
            'reality_scope' => [
                'entity_count' => count($entities),
                'entity_refs' => array_column($entities, 'entity_id'),
                'opportunity_id' => $opportunity['opportunity_id'] ?? null,
                'risk_id' => $risk['risk_id'] ?? null,
            ],
            'freshness' => $freshness,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['scan_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $input): array
    {
        $question = $this->question($input);
        $domain = $this->stringValue($input['domain'] ?? null) ?? $this->classifyDomain($question);
        $evidenceRefs = $this->stringList($input['evidence_refs'] ?? []);
        $freshness = $this->freshness($input, $evidenceRefs);
        $contextSignals = $this->contextSignals($input);
        $entities = $this->seedEntities($input, $domain, $evidenceRefs);
        $opportunity = $this->recordOpportunity($question, $domain, $evidenceRefs);
        $risk = $this->recordRisk($question, $evidenceRefs);
        $assumptions = $this->recordAssumptions($question, $domain, $evidenceRefs);
        $options = $this->options($question, $domain, $risk);
        $ranking = $this->recordPriorityRanking($options, $domain, $evidenceRefs);
        $resources = $this->recordResourceAllocation($ranking, $evidenceRefs);
        $simulation = $this->recordSimulation($options, $risk, $evidenceRefs);
        $recommended = $this->recommend($ranking, $risk, $freshness, $evidenceRefs);
        $status = $recommended['status'];

        $decisionPayload = [
            'schema_version' => self::DECISION_SCHEMA,
            'status' => $status,
            'question_hash' => MissionCanonicalHash::sha256(['question' => $question]),
            'question' => $question,
            'reality_scope' => [
                'domain' => $domain,
                'entity_refs' => array_column($entities, 'entity_id'),
                'priority_ranking_id' => $ranking['ranking_id'] ?? null,
                'resource_allocation_id' => $resources['allocation_id'] ?? null,
                'simulation_id' => $simulation['simulation_id'] ?? null,
                'context_signal_hashes' => array_column($contextSignals, 'signal_hash'),
            ],
            'recommended_action' => $recommended['action'],
            'why_now' => $recommended['why_now'],
            'why_not' => $recommended['why_not'],
            'confidence' => $recommended['confidence'],
            'options' => $options,
            'tradeoffs' => $this->tradeoffs($options, $risk),
            'assumption_refs' => array_column($assumptions, 'assumption_id'),
            'risk_refs' => [$risk['risk_id'] ?? null],
            'opportunity_refs' => [$opportunity['opportunity_id'] ?? null],
            'freshness' => $freshness,
            'context_signals' => $contextSignals,
            'next_actions' => $recommended['next_actions'],
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
        $decisionPayload['decision_hash'] = MissionCanonicalHash::sha256($decisionPayload);

        $decision = null;
        if (DatabaseTableAvailability::has('atlas_strategic_decisions')) {
            $decision = AtlasStrategicDecision::query()->create($decisionPayload);
        }

        $briefing = $this->recordBriefing($decision?->id, $decisionPayload, $opportunity, $risk, $assumptions, $evidenceRefs);

        return [
            'schema_version' => self::DECISION_SCHEMA,
            'status' => $status,
            'decision_id' => $decision?->id,
            'briefing_id' => $briefing['briefing_id'] ?? null,
            'recommended_action' => $recommended['action'],
            'why_now' => $recommended['why_now'],
            'why_not' => $recommended['why_not'],
            'confidence' => $recommended['confidence'],
            'freshness' => $freshness,
            'context_signals' => $contextSignals,
            'assumptions' => $assumptions,
            'risk' => $risk,
            'opportunity' => $opportunity,
            'priority' => $ranking,
            'resource_allocation' => $resources,
            'simulation' => $simulation,
            'next_actions' => $recommended['next_actions'],
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
            'decision_hash' => $decisionPayload['decision_hash'],
            'writes' => $decision !== null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $entities = $this->queryWindow(AtlasRealityEntity::class, 'atlas_reality_entities', $since);
        $decisions = $this->queryWindow(AtlasStrategicDecision::class, 'atlas_strategic_decisions', $since);
        $opportunities = $this->queryWindow(AtlasOpportunitySignal::class, 'atlas_opportunity_signals', $since);
        $risks = $this->queryWindow(AtlasRiskSignal::class, 'atlas_risk_signals', $since);
        $briefings = $this->queryWindow(AtlasExecutiveBriefing::class, 'atlas_executive_briefings', $since);

        $payload = [
            'schema_version' => 'atlas.strategic_reality.control_plane.v1',
            'status' => $decisions->where('status', 'blocked')->isNotEmpty() || $risks->where('severity', 'critical')->isNotEmpty() ? 'watch' : 'ready',
            'summary' => [
                'reality_entities_total' => $entities->count(),
                'strategic_decisions_total' => $decisions->count(),
                'blocked_decisions' => $decisions->where('status', 'blocked')->count(),
                'watch_decisions' => $decisions->where('status', 'watch')->count(),
                'opportunities_total' => $opportunities->count(),
                'risks_total' => $risks->count(),
                'critical_risks' => $risks->where('severity', 'critical')->count(),
                'executive_briefings_total' => $briefings->count(),
            ],
            'recent_decisions' => $decisions->take(20)->map(fn (AtlasStrategicDecision $decision): array => [
                'decision_id' => (string) $decision->id,
                'status' => (string) $decision->status,
                'question_hash' => (string) $decision->question_hash,
                'recommended_action' => Str::limit((string) $decision->recommended_action, 140),
                'confidence' => $decision->confidence,
                'decision_hash' => (string) $decision->decision_hash,
                'created_at' => $decision->created_at?->toJSON(),
            ])->values()->all(),
            'recent_risks' => $risks->take(20)->map(fn (AtlasRiskSignal $risk): array => [
                'risk_id' => (string) $risk->id,
                'risk_type' => (string) $risk->risk_type,
                'severity' => (string) $risk->severity,
                'risk_hash' => (string) $risk->risk_hash,
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return [
            'recommends_only' => true,
            'external_execution_performed' => false,
            'provider_invoked' => false,
            'benchmark_not_run' => true,
            'requires_mission_mode_for_execution' => true,
            'requires_approval_for_external_action' => true,
            'declares_absolute_truth' => false,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     */
    private function question(array $input): string
    {
        return $this->stringValue($input['question'] ?? $input['objective'] ?? $input['prompt'] ?? null) ?? 'Qual e a melhor proxima acao?';
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<int,string>  $evidenceRefs
     * @return array<int,array<string,mixed>>
     */
    private function seedEntities(array $input, string $domain, array $evidenceRefs): array
    {
        $rawEntities = is_array($input['entities'] ?? null) ? $input['entities'] : [
            ['type' => 'operator', 'name' => 'operator'],
            ['type' => 'domain', 'name' => $domain],
            ['type' => 'atlas_layer', 'name' => 'ASRE'],
        ];

        $records = [];
        foreach ($rawEntities as $raw) {
            if (! is_array($raw)) {
                continue;
            }
            $type = $this->stringValue($raw['type'] ?? null) ?? 'reality_item';
            $name = $this->stringValue($raw['name'] ?? null) ?? $type;
            $payload = [
                'schema_version' => self::ENTITY_SCHEMA,
                'status' => 'active',
                'entity_key' => Str::slug($type.'-'.$name),
                'entity_type' => $type,
                'name' => $name,
                'authority_level' => $this->stringValue($raw['authority_level'] ?? null) ?? 'operator_declared',
                'freshness_status' => $this->stringValue($raw['freshness_status'] ?? null) ?? ($evidenceRefs === [] ? 'weak' : 'current'),
                'observed_at' => CarbonImmutable::now(),
                'valid_until' => CarbonImmutable::now()->addDays(14),
                'attributes' => is_array($raw['attributes'] ?? null) ? $raw['attributes'] : [],
                'evidence_refs' => $this->stringList($raw['evidence_refs'] ?? $evidenceRefs),
                'source_refs' => $this->stringList($raw['source_refs'] ?? []),
            ];
            $payload['entity_hash'] = MissionCanonicalHash::sha256($payload);
            $record = null;
            if (DatabaseTableAvailability::has('atlas_reality_entities')) {
                $record = AtlasRealityEntity::query()->updateOrCreate(['entity_key' => $payload['entity_key']], $payload);
            }
            $records[] = [
                'schema_version' => self::ENTITY_SCHEMA,
                'entity_id' => $record?->id,
                'entity_key' => $payload['entity_key'],
                'entity_type' => $type,
                'entity_hash' => $payload['entity_hash'],
            ];
        }

        $this->recordRelationship($records[0]['entity_id'] ?? null, $records[1]['entity_id'] ?? null, 'operates_in', $evidenceRefs);

        return $records;
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     */
    private function recordRelationship(?string $sourceId, ?string $targetId, string $type, array $evidenceRefs): void
    {
        if (! DatabaseTableAvailability::has('atlas_reality_relationships')) {
            return;
        }
        $payload = [
            'schema_version' => self::RELATIONSHIP_SCHEMA,
            'status' => 'active',
            'source_entity_id' => $sourceId,
            'target_entity_id' => $targetId,
            'relationship_type' => $type,
            'weight' => 1,
            'attributes' => ['source' => 'asre_runtime'],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['relationship_hash'] = MissionCanonicalHash::sha256($payload);
        AtlasRealityRelationship::query()->create($payload);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<int,array<string,mixed>>
     */
    private function contextSignals(array $input): array
    {
        $candidates = [
            'persistent_context' => [
                'schema_version' => $this->stringValue(data_get($input, 'context_signals.persistent_context.schema_version') ?? data_get($input, 'persistent_context.schema_version')),
                'status' => $this->stringValue(data_get($input, 'context_signals.persistent_context.status') ?? data_get($input, 'persistent_context.status')),
                'hash' => $this->stringValue(data_get($input, 'context_signals.persistent_context.persistent_context_hash') ?? data_get($input, 'persistent_context.persistent_context_hash')),
            ],
            'aemor' => [
                'schema_version' => $this->stringValue(data_get($input, 'context_signals.aemor.schema_version') ?? data_get($input, 'aemor_episode.schema_version')),
                'status' => $this->stringValue(data_get($input, 'context_signals.aemor.status') ?? data_get($input, 'aemor_episode.status')),
                'hash' => $this->stringValue(data_get($input, 'context_signals.aemor.episode_hash') ?? data_get($input, 'aemor_episode.episode_hash')),
            ],
            'intelligence_factory' => [
                'schema_version' => $this->stringValue(data_get($input, 'context_signals.intelligence_factory.schema_version') ?? data_get($input, 'intelligence_factory.schema_version')),
                'status' => $this->stringValue(data_get($input, 'context_signals.intelligence_factory.status') ?? data_get($input, 'intelligence_factory.status')),
                'hash' => $this->stringValue(data_get($input, 'context_signals.intelligence_factory.advice_hash') ?? data_get($input, 'intelligence_factory.advice_hash')),
            ],
            'context_operations' => [
                'schema_version' => $this->stringValue(data_get($input, 'context_signals.context_operations.schema_version') ?? data_get($input, 'context_operations.schema_version')),
                'status' => $this->stringValue(data_get($input, 'context_signals.context_operations.status') ?? data_get($input, 'context_operations.status')),
                'hash' => $this->stringValue(data_get($input, 'context_signals.context_operations.context_operations_hash') ?? data_get($input, 'context_operations.context_operations_hash')),
            ],
        ];

        $signals = [];
        foreach ($candidates as $source => $candidate) {
            if (($candidate['schema_version'] ?? null) === null
                && ($candidate['status'] ?? null) === null
                && ($candidate['hash'] ?? null) === null) {
                continue;
            }

            $signal = [
                'source' => $source,
                'schema_version' => $candidate['schema_version'] ?? null,
                'status' => $candidate['status'] ?? 'unknown',
                'hash' => $candidate['hash'] ?? null,
            ];
            $signal['signal_hash'] = MissionCanonicalHash::sha256($signal);
            $signals[] = $signal;
        }

        return $signals;
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordOpportunity(string $question, string $domain, array $evidenceRefs): array
    {
        $type = str_contains(Str::lower($question), 'capability') || str_contains(Str::lower($question), 'melhor') ? 'capability_leverage' : 'focus_leverage';
        $payload = [
            'schema_version' => self::OPPORTUNITY_SCHEMA,
            'status' => 'candidate',
            'opportunity_type' => $type,
            'domain' => $domain,
            'summary' => 'Priorizar a acao com maior alavancagem estrategica no escopo atual.',
            'leverage_score' => $domain === 'programming' ? 8 : 7,
            'evidence_refs' => $evidenceRefs,
            'metadata' => ['question_hash' => MissionCanonicalHash::sha256(['question' => $question])],
        ];
        $payload['opportunity_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_opportunity_signals') ? AtlasOpportunitySignal::query()->create($payload) : null;

        return [
            'schema_version' => self::OPPORTUNITY_SCHEMA,
            'opportunity_id' => $record?->id,
            'opportunity_type' => $type,
            'leverage_score' => $payload['leverage_score'],
            'opportunity_hash' => $payload['opportunity_hash'],
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordRisk(string $question, array $evidenceRefs): array
    {
        $text = Str::lower($question);
        $critical = str_contains($text, 'comprar') || str_contains($text, 'vender') || str_contains($text, 'trade') || str_contains($text, 'pagamento');
        $stale = $evidenceRefs === [];
        $payload = [
            'schema_version' => self::RISK_SCHEMA,
            'status' => $critical || $stale ? 'open' : 'monitored',
            'risk_type' => $critical ? 'external_or_financial_action' : ($stale ? 'weak_evidence' : 'priority_error'),
            'severity' => $critical ? 'critical' : ($stale ? 'high' : 'medium'),
            'summary' => $critical ? 'Acao externa/financeira exige governanca antes de qualquer execucao.' : ($stale ? 'Decisao estrategica sem evidencia suficiente.' : 'Risco de priorizacao incorreta se assumptions mudarem.'),
            'mitigations' => $critical ? ['operator_approval', 'second_review', 'mission_mode', 'rollback_plan'] : ['evidence_refs_required', 'review_assumptions'],
            'evidence_refs' => $evidenceRefs,
            'metadata' => ['question_hash' => MissionCanonicalHash::sha256(['question' => $question])],
        ];
        $payload['risk_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_risk_signals') ? AtlasRiskSignal::query()->create($payload) : null;

        return [
            'schema_version' => self::RISK_SCHEMA,
            'risk_id' => $record?->id,
            'risk_type' => $payload['risk_type'],
            'severity' => $payload['severity'],
            'mitigations' => $payload['mitigations'],
            'risk_hash' => $payload['risk_hash'],
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<int,array<string,mixed>>
     */
    private function recordAssumptions(string $question, string $domain, array $evidenceRefs): array
    {
        $items = [
            'O operador quer maximizar alavancagem real, nao apenas velocidade local.',
            'A decisao deve respeitar o estado atual do dominio '.$domain.'.',
            'Sem evidencia fresca, a recomendacao deve ser reduzida para watch/blocked.',
        ];
        $records = [];
        foreach ($items as $statement) {
            $payload = [
                'schema_version' => self::ASSUMPTION_SCHEMA,
                'status' => $evidenceRefs === [] ? 'weak' : 'active',
                'scope_type' => 'strategic_reality',
                'scope_id' => MissionCanonicalHash::sha256(['question' => $question]),
                'statement' => $statement,
                'confidence' => $evidenceRefs === [] ? 'low' : 'medium',
                'invalidators' => ['new_evidence_contradicts_assumption', 'operator_changes_goal', 'freshness_gate_blocks_context'],
                'evidence_refs' => $evidenceRefs,
                'review_at' => CarbonImmutable::now()->addDays(7),
            ];
            $payload['assumption_hash'] = MissionCanonicalHash::sha256($payload);
            $record = DatabaseTableAvailability::has('atlas_strategic_assumptions') ? AtlasStrategicAssumption::query()->create($payload) : null;
            $records[] = [
                'schema_version' => self::ASSUMPTION_SCHEMA,
                'assumption_id' => $record?->id,
                'status' => $payload['status'],
                'statement' => $statement,
                'assumption_hash' => $payload['assumption_hash'],
            ];
        }

        return $records;
    }

    /**
     * @param  array<string,mixed>  $risk
     * @return array<int,array<string,mixed>>
     */
    private function options(string $question, string $domain, array $risk): array
    {
        return [
            [
                'id' => 'focus_highest_leverage_internal',
                'label' => 'Fechar a maior alavanca interna antes de abrir nova frente.',
                'domain' => $domain,
                'roi' => 8,
                'risk' => 3,
                'reversibility' => 8,
                'time_to_value' => 7,
            ],
            [
                'id' => 'collect_fresh_evidence',
                'label' => 'Buscar evidencias frescas antes de decidir.',
                'domain' => $domain,
                'roi' => 6,
                'risk' => 2,
                'reversibility' => 9,
                'time_to_value' => 5,
            ],
            [
                'id' => 'external_action_after_approval',
                'label' => 'Promover para Mission Mode com approval antes de acao externa.',
                'domain' => $domain,
                'roi' => ($risk['severity'] ?? null) === 'critical' ? 7 : 4,
                'risk' => ($risk['severity'] ?? null) === 'critical' ? 9 : 6,
                'reversibility' => 4,
                'time_to_value' => 4,
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordPriorityRanking(array $options, string $domain, array $evidenceRefs): array
    {
        $criteria = [
            'roi_weight' => 0.35,
            'risk_weight' => 0.25,
            'reversibility_weight' => 0.20,
            'time_to_value_weight' => 0.20,
        ];
        $ranked = collect($options)
            ->map(function (array $option) use ($criteria): array {
                $score = ((int) $option['roi'] * $criteria['roi_weight'])
                    - ((int) $option['risk'] * $criteria['risk_weight'])
                    + ((int) $option['reversibility'] * $criteria['reversibility_weight'])
                    + ((int) $option['time_to_value'] * $criteria['time_to_value_weight']);

                return $option + ['score' => round($score, 2)];
            })
            ->sortByDesc('score')
            ->values()
            ->all();
        $payload = [
            'schema_version' => self::PRIORITY_SCHEMA,
            'status' => 'ready',
            'scope_type' => 'domain',
            'scope_id' => $domain,
            'criteria' => $criteria,
            'ranked_options' => $ranked,
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['ranking_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_priority_rankings') ? AtlasPriorityRanking::query()->create($payload) : null;

        return [
            'schema_version' => self::PRIORITY_SCHEMA,
            'ranking_id' => $record?->id,
            'top_option' => $ranked[0] ?? null,
            'ranking_hash' => $payload['ranking_hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $ranking
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordResourceAllocation(array $ranking, array $evidenceRefs): array
    {
        $payload = [
            'schema_version' => self::RESOURCE_SCHEMA,
            'status' => 'ready',
            'resources' => [
                'operator_attention' => 'limited',
                'agent_time' => 'available_after_scope',
                'external_execution' => 'approval_required',
            ],
            'allocation' => [
                'primary_focus' => data_get($ranking, 'top_option.id'),
                'operator_review' => 'required_for_external_action',
                'agent_allocation' => 'use_subagents_only_with_context_pack_and_return_audit',
            ],
            'constraints' => ['no_benchmark', 'no_external_execution_without_approval', 'evidence_refs_required'],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['allocation_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_resource_allocation_plans') ? AtlasResourceAllocationPlan::query()->create($payload) : null;

        return [
            'schema_version' => self::RESOURCE_SCHEMA,
            'allocation_id' => $record?->id,
            'allocation_hash' => $payload['allocation_hash'],
            'primary_focus' => data_get($payload, 'allocation.primary_focus'),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @param  array<string,mixed>  $risk
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordSimulation(array $options, array $risk, array $evidenceRefs): array
    {
        $payload = [
            'schema_version' => self::SIMULATION_SCHEMA,
            'status' => ($risk['severity'] ?? null) === 'critical' ? 'watch' : 'passed',
            'mode' => 'dry_run',
            'options' => $options,
            'predicted_outcomes' => [
                'focus_highest_leverage_internal' => 'reduces_context_switching_and_improves_compounding',
                'collect_fresh_evidence' => 'improves_decision_quality_when_context_is_weak',
                'external_action_after_approval' => 'keeps_irreversible_actions_governed',
            ],
            'risks' => [$risk],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['simulation_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_strategic_simulations') ? AtlasStrategicSimulation::query()->create($payload) : null;

        return [
            'schema_version' => self::SIMULATION_SCHEMA,
            'simulation_id' => $record?->id,
            'status' => $payload['status'],
            'simulation_hash' => $payload['simulation_hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $ranking
     * @param  array<string,mixed>  $risk
     * @param  array<string,mixed>  $freshness
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recommend(array $ranking, array $risk, array $freshness, array $evidenceRefs): array
    {
        if (($risk['severity'] ?? null) === 'critical') {
            return [
                'status' => 'blocked',
                'action' => 'Nao executar. Criar Mission Mode com approval, second review, rollback e evidence pack antes de qualquer acao externa.',
                'why_now' => 'O pedido envolve acao externa, financeira ou irreversivel.',
                'why_not' => 'Executar diretamente quebraria a governanca do Atlas.',
                'confidence' => 0.86,
                'next_actions' => ['create_mission_mode_packet', 'request_operator_approval', 'define_rollback_plan'],
            ];
        }
        if (($freshness['status'] ?? null) === 'blocked' || $evidenceRefs === []) {
            return [
                'status' => 'watch',
                'action' => 'Coletar evidencia fresca antes de transformar a recomendacao em execucao.',
                'why_now' => 'A decisao depende de realidade atual e o contexto ainda esta fraco.',
                'why_not' => 'A falta de evidence refs aumenta chance de decisao errada.',
                'confidence' => 0.55,
                'next_actions' => ['run_reality_scan', 'attach_evidence_refs', 'rerun_asre_decide'],
            ];
        }

        return [
            'status' => 'ready',
            'action' => (string) data_get($ranking, 'top_option.label', 'Executar a maior alavanca interna com evidencia.'),
            'why_now' => 'A opcao ranqueada combina alavancagem, baixo risco relativo e reversibilidade.',
            'why_not' => 'Abrir nova frente sem fechar a alavanca principal aumenta WIP e reduz compounding.',
            'confidence' => 0.78,
            'next_actions' => ['create_mission_or_work_order', 'bind_evidence_refs', 'record_outcome_in_aemor'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $options
     * @param  array<string,mixed>  $risk
     * @return array<int,array<string,mixed>>
     */
    private function tradeoffs(array $options, array $risk): array
    {
        return collect($options)->map(fn (array $option): array => [
            'option_id' => $option['id'] ?? 'unknown',
            'upside' => 'strategic_leverage',
            'downside' => ($risk['severity'] ?? null) === 'critical' ? 'requires_governed_approval' : 'opportunity_cost',
        ])->values()->all();
    }

    /**
     * @param  array<string,mixed>  $decisionPayload
     * @param  array<string,mixed>  $opportunity
     * @param  array<string,mixed>  $risk
     * @param  array<int,array<string,mixed>>  $assumptions
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function recordBriefing(?string $decisionId, array $decisionPayload, array $opportunity, array $risk, array $assumptions, array $evidenceRefs): array
    {
        $payload = [
            'strategic_decision_id' => $decisionId,
            'schema_version' => self::BRIEFING_SCHEMA,
            'status' => $decisionPayload['status'],
            'briefing_type' => 'next_best_action',
            'sections' => [
                'answer' => $decisionPayload['recommended_action'],
                'why_now' => $decisionPayload['why_now'],
                'why_not' => $decisionPayload['why_not'],
                'opportunity' => $opportunity,
                'risk' => $risk,
                'assumptions' => $assumptions,
                'freshness' => $decisionPayload['freshness'],
                'next_actions' => $decisionPayload['next_actions'],
            ],
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['briefing_hash'] = MissionCanonicalHash::sha256($payload);
        $record = DatabaseTableAvailability::has('atlas_executive_briefings') ? AtlasExecutiveBriefing::query()->create($payload) : null;

        return [
            'schema_version' => self::BRIEFING_SCHEMA,
            'briefing_id' => $record?->id,
            'status' => $payload['status'],
            'briefing_hash' => $payload['briefing_hash'],
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function freshness(array $input, array $evidenceRefs): array
    {
        $observedAt = $this->stringValue($input['observed_at'] ?? null);
        $stale = (bool) ($input['stale'] ?? false);
        $status = $stale || $evidenceRefs === [] ? 'blocked' : 'current';

        return [
            'schema_version' => 'atlas.strategic_reality.freshness.v1',
            'status' => $status,
            'observed_at' => $observedAt ?? CarbonImmutable::now()->toJSON(),
            'valid_until' => CarbonImmutable::now()->addDays(7)->toJSON(),
            'missing_required_sources' => $evidenceRefs === [] ? ['evidence_refs'] : [],
            'reason' => $status === 'blocked' ? 'fresh_evidence_required' : 'evidence_refs_present',
        ];
    }

    private function classifyDomain(string $question): string
    {
        $text = Str::lower($question);
        if (str_contains($text, 'codigo') || str_contains($text, 'program') || str_contains($text, 'forge') || str_contains($text, 'dev')) {
            return 'programming';
        }
        if (str_contains($text, 'finance') || str_contains($text, 'carteira') || str_contains($text, 'trade')) {
            return 'finance';
        }
        if (str_contains($text, 'marketing') || str_contains($text, 'campanha') || str_contains($text, 'venda')) {
            return 'marketing';
        }

        return 'strategy';
    }

    /**
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  class-string<T>  $model
     * @return Collection<int,T>
     */
    private function queryWindow(string $model, string $table, CarbonImmutable $since): Collection
    {
        if (! DatabaseTableAvailability::has($table)) {
            return collect();
        }

        return $model::query()->where('created_at', '>=', $since)->latest()->limit(200)->get();
    }

    private function stringValue(mixed $value): ?string
    {
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): ?string => $this->stringValue($item),
            $value
        ))));
    }
}
