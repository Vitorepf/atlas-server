<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor;

use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use App\Services\Ai\Compounding\AtlasCompoundingOutcomeEvaluator;
use App\Services\Ai\Context\AtlasIntelligenceRolloutMode;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * One outcome interface for Dev, Forge and Autônomos.
 *
 * It delegates all persistence, judgment and proposal creation to AEMOR. A
 * successful claim without evidence is rejected before an episode is opened;
 * learning remains a pending delta and is never promoted here.
 */
class AtlasEngineeringOutcomeRecorder
{
    public const SCHEMA_VERSION = 'atlas.engineering_outcome.v1';

    public function __construct(
        private readonly AtlasAemorRuntimeService $runtime,
        private readonly AtlasAemorJudgmentService $judgment,
    ) {}

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function record(array $input): array
    {
        $enabled = (bool) config('atlas.aemor.engineering_outcome_enabled', true);
        $rolloutMode = AtlasIntelligenceRolloutMode::resolve([
            'enabled' => $enabled,
            'mode' => (string) config('atlas.aemor.engineering_outcome_mode', AtlasIntelligenceRolloutMode::DEFAULT),
            'canary_percent' => (int) config('atlas.aemor.engineering_outcome_canary_percent', 0),
        ], [
            'workspace' => (string) ($input['workspace'] ?? base_path()),
            'flow_id' => 'engineering.'.strtolower(trim((string) ($input['executor'] ?? ''))),
            'actor' => (string) ($input['executor'] ?? 'engineering'),
            'scope_id' => (string) ($input['scope_id'] ?? ''),
        ]);
        $rolloutReceipt = AtlasIntelligenceRolloutMode::receipt($rolloutMode, $enabled);
        if ($rolloutMode === AtlasIntelligenceRolloutMode::OFFLINE) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'offline',
                'reason' => 'engineering_outcome_rollout_offline',
                'writes' => false,
                'rollout' => $rolloutReceipt,
            ];
        }

        $executor = strtolower(trim((string) ($input['executor'] ?? '')));
        $objective = trim((string) ($input['objective'] ?? ''));
        $status = trim((string) ($input['status'] ?? ''));
        $evidenceRefs = array_values(array_unique(array_filter(
            (array) ($input['evidence_refs'] ?? []),
            static fn (mixed $ref): bool => is_string($ref) && trim($ref) !== '',
        )));

        if (! in_array($executor, ['dev', 'forge', 'autonomos'], true)) {
            return $this->blocked('invalid_executor');
        }
        if ($objective === '') {
            return $this->blocked('objective_required');
        }
        if ($evidenceRefs === []) {
            return $this->blocked('evidence_required');
        }
        if (! in_array($status, ['succeeded', 'failed', 'blocked'], true)) {
            return $this->blocked('invalid_status');
        }

        try {
            $episode = $this->runtime->openEpisode([
                'objective' => $objective,
                'workspace' => (string) ($input['workspace'] ?? base_path()),
                'surface_id' => (string) ($input['surface_id'] ?? $executor),
                'domain' => 'programming',
                'flow_id' => 'engineering.'.$executor,
                'provider' => isset($input['provider']) ? (string) $input['provider'] : null,
                'scope_type' => (string) ($input['scope_type'] ?? 'workspace'),
                'scope_id' => isset($input['scope_id']) ? (string) $input['scope_id'] : null,
                'persistent_context_hash' => isset($input['persistent_context_hash'])
                    ? (string) $input['persistent_context_hash']
                    : null,
                'evidence_refs' => $evidenceRefs,
                'metadata' => [
                    'engineering_outcome_schema' => self::SCHEMA_VERSION,
                    'executor' => $executor,
                    'rollout_mode' => $rolloutMode,
                ],
            ]);
            if (($episode['status'] ?? null) !== 'open' || empty($episode['episode_id'])) {
                return $this->degraded('episode_not_opened');
            }

            $event = $this->runtime->observe([
                'episode_id' => $episode['episode_id'],
                'event_type' => 'engineering_outcome_reported',
                'stage' => 'completion',
                'status' => $status,
                'payload' => [
                    'executor' => $executor,
                    'tests_passed' => (bool) data_get($input, 'metrics.tests_passed', false),
                    'provider_calls_made' => (bool) ($input['provider_calls_made'] ?? false),
                    'rollout_mode' => $rolloutMode,
                ],
                'evidence_refs' => $evidenceRefs,
            ]);
            $outcome = $this->runtime->closeOutcome([
                'episode_id' => $episode['episode_id'],
                'status' => $status,
                'outcome_type' => 'engineering_delivery',
                'summary' => trim((string) ($input['summary'] ?? $objective)),
                'metrics' => (array) ($input['metrics'] ?? []),
                'blockers' => (array) ($input['blockers'] ?? []),
                'context_utility' => (array) ($input['context_utility'] ?? []),
                'patch_outcome' => (array) ($input['patch_outcome'] ?? []),
                'evidence_refs' => $evidenceRefs,
            ]);
            $judgment = $this->judgment->judge((string) $episode['episode_id']);

            $learning = ['status' => 'skipped', 'reason' => 'no_learning_claim'];
            $claim = trim((string) ($input['learning_claim'] ?? ''));
            $allowDistill = AtlasIntelligenceRolloutMode::shouldExecuteLive($rolloutMode);
            if ($allowDistill && $claim !== '' && data_get($judgment, 'false_learning_gate.status') === 'pass') {
                $learning = $this->runtime->distill([
                    'episode_id' => $episode['episode_id'],
                    'outcome_id' => $outcome['outcome_id'] ?? null,
                    'claim' => $claim,
                    'evidence_refs' => $evidenceRefs,
                ]);
            } elseif ($claim !== '' && ! $allowDistill) {
                $learning = ['status' => 'shadow_skipped', 'reason' => 'rollout_shadow_no_distill'];
            }

            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'recorded',
                'executor' => $executor,
                'rollout' => $rolloutReceipt,
                'episode' => [
                    'status' => $episode['status'] ?? null,
                    'episode_id' => $episode['episode_id'] ?? null,
                ],
                'event' => [
                    'status' => $event['status'] ?? null,
                    'event_id' => $event['event_id'] ?? null,
                ],
                'outcome' => [
                    'status' => $outcome['status'] ?? null,
                    'outcome_id' => $outcome['outcome_id'] ?? null,
                ],
                'judgment' => [
                    'status' => $judgment['status'] ?? null,
                    'judgment_hash' => $judgment['judgment_hash'] ?? null,
                ],
                'learning' => [
                    'status' => $learning['status'] ?? null,
                    'candidate_id' => $learning['memory_candidate_id'] ?? null,
                    'memory_delta_id' => $learning['memory_delta_id'] ?? null,
                ],
                'promotion_policy' => [
                    'auto_promote' => false,
                    'operator_review_required' => true,
                    'note' => 'AEMOR deltas stay pending; compounding auto-promote is a separate governed path',
                ],
                'spine' => $this->fanOutSpineWriters($input, $status, $evidenceRefs, (string) ($episode['episode_id'] ?? '')),
            ];
        } catch (Throwable $exception) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'degraded',
                'reason' => 'aemor_exception',
                'exception_class' => $exception::class,
                'error_hash' => hash('sha256', $exception->getMessage()),
                'rollout' => $rolloutReceipt,
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function blocked(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'blocked',
            'reason' => $reason,
            'writes' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function degraded(string $reason): array
    {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'degraded',
            'reason' => $reason,
        ];
    }

    /**
     * OUTC-01 spine fan-out: the same harness-captured outcome also lands in
     * ai_run_outcomes and live_outcomes.jsonl — one writer, three sinks.
     *
     * @param  list<string>  $evidenceRefs
     * @return array<string,mixed>
     */
    private function fanOutSpineWriters(array $input, string $status, array $evidenceRefs, string $episodeId): array
    {
        $result = [
            'ai_run_outcome' => ['recorded' => false],
            'live_outcome' => ['recorded' => false],
        ];

        $executor = strtolower(trim((string) ($input['executor'] ?? '')));
        $runId = trim((string) ($input['run_id'] ?? $input['scope_id'] ?? $episodeId));
        if ($runId === '') {
            $runId = 'engineering-'.substr(hash('sha256', json_encode($input)), 0, 16);
        }

        $flowId = trim((string) ($input['outcome_flow_id'] ?? ''));
        if ($flowId === '') {
            $flowId = match ($executor) {
                'dev' => 'atlas_dev',
                'forge' => 'atlas_forge',
                'autonomos' => 'atlas_autonomos',
                default => 'engineering_'.$executor,
            };
        }

        $outcomeStatus = match ($status) {
            'succeeded' => 'passed',
            'failed' => 'failed',
            default => 'blocked',
        };

        try {
            if (DatabaseTableAvailability::has('ai_run_outcomes')) {
                $evaluator = app(AtlasCompoundingOutcomeEvaluator::class);
                $outcome = $evaluator->evaluate([
                    'run_id' => $runId,
                    'flow_id' => $flowId,
                    'outcome_status' => $outcomeStatus,
                    'evidence_refs' => $evidenceRefs,
                    'execution_quality' => data_get($input, 'metrics.tests_passed') === true ? 90 : 40,
                    'evidence_quality' => $evidenceRefs === [] ? 35 : 90,
                    'verified' => (bool) ($input['verified'] ?? ($outcomeStatus === 'passed')),
                    'actor_tag' => isset($input['actor_tag']) ? (string) $input['actor_tag'] : null,
                    'lote' => $input['lote'] ?? null,
                    'slice_id' => isset($input['slice_id']) ? (string) $input['slice_id'] : null,
                    'slice_state' => isset($input['slice_state']) ? (string) $input['slice_state'] : null,
                ]);
                $result['ai_run_outcome'] = [
                    'recorded' => true,
                    'id' => $outcome->id,
                    'outcome_hash' => $outcome->outcome_hash,
                ];
            }
        } catch (Throwable) {
            // fail-open
        }

        try {
            $feedback = app(AtlasDecideLiveOutcomeFeedbackService::class);
            $liveResult = match ($outcomeStatus) {
                'passed' => AtlasDecideLiveOutcomeFeedbackService::RESULT_SUCCESS,
                'failed' => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
                default => AtlasDecideLiveOutcomeFeedbackService::RESULT_FAILURE,
            };
            $record = $feedback->record([
                'task_category' => $executor === 'dev' ? 'programming' : $executor,
                'role' => (string) ($input['surface_id'] ?? $executor),
                'provider' => (string) ($input['provider'] ?? 'local'),
                'result' => $liveResult,
                'proven_real' => (bool) ($input['verified'] ?? false) && $outcomeStatus === 'passed',
                'quality_score' => data_get($input, 'metrics.tests_passed') === true ? 1.0 : 0.0,
                'actor' => 'engineering_outcome_spine:'.(isset($input['actor_tag']) ? (string) $input['actor_tag'] : $executor),
            ]);
            $result['live_outcome'] = ['recorded' => true, 'receipt' => $record];
        } catch (Throwable) {
            // fail-open
        }

        return $result;
    }
}
