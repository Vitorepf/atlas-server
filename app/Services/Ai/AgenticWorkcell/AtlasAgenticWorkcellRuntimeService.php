<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell;

use App\Models\AtlasAgenticWorkcell;
use App\Models\AtlasAgenticWorkcellEvent;
use App\Models\AtlasAgenticWorkcellOrgPattern;
use App\Models\AtlasAgenticWorkcellOutcome;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellDesignArtifactsSupport;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellRoleContractSupport;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\RuntimeEfficiency\AtlasRuntimeEfficiencyGovernorService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

final class AtlasAgenticWorkcellRuntimeService
{
    public const WORKCELL_SCHEMA = 'atlas.agentic_workcell.v1';

    public const EVENT_SCHEMA = 'atlas.agentic_workcell.event.v1';

    public const OUTCOME_SCHEMA = 'atlas.agentic_workcell.outcome.v1';

    public const ORG_PATTERN_SCHEMA = 'atlas.agentic_workcell.org_pattern.v1';

    public const CONTROL_PLANE_SCHEMA = 'atlas.agentic_workcell.control_plane.v1';

    public const STATUS_READY = 'ready';

    public const STATUS_WATCH = 'watch';

    public const STATUS_BLOCKED = 'blocked';

    public const LEVEL_L1 = 'AAWR-L1 Structured Delegation';

    public const LEVEL_L2 = 'AAWR-L2 Context-Isolated Workcells';

    public const LEVEL_L3 = 'AAWR-L3 Verified Parallel Execution';

    public const LEVEL_L4 = 'AAWR-L4 Adaptive Workcell Intelligence';

    public const LEVEL_L5 = 'AAWR-L5 Organizational Intelligence Engine';

    public function __construct(
        private readonly ?AtlasRuntimeEfficiencyGovernorService $areg = null,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function design(array $input): array
    {
        $objective = AgenticWorkcellTopologyPolicySupport::objective($input);
        $domain = AgenticWorkcellTopologyPolicySupport::normalizeDomain(
            AiValueNormalizer::trimmedScalarStringOrNull($input['domain'] ?? null)
                ?? AgenticWorkcellTopologyPolicySupport::classifyDomain($objective)
        );
        $flowId = AiValueNormalizer::trimmedScalarStringOrNull($input['flow_id'] ?? null)
            ?? AgenticWorkcellTopologyPolicySupport::flowForDomain($domain, $input);
        $surfaceId = AiValueNormalizer::trimmedScalarStringOrNull($input['surface_id'] ?? null) ?? 'atlas_ai';
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValues($input['evidence_refs'] ?? []);
        $contextRefs = AiStringListNormalizer::trimmedScalarValues($input['context_refs'] ?? []);
        $aregDecision = $this->aregDecision($objective, $domain, $flowId, $surfaceId, $evidenceRefs, $contextRefs, $input);
        $complexity = (int) ($aregDecision['complexity_score'] ?? AgenticWorkcellTopologyPolicySupport::complexityScore($objective, $domain));
        $risk = (int) ($aregDecision['risk_score'] ?? AgenticWorkcellTopologyPolicySupport::riskScore($objective, $domain, $evidenceRefs, $input));
        $topology = AgenticWorkcellTopologyPolicySupport::chooseTopology($objective, $domain, $flowId, $complexity, $risk, $input, $aregDecision);
        $status = AgenticWorkcellTopologyPolicySupport::status($topology, $risk, $evidenceRefs, $input);
        $orgDesign = AgenticWorkcellDesignArtifactsSupport::orgDesign($topology, $domain, $flowId, $complexity, $risk, $input);
        $roleRoster = AgenticWorkcellRoleContractSupport::roleRoster($topology, $domain, $flowId, $risk);
        $taskGraph = AgenticWorkcellDesignArtifactsSupport::taskGraph($objective, $topology, $domain, $flowId, $roleRoster, $input);
        $contextPacks = AgenticWorkcellDesignArtifactsSupport::contextPacks($objective, $domain, $flowId, $roleRoster, $contextRefs, $evidenceRefs, $input);
        $executionSchedule = AgenticWorkcellDesignArtifactsSupport::executionSchedule($topology, $roleRoster, $taskGraph, $risk);
        $verificationPlan = AgenticWorkcellDesignArtifactsSupport::verificationPlan($topology, $domain, $flowId, $risk, $roleRoster);
        $evidenceLedger = AgenticWorkcellDesignArtifactsSupport::evidenceLedger($objective, $roleRoster, $taskGraph, $evidenceRefs);
        $workcellAdmission = AgenticWorkcellDesignArtifactsSupport::workcellAdmission($input, $topology, $roleRoster, $risk, $evidenceRefs);
        $memoryPacket = AgenticWorkcellDesignArtifactsSupport::memoryPacket($objective, $domain, $flowId, $topology, $roleRoster);
        $counterfactualReplay = AgenticWorkcellDesignArtifactsSupport::counterfactualReplay($objective, $domain, $flowId, $topology, $complexity, $risk);
        $learningPolicy = AgenticWorkcellDesignArtifactsSupport::learningPolicy($domain, $flowId, $topology, $risk);
        if (($workcellAdmission['status'] ?? null) === 'blocked') {
            $status = self::STATUS_BLOCKED;
        }
        $controlPlaneSummary = AgenticWorkcellDesignArtifactsSupport::controlPlaneSummary($topology, $roleRoster, $taskGraph, $verificationPlan, $status);

        $payload = [
            'schema_version' => self::WORKCELL_SCHEMA,
            'status' => $status,
            'surface_id' => $surfaceId,
            'domain' => $domain,
            'flow_id' => $flowId,
            'topology' => $topology,
            'maturity_level' => self::LEVEL_L5,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'objective' => $objective,
            'areg_decision' => $aregDecision,
            'org_design' => $orgDesign,
            'role_roster' => $roleRoster,
            'task_graph' => $taskGraph,
            'context_packs' => $contextPacks,
            'execution_schedule' => $executionSchedule,
            'verification_plan' => $verificationPlan,
            'evidence_ledger' => $evidenceLedger,
            'workcell_admission' => $workcellAdmission,
            'memory_packet' => $memoryPacket,
            'counterfactual_replay' => $counterfactualReplay,
            'learning_policy' => $learningPolicy,
            'control_plane_summary' => $controlPlaneSummary,
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
        $payload['workcell_hash'] = MissionCanonicalHash::sha256($payload);

        $record = null;
        if (DatabaseTableAvailability::has('atlas_agentic_workcells')) {
            $record = AtlasAgenticWorkcell::query()->create($payload);
        }

        return [
            ...$payload,
            // Cognitive Pressure Layer advisory guards (Atlas Orchestrator Canon). Response-only
            // (never persisted, never in workcell_hash): they schedule no task and own no write
            // scope, so they cannot disturb the counted execution roster.
            'pressure_layer_guards' => AgenticWorkcellDesignArtifactsSupport::pressureLayerAdvisoryRoster($domain, $flowId),
            'workcell_id' => $record?->id,
            'writes' => $record !== null,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function recordEvent(array $input): array
    {
        $workcell = $this->workcell($input['workcell_id'] ?? null);
        if (! $workcell instanceof AtlasAgenticWorkcell) {
            return AgenticWorkcellDesignArtifactsSupport::blocked(self::EVENT_SCHEMA, 'missing_workcell', 'AAWR event requires an existing workcell.');
        }
        $payload = [
            'workcell_id' => $workcell->id,
            'schema_version' => self::EVENT_SCHEMA,
            'event_type' => AiValueNormalizer::trimmedScalarStringOrNull($input['event_type'] ?? null) ?? 'workcell_observation',
            'status' => AiValueNormalizer::trimmedScalarStringOrNull($input['status'] ?? null) ?? 'observed',
            'payload' => AgenticWorkcellTopologyPolicySupport::sanitizePayload(is_array($input['payload'] ?? null) ? $input['payload'] : []),
            'evidence_refs' => AiStringListNormalizer::trimmedScalarValues($input['evidence_refs'] ?? []),
        ];
        $circuitBreaker = AgenticWorkcellTopologyPolicySupport::circuitBreakerReceipt($input['circuit_breaker'] ?? null);
        if ($circuitBreaker !== []) {
            $payload['payload']['circuit_breaker'] = $circuitBreaker;
        }
        $payload['event_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellEvent::query()->create($payload);

        return [
            'schema_version' => self::EVENT_SCHEMA,
            'status' => $payload['status'],
            'event_id' => $record->id,
            'workcell_id' => $workcell->id,
            'event_hash' => $record->event_hash,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function closeOutcome(array $input): array
    {
        $workcell = $this->workcell($input['workcell_id'] ?? null);
        if (! $workcell instanceof AtlasAgenticWorkcell) {
            return AgenticWorkcellDesignArtifactsSupport::blocked(self::OUTCOME_SCHEMA, 'missing_workcell', 'AAWR outcome requires an existing workcell.');
        }
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValues($input['evidence_refs'] ?? []);
        $qualityScore = AgenticWorkcellDesignArtifactsSupport::numericOrNull($input['quality_score'] ?? null);
        $roiScore = AgenticWorkcellDesignArtifactsSupport::numericOrNull($input['coordination_roi_score'] ?? null);
        $signals = is_array($input['signals'] ?? null) ? $input['signals'] : [];
        $circuitBreaker = AgenticWorkcellTopologyPolicySupport::circuitBreakerReceipt($input['circuit_breaker'] ?? null);
        if ($circuitBreaker !== []) {
            $signals['circuit_breaker'] = $circuitBreaker;
        }
        $status = $evidenceRefs === [] ? self::STATUS_BLOCKED : (AiValueNormalizer::trimmedScalarStringOrNull($input['status'] ?? null) ?? self::STATUS_READY);
        $payload = [
            'workcell_id' => $workcell->id,
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $status,
            'quality_score' => $qualityScore,
            'coordination_roi_score' => $roiScore,
            'signals' => $signals,
            'learning_candidates' => AgenticWorkcellDesignArtifactsSupport::learningCandidates(
                AiValueNormalizer::trimmedScalarStringOrNull($workcell->topology),
                $qualityScore,
                $roiScore,
                $signals,
            ),
            'evidence_refs' => $evidenceRefs,
        ];
        $payload['outcome_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellOutcome::query()->create($payload);
        $pattern = $this->compileOrgPattern($workcell, $record);

        return [
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $status,
            'outcome_id' => $record->id,
            'workcell_id' => $workcell->id,
            'outcome_hash' => $record->outcome_hash,
            'learning_candidates' => $payload['learning_candidates'],
            'compiled_org_pattern' => $pattern,
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
    }

    /**
     * Read-only crash reconciliation. Existing receipts are the authority: a
     * restart may resume from the last missing boundary, but never repeats a
     * provider call, integration or role disposition already recorded.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function reconcileIncompleteWorkcell(array $input): array
    {
        $workcell = $this->workcell($input['workcell_id'] ?? null);
        if (! $workcell instanceof AtlasAgenticWorkcell || ! DatabaseTableAvailability::has('atlas_agentic_workcell_events')) {
            return AgenticWorkcellDesignArtifactsSupport::blocked(self::EVENT_SCHEMA, 'missing_workcell_or_event_store', 'Reconciliation requires a persisted workcell and event store.');
        }
        $events = AtlasAgenticWorkcellEvent::query()->where('workcell_id', $workcell->id)->orderBy('created_at')->get();
        $outcomes = DatabaseTableAvailability::has('atlas_agentic_workcell_outcomes')
            ? AtlasAgenticWorkcellOutcome::query()->where('workcell_id', $workcell->id)->orderBy('created_at')->get()
            : collect();
        $counts = $events->groupBy('event_type')->map(fn (Collection $group): int => $group->count())->all();
        $terminal = $events->contains(fn (AtlasAgenticWorkcellEvent $event): bool => in_array((string) $event->status, [self::STATUS_READY, self::STATUS_BLOCKED, 'held', 'refused', 'completed'], true))
            || $outcomes->isNotEmpty();
        $providerCalls = (int) ($counts['provider.called'] ?? 0);
        $integrations = (int) ($counts['integration.completed'] ?? 0);
        $dispositions = (int) ($counts['disposition.recorded'] ?? 0);
        $payload = [
            'schema_version' => 'atlas.agentic_workcell.reconciliation.v1',
            'status' => $terminal ? 'already_terminal' : 'recovery_required',
            'workcell_id' => (string) $workcell->id,
            'event_count' => $events->count(),
            'outcome_count' => $outcomes->count(),
            'last_event_hash' => $events->last()?->event_hash,
            'receipt_counts' => [
                'provider_calls' => $providerCalls,
                'integrations' => $integrations,
                'dispositions' => $dispositions,
            ],
            'replay_safety' => [
                'provider_call_will_be_duplicated' => false,
                'integration_will_be_duplicated' => false,
                'disposition_will_be_duplicated' => false,
                'resume_from_last_receipt_only' => true,
            ],
            'actions' => $terminal ? [] : ['resume_from_last_receipt'],
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
        $payload['reconciliation_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $hasTable = DatabaseTableAvailability::has('atlas_agentic_workcells');
        $workcells = $hasTable
            ? AtlasAgenticWorkcell::query()->where('created_at', '>=', $since)->latest()->limit(200)->get()
            : collect();
        $outcomes = DatabaseTableAvailability::has('atlas_agentic_workcell_outcomes')
            ? AtlasAgenticWorkcellOutcome::query()->where('created_at', '>=', $since)->latest()->limit(100)->get()
            : collect();
        $patterns = DatabaseTableAvailability::has('atlas_agentic_workcell_org_patterns')
            ? AtlasAgenticWorkcellOrgPattern::query()->where('created_at', '>=', $since)->latest()->limit(50)->get()
            : collect();
        $payload = [
            'schema_version' => self::CONTROL_PLANE_SCHEMA,
            'status' => $hasTable ? ($workcells->where('status', self::STATUS_BLOCKED)->isNotEmpty() ? self::STATUS_WATCH : self::STATUS_READY) : 'missing',
            'summary' => [
                'workcells_total' => $workcells->count(),
                'ready' => $workcells->where('status', self::STATUS_READY)->count(),
                'watch' => $workcells->where('status', self::STATUS_WATCH)->count(),
                'blocked' => $workcells->where('status', self::STATUS_BLOCKED)->count(),
                'outcomes_total' => $outcomes->count(),
                'org_patterns_total' => $patterns->count(),
                'average_quality_score' => AgenticWorkcellDesignArtifactsSupport::average($outcomes, 'quality_score'),
                'average_coordination_roi_score' => AgenticWorkcellDesignArtifactsSupport::average($outcomes, 'coordination_roi_score'),
            ],
            'by_topology' => AgenticWorkcellDesignArtifactsSupport::countsBy($workcells, 'topology'),
            'by_flow' => AgenticWorkcellDesignArtifactsSupport::countsBy($workcells, 'flow_id'),
            'recent_workcells' => $workcells->take(20)->map(fn (AtlasAgenticWorkcell $workcell): array => [
                'workcell_id' => (string) $workcell->id,
                'status' => (string) $workcell->status,
                'domain' => $workcell->domain,
                'flow_id' => $workcell->flow_id,
                'topology' => (string) $workcell->topology,
                'maturity_level' => (string) $workcell->maturity_level,
                'objective_hash' => (string) $workcell->objective_hash,
                'workcell_hash' => (string) $workcell->workcell_hash,
                'created_at' => $workcell->created_at?->toJSON(),
            ])->values()->all(),
            'recent_patterns' => $patterns->take(10)->map(fn (AtlasAgenticWorkcellOrgPattern $pattern): array => [
                'pattern_id' => (string) $pattern->id,
                'status' => (string) $pattern->status,
                'flow_id' => $pattern->flow_id,
                'topology' => (string) $pattern->topology,
                'pattern_hash' => (string) $pattern->pattern_hash,
            ])->values()->all(),
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * Thin host facade over pure {@see AgenticWorkcellTopologyPolicySupport::claimPolicy()}.
     *
     * @return array<string,mixed>
     */
    public function claimPolicy(): array
    {
        return AgenticWorkcellTopologyPolicySupport::claimPolicy();
    }

    /**
     * @return array<string,mixed>
     */
    private function aregDecision(string $objective, string $domain, string $flowId, string $surfaceId, array $evidenceRefs, array $contextRefs, array $input): array
    {
        $areg = $this->areg ?? app(AtlasRuntimeEfficiencyGovernorService::class);

        return $areg->govern([
            'prompt' => $objective,
            'domain' => $domain,
            'flow_id' => $flowId,
            'surface_id' => $surfaceId,
            'evidence_refs' => $evidenceRefs,
            'context_refs' => $contextRefs,
            'external_execution_requested' => (bool) data_get($input, 'external_execution_requested', false),
            'source' => 'aawr',
        ]);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function compileOrgPattern(AtlasAgenticWorkcell $workcell, AtlasAgenticWorkcellOutcome $outcome): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_agentic_workcell_org_patterns')) {
            return null;
        }
        $status = ($outcome->quality_score ?? 0) >= 0.70 && ($outcome->coordination_roi_score ?? 0) >= 0.50 ? self::STATUS_READY : self::STATUS_WATCH;
        $payload = [
            'schema_version' => self::ORG_PATTERN_SCHEMA,
            'status' => $status,
            'domain' => $workcell->domain,
            'flow_id' => $workcell->flow_id,
            'topology' => $workcell->topology,
            'pattern' => [
                'org_design' => $workcell->org_design,
                'role_roster' => $workcell->role_roster,
                'execution_schedule' => $workcell->execution_schedule,
                'verification_plan' => $workcell->verification_plan,
            ],
            'quality_stats' => [
                'quality_score' => $outcome->quality_score,
                'coordination_roi_score' => $outcome->coordination_roi_score,
                'outcome_hash' => $outcome->outcome_hash,
            ],
            'evidence_refs' => $outcome->evidence_refs ?? [],
        ];
        $payload['pattern_hash'] = MissionCanonicalHash::sha256($payload);
        $record = AtlasAgenticWorkcellOrgPattern::query()->create($payload);

        return [
            ...$payload,
            'pattern_id' => $record->id,
            'writes' => true,
        ];
    }

    private function workcell(mixed $id): ?AtlasAgenticWorkcell
    {
        $id = AiValueNormalizer::trimmedScalarStringOrNull($id);
        if ($id === null || ! DatabaseTableAvailability::has('atlas_agentic_workcells')) {
            return null;
        }

        return AtlasAgenticWorkcell::query()->find($id);
    }
}
