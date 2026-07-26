<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasProgrammingGateRun;
use App\Models\AtlasProgrammingReview;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentScheduleCanon;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerException;
use App\Services\Ai\Programming\Forge\ForgeMultiAgentSchedulerService;
use App\Services\Ai\Programming\ProgrammingLearningCandidateProjector;
use App\Services\Ai\Programming\ProgrammingLearningCandidateStore;

/**
 * Adaptive control plane that composes AHCL v1 into the stronger v2-v5 runtime.
 *
 * This service is intentionally deterministic: it does not call providers,
 * mutate workspace files, or execute Forge. It turns the current governed
 * work-item state into a live session decision, a Forge multi-agent plan, and
 * a predictive replay/learning packet that downstream surfaces can consume.
 *
 * @see docs/engineering-knowledge-base/atlas-adaptive-hierarchical-control-plane.md
 */
class ProgrammingAdaptiveHierarchicalControlPlaneService
{
    public const SCHEMA_VERSION = 'atlas.programming.adaptive_hierarchical_control_plane.v1';

    public const LIVE_SESSION_SCHEMA_VERSION = 'atlas.programming.ahcl.live_session_control.v2';

    public const FORGE_MULTI_AGENT_SCHEMA_VERSION = 'atlas.programming.ahcl.forge_multi_agent_control.v3';

    public const PREDICTIVE_REPLAY_LEARNING_SCHEMA_VERSION = 'atlas.programming.ahcl.predictive_replay_learning.v4';

    public const OPTIMIZATION_CONTROL_TWIN_SCHEMA_VERSION = 'atlas.programming.ahcl.optimization_control_twin.v5';

    public function __construct(
        private readonly ProgrammingHierarchicalControlLoopService $ahcl,
        private readonly ForgeMultiAgentSchedulerService $forgeScheduler,
        private readonly ProgrammingEvidenceLedger $evidenceLedger,
        private readonly ProgrammingGovernanceService $governance,
        private readonly ProgrammingLearningCandidateProjector $learningProjector,
        private readonly ProgrammingLearningCandidateStore $learningStore,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function snapshot(AtlasProgrammingWorkItem $workItem, array $options = []): array
    {
        $workItem = $workItem->refresh();
        $ahcl = $this->ahcl->evaluate($workItem);
        $live = $this->liveSessionControl($workItem, $ahcl, $options);
        $forge = $this->forgeMultiAgentControl($workItem, $ahcl, $live, $options);
        $predictive = $this->predictiveReplayLearning($workItem, $ahcl, $live, $forge, $options);
        $twin = $this->optimizationControlTwin($workItem, $ahcl, $live, $forge, $predictive);
        $evolution = $this->nextEvolutionScan($ahcl, $live, $forge, $predictive, $twin);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'work_item_id' => $workItem->id,
            'work_item_code' => $workItem->code,
            'controller' => [
                'name' => 'Atlas Adaptive Hierarchical Control Plane',
                'abbreviation' => 'AAHCP',
                'levels' => [
                    'v1' => 'AHCL Completion Control',
                    'v2' => 'Live Session Control',
                    'v3' => 'Forge Multi-Agent Control',
                    'v4' => 'Predictive Control + Replay + Learning',
                    'v5' => 'Optimization Control Twin',
                ],
            ],
            'ahcl_v1' => $ahcl,
            'live_session_control_v2' => $live,
            'forge_multi_agent_control_v3' => $forge,
            'predictive_replay_learning_v4' => $predictive,
            'optimization_control_twin_v5' => $twin,
            'next_evolution_scan' => $evolution,
            'plane_hash' => $this->hashPayload([
                'work_item_id' => $workItem->id,
                'ahcl_action' => data_get($ahcl, 'halt_decision.action'),
                'live_tick' => data_get($live, 'next_tick'),
                'forge_decision' => data_get($forge, 'control_decision'),
                'prediction' => data_get($predictive, 'recommended_path'),
                'twin' => data_get($twin, 'optimization_decision'),
                'evolution' => $evolution,
            ]),
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function liveSessionControl(AtlasProgrammingWorkItem $workItem, array $ahcl, array $options = []): array
    {
        $action = (string) data_get($ahcl, 'halt_decision.action', ProgrammingHierarchicalControlLoopService::ACTION_CONTINUE);
        $reason = (string) data_get($ahcl, 'halt_decision.reason', 'unknown');
        $phase = $this->sessionPhase($workItem, $action);
        $timeline = $this->timeline($workItem);
        $latestEvent = $timeline === [] ? null : $timeline[array_key_last($timeline)];

        $nextTick = [
            'action' => $action,
            'reason' => $reason,
            'phase' => $phase,
            'next_step' => data_get($ahcl, 'halt_decision.next_step'),
            'next_command' => data_get($ahcl, 'halt_decision.next_command'),
            'should_pause_provider' => in_array($action, [
                ProgrammingHierarchicalControlLoopService::ACTION_REPLAN,
                ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE,
            ], true),
            'should_request_operator' => $action === ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE,
            'should_emit_checkpoint' => $this->shouldEmitCheckpoint($workItem, $action),
        ];

        return [
            'schema_version' => self::LIVE_SESSION_SCHEMA_VERSION,
            'status' => $action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT ? 'submit_ready' : 'active_control',
            'session_phase' => $phase,
            'work_item_status' => $workItem->status,
            'current_stage' => $workItem->current_stage,
            'next_tick' => $nextTick,
            'live_controls' => [
                'context_budget' => $this->contextBudget($workItem),
                'scope_guard' => $this->latestGateStatus($workItem, 'scope-guard'),
                'evidence_after_each_command' => true,
                'checkpoint_resume' => [
                    'required' => $nextTick['should_emit_checkpoint'],
                    'checkpoint_basis' => ['spec_hash', 'plan_hash', 'latest_event', 'halt_decision'],
                ],
                'repair_loop' => [
                    'active' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR,
                    'required_repairs' => array_values((array) data_get($ahcl, 'halt_decision.required_repairs', [])),
                ],
                'replan_loop' => [
                    'active' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPLAN,
                    'h_cycle_required' => (bool) data_get($ahcl, 'halt_decision.h_cycle_required', false),
                ],
            ],
            'event_stream' => [
                'count' => count($timeline),
                'latest_event' => $latestEvent,
                'events' => $timeline,
            ],
            'operator_surface' => [
                'primary_badge' => strtoupper($action),
                'secondary_badge' => $phase,
                'display_fields' => ['action', 'reason', 'readiness_score', 'next_step', 'next_command'],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $live
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function forgeMultiAgentControl(
        AtlasProgrammingWorkItem $workItem,
        array $ahcl,
        array $live,
        array $options = [],
    ): array {
        $packets = $this->workPacketsFromTasks($workItem);
        $riskBand = $this->forgeRiskBand((string) $workItem->risk_level);
        $action = (string) data_get($ahcl, 'halt_decision.action');
        $allowSerialize = (bool) ($options['allow_serialize'] ?? false);
        $requiresForge = $this->requiresForge($workItem, $packets, $action);
        $schedule = null;
        $scheduleError = null;

        try {
            $schedule = $this->forgeScheduler->plan(
                taskSummary: $workItem->intent_text,
                workPackets: $packets,
                riskBand: $riskBand,
                intake: null,
                options: [
                    'allow_serialize' => $allowSerialize,
                    'verification_required' => $riskBand !== ForgeMultiAgentScheduleCanon::RISK_LOW,
                    'reviewer_required' => $action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT
                        || $workItem->scope_mode === ProgrammingScopeMode::Structural->value,
                    'failure_context' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR,
                    'work_order_id' => $workItem->id,
                    'obra_id' => $workItem->id,
                ],
            );
        } catch (ForgeMultiAgentSchedulerException $e) {
            $scheduleError = [
                'type' => $e::class,
                'message' => $e->getMessage(),
            ];
        }

        $scheduleStatus = is_array($schedule) ? (string) ($schedule['status'] ?? 'unknown') : 'blocked';
        $controlDecision = $this->forgeControlDecision($action, $requiresForge, $scheduleStatus, $scheduleError);

        return [
            'schema_version' => self::FORGE_MULTI_AGENT_SCHEMA_VERSION,
            'status' => $controlDecision['status'],
            'requires_forge' => $requiresForge,
            'control_decision' => $controlDecision,
            'work_packets' => $packets,
            'schedule' => $schedule,
            'schedule_error' => $scheduleError,
            'provider_strategy' => $this->providerStrategy($workItem, $schedule, $action),
            'integration_guard' => [
                'merge_requires_review' => true,
                'collision_matrix_required' => count($packets) > 1,
                'promotion_requires_ahcl_submit' => true,
                'completion_gate' => 'hierarchical-control -> completion',
            ],
            'live_session_binding' => [
                'session_phase' => data_get($live, 'session_phase'),
                'next_tick_action' => data_get($live, 'next_tick.action'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $live
     * @param  array<string,mixed>  $forge
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function predictiveReplayLearning(
        AtlasProgrammingWorkItem $workItem,
        array $ahcl,
        array $live,
        array $forge,
        array $options = [],
    ): array {
        $timeline = $this->timeline($workItem);
        $action = (string) data_get($ahcl, 'halt_decision.action');
        $predictionOptions = $this->predictionOptions($workItem, $ahcl, $forge);
        $recommended = $this->recommendedPath($action, $predictionOptions);
        $learningCandidates = $this->learningCandidates($workItem, $ahcl, $timeline, $forge);

        return [
            'schema_version' => self::PREDICTIVE_REPLAY_LEARNING_SCHEMA_VERSION,
            'status' => $recommended['status'],
            'replay' => [
                'schema_version' => 'atlas.programming.ahcl.replay.v1',
                'event_count' => count($timeline),
                'timeline' => $timeline,
                'replay_hash' => $this->hashPayload($timeline),
            ],
            'prediction_options' => $predictionOptions,
            'recommended_path' => $recommended,
            'learning' => [
                'auto_apply_allowed' => false,
                'candidate_count' => count($learningCandidates),
                'candidates' => $learningCandidates,
                'promotion_gate' => 'ProgrammingLearningPromotionGate',
            ],
            'feedback_to_live_session' => [
                'next_tick_action' => data_get($live, 'next_tick.action'),
                'checkpoint_required' => data_get($live, 'live_controls.checkpoint_resume.required'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $live
     * @param  array<string,mixed>  $forge
     * @param  array<string,mixed>  $predictive
     * @return array<string,mixed>
     */
    public function optimizationControlTwin(
        AtlasProgrammingWorkItem $workItem,
        array $ahcl,
        array $live,
        array $forge,
        array $predictive,
    ): array {
        $history = $this->historicalControlSignals();
        $sampleSize = (int) $history['sample_size'];
        $recommendations = $this->policyRecommendations($workItem, $ahcl, $forge, $predictive, $history);
        $status = $sampleSize < 5 ? 'calibrating' : ($recommendations === [] ? 'optimized' : 'recommend_policy_adjustment');

        return [
            'schema_version' => self::OPTIMIZATION_CONTROL_TWIN_SCHEMA_VERSION,
            'status' => $status,
            'optimization_decision' => [
                'action' => $recommendations === [] ? 'observe' : 'recommend_policy_adjustment',
                'reason' => $sampleSize < 5 ? 'insufficient_calibration_sample' : 'historical_signal_analysis',
                'auto_apply_allowed' => false,
                'operator_review_required' => $recommendations !== [],
            ],
            'calibration' => $history,
            'control_weights' => $this->controlWeights($ahcl, $history),
            'policy_recommendations' => $recommendations,
            'twin_hash' => $this->hashPayload([
                'work_item_id' => $workItem->id,
                'history' => $history,
                'recommendations' => $recommendations,
                'ahcl_action' => data_get($ahcl, 'halt_decision.action'),
                'recommended_path' => data_get($predictive, 'recommended_path.path'),
            ]),
        ];
    }

    /**
     * Persist the current control-plane event as Programming Governance evidence.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function persistControlEvent(AtlasProgrammingWorkItem $workItem, array $snapshot): array
    {
        $receipt = $this->evidenceLedger->record($workItem, [
            'schema_version' => 'atlas.programming.ahcl.control_event_receipt.v1',
            'evidence_type' => 'adaptive_control_plane_event',
            'status' => (string) data_get($snapshot, 'predictive_replay_learning_v4.status', 'recorded'),
            'command' => 'atlas:programming:adaptive-control-plane '.$workItem->code.' --json',
            'output' => json_encode([
                'plane_hash' => data_get($snapshot, 'plane_hash'),
                'ahcl_action' => data_get($snapshot, 'ahcl_v1.halt_decision.action'),
                'live_action' => data_get($snapshot, 'live_session_control_v2.next_tick.action'),
                'forge_status' => data_get($snapshot, 'forge_multi_agent_control_v3.status'),
                'predictive_path' => data_get($snapshot, 'predictive_replay_learning_v4.recommended_path.path'),
                'twin_status' => data_get($snapshot, 'optimization_control_twin_v5.status'),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '',
            'files' => [],
            'tests' => [],
            'summary' => 'AAHCP control-plane event persisted for replay, Atlas Code and learning governance.',
            'execution_mode' => 'adaptive_hierarchical_control_plane',
            'plane_hash' => data_get($snapshot, 'plane_hash'),
            'replay_hash' => data_get($snapshot, 'predictive_replay_learning_v4.replay.replay_hash'),
        ]);

        $this->governance->appendEvidence($workItem, $receipt);

        return [
            'schema_version' => 'atlas.programming.ahcl.control_event_persistence.v1',
            'status' => 'persisted',
            'receipt_id' => $receipt['receipt_id'] ?? null,
            'engineering_evidence_id' => data_get($receipt, 'storage.id'),
            'plane_hash' => data_get($snapshot, 'plane_hash'),
            'replay_hash' => data_get($snapshot, 'predictive_replay_learning_v4.replay.replay_hash'),
            'event_stream_count_before_append' => (int) data_get($snapshot, 'live_session_control_v2.event_stream.count', 0),
        ];
    }

    /**
     * Queue v4/v5 learning signals into the governed Programming Learning review queue.
     *
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    public function emitLearningCandidates(AtlasProgrammingWorkItem $workItem, array $snapshot, int $ttlDays = 30): array
    {
        $rawCandidates = array_merge(
            (array) data_get($snapshot, 'predictive_replay_learning_v4.learning.candidates', []),
            (array) data_get($snapshot, 'optimization_control_twin_v5.policy_recommendations', []),
        );
        $evidenceRefs = $this->learningEvidenceRefs($workItem, $snapshot);
        $queued = [];

        foreach ($rawCandidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $result = [
                'status' => (string) data_get($snapshot, 'predictive_replay_learning_v4.status', 'blocked'),
                'source' => 'adaptive_hierarchical_control_plane',
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'plane_hash' => (string) data_get($snapshot, 'plane_hash', ''),
                'replay_hash' => (string) data_get($snapshot, 'predictive_replay_learning_v4.replay.replay_hash', ''),
                'candidate' => $candidate,
                'evidence_refs' => $evidenceRefs,
            ];
            $projected = array_merge($this->learningProjector->project($result), [
                'source' => 'adaptive_hierarchical_control_plane',
                'kind' => (string) ($candidate['kind'] ?? 'aahcp_learning'),
                'severity' => (string) ($candidate['severity'] ?? $candidate['priority'] ?? 'medium'),
                'summary' => (string) ($candidate['summary'] ?? 'AAHCP learning candidate'),
                'work_item_id' => (string) $workItem->id,
                'work_item_code' => (string) $workItem->code,
                'plane_hash' => (string) data_get($snapshot, 'plane_hash', ''),
                'replay_hash' => (string) data_get($snapshot, 'predictive_replay_learning_v4.replay.replay_hash', ''),
                'raw_candidate' => $candidate,
            ]);

            $queued[] = $this->learningStore->enqueue($projected, $ttlDays);
        }

        return [
            'schema_version' => 'atlas.programming.ahcl.learning_emission.v1',
            'status' => $queued === [] ? 'no_candidates' : 'queued_for_review',
            'auto_apply_allowed' => false,
            'work_item_id' => (string) $workItem->id,
            'work_item_code' => (string) $workItem->code,
            'candidate_count' => count($rawCandidates),
            'queued_count' => count(array_filter(
                $queued,
                static fn (array $candidate): bool => (bool) data_get($candidate, 'review_queue.queued', false),
            )),
            'candidates' => $queued,
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $live
     * @param  array<string,mixed>  $forge
     * @param  array<string,mixed>  $predictive
     * @return array<string,mixed>
     */
    private function nextEvolutionScan(
        array $ahcl,
        array $live,
        array $forge,
        array $predictive,
        array $twin,
    ): array {
        $followUps = [];

        if (data_get($live, 'event_stream.count', 0) === 0) {
            $followUps[] = 'live_event_bus_persistence';
        }
        if (data_get($forge, 'requires_forge') === true && data_get($forge, 'schedule') === null) {
            $followUps[] = 'forge_scheduler_binding';
        }
        if ((int) data_get($predictive, 'learning.candidate_count', 0) > 0) {
            $followUps[] = 'learning_candidate_review_queue_binding';
        }
        if ((int) data_get($twin, 'calibration.sample_size', 0) < 25) {
            $followUps[] = 'calibration_sample_growth';
        }

        return [
            'schema_version' => 'atlas.programming.ahcl.next_evolution_scan.v1',
            'significant_jump_remaining' => false,
            'candidate_next_level' => null,
            'follow_up_backlog' => array_values(array_unique($followUps)),
            'rule' => 'Continue evolving only when a gap unlocks materially stronger engineering control.',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function historicalControlSignals(): array
    {
        $items = AtlasProgrammingWorkItem::query()
            ->orderByDesc('created_at')
            ->limit(25)
            ->get();

        $sampleSize = $items->count();
        $closed = $items->where('status', 'closed')->count();
        $blocked = $items->where('status', 'blocked')->count();
        $structural = $items->where('scope_mode', ProgrammingScopeMode::Structural->value)->count();
        $withEvidence = $items->filter(static fn (AtlasProgrammingWorkItem $item): bool => (array) $item->evidence_refs_json !== [])->count();
        $reviews = AtlasProgrammingReview::query()
            ->whereIn('work_item_id', $items->pluck('id')->all())
            ->get();
        $changesRequested = $reviews->where('result', 'changes_requested')->count();

        return [
            'sample_size' => $sampleSize,
            'closed_count' => $closed,
            'blocked_count' => $blocked,
            'structural_count' => $structural,
            'with_evidence_count' => $withEvidence,
            'review_count' => $reviews->count(),
            'changes_requested_count' => $changesRequested,
            'closure_rate' => $sampleSize > 0 ? round($closed / $sampleSize, 4) : null,
            'evidence_coverage_rate' => $sampleSize > 0 ? round($withEvidence / $sampleSize, 4) : null,
            'repair_pressure_rate' => $reviews->count() > 0 ? round($changesRequested / $reviews->count(), 4) : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $forge
     * @param  array<string,mixed>  $predictive
     * @param  array<string,mixed>  $history
     * @return list<array<string,mixed>>
     */
    private function policyRecommendations(
        AtlasProgrammingWorkItem $workItem,
        array $ahcl,
        array $forge,
        array $predictive,
        array $history,
    ): array {
        $recommendations = [];
        $sampleSize = (int) ($history['sample_size'] ?? 0);

        if ($sampleSize < 5) {
            $recommendations[] = [
                'kind' => 'calibration_sample_growth',
                'summary' => 'Keep collecting governed work-item outcomes before trusting optimization weights.',
                'priority' => 'medium',
                'auto_apply_allowed' => false,
            ];
        }

        if (($history['evidence_coverage_rate'] ?? null) !== null && (float) $history['evidence_coverage_rate'] < 0.8) {
            $recommendations[] = [
                'kind' => 'strengthen_evidence_prompts',
                'summary' => 'Evidence coverage below target; require evidence after each provider command.',
                'priority' => 'high',
                'auto_apply_allowed' => false,
            ];
        }

        if (($history['repair_pressure_rate'] ?? null) !== null && (float) $history['repair_pressure_rate'] > 0.25) {
            $recommendations[] = [
                'kind' => 'increase_pre_review_verification',
                'summary' => 'Review changes_requested pressure is high; add verifier before operator review.',
                'priority' => 'medium',
                'auto_apply_allowed' => false,
            ];
        }

        if (data_get($forge, 'control_decision.status') === 'blocked') {
            $recommendations[] = [
                'kind' => 'split_or_serialize_work_packets',
                'summary' => 'Forge ownership collision detected; split packets or require serialize consent.',
                'priority' => 'high',
                'auto_apply_allowed' => false,
            ];
        }

        if (data_get($predictive, 'recommended_path.path') === 'repair_first'
            && data_get($ahcl, 'halt_decision.action') === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR) {
            $recommendations[] = [
                'kind' => 'repair_packet_template',
                'summary' => 'Generate a repair packet template from failed gates and review notes.',
                'priority' => 'medium',
                'auto_apply_allowed' => false,
            ];
        }

        if ($workItem->scope_mode === ProgrammingScopeMode::Structural->value
            && data_get($ahcl, 'halt_decision.action') === ProgrammingHierarchicalControlLoopService::ACTION_CONTINUE) {
            $recommendations[] = [
                'kind' => 'structural_checkpoint_frequency',
                'summary' => 'Structural work should checkpoint before every provider handoff.',
                'priority' => 'medium',
                'auto_apply_allowed' => false,
            ];
        }

        return $recommendations;
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $history
     * @return array<string,mixed>
     */
    private function controlWeights(array $ahcl, array $history): array
    {
        $repairPressure = (float) ($history['repair_pressure_rate'] ?? 0.0);
        $evidenceCoverage = (float) ($history['evidence_coverage_rate'] ?? 1.0);
        $action = (string) data_get($ahcl, 'halt_decision.action');

        return [
            'evidence_weight' => round($evidenceCoverage < 0.8 ? 1.25 : 1.0, 2),
            'review_weight' => round($repairPressure > 0.25 ? 1.25 : 1.0, 2),
            'forge_weight' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR ? 1.15 : 1.0,
            'cost_weight' => $action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT ? 0.75 : 1.0,
            'mutation_policy' => 'recommend_only',
        ];
    }

    private function sessionPhase(AtlasProgrammingWorkItem $workItem, string $action): string
    {
        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT) {
            return 'submit_control';
        }
        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR) {
            return 'repair_control';
        }
        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_REPLAN) {
            return 'planning_control';
        }
        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE) {
            return 'operator_control';
        }

        return match ((string) $workItem->current_stage) {
            'intake', 'spec' => 'planning_control',
            'execution', 'evidence' => 'execution_control',
            'verifying' => 'verification_control',
            'completion' => 'completion_control',
            default => 'live_control',
        };
    }

    private function contextBudget(AtlasProgrammingWorkItem $workItem): array
    {
        $risk = (string) $workItem->risk_level;
        $structural = $workItem->scope_mode === ProgrammingScopeMode::Structural->value;

        return [
            'mode' => $structural ? 'expanded' : 'compact',
            'minimum_context' => $structural
                ? ['owner_docs', 'code_intelligence', 'spec', 'plan', 'task_contracts', 'latest_evidence']
                : ['intent', 'task_contracts', 'latest_evidence'],
            'risk_multiplier' => in_array($risk, ['high', 'critical'], true) ? 'high' : 'normal',
        ];
    }

    private function shouldEmitCheckpoint(AtlasProgrammingWorkItem $workItem, string $action): bool
    {
        return $workItem->scope_mode === ProgrammingScopeMode::Structural->value
            || in_array($action, [
                ProgrammingHierarchicalControlLoopService::ACTION_REPAIR,
                ProgrammingHierarchicalControlLoopService::ACTION_REPLAN,
                ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE,
                ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT,
            ], true);
    }

    private function latestGateStatus(AtlasProgrammingWorkItem $workItem, string $gate): array
    {
        $run = $workItem->latestGate($gate);

        return [
            'gate' => $gate,
            'status' => $run?->status,
            'reason' => $run?->reason,
            'created_at' => $run?->created_at?->toJSON(),
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function timeline(AtlasProgrammingWorkItem $workItem): array
    {
        $events = [];
        $ordinal = 0;

        foreach ((array) $workItem->evidence_refs_json as $receipt) {
            $events[] = [
                'ordinal' => ++$ordinal,
                'kind' => 'evidence',
                'status' => is_array($receipt) ? ($receipt['status'] ?? 'recorded') : 'recorded',
                'summary' => is_array($receipt) ? ($receipt['summary'] ?? $receipt['command'] ?? 'evidence_receipt') : 'evidence_receipt',
                'payload' => $receipt,
                'created_at' => is_array($receipt) ? ($receipt['recorded_at'] ?? $receipt['created_at'] ?? null) : null,
            ];
        }

        $workItem->gateRuns()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (AtlasProgrammingGateRun $run) use (&$events, &$ordinal): void {
                $events[] = [
                    'ordinal' => ++$ordinal,
                    'kind' => 'gate',
                    'gate' => $run->gate_name,
                    'status' => $run->status,
                    'summary' => $run->reason,
                    'created_at' => $run->created_at?->toJSON(),
                ];
            });

        $workItem->reviews()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get()
            ->each(function (AtlasProgrammingReview $review) use (&$events, &$ordinal): void {
                $events[] = [
                    'ordinal' => ++$ordinal,
                    'kind' => 'review',
                    'status' => $review->result,
                    'summary' => $review->summary,
                    'created_at' => $review->created_at?->toJSON(),
                ];
            });

        usort($events, static function (array $a, array $b): int {
            $aTime = (string) ($a['created_at'] ?? '');
            $bTime = (string) ($b['created_at'] ?? '');
            if ($aTime === $bTime) {
                return ((int) $a['ordinal']) <=> ((int) $b['ordinal']);
            }

            return $aTime <=> $bTime;
        });

        return array_values($events);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function workPacketsFromTasks(AtlasProgrammingWorkItem $workItem): array
    {
        $tasks = array_values((array) $workItem->tasks_json);
        if ($tasks === []) {
            return [[
                'packet_id' => 'packet-1',
                'objective' => $workItem->intent_text,
                'expected_files' => [],
                'dependencies' => [],
                'suggested_tests' => [],
            ]];
        }

        $packets = [];
        foreach ($tasks as $index => $task) {
            if (! is_array($task)) {
                continue;
            }

            $packetId = (string) ($task['id'] ?? $task['packet_id'] ?? 'packet-'.($index + 1));
            $expectedFiles = array_values(array_unique(array_filter(array_merge(
                (array) ($task['expected_files'] ?? []),
                (array) ($task['allowed_files'] ?? []),
            ), 'is_string')));

            $packets[] = [
                'packet_id' => $packetId,
                'objective' => (string) ($task['objective'] ?? $task['acceptance_criteria'][0] ?? $workItem->intent_text),
                'expected_files' => $expectedFiles,
                'dependencies' => array_values((array) ($task['dependencies'] ?? [])),
                'suggested_tests' => array_values((array) ($task['validation_commands'] ?? [])),
                'risk_level' => (string) ($task['risk_level'] ?? $workItem->risk_level),
            ];
        }

        return $packets === [] ? [[
            'packet_id' => 'packet-1',
            'objective' => $workItem->intent_text,
            'expected_files' => [],
            'dependencies' => [],
            'suggested_tests' => [],
        ]] : array_values($packets);
    }

    private function forgeRiskBand(string $risk): string
    {
        return in_array($risk, ForgeMultiAgentScheduleCanon::RISK_BANDS, true)
            ? $risk
            : ForgeMultiAgentScheduleCanon::RISK_MEDIUM;
    }

    /**
     * @param  list<array<string,mixed>>  $packets
     */
    private function requiresForge(AtlasProgrammingWorkItem $workItem, array $packets, string $action): bool
    {
        return $workItem->scope_mode === ProgrammingScopeMode::Structural->value
            || count($packets) > 1
            || in_array((string) $workItem->risk_level, ['high', 'critical'], true)
            || $action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR;
    }

    /**
     * @param  array<string,mixed>|null  $schedule
     * @param  array<string,mixed>|null  $scheduleError
     * @return array<string,mixed>
     */
    private function forgeControlDecision(
        string $action,
        bool $requiresForge,
        string $scheduleStatus,
        ?array $scheduleError,
    ): array {
        if ($scheduleError !== null || $scheduleStatus === ForgeMultiAgentScheduleCanon::STATUS_BLOCKED_BY_OWNERSHIP_CONFLICT) {
            return [
                'status' => 'blocked',
                'action' => 'hold_for_collision_or_scheduler_repair',
                'reason' => $scheduleError['message'] ?? $scheduleStatus,
            ];
        }

        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT) {
            return [
                'status' => 'release_ready',
                'action' => 'promote_to_completion_or_release_gate',
                'reason' => 'ahcl_submit_ready',
            ];
        }

        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR) {
            return [
                'status' => 'repair_packet_required',
                'action' => 'open_repair_packet',
                'reason' => 'ahcl_repair',
            ];
        }

        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_REPLAN) {
            return [
                'status' => 'planning_required',
                'action' => 'return_to_spec_or_plan',
                'reason' => 'ahcl_replan',
            ];
        }

        if ($action === ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE) {
            return [
                'status' => 'operator_decision_required',
                'action' => 'pause_forge_until_operator_decision',
                'reason' => 'ahcl_escalate',
            ];
        }

        return [
            'status' => $requiresForge ? 'forge_execution_ready' : 'dev_execution_ready',
            'action' => $requiresForge ? 'dispatch_forge_schedule' : 'continue_dev_fast_path',
            'reason' => $requiresForge ? 'work_requires_forge_control' : 'compact_work_can_continue_in_dev',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $schedule
     * @return array<string,mixed>
     */
    private function providerStrategy(AtlasProgrammingWorkItem $workItem, ?array $schedule, string $action): array
    {
        $roles = is_array($schedule) ? (array) ($schedule['roles_summary'] ?? []) : [];
        $risk = (string) $workItem->risk_level;

        return [
            'primary_profile' => in_array($risk, ['high', 'critical'], true) ? 'senior_reasoning_provider' : 'efficient_coding_provider',
            'fallback_profile' => 'provider_neutral_repair_or_review',
            'role_provider_hints' => array_map(
                static fn (string $role): array => [
                    'role' => $role,
                    'preferred_capability' => match ($role) {
                        ForgeMultiAgentScheduleCanon::ROLE_VERIFIER => 'tests_and_evidence',
                        ForgeMultiAgentScheduleCanon::ROLE_REVIEWER => 'code_review_and_risk',
                        ForgeMultiAgentScheduleCanon::ROLE_RESEARCHER => 'codebase_context',
                        ForgeMultiAgentScheduleCanon::ROLE_DEBUGGER => 'failure_diagnosis',
                        default => 'implementation',
                    },
                ],
                array_keys($roles),
            ),
            'action_binding' => $action,
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  array<string,mixed>  $forge
     * @return list<array<string,mixed>>
     */
    private function predictionOptions(AtlasProgrammingWorkItem $workItem, array $ahcl, array $forge): array
    {
        $action = (string) data_get($ahcl, 'halt_decision.action');
        $readiness = (float) data_get($ahcl, 'readiness_score.score', 0.0);
        $riskPenalty = in_array((string) $workItem->risk_level, ['high', 'critical'], true) ? 0.15 : 0.0;
        $scheduleBlocked = (string) data_get($forge, 'control_decision.status') === 'blocked';

        $base = [
            [
                'path' => 'continue_dev_fast_path',
                'expected_action' => 'continue',
                'cost_score' => 0.25,
                'risk_score' => 0.35 + $riskPenalty,
                'pass_probability' => $action === ProgrammingHierarchicalControlLoopService::ACTION_CONTINUE ? 0.72 : 0.35,
            ],
            [
                'path' => 'repair_first',
                'expected_action' => 'repair',
                'cost_score' => 0.45,
                'risk_score' => 0.30 + $riskPenalty,
                'pass_probability' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPAIR ? 0.78 : 0.40,
            ],
            [
                'path' => 'replan_first',
                'expected_action' => 'replan',
                'cost_score' => 0.55,
                'risk_score' => 0.25,
                'pass_probability' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPLAN ? 0.82 : 0.45,
            ],
            [
                'path' => 'forge_parallel_control',
                'expected_action' => 'dispatch_forge_schedule',
                'cost_score' => 0.75,
                'risk_score' => $scheduleBlocked ? 0.95 : 0.22 + $riskPenalty,
                'pass_probability' => $scheduleBlocked ? 0.05 : 0.68,
            ],
            [
                'path' => 'submit_now',
                'expected_action' => 'submit',
                'cost_score' => 0.10,
                'risk_score' => $readiness >= 9.5 ? 0.05 : 0.90,
                'pass_probability' => $action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT ? 0.98 : 0.02,
            ],
        ];

        return array_map(static function (array $option): array {
            $option['expected_value'] = round(((float) $option['pass_probability']) - ((float) $option['risk_score'] * 0.4) - ((float) $option['cost_score'] * 0.2), 4);

            return $option;
        }, $base);
    }

    /**
     * @param  list<array<string,mixed>>  $predictionOptions
     * @return array<string,mixed>
     */
    private function recommendedPath(string $action, array $predictionOptions): array
    {
        $target = match ($action) {
            ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT => 'submit_now',
            ProgrammingHierarchicalControlLoopService::ACTION_REPAIR => 'repair_first',
            ProgrammingHierarchicalControlLoopService::ACTION_REPLAN => 'replan_first',
            ProgrammingHierarchicalControlLoopService::ACTION_ESCALATE => 'replan_first',
            default => 'continue_dev_fast_path',
        };

        foreach ($predictionOptions as $option) {
            if (($option['path'] ?? null) === $target) {
                return [
                    'status' => $action === ProgrammingHierarchicalControlLoopService::ACTION_SUBMIT ? 'ready' : 'not_ready',
                    'path' => $target,
                    'expected_action' => $option['expected_action'],
                    'expected_value' => $option['expected_value'],
                    'reason' => 'aligned_with_ahcl_action_'.$action,
                ];
            }
        }

        return [
            'status' => 'not_ready',
            'path' => 'continue_dev_fast_path',
            'expected_action' => 'continue',
            'expected_value' => 0.0,
            'reason' => 'fallback_path',
        ];
    }

    /**
     * @param  array<string,mixed>  $ahcl
     * @param  list<array<string,mixed>>  $timeline
     * @param  array<string,mixed>  $forge
     * @return list<array<string,mixed>>
     */
    private function learningCandidates(
        AtlasProgrammingWorkItem $workItem,
        array $ahcl,
        array $timeline,
        array $forge,
    ): array {
        $candidates = [];
        $action = (string) data_get($ahcl, 'halt_decision.action');
        $requiredRepairs = array_values((array) data_get($ahcl, 'halt_decision.required_repairs', []));

        if ($requiredRepairs !== []) {
            $candidates[] = [
                'kind' => 'gate_repair_pattern',
                'severity' => $action === ProgrammingHierarchicalControlLoopService::ACTION_REPLAN ? 'high' : 'medium',
                'summary' => 'AHCL required repairs: '.implode(', ', $requiredRepairs),
                'targets' => $requiredRepairs,
                'auto_apply_allowed' => false,
            ];
        }

        if (data_get($forge, 'control_decision.status') === 'blocked') {
            $candidates[] = [
                'kind' => 'forge_collision_learning',
                'severity' => 'high',
                'summary' => 'Forge schedule blocked; improve work packet ownership or serialization policy.',
                'targets' => ['forge_multi_agent_schedule', 'scope_guard'],
                'auto_apply_allowed' => false,
            ];
        }

        foreach ($timeline as $event) {
            if (($event['kind'] ?? null) === 'review' && ($event['status'] ?? null) === 'changes_requested') {
                $candidates[] = [
                    'kind' => 'review_feedback_learning',
                    'severity' => 'medium',
                    'summary' => (string) ($event['summary'] ?? 'review requested changes'),
                    'targets' => ['review', 'repair_loop'],
                    'auto_apply_allowed' => false,
                ];
            }
        }

        if ((array) $workItem->evidence_refs_json === [] && $workItem->current_stage !== 'intake') {
            $candidates[] = [
                'kind' => 'evidence_gap_learning',
                'severity' => 'medium',
                'summary' => 'Work item advanced without evidence; strengthen live evidence prompts.',
                'targets' => ['evidence_required', 'live_session_control'],
                'auto_apply_allowed' => false,
            ];
        }

        return $candidates;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<string>
     */
    private function learningEvidenceRefs(AtlasProgrammingWorkItem $workItem, array $snapshot): array
    {
        $refs = [
            'work_item:'.$workItem->code,
        ];

        $planeHash = (string) data_get($snapshot, 'plane_hash', '');
        if ($planeHash !== '') {
            $refs[] = 'aahcp_plane:'.$planeHash;
        }

        $replayHash = (string) data_get($snapshot, 'predictive_replay_learning_v4.replay.replay_hash', '');
        if ($replayHash !== '') {
            $refs[] = 'aahcp_replay:'.$replayHash;
        }

        foreach ((array) $workItem->evidence_refs_json as $receipt) {
            if (! is_array($receipt)) {
                continue;
            }
            $receiptId = (string) ($receipt['receipt_id'] ?? '');
            if ($receiptId !== '') {
                $refs[] = 'receipt:'.$receiptId;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function hashPayload(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
