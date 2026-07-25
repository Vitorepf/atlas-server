<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell;

use App\Models\AtlasAgenticWorkcell;
use App\Models\AtlasAgenticWorkcellEvent;
use App\Models\AtlasAgenticWorkcellOrgPattern;
use App\Models\AtlasAgenticWorkcellOutcome;
use App\Services\Ai\AgenticWorkcell\Support\AgenticWorkcellTopologyPolicySupport;
use App\Services\Ai\EngineeringKernel\PressureLayerGuards;
use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;
use App\Services\Ai\EngineeringKernel\ExecutionOrder;
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
        $orgDesign = $this->orgDesign($topology, $domain, $flowId, $complexity, $risk, $input);
        $roleRoster = $this->roleRoster($topology, $domain, $flowId, $risk, $input);
        $taskGraph = $this->taskGraph($objective, $topology, $domain, $flowId, $roleRoster, $input);
        $contextPacks = $this->contextPacks($objective, $domain, $flowId, $roleRoster, $contextRefs, $evidenceRefs, $input);
        $executionSchedule = $this->executionSchedule($topology, $roleRoster, $taskGraph, $risk);
        $verificationPlan = $this->verificationPlan($topology, $domain, $flowId, $risk, $roleRoster);
        $evidenceLedger = $this->evidenceLedger($objective, $roleRoster, $taskGraph, $evidenceRefs);
        $workcellAdmission = $this->workcellAdmission($input, $topology, $roleRoster, $risk, $evidenceRefs);
        $memoryPacket = $this->memoryPacket($objective, $domain, $flowId, $topology, $roleRoster);
        $counterfactualReplay = $this->counterfactualReplay($objective, $domain, $flowId, $topology, $complexity, $risk);
        $learningPolicy = $this->learningPolicy($domain, $flowId, $topology, $risk);
        if (($workcellAdmission['status'] ?? null) === 'blocked') {
            $status = self::STATUS_BLOCKED;
        }
        $controlPlaneSummary = $this->controlPlaneSummary($topology, $roleRoster, $taskGraph, $verificationPlan, $status);

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
            'pressure_layer_guards' => $this->pressureLayerAdvisoryRoster($domain, $flowId),
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
            return $this->blocked(self::EVENT_SCHEMA, 'missing_workcell', 'AAWR event requires an existing workcell.');
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
            return $this->blocked(self::OUTCOME_SCHEMA, 'missing_workcell', 'AAWR outcome requires an existing workcell.');
        }
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValues($input['evidence_refs'] ?? []);
        $qualityScore = $this->numericOrNull($input['quality_score'] ?? null);
        $roiScore = $this->numericOrNull($input['coordination_roi_score'] ?? null);
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
            'learning_candidates' => $this->learningCandidates($workcell, $qualityScore, $roiScore, $signals),
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
            return $this->blocked(self::EVENT_SCHEMA, 'missing_workcell_or_event_store', 'Reconciliation requires a persisted workcell and event store.');
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
                'average_quality_score' => $this->average($outcomes, 'quality_score'),
                'average_coordination_roi_score' => $this->average($outcomes, 'coordination_roi_score'),
            ],
            'by_topology' => $this->countsBy($workcells, 'topology'),
            'by_flow' => $this->countsBy($workcells, 'flow_id'),
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
     * A workcell may be designed without an order for legacy planning callers, but when an
     * ExecutionOrder is supplied it becomes the authority for hashes, risk, scope and evidence.
     * This method is pure and only emits a receipt-shaped admission plan; it never executes.
     *
     * @return array<string,mixed>
     */
    private function workcellAdmission(array $input, string $topology, array $roles, int $risk, array $evidenceRefs): array
    {
        $requestedTopology = AiValueNormalizer::trimmedScalarStringOrNull($input['topology'] ?? null);
        if ($requestedTopology !== null && ! in_array($requestedTopology, AgenticWorkcellTopologyPolicySupport::TOPOLOGIES, true)) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'blocked',
                'reason' => 'unsupported_workcell_topology',
                'requested_topology' => $requestedTopology,
            ];
        }
        $rawOrder = $input['execution_order'] ?? null;
        if ($rawOrder === null) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'legacy_planning_only',
                'reason' => 'execution_order_not_supplied',
                'requires_execution_order_before_execution' => true,
            ];
        }
        if (! is_array($rawOrder)) {
            return ['schema_version' => 'atlas.agentic_workcell.admission.v1', 'status' => 'blocked', 'reason' => 'execution_order_invalid'];
        }

        try {
            $order = ExecutionOrder::fromArray($rawOrder);
        } catch (\Throwable $exception) {
            return [
                'schema_version' => 'atlas.agentic_workcell.admission.v1',
                'status' => 'blocked',
                'reason' => 'execution_order_rejected',
                'detail' => $exception->getMessage(),
            ];
        }

        $orderRoleIds = array_keys($order->roleRoster);
        $workcellRoleIds = array_values(array_map(static fn (array $role): string => (string) $role['role_id'], $roles));
        $blockers = [];
        $mappedTopology = AgenticWorkcellTopologyPolicySupport::executionOrderTopologyMap($order->workTopology);
        if ($mappedTopology !== $topology) {
            $blockers[] = 'execution_order_topology_mismatch';
        }
        if ($orderRoleIds !== EngineeringRoleRoster::OFFICIAL_ROLES || $workcellRoleIds !== EngineeringRoleRoster::OFFICIAL_ROLES) {
            $blockers[] = 'official_22_role_roster_required';
        }
        if ($order->roleRoster !== [] && count(array_unique($orderRoleIds)) !== count($orderRoleIds)) {
            $blockers[] = 'role_ownership_overlap';
        }
        if ($order->allowedScope === []) {
            $blockers[] = 'allowed_scope_required';
        }
        if ($order->authorityEnvelope['kind'] === '') {
            $blockers[] = 'authority_required';
        }
        if ($order->evidencePolicy === [] || $order->evidencePolicy['acceptance_event_id'] === '') {
            $blockers[] = 'evidence_policy_required';
        }
        $identities = [];
        foreach ($order->roleRoster as $entry) {
            foreach (['builder_id', 'verifier_id', 'final_certifier_id'] as $identityKey) {
                $identity = trim((string) ($entry[$identityKey] ?? ''));
                if ($identity !== '') $identities[$identityKey][] = $identity;
            }
        }
        $identityValues = [];
        foreach ($identities as $values) {
            $identityValues = [...$identityValues, ...$values];
        }
        if ($identityValues !== [] && count(array_unique($identityValues)) !== count($identityValues)) {
            $blockers[] = 'role_witness_identity_overlap';
        }
        $suppliedSandboxes = is_array($input['candidate_sandboxes'] ?? null) ? $input['candidate_sandboxes'] : [];
        $sandboxRefs = array_values(array_filter(array_map(static fn (mixed $sandbox): string => is_array($sandbox) ? trim((string) ($sandbox['sandbox_ref'] ?? '')) : '', $suppliedSandboxes)));
        if ($sandboxRefs !== [] && count(array_unique($sandboxRefs)) !== count($sandboxRefs)) {
            $blockers[] = 'candidate_sandbox_shared';
        }
        if (array_key_exists('mode', $input) && (string) $input['mode'] !== $order->mode) {
            $blockers[] = 'mode_quality_bar_mismatch';
        }
        $candidateApproaches = array_values(array_unique(array_filter(array_map(
            static fn (mixed $approach): string => is_array($approach)
                ? trim((string) ($approach['approach_id'] ?? $approach['id'] ?? ''))
                : trim((string) $approach),
            is_array($input['candidate_approaches'] ?? null) ? $input['candidate_approaches'] : [],
        ))));
        $verifierFamilies = array_values(array_unique(array_filter(array_map(
            static fn (mixed $family): string => is_array($family)
                ? trim((string) ($family['family_id'] ?? $family['id'] ?? ''))
                : trim((string) $family),
            is_array($input['verifier_families'] ?? null) ? $input['verifier_families'] : [],
        ))));
        $r5CompetitionRequired = $order->riskClass === 'R5';
        if ($r5CompetitionRequired && $order->workTopology !== 'candidate_set') {
            $blockers[] = 'r5_candidate_set_topology_required';
        }
        if ($r5CompetitionRequired && count($candidateApproaches) < 2) {
            $blockers[] = 'r5_competing_approaches_required';
        }
        if ($r5CompetitionRequired && count($verifierFamilies) < 2) {
            $blockers[] = 'r5_distinct_verifier_families_required';
        }
        if ($evidenceRefs === [] && $order->riskClass !== 'R0') {
            $blockers[] = 'initial_evidence_refs_required';
        }

        $candidateCount = in_array($topology, ['parallel_scouts', 'tournament', 'red_blue_team', 'mapreduce_research', 'forge_milestone_crew'], true) ? 3 : 1;
        $sandboxes = array_map(static fn (int $index): array => [
            'candidate_id' => 'candidate-'.$index,
            'sandbox_ref' => 'isolated:'.$order->deliveryId.':candidate-'.$index,
            'owner' => 'candidate-'.$index,
            'integration' => 'serial_only',
        ], range(1, $candidateCount));

        return [
            'schema_version' => 'atlas.agentic_workcell.admission.v1',
            'status' => $blockers === [] ? 'admitted' : 'blocked',
            'blockers' => array_values(array_unique($blockers)),
            'execution_order_hash' => $order->canonicalHash(),
            'product_intent_verdict_hash' => $order->productIntentVerdictHash,
            'spec_hash' => $order->specHash,
            'world_model_snapshot_hash' => $order->worldModelSnapshotHash,
            'risk_class' => $order->riskClass,
            'required_depth' => EngineeringRoleRoster::depthProfile($order->riskClass),
            'execution_order_topology' => $order->workTopology,
            'aawr_topology' => $mappedTopology,
            'evidence_policy' => $order->evidencePolicy,
            'authority_kind' => $order->authorityEnvelope['kind'],
            'allowed_scope' => $order->allowedScope,
            'forbidden_scope' => $order->forbiddenScope,
            'candidate_sandboxes' => $sandboxes,
            'integration_lane' => ['mode' => 'serial', 'protected' => true],
            'candidate_competition' => [
                'required' => $r5CompetitionRequired,
                'status' => $r5CompetitionRequired && count($candidateApproaches) >= 2 && count($verifierFamilies) >= 2 ? 'configured' : ($r5CompetitionRequired ? 'blocked' : 'not_required'),
                'approaches' => $candidateApproaches,
                'verifier_families' => $verifierFamilies,
                'independent_verifier_families' => count($verifierFamilies) >= 2,
                'candidates' => $r5CompetitionRequired
                    ? array_map(static fn (int $index): array => [
                        'candidate_id' => 'candidate-'.$index,
                        'approach_id' => $candidateApproaches[$index - 1] ?? $candidateApproaches[($index - 1) % max(1, count($candidateApproaches))] ?? null,
                        'verifier_family' => $verifierFamilies[$index - 1] ?? $verifierFamilies[($index - 1) % max(1, count($verifierFamilies))] ?? null,
                    ], range(1, max(2, min($candidateCount, count($candidateApproaches)))))
                    : [],
            ],
            'judge_context' => ['includes' => ['frozen_spec', 'candidate_artifact', 'independent_evidence'], 'excludes' => ['author_defense']],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function orgDesign(string $topology, string $domain, string $flowId, int $complexity, int $risk, array $input): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.org_design.v1',
            'topology' => $topology,
            'domain' => $domain,
            'flow_id' => $flowId,
            'complexity_score' => $complexity,
            'risk_score' => $risk,
            'organizational_principles' => [
                'minimal_context_per_role',
                'explicit_ownership_boundaries',
                'parallelism_only_when_non_overlapping',
                'independent_verification_required',
                'evidence_before_completion',
                'outcome_learning_after_close',
            ],
            'operator_review_required' => $risk >= 8 || in_array($domain, ['finance', 'strategy'], true),
            'topology_reason' => 'selected_by_complexity_risk_domain_and_areg_budget',
            'org_design_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $complexity, $risk, $input['source'] ?? null]),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function roleRoster(string $topology, string $domain, string $flowId, int $risk, array $input): array
    {
        $riskBand = AgenticWorkcellTopologyPolicySupport::riskBand($risk);
        $topologyAssignments = $this->topologyAssignments($topology);

        return collect(EngineeringRoleRoster::OFFICIAL_ROLES)
            ->map(function (string $role, int $index) use ($domain, $flowId, $risk, $riskBand, $topologyAssignments): array {
                return [
                    'role_id' => $role,
                    'agent_index' => $index + 1,
                    'membership_source' => 'EngineeringRoleRoster::OFFICIAL_ROLES',
                    'risk_band' => $riskBand,
                    'depth' => $this->depthProfile($riskBand, $role),
                    'topology_assignment' => $topologyAssignments[$role] ?? 'supporting_review',
                    'independent_context' => in_array($role, ['qa_testing', 'evidence_audit', 'final_certification'], true),
                    'domain' => $domain,
                    'flow_id' => $flowId,
                    'context_scope' => $this->roleContextScope($role),
                    'output_contract' => $this->roleOutputContract($role),
                    'tool_boundary' => $this->roleToolBoundary($role, $risk),
                    'forbidden_actions' => ['spawn_provider_directly', 'mutate_files_outside_ownership', 'declare_completion_without_evidence'],
                ];
            })
            ->values()
            ->all();
    }

    private function depthProfile(string $riskBand, string $role): string
    {
        if ($riskBand === 'R0') return 'minimal_evidence';
        if ($riskBand === 'R1') return 'light_independent_review';
        if ($riskBand === 'R2') return 'standard_contract_integration';
        if ($riskBand === 'R3') return 'multi_verifier_regression_compatibility';
        if ($riskBand === 'R4') return in_array($role, ['appsec_privacy', 'performance_resilience', 'devops_sre', 'evidence_audit'], true)
            ? 'security_mutation_property_chaos_rollback' : 'deep_independent_regression';

        return 'competing_candidates_different_family_disaster_drill';
    }

    /** @return array<string,string> */
    private function topologyAssignments(string $topology): array
    {
        $assignments = array_fill_keys(EngineeringRoleRoster::OFFICIAL_ROLES, 'supporting_review');
        $assignments['product_strategy'] = 'lead';
        $assignments['product_management'] = 'coordination';
        $assignments['architecture'] = $topology === 'forge_milestone_crew' ? 'lead_architecture' : 'design';
        $assignments['evidence_audit'] = 'independent_audit';
        $assignments['final_certification'] = 'final_certification';
        $assignments['qa_testing'] = 'independent_verification';

        return $assignments;
    }

    /**
     * The Cognitive Pressure Layer advisory guard roles (Atlas Orchestrator Canon +
     * acos-cognitive-role-matrix-8-minimal). Read-only ADVISORY roles that PRESSURE the
     * role_roster's work — one verdict per guard (runtime_verifier / context_cartographer /
     * boundary_wiring_guard) feeds the proof-gated outcome ledger the Decision Core weighs.
     * Kept OUT of the counted execution roster (they schedule no task, own no write scope);
     * they are the width/deterministic verification tier, surfaced alongside it.
     *
     * @return list<array<string,mixed>>
     */
    private function pressureLayerAdvisoryRoster(string $domain, string $flowId): array
    {
        return collect(PressureLayerGuards::advisoryRoles())
            ->map(fn (array $guard): array => [
                'role_id' => (string) $guard['role_id'],
                'advisory' => true,
                'read_only' => true,
                'tier' => (string) ($guard['tier'] ?? 'width'),
                'domain' => $domain,
                'flow_id' => $flowId,
                'prevents' => (string) ($guard['prevents'] ?? ''),
                'signal' => (string) ($guard['signal'] ?? ''),
                'forbidden_actions' => ['mutate_files', 'spawn_provider_directly', 'fabricate_verdict'],
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    private function taskGraph(string $objective, string $topology, string $domain, string $flowId, array $roles, array $input): array
    {
        $leadTaskId = 'task_01_'.(string) data_get($roles, '0.role_id', 'lead_synthesizer');

        $tasks = collect($roles)->map(function (array $role, int $index) use ($objective, $leadTaskId): array {
            $roleId = (string) $role['role_id'];

            return [
                'task_id' => 'task_'.str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT).'_'.$roleId,
                'role_id' => $roleId,
                'objective_hash' => MissionCanonicalHash::sha256([$objective, $roleId]),
                'depends_on' => $this->taskDependencies($roleId, $index, $leadTaskId),
                'expected_artifacts' => $this->expectedArtifacts($roleId),
                'acceptance_criteria' => ['output_contract_satisfied', 'evidence_refs_declared', 'no_scope_overreach'],
            ];
        })->values();

        return [
            'schema_version' => 'atlas.agentic_workcell.task_graph.v1',
            'topology' => $topology,
            'domain' => $domain,
            'flow_id' => $flowId,
            'tasks' => $tasks->all(),
            'dependency_edges' => $tasks->flatMap(fn (array $task): array => collect($task['depends_on'])->map(fn (string $dep): array => ['from' => $dep, 'to' => $task['task_id']])->all())->values()->all(),
            'conflict_policy' => [
                'parallel_tasks_must_have_disjoint_write_scope' => true,
                'shared_files_require_serial_merge_or_single_owner' => true,
                'reviewers_are_read_only' => true,
            ],
            'task_graph_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $tasks->pluck('task_id')->all()]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPacks(string $objective, string $domain, string $flowId, array $roles, array $contextRefs, array $evidenceRefs, array $input): array
    {
        $packs = collect($roles)->map(fn (array $role): array => [
            'schema_version' => 'atlas.agentic_workcell.context_pack.v1',
            'role_id' => $role['role_id'],
            'objective_hash' => MissionCanonicalHash::sha256([$objective, $role['role_id']]),
            'included_context_refs' => array_slice($contextRefs, 0, 12),
            'evidence_refs' => $evidenceRefs,
            'must_keep' => [
                ['kind' => 'goal', 'value_hash' => MissionCanonicalHash::sha256(['goal' => $objective])],
                ['kind' => 'domain', 'value' => $domain],
                ['kind' => 'flow_id', 'value' => $flowId],
                ['kind' => 'role_boundary', 'value' => $role['role_id']],
            ],
            'forbidden_context' => ['unbounded_chat_history', 'raw_provider_transcript', 'irrelevant_tool_manuals'],
            'request_more_context_contract' => [
                'requires_reason' => true,
                'requires_expected_value' => true,
                'approved_by' => 'AREG',
            ],
        ])->values()->all();

        return [
            'schema_version' => 'atlas.agentic_workcell.context_packs.v1',
            'packs' => $packs,
            'pack_count' => count($packs),
            'context_isolation_required' => true,
            'context_packs_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $packs]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function executionSchedule(string $topology, array $roles, array $taskGraph, int $risk): array
    {
        $tasks = collect((array) ($taskGraph['tasks'] ?? []));
        $parallel = ! in_array($topology, ['solo_agent', 'critic_chain'], true);
        $groups = $parallel
            ? [
                ['group_id' => 'g1_context_and_design', 'tasks' => $tasks->take(max(1, min(3, $tasks->count())))->pluck('task_id')->all()],
                ['group_id' => 'g2_execution_or_analysis', 'tasks' => $tasks->slice(3)->take(max(1, $tasks->count() - 5))->pluck('task_id')->all()],
                ['group_id' => 'g3_verification_and_synthesis', 'tasks' => $tasks->slice(max(0, $tasks->count() - 2))->pluck('task_id')->all()],
            ]
            : [['group_id' => 'g1_serial', 'tasks' => $tasks->pluck('task_id')->all()]];

        return [
            'schema_version' => 'atlas.agentic_workcell.execution_schedule.v1',
            'topology' => $topology,
            'parallelism_allowed' => $parallel,
            'max_parallel_agents' => $parallel ? min(8, max(2, count($roles) - 2)) : 1,
            'groups' => array_values(array_filter($groups, fn (array $group): bool => $group['tasks'] !== [])),
            'coordination_gates' => ['scope_lock_before_work', 'merge_after_verification', 'lead_synthesis_after_evidence'],
            'risk_mode' => $risk >= 8 ? 'strict' : 'standard',
            'schedule_hash' => MissionCanonicalHash::sha256([$topology, $roles, $groups, $risk]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function verificationPlan(string $topology, string $domain, string $flowId, int $risk, array $roles): array
    {
        $checks = match ($domain) {
            'programming' => ['focused_tests', 'diff_review', 'ownership_overlap_check', 'receipt_check'],
            'research' => ['source_coverage', 'counter_source_review', 'claim_uncertainty_audit'],
            'finance' => ['freshness_check', 'risk_disclosure', 'no_external_action'],
            'strategy' => ['assumption_check', 'red_blue_adjudication', 'operator_review'],
            default => ['response_shape_check', 'evidence_refs_check'],
        };
        if ($risk >= 8) {
            $checks[] = 'policy_gate';
            $checks[] = 'adversarial_verification';
        }

        return [
            'schema_version' => 'atlas.agentic_workcell.verification_plan.v1',
            'independent_verifier_required' => true,
            'critic_required' => ! in_array($topology, ['solo_agent'], true) || $risk >= 6,
            'evidence_auditor_required' => count($roles) >= 4 || $risk >= 7,
            'checks' => array_values(array_unique($checks)),
            'completion_allowed_without_evidence' => false,
            'verification_hash' => MissionCanonicalHash::sha256([$topology, $domain, $flowId, $risk, $checks]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function evidenceLedger(string $objective, array $roles, array $taskGraph, array $evidenceRefs): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.evidence_ledger.v1',
            'required_receipts' => ['workcell_design', 'role_context_pack', 'task_output', 'verification_result', 'final_synthesis'],
            'initial_evidence_refs' => $evidenceRefs,
            'role_receipt_requirements' => collect($roles)->mapWithKeys(fn (array $role): array => [(string) $role['role_id'] => ['context_pack_hash', 'output_hash', 'evidence_refs']])->all(),
            'task_count' => count((array) ($taskGraph['tasks'] ?? [])),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function memoryPacket(string $objective, string $domain, string $flowId, string $topology, array $roles): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.memory_packet.v1',
            'must_persist' => ['goal', 'topology', 'role_roster', 'task_graph', 'verification_plan', 'blockers', 'outcome'],
            'must_not_persist_without_review' => ['provider_raw_output', 'unverified_claim', 'temporary_speculation'],
            'scope' => $domain.':'.$flowId,
            'topology' => $topology,
            'role_ids' => collect($roles)->pluck('role_id')->values()->all(),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function counterfactualReplay(string $objective, string $domain, string $flowId, string $selectedTopology, int $complexity, int $risk): array
    {
        $candidates = collect(AgenticWorkcellTopologyPolicySupport::TOPOLOGIES)
            ->map(fn (string $topology): array => AgenticWorkcellTopologyPolicySupport::scoreTopology($topology, $selectedTopology, $domain, $flowId, $complexity, $risk))
            ->sortByDesc('utility_score')
            ->values()
            ->all();

        return [
            'schema_version' => 'atlas.agentic_workcell.counterfactual_replay.v1',
            'selected_topology' => $selectedTopology,
            'winning_topology' => (string) data_get($candidates, '0.topology', $selectedTopology),
            'candidates' => $candidates,
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'replay_hash' => MissionCanonicalHash::sha256([$domain, $flowId, $selectedTopology, $candidates]),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningPolicy(string $domain, string $flowId, string $topology, int $risk): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.learning_policy.v1',
            'records_outcome' => true,
            'compiles_org_pattern' => true,
            'anti_false_learning_gate' => [
                'requires_evidence_refs' => true,
                'requires_quality_and_roi' => true,
                'operator_review_required_when_risk_high' => $risk >= 8,
            ],
            'future_policy_inputs' => ['topology_success_rate', 'role_utility', 'context_pack_roi', 'verification_findings'],
            'scope' => $domain.':'.$flowId.':'.$topology,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function controlPlaneSummary(string $topology, array $roles, array $taskGraph, array $verificationPlan, string $status): array
    {
        return [
            'schema_version' => 'atlas.agentic_workcell.control_summary.v1',
            'status' => $status,
            'topology' => $topology,
            'role_count' => count($roles),
            'task_count' => count((array) ($taskGraph['tasks'] ?? [])),
            'verification_check_count' => count((array) ($verificationPlan['checks'] ?? [])),
            'independent_verification_required' => true,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function learningCandidates(AtlasAgenticWorkcell $workcell, ?float $qualityScore, ?float $roiScore, array $signals): array
    {
        $candidates = [];
        if ($qualityScore !== null && $qualityScore < 0.65) {
            $candidates[] = ['kind' => 'change_topology', 'reason' => 'low_quality_score', 'current_topology' => $workcell->topology];
        }
        if ($roiScore !== null && $roiScore < 0.45) {
            $candidates[] = ['kind' => 'reduce_agent_count_or_context', 'reason' => 'low_coordination_roi'];
        }
        if (($signals['verification_failed'] ?? false) === true) {
            $candidates[] = ['kind' => 'strengthen_verifier_or_red_team', 'reason' => 'verification_failed'];
        }
        if ($candidates === []) {
            $candidates[] = ['kind' => 'promote_org_pattern_candidate', 'reason' => 'quality_and_roi_acceptable'];
        }

        return $candidates;
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

    private function roleContextScope(string $roleId): string
    {
        return match (true) {
            str_contains($roleId, 'verifier') || str_contains($roleId, 'critic') || str_contains($roleId, 'reviewer')
                || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true) => 'read_only_evidence_and_outputs',
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => 'owned_work_packet_only',
            str_contains($roleId, 'scout') || str_contains($roleId, 'cartographer') => 'retrieval_and_mapping_only',
            default => 'goal_and_coordination_context',
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function roleOutputContract(string $roleId): array
    {
        return [
            'must_return' => ['summary', 'evidence_refs', 'confidence', 'blockers', 'next_action'],
            'role_specific_artifact' => str_contains($roleId, 'verifier') || str_contains($roleId, 'critic')
                || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification'], true) ? 'verification_report' : 'work_product_or_findings',
            'forbidden_output' => ['unsupported_completion_claim', 'hidden_assumptions', 'raw_secret_or_credential'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function roleToolBoundary(string $roleId, int $risk): array
    {
        $readOnly = str_contains($roleId, 'critic') || str_contains($roleId, 'auditor') || str_contains($roleId, 'reviewer') || str_contains($roleId, 'scout')
            || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true);

        return [
            'read_only' => $readOnly,
            'writes_allowed' => ! $readOnly && $risk < 9,
            'requires_receipt' => true,
            'external_side_effects_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    private function taskDependencies(string $roleId, int $index, string $leadTaskId): array
    {
        if ($index === 0) {
            return [];
        }
        if (str_contains($roleId, 'verifier') || str_contains($roleId, 'auditor') || str_contains($roleId, 'certifier') || str_contains($roleId, 'synthesizer') || str_contains($roleId, 'adjudicator') || str_contains($roleId, 'critic')
            || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true)) {
            return [$leadTaskId];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function expectedArtifacts(string $roleId): array
    {
        return match (true) {
            str_contains($roleId, 'verifier') || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification'], true) => ['verification_report', 'failed_or_passed_checks'],
            str_contains($roleId, 'critic') => ['risk_report', 'counterarguments'],
            str_contains($roleId, 'auditor') => ['evidence_manifest', 'missing_evidence'],
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => ['work_product', 'changed_artifacts_or_plan'],
            default => ['findings', 'handoff_packet'],
        };
    }

    /**
     * @param  Collection<int,object>  $rows
     */
    private function average(Collection $rows, string $field): ?float
    {
        return $rows->isEmpty() ? null : round(AiValueNormalizer::finiteFloatOrNull($rows->avg($field)) ?? 0.0, 2);
    }

    /**
     * @param  Collection<int,object>  $rows
     * @return array<string,int>
     */
    private function countsBy(Collection $rows, string $field): array
    {
        return $rows
            ->groupBy(fn (object $row): string => (string) ($row->{$field} ?? 'unknown'))
            ->map(fn (Collection $group): int => $group->count())
            ->sortKeys()
            ->all();
    }

    private function workcell(mixed $id): ?AtlasAgenticWorkcell
    {
        $id = AiValueNormalizer::trimmedScalarStringOrNull($id);
        if ($id === null || ! DatabaseTableAvailability::has('atlas_agentic_workcells')) {
            return null;
        }

        return AtlasAgenticWorkcell::query()->find($id);
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $reason, string $message): array
    {
        return [
            'schema_version' => $schema,
            'status' => self::STATUS_BLOCKED,
            'blocker' => [
                'reason' => $reason,
                'message' => $message,
            ],
            'claim_policy' => AgenticWorkcellTopologyPolicySupport::claimPolicy(),
        ];
    }

    private function numericOrNull(mixed $value): ?float
    {
        return AiValueNormalizer::finiteFloatOrNull($value);
    }
}
