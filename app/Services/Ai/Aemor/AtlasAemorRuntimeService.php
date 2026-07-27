<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor;

use App\Models\AiMemoryDelta;
use App\Models\AtlasAaeosTestRunReceipt;
use App\Models\AtlasAemorExecutionEpisode;
use App\Models\AtlasAemorExecutionEvent;
use App\Models\AtlasAemorLearningSignal;
use App\Models\AtlasAemorMemoryCandidate;
use App\Models\AtlasAemorOutcome;
use App\Models\AtlasLedgerEvent;
use App\Services\Ai\IntelligenceFactory\AtlasIntelligenceFactoryRuntimeService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\Skills\AtlasSkillEvolutionRuntimeService;
use App\Services\Ai\Support\AiStringListNormalizer;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

final class AtlasAemorRuntimeService
{
    public const EPISODE_SCHEMA = 'atlas.aemor.execution_episode.v1';

    public const EVENT_SCHEMA = 'atlas.aemor.execution_event.v1';

    public const OUTCOME_SCHEMA = 'atlas.aemor.outcome.v1';

    public const LEARNING_SIGNAL_SCHEMA = 'atlas.aemor.learning_signal.v1';

    public const MEMORY_CANDIDATE_SCHEMA = 'atlas.aemor.memory_candidate.v1';

    public const REPLAY_SCHEMA = 'atlas.aemor.replay_manifest.v1';

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function openEpisode(array $input): array
    {
        $objective = $this->stringValue($input['objective'] ?? $input['prompt'] ?? $input['input_text'] ?? null) ?? 'AEMOR execution episode';
        $scopeType = $this->stringValue($input['scope_type'] ?? null) ?? 'workspace';
        $scopeId = $this->stringValue($input['scope_id'] ?? null) ?? $this->workspaceScopeId($this->stringValue($input['workspace'] ?? null) ?? base_path());
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['evidence_refs'] ?? []);
        $payload = [
            'schema_version' => self::EPISODE_SCHEMA,
            'status' => 'open',
            'scope_type' => $scopeType,
            'scope_id' => $scopeId,
            'workspace' => $this->stringValue($input['workspace'] ?? null) ?? base_path(),
            'surface_id' => $this->stringValue($input['surface_id'] ?? null),
            'domain' => $this->stringValue($input['domain'] ?? null),
            'flow_id' => $this->stringValue($input['flow_id'] ?? null),
            'provider' => $this->stringValue($input['provider'] ?? null),
            'trace_id' => $this->uuidOrNull($input['trace_id'] ?? null),
            'mission_id' => $this->uuidOrNull($input['mission_id'] ?? null),
            'work_order_id' => $this->uuidOrNull($input['work_order_id'] ?? null),
            'obra_id' => $this->uuidOrNull($input['obra_id'] ?? null),
            'apcr_pack_id' => $this->uuidOrNull($input['apcr_pack_id'] ?? null),
            'persistent_context_hash' => $this->stringValue($input['persistent_context_hash'] ?? null),
            'objective_hash' => MissionCanonicalHash::sha256(['objective' => $objective]),
            'objective' => $objective,
            'workspace_baseline' => is_array($input['workspace_baseline'] ?? null) ? $input['workspace_baseline'] : [],
            'risk_prediction' => is_array($input['risk_prediction'] ?? null) ? $input['risk_prediction'] : $this->riskPrediction($objective, [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ]),
            'evidence_refs' => $evidenceRefs,
            'metadata' => [
                'claim_policy' => $this->claimPolicy(),
                'source' => $this->stringValue($input['source'] ?? null) ?? 'aemor_runtime',
            ],
            'opened_at' => CarbonImmutable::now()->toJSON(),
        ];
        $payload['episode_hash'] = MissionCanonicalHash::sha256($payload);
        $record = null;
        if (DatabaseTableAvailability::has('atlas_aemor_execution_episodes')) {
            $record = AtlasAemorExecutionEpisode::query()->create($payload);
        }

        return [
            'schema_version' => self::EPISODE_SCHEMA,
            'status' => 'open',
            'episode_id' => $record?->id,
            'episode_hash' => $payload['episode_hash'],
            'scope' => [
                'scope_type' => $scopeType,
                'scope_id' => $scopeId,
            ],
            'risk_prediction' => $payload['risk_prediction'],
            'evidence_refs' => $evidenceRefs,
            'writes' => $record !== null,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function observe(array $input): array
    {
        $episode = $this->episode($input['episode_id'] ?? null);
        if ($episode === null) {
            return $this->blocked('atlas.aemor.execution_event.v1', 'missing_episode', 'AEMOR event requires an existing episode.');
        }

        $payload = is_array($input['payload'] ?? null) ? $input['payload'] : [];
        $evidenceRefs = AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['evidence_refs'] ?? data_get($payload, 'evidence_refs', []));
        $event = [
            'episode_id' => $episode->id,
            'schema_version' => self::EVENT_SCHEMA,
            'event_type' => $this->stringValue($input['event_type'] ?? null) ?? 'observation',
            'stage' => $this->stringValue($input['stage'] ?? null),
            'status' => $this->stringValue($input['status'] ?? null) ?? 'observed',
            'payload' => $this->sanitizePayload($payload),
            'evidence_refs' => $evidenceRefs,
            'causation_event_id' => $this->uuidOrNull($input['causation_event_id'] ?? null),
            'occurred_at' => CarbonImmutable::now()->toJSON(),
        ];
        $event['payload_hash'] = MissionCanonicalHash::sha256($event['payload']);
        $event['event_hash'] = MissionCanonicalHash::sha256($event);
        $record = AtlasAemorExecutionEvent::query()->create($event);

        return [
            'schema_version' => self::EVENT_SCHEMA,
            'status' => 'observed',
            'episode_id' => $episode->id,
            'event_id' => $record->id,
            'event_hash' => $record->event_hash,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function closeOutcome(array $input): array
    {
        $episode = $this->episode($input['episode_id'] ?? null);
        if ($episode === null) {
            return $this->blocked(self::OUTCOME_SCHEMA, 'missing_episode', 'AEMOR outcome requires an existing episode.');
        }

        $evidenceRefs = AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['evidence_refs'] ?? []);
        $status = $this->stringValue($input['status'] ?? null) ?? ($evidenceRefs === [] ? 'blocked' : 'succeeded');
        $metrics = $this->normalizeOutcomeMetrics(
            is_array($input['metrics'] ?? null) ? $input['metrics'] : [],
            $evidenceRefs,
        );
        $outcome = [
            'episode_id' => $episode->id,
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $evidenceRefs === [] ? 'blocked' : $status,
            'outcome_type' => $this->stringValue($input['outcome_type'] ?? null) ?? ($status === 'succeeded' ? 'success' : 'failure'),
            'failure_signature' => $this->failureSignature($input, $episode),
            'summary' => $this->stringValue($input['summary'] ?? null) ?? 'AEMOR outcome closed.',
            'metrics' => $metrics,
            'blockers' => $evidenceRefs === [] ? [['id' => 'missing_evidence_refs', 'reason' => 'AEMOR never closes a trustworthy outcome without evidence refs.']] : (is_array($input['blockers'] ?? null) ? $input['blockers'] : []),
            'evidence_refs' => $evidenceRefs,
            'context_utility' => is_array($input['context_utility'] ?? null) ? $input['context_utility'] : $this->defaultContextUtility($episode),
            'patch_outcome' => is_array($input['patch_outcome'] ?? null) ? $input['patch_outcome'] : [],
            'claim_policy' => $this->claimPolicy(),
            'closed_at' => CarbonImmutable::now()->toJSON(),
        ];
        $outcome['outcome_hash'] = MissionCanonicalHash::sha256($outcome);
        $record = AtlasAemorOutcome::query()->create($outcome);
        $episode->forceFill([
            'status' => $outcome['status'],
            'closed_at' => CarbonImmutable::now(),
        ])->save();
        $intelligenceFactoryEvolution = $this->evolveIntelligenceFactoryFromOutcome($episode, $record, $evidenceRefs);

        return [
            'schema_version' => self::OUTCOME_SCHEMA,
            'status' => $outcome['status'],
            'episode_id' => $episode->id,
            'outcome_id' => $record->id,
            'outcome_hash' => $record->outcome_hash,
            'failure_signature' => $record->failure_signature,
            'evidence_refs' => $evidenceRefs,
            'blockers' => $outcome['blockers'],
            'intelligence_factory_evolution' => $intelligenceFactoryEvolution,
            'metrics_verified' => (bool) data_get($metrics, 'metrics_verified', false),
            'metrics_provenance' => data_get($metrics, 'provenance'),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function distill(array $input): array
    {
        $episode = $this->episode($input['episode_id'] ?? null);
        if ($episode === null) {
            return $this->blocked(self::LEARNING_SIGNAL_SCHEMA, 'missing_episode', 'AEMOR distillation requires an existing episode.');
        }
        $outcome = $this->outcome($input['outcome_id'] ?? null) ?? $episode->outcome()->latest()->first();
        if (! $outcome instanceof AtlasAemorOutcome) {
            return $this->blocked(self::LEARNING_SIGNAL_SCHEMA, 'missing_outcome', 'AEMOR distillation requires a closed outcome.');
        }

        $evidenceRefs = AiStringListNormalizer::trimmedScalarValuesFromArrayCast($input['evidence_refs'] ?? $outcome->evidence_refs ?? []);
        $claim = $this->stringValue($input['claim'] ?? null) ?? $outcome->summary;
        $gate = $this->promotionGate($outcome, $evidenceRefs);
        $signal = [
            'episode_id' => $episode->id,
            'outcome_id' => $outcome->id,
            'schema_version' => self::LEARNING_SIGNAL_SCHEMA,
            'signal_type' => $this->stringValue($input['signal_type'] ?? null) ?? ($outcome->status === 'succeeded' ? 'execution_strategy' : 'failure_pattern'),
            'status' => $gate['status'] === 'pass' ? 'candidate' : 'blocked',
            'claim' => $claim,
            'confidence' => min(0.95, max(0.1, (float) ($input['confidence'] ?? 0.7))),
            'scope_type' => $episode->scope_type,
            'scope_id' => $episode->scope_id,
            'use_when' => (array) ($input['use_when'] ?? ['future execution matches this scope and outcome pattern']),
            'do_not_use_when' => (array) ($input['do_not_use_when'] ?? ['superseded, stale, or unsupported by current evidence']),
            'evidence_refs' => $evidenceRefs,
            'metadata' => [
                'outcome_hash' => $outcome->outcome_hash,
                'claim_policy' => $this->claimPolicy(),
            ],
        ];
        $signal['signal_hash'] = MissionCanonicalHash::sha256($signal);
        $signalRecord = AtlasAemorLearningSignal::query()->create($signal);

        $candidateRecord = null;
        $memoryDelta = null;
        if ($gate['status'] === 'pass') {
            if (DatabaseTableAvailability::has('ai_memory_deltas')) {
                $memoryDelta = AiMemoryDelta::query()->create([
                    'source_trace_id' => $episode->trace_id,
                    'source_session_id' => null,
                    'source_workspace' => $episode->workspace,
                    'type' => $signal['signal_type'],
                    'claim' => $claim,
                    'evidence' => $evidenceRefs,
                    'scope' => $episode->scope_type.':'.($episode->scope_id ?? 'atlas'),
                    'confidence' => $signal['confidence'],
                    'use_when' => $signal['use_when'],
                    'do_not_use_when' => $signal['do_not_use_when'],
                    'requires_confirmation' => true,
                    'status' => 'pending',
                ]);
            }
            $candidate = [
                'episode_id' => $episode->id,
                'outcome_id' => $outcome->id,
                'learning_signal_id' => $signalRecord->id,
                'memory_delta_id' => $memoryDelta?->id,
                'schema_version' => self::MEMORY_CANDIDATE_SCHEMA,
                'status' => 'watch',
                'memory_type' => $signal['signal_type'],
                'claim' => $claim,
                'confidence' => $signal['confidence'],
                'scope_type' => $episode->scope_type,
                'scope_id' => $episode->scope_id,
                'promotion_gate' => $gate,
                'evidence_refs' => $evidenceRefs,
                'metadata' => ['requires_confirmation' => true],
            ];
            $candidate['candidate_hash'] = MissionCanonicalHash::sha256($candidate);
            $candidateRecord = AtlasAemorMemoryCandidate::query()->create($candidate);
        }

        $skillEvolution = ($gate['status'] === 'pass' && (bool) ($input['propose_skill_candidate'] ?? false))
            ? $this->proposeSkillCandidateFromOutcome($episode, $outcome, $claim, $evidenceRefs)
            : null;

        return [
            'schema_version' => self::LEARNING_SIGNAL_SCHEMA,
            'status' => $gate['status'] === 'pass' ? 'candidate' : 'blocked',
            'episode_id' => $episode->id,
            'outcome_id' => $outcome->id,
            'learning_signal_id' => $signalRecord->id,
            'memory_candidate_id' => $candidateRecord?->id,
            'memory_delta_id' => $memoryDelta?->id,
            'promotion_gate' => $gate,
            'skill_evolution' => $skillEvolution,
            'evidence_refs' => $evidenceRefs,
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function replayManifest(string $episodeId): array
    {
        $episode = $this->episode($episodeId);
        if ($episode === null) {
            return $this->blocked(self::REPLAY_SCHEMA, 'missing_episode', 'Replay requires an existing episode.');
        }
        $events = $episode->events()->orderBy('created_at')->get();
        $outcome = $episode->outcome()->latest()->first();
        $payload = [
            'schema_version' => self::REPLAY_SCHEMA,
            'episode_id' => $episode->id,
            'episode_hash' => $episode->episode_hash,
            'persistent_context_hash' => $episode->persistent_context_hash,
            'event_hashes' => $events->pluck('event_hash')->values()->all(),
            'outcome_hash' => $outcome?->outcome_hash,
            'required_refs' => array_values(array_unique(array_merge(
                (array) ($episode->evidence_refs ?? []),
                $outcome instanceof AtlasAemorOutcome ? (array) ($outcome->evidence_refs ?? []) : [],
            ))),
            'replay_steps' => [
                'load_episode',
                'load_events_in_order',
                'load_outcome',
                'verify_hashes',
                'reconstruct_context_from_persistent_context_hash',
            ],
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['replay_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function controlPlane(int $hours = 24): array
    {
        $since = CarbonImmutable::now()->subHours(max(1, $hours));
        $episodes = DatabaseTableAvailability::has('atlas_aemor_execution_episodes')
            ? AtlasAemorExecutionEpisode::query()->where('created_at', '>=', $since)->get()
            : collect();
        $outcomes = DatabaseTableAvailability::has('atlas_aemor_outcomes')
            ? AtlasAemorOutcome::query()->where('created_at', '>=', $since)->get()
            : collect();
        $blockers = $outcomes
            ->filter(fn (AtlasAemorOutcome $outcome): bool => $outcome->status === 'blocked' || $outcome->status === 'failed')
            ->take(20)
            ->map(fn (AtlasAemorOutcome $outcome): array => [
                'type' => 'aemor_outcome',
                'episode_id' => $outcome->episode_id,
                'outcome_id' => $outcome->id,
                'status' => $outcome->status,
                'failure_signature' => $outcome->failure_signature,
            ])
            ->values()
            ->all();
        $payload = [
            'schema_version' => 'atlas.aemor.control_plane.v1',
            'status' => $blockers === [] ? 'healthy' : 'watch',
            'summary' => [
                'episodes_total' => $episodes->count(),
                'open' => $episodes->where('status', 'open')->count(),
                'succeeded' => $outcomes->where('status', 'succeeded')->count(),
                'failed' => $outcomes->where('status', 'failed')->count(),
                'blocked' => $outcomes->where('status', 'blocked')->count(),
                'learning_signals' => DatabaseTableAvailability::has('atlas_aemor_learning_signals') ? AtlasAemorLearningSignal::query()->where('created_at', '>=', $since)->count() : 0,
                'memory_candidates' => DatabaseTableAvailability::has('atlas_aemor_memory_candidates') ? AtlasAemorMemoryCandidate::query()->where('created_at', '>=', $since)->count() : 0,
            ],
            'blockers' => $blockers,
            'claim_policy' => $this->claimPolicy(),
        ];
        $payload['control_plane_hash'] = MissionCanonicalHash::sha256($payload);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    public function riskPrediction(string $goal, array $scope = []): array
    {
        $keywords = $this->keywords($goal);
        $matches = [];
        if (DatabaseTableAvailability::has('atlas_aemor_outcomes')) {
            $recent = AtlasAemorOutcome::query()
                ->whereIn('status', ['failed', 'blocked'])
                ->latest()
                ->limit(50)
                ->get();
            foreach ($recent as $outcome) {
                $haystack = mb_strtolower($outcome->summary.' '.($outcome->failure_signature ?? ''));
                $score = 0;
                foreach ($keywords as $keyword) {
                    if ($keyword !== '' && str_contains($haystack, $keyword)) {
                        $score++;
                    }
                }
                if ($score > 0) {
                    $matches[] = [
                        'outcome_id' => $outcome->id,
                        'episode_id' => $outcome->episode_id,
                        'failure_signature' => $outcome->failure_signature,
                        'score' => $score,
                    ];
                }
            }
        }

        return [
            'schema_version' => 'atlas.aemor.pre_execution_risk_prediction.v1',
            'status' => $matches === [] ? 'clear' : 'watch',
            'goal_hash' => MissionCanonicalHash::sha256(['goal' => $goal]),
            'scope' => $scope,
            'similar_failures' => array_slice($matches, 0, 8),
            'required_mitigations' => $matches === [] ? [] : [
                'include_prior_failure_context',
                'run_targeted_tests_before_completion',
                'attach_evidence_refs_to_outcome',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function memoryAudit(): array
    {
        $candidates = DatabaseTableAvailability::has('atlas_aemor_memory_candidates') ? AtlasAemorMemoryCandidate::query()->latest()->limit(50)->get() : collect();
        $blocked = $candidates->where('status', 'blocked')->count();
        $watch = $candidates->where('status', 'watch')->count();

        return [
            'schema_version' => 'atlas.aemor.memory_audit.v1',
            'status' => $blocked > 0 ? 'watch' : 'healthy',
            'summary' => [
                'total' => $candidates->count(),
                'watch' => $watch,
                'blocked' => $blocked,
            ],
            'recent' => $candidates->map(fn (AtlasAemorMemoryCandidate $candidate): array => [
                'id' => $candidate->id,
                'status' => $candidate->status,
                'memory_type' => $candidate->memory_type,
                'candidate_hash' => $candidate->candidate_hash,
            ])->values()->all(),
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    public function episode(mixed $id): ?AtlasAemorExecutionEpisode
    {
        return is_string($id) && DatabaseTableAvailability::has('atlas_aemor_execution_episodes')
            ? AtlasAemorExecutionEpisode::query()->find($id)
            : null;
    }

    public function outcome(mixed $id): ?AtlasAemorOutcome
    {
        return is_string($id) && DatabaseTableAvailability::has('atlas_aemor_outcomes')
            ? AtlasAemorOutcome::query()->find($id)
            : null;
    }

    /**
     * @return array<string,bool>
     */
    public function claimPolicy(): array
    {
        return [
            'benchmark_not_run' => true,
            'rivals_compared' => false,
            'provider_calls_made' => false,
            'allows_external_superiority_claim' => false,
            'auto_promotes_memory' => false,
            'auto_changes_policy' => false,
        ];
    }

    /**
     * @param  array<int,string>  $evidenceRefs
     * @return array<string,mixed>|null
     */
    private function evolveIntelligenceFactoryFromOutcome(AtlasAemorExecutionEpisode $episode, AtlasAemorOutcome $outcome, array $evidenceRefs): ?array
    {
        if ($evidenceRefs === []) {
            return null;
        }
        if (! DatabaseTableAvailability::has('atlas_intelligence_factory_evolution_events')) {
            return null;
        }

        try {
            return app(AtlasIntelligenceFactoryRuntimeService::class)->evolveFromAemor([
                'source_type' => 'aemor_outcome',
                'source_id' => $outcome->id,
                'event_type' => $outcome->status === 'succeeded'
                    ? 'successful_outcome_learning_candidate'
                    : 'failed_outcome_learning_candidate',
                'summary' => $outcome->summary,
                'domain' => $episode->domain,
                'flow_id' => $episode->flow_id,
                'evidence_refs' => $evidenceRefs,
            ]);
        } catch (\Throwable) {
            return [
                // GOD-DEBULK 3b: literal (was AtlasIntelligenceFactoryRuntimeService::EVOLUTION_SCHEMA).
                // With the class quarantined to archive/, evaluating the constant inside this catch
                // would itself throw and kill the outcome-close path (blueprint 91c334a27 §2.2).
                'schema_version' => 'atlas.intelligence_factory.capability_evolution.v1',
                'status' => 'skipped',
                'reason' => 'intelligence_factory_unavailable',
                'writes' => false,
            ];
        }
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>|null
     */
    private function proposeSkillCandidateFromOutcome(AtlasAemorExecutionEpisode $episode, AtlasAemorOutcome $outcome, string $claim, array $evidenceRefs): ?array
    {
        try {
            return app(AtlasSkillEvolutionRuntimeService::class)->propose([
                'workspace' => $episode->workspace ?: base_path(),
                'objective' => $claim,
                'summary' => $outcome->summary,
                'domain' => $episode->domain ?: 'programming',
                'flow_id' => $episode->flow_id ?: 'atlas_dev',
                'evidence_refs' => $evidenceRefs,
            ]);
        } catch (\Throwable) {
            return [
                'schema_version' => AtlasSkillEvolutionRuntimeService::PROPOSAL_SCHEMA,
                'status' => 'skipped',
                'reason' => 'skill_evolution_unavailable',
                'claim_policy' => $this->claimPolicy(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function promotionGate(AtlasAemorOutcome $outcome, array $evidenceRefs): array
    {
        $blockers = [];
        if ($evidenceRefs === []) {
            $blockers[] = 'missing_evidence_refs';
        }
        if ($outcome->status === 'blocked') {
            $blockers[] = 'outcome_blocked';
        }

        // Anti-false-learning invariants: promotion trusts metrics_verified only —
        // caller claims without resolvable evidence never auto-promote as verified.
        if ($outcome->status === 'succeeded') {
            $metrics = is_array($outcome->metrics) ? $outcome->metrics : [];
            if (empty($metrics['metrics_verified'])) {
                $blockers[] = 'metrics_not_verified';
            } else {
                if (empty($metrics['tests_passed'])) {
                    $blockers[] = 'success_without_test_or_gate_evidence';
                }
                if (empty($metrics['attribution_reviewed'])) {
                    $blockers[] = 'unreviewed_alternative_explanations';
                }
            }
        }

        return [
            'schema_version' => 'atlas.aemor.memory_promotion_receipt.v1',
            'status' => $blockers === [] ? 'pass' : 'blocked',
            'promotion_allowed' => false,
            'requires_confirmation' => true,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * @param  array<string,mixed>  $callerMetrics
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function normalizeOutcomeMetrics(array $callerMetrics, array $evidenceRefs): array
    {
        $resolved = $this->resolveVerifiedMetricsFromEvidence($evidenceRefs);
        $nonVerificationMetrics = array_diff_key(
            $callerMetrics,
            array_flip(['tests_passed', 'attribution_reviewed', 'metrics_verified', 'provenance', 'caller_claims', 'resolved_evidence_refs']),
        );

        if ($resolved['verified']) {
            return array_merge($nonVerificationMetrics, $resolved['metrics'], [
                'metrics_verified' => true,
                'provenance' => 'evidence_resolved',
                'resolved_evidence_refs' => $resolved['resolved_refs'],
            ]);
        }

        $callerClaims = array_filter([
            'tests_passed' => data_get($callerMetrics, 'tests_passed'),
            'attribution_reviewed' => data_get($callerMetrics, 'attribution_reviewed'),
        ], static fn (mixed $value): bool => $value !== null);

        return array_merge($nonVerificationMetrics, [
            'metrics_verified' => false,
            'provenance' => 'caller_claim',
            'caller_claims' => $callerClaims,
        ]);
    }

    /**
     * @param  list<string>  $evidenceRefs
     * @return array{verified:bool,metrics:array{tests_passed:bool,attribution_reviewed:bool},resolved_refs:list<string>}
     */
    private function resolveVerifiedMetricsFromEvidence(array $evidenceRefs): array
    {
        $testsPassed = false;
        $attributionReviewed = false;
        $resolvedRefs = [];

        foreach ($evidenceRefs as $evidenceRef) {
            $ref = trim((string) $evidenceRef);
            if ($ref === '') {
                continue;
            }

            $receiptProof = $this->resolveGreenTestRunReceiptRef($ref);
            if ($receiptProof !== null) {
                $testsPassed = $testsPassed || $receiptProof['tests_passed'];
                $attributionReviewed = $attributionReviewed || $receiptProof['attribution_reviewed'];
                $resolvedRefs[] = $ref;

                continue;
            }

            $ledgerProof = $this->resolveLedgerVerificationRef($ref);
            if ($ledgerProof !== null) {
                $testsPassed = $testsPassed || $ledgerProof['tests_passed'];
                $attributionReviewed = $attributionReviewed || $ledgerProof['attribution_reviewed'];
                $resolvedRefs[] = $ref;
            }
        }

        return [
            'verified' => $testsPassed,
            'metrics' => [
                'tests_passed' => $testsPassed,
                'attribution_reviewed' => $attributionReviewed,
            ],
            'resolved_refs' => array_values(array_unique($resolvedRefs)),
        ];
    }

    /**
     * @return array{tests_passed:bool,attribution_reviewed:bool}|null
     */
    private function resolveGreenTestRunReceiptRef(string $ref): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_aaeos_test_run_receipts')) {
            return null;
        }

        $id = str_starts_with($ref, 'test_run_receipt:')
            ? substr($ref, strlen('test_run_receipt:'))
            : $ref;
        if (! Str::isUuid($id)) {
            return null;
        }

        $receipt = AtlasAaeosTestRunReceipt::query()->find($id);
        if (! $receipt instanceof AtlasAaeosTestRunReceipt || ! $receipt->passed || (int) $receipt->tests_run < 1) {
            return null;
        }

        // Um recibo que se declara sintético não é evidência. Ele existe para que
        // um harness possa exercitar o caminho inteiro de produção sem mentir; o
        // preço é que ele nunca conta. Sem esta linha, a marca `synthetic` seria o
        // que era até aqui — convenção educada que ninguém consulta, e uma linha
        // fabricada verificaria um outcome como se fosse entrega real.
        if ((bool) data_get($receipt->metadata, 'synthetic', false)) {
            return null;
        }

        return [
            'tests_passed' => true,
            'attribution_reviewed' => (bool) data_get($receipt->metadata, 'attribution_reviewed'),
        ];
    }

    /**
     * @return array{tests_passed:bool,attribution_reviewed:bool}|null
     */
    private function resolveLedgerVerificationRef(string $ref): ?array
    {
        if (! DatabaseTableAvailability::has('atlas_ledger_events')) {
            return null;
        }

        $needle = str_starts_with($ref, 'ledger:') ? substr($ref, strlen('ledger:')) : $ref;
        if ($needle === '') {
            return null;
        }

        $event = AtlasLedgerEvent::query()
            ->where(function ($query) use ($needle): void {
                $query->where('event_id', $needle)->orWhere('receipt_id', $needle);
            })
            ->first();

        return $event instanceof AtlasLedgerEvent ? $this->ledgerEventVerificationProof($event) : null;
    }

    /**
     * @return array{tests_passed:bool,attribution_reviewed:bool}|null
     */
    private function ledgerEventVerificationProof(AtlasLedgerEvent $event): ?array
    {
        $payload = is_array($event->payload) ? $event->payload : [];
        $eventType = (string) $event->event_type;

        // O ramo GATE_PASSED foi removido: 0 de 44.717 eventos no ledger têm esse tipo, e
        // o seu `?? true` fabricava prova — DevGateLedgerEmitter mapeia 'skipped' para
        // GatePassed, e um payload de gate PULADO, sem campo tests_passed, saía daqui como
        // tests_passed=true. Um GATE_PASSED real cai no teste abaixo e só conta se disser
        // explicitamente que o teste passou.
        if ((bool) data_get($payload, 'tests_passed') || (bool) data_get($payload, 'verification_passed')) {
            return [
                'tests_passed' => true,
                'attribution_reviewed' => (bool) data_get($payload, 'attribution_reviewed', false),
            ];
        }

        return null;
    }

    private function failureSignature(array $input, AtlasAemorExecutionEpisode $episode): ?string
    {
        $explicit = $this->stringValue($input['failure_signature'] ?? null);
        if ($explicit !== null) {
            return $explicit;
        }
        $status = $this->stringValue($input['status'] ?? null);
        if ($status === 'succeeded') {
            return null;
        }

        return substr(MissionCanonicalHash::sha256([
            'scope' => $episode->scope_type.':'.$episode->scope_id,
            'summary' => $input['summary'] ?? '',
            'outcome_type' => $input['outcome_type'] ?? 'failure',
        ]), 0, 24);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultContextUtility(AtlasAemorExecutionEpisode $episode): array
    {
        return [
            'schema_version' => 'atlas.aemor.context_utility.v1',
            'persistent_context_hash' => $episode->persistent_context_hash,
            'included_sources' => [],
            'helpful_sources' => [],
            'irrelevant_sources' => [],
            'stale_sources' => [],
            'missing_sources' => [],
            'status' => $episode->persistent_context_hash ? 'measured' : 'unknown',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $schema, string $id, string $reason): array
    {
        return [
            'schema_version' => $schema,
            'status' => 'blocked',
            'blockers' => [[
                'id' => $id,
                'reason' => $reason,
            ]],
            'claim_policy' => $this->claimPolicy(),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitizePayload(array $payload): array
    {
        unset($payload['response_text'], $payload['operator_input'], $payload['raw_text'], $payload['content']);

        return $payload;
    }

    /**
     * @return list<string>
     */
    private function keywords(string $text): array
    {
        $words = preg_split('/[^a-zA-Z0-9_]+/', mb_strtolower($text)) ?: [];

        return array_values(array_unique(array_filter($words, static fn (string $word): bool => mb_strlen($word) >= 4)));
    }

    private function workspaceScopeId(string $workspace): string
    {
        return Str::limit(str_replace(['/', '\\', ' '], '_', trim($workspace)), 150, '');
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function uuidOrNull(mixed $value): ?string
    {
        return is_string($value) && Str::isUuid($value) ? $value : null;
    }
}
