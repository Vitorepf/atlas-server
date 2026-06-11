<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Atlas Code Forge Fast Path · Run Status Service (v2).
 *
 * Reconstroi o estado real de um Fast Path run a partir de:
 *   - AtlasProject.metadata.latest_atlas_code_forge_fast_path_run / *_history
 *   - AtlasProject.metadata.latest_forge_live_execution
 *   - AtlasProject.metadata.latest_forge_live_execution_async
 *   - AtlasProject.metadata.atlas_code_forge_live_execution_async_history
 *   - AtlasProject.metadata.atlas_code_forge_live_execution_history
 *   - AtlasProject.metadata.atlas_code_forge_reviews
 *
 * Nao executa nada, nao chama provider externo, nao sintetiza sucesso.
 *
 * Schema: atlas.code.forge_fast_path_run_status.v1
 */
class AtlasCodeForgeFastPathStatusService
{
    public const SCHEMA_VERSION = 'atlas.code.forge_fast_path_run_status.v1';

    /**
     * @return array<string,mixed>
     */
    public function status(AtlasProject $project, string $runId): array
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $run = $this->findRun($metadata, $runId);

        if ($run === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'obra_id' => (string) $project->getKey(),
                'fast_path_run_id' => $runId,
                'run_found' => false,
                'blocker' => 'fast_path_run_not_found',
                'reason' => "Fast Path run [{$runId}] nao encontrado em latest_atlas_code_forge_fast_path_run nem em history desta Obra.",
                'next_action' => 'start_new_fast_path_or_correct_run_id',
                'http_status' => 404,
                'external_provider_call' => false,
            ];
        }

        $runObraId = (string) ($run['obra_id'] ?? '');
        if ($runObraId !== '' && $runObraId !== (string) $project->getKey()) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'obra_id' => (string) $project->getKey(),
                'fast_path_run_id' => $runId,
                'run_found' => true,
                'blocker' => 'fast_path_run_obra_mismatch',
                'reason' => "Fast Path run [{$runId}] pertence a obra [{$runObraId}], nao a [".(string) $project->getKey()."].",
                'next_action' => 'use_correct_obra',
                'http_status' => 403,
                'external_provider_call' => false,
            ];
        }

        $executionId = AiValueNormalizer::trimmedStringOrNull($run['execution_id'] ?? null);
        $historyId = AiValueNormalizer::trimmedStringOrNull($run['history_id'] ?? null);

        $async = $executionId !== null ? $this->findAsyncExecution($metadata, $executionId) : null;
        $forgeLive = $this->forgeLiveForRun($metadata, $run, $async);
        $review = $this->findLatestReview($metadata, $historyId, $executionId);

        $reviewState = $this->resolveReviewState($run, $async, $forgeLive, $review);
        $repair = $this->resolveRepairState($forgeLive, $async);
        $lifecycleStatus = $this->resolveLifecycleStatus($run, $async, $forgeLive, $reviewState, $repair);
        $progressPercent = $this->resolveProgressPercent($run, $async, $forgeLive, $lifecycleStatus);
        $nextAction = $this->resolveNextAction($lifecycleStatus, $reviewState, $repair, $run);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'fast_path_run_id' => (string) ($run['fast_path_run_id'] ?? $runId),
            'obra_id' => (string) $project->getKey(),
            'work_item_id' => $run['work_item_id'] ?? null,
            'work_item_code' => $run['work_item_code'] ?? null,
            'execution_id' => $executionId,
            'history_id' => $historyId,
            'checkpoint_id' => $run['checkpoint_id'] ?? null,
            'status' => $lifecycleStatus,
            'mode' => (string) ($run['mode'] ?? 'execute_async'),
            'current_stage' => (string) ($run['current_stage'] ?? 'unknown'),
            'progress_percent' => $progressPercent,
            'spec_hash' => $run['spec_hash'] ?? null,
            'plan_hash' => $run['plan_hash'] ?? null,
            'task_count' => (int) ($run['task_count'] ?? 0),
            'started_at' => $run['started_at'] ?? null,
            'updated_at' => $run['updated_at'] ?? null,
            'completed_at' => $this->resolveCompletedAt($run, $lifecycleStatus, $async, $forgeLive),
            'blockers' => array_values(array_unique(array_filter(array_merge(
                (array) ($run['blockers'] ?? []),
                $repair['blockers'] ?? [],
            ), static fn (mixed $v): bool => is_string($v) && $v !== ''))),
            'evidence_refs' => array_values(array_unique(array_filter(array_merge(
                (array) ($run['evidence_refs'] ?? []),
                $async ? (array) ($async['evidence_refs'] ?? []) : [],
            ), static fn (mixed $v): bool => is_string($v) && $v !== ''))),
            'evidence_ref_count' => count((array) ($forgeLive['evidence_refs'] ?? $run['evidence_refs'] ?? [])),
            'ledger_event_count' => (int) ($forgeLive['ledger_event_count'] ?? 0),
            'async_execution' => $this->asyncProjection($async),
            'forge_live_execution' => $this->forgeLiveProjection($forgeLive),
            'review_gate' => $reviewState,
            'repair' => $repair,
            'commands' => $this->commandsFor($project, $run, $reviewState, $repair, $lifecycleStatus),
            'next_action' => $nextAction,
            'run_found' => true,
            'http_status' => 200,
            'external_provider_call' => false,
            'note' => 'Status reconstruido a partir do estado real da Obra. Nao sintetiza sucesso. Review gate bloqueia completed automatico.',
        ];
    }

    /**
     * Mesmo shape do `status` mas sem mudar estado — `resume` apenas reidrata
     * o run e retorna `next_action` claro.
     *
     * @return array<string,mixed>
     */
    public function resume(AtlasProject $project, string $runId): array
    {
        return $this->status($project, $runId);
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    private function findRun(array $metadata, string $runId): ?array
    {
        $latest = data_get($metadata, 'latest_atlas_code_forge_fast_path_run');
        if (is_array($latest) && (string) ($latest['fast_path_run_id'] ?? '') === $runId) {
            return $latest;
        }

        foreach ((array) data_get($metadata, 'atlas_code_forge_fast_path_run_history', []) as $entry) {
            if (is_array($entry) && (string) ($entry['fast_path_run_id'] ?? '') === $runId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    private function findAsyncExecution(array $metadata, string $executionId): ?array
    {
        $latest = data_get($metadata, 'latest_forge_live_execution_async');
        if (is_array($latest) && (string) ($latest['execution_id'] ?? '') === $executionId) {
            return $latest;
        }

        $runs = (array) data_get($metadata, 'atlas_code_forge_live_execution_async_history', []);
        foreach ($runs as $entry) {
            if (is_array($entry) && (string) ($entry['execution_id'] ?? '') === $executionId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     * @return array<string,mixed>
     */
    private function forgeLiveForRun(array $metadata, array $run, ?array $async): array
    {
        $latest = data_get($metadata, 'latest_forge_live_execution');
        if (is_array($latest) && $this->forgeLiveMatchesRun($latest, $run, $async)) {
            return $latest;
        }

        foreach ((array) data_get($metadata, 'atlas_code_forge_live_execution_history', []) as $entry) {
            if (is_array($entry) && $this->forgeLiveMatchesRun($entry, $run, $async)) {
                return $entry;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     */
    private function forgeLiveMatchesRun(array $forgeLive, array $run, ?array $async): bool
    {
        $runObraId = AiValueNormalizer::trimmedStringOrNull($run['obra_id'] ?? null);
        $forgeObraId = AiValueNormalizer::trimmedStringOrNull($forgeLive['obra_id'] ?? null);
        if ($runObraId !== null && $forgeObraId !== null && $runObraId !== $forgeObraId) {
            return false;
        }

        $historyId = AiValueNormalizer::trimmedStringOrNull($run['history_id'] ?? null);
        if ($historyId !== null) {
            if ((string) ($forgeLive['history_id'] ?? '') === $historyId) {
                return true;
            }
            if ((string) ($forgeLive['run_id'] ?? '') === $historyId) {
                return true;
            }
        }

        $asyncRunId = $async !== null ? AiValueNormalizer::trimmedStringOrNull($async['run_id'] ?? null) : null;
        if ($asyncRunId !== null && (string) ($forgeLive['run_id'] ?? '') === $asyncRunId) {
            return true;
        }

        $asyncEvidenceId = $async !== null ? AiValueNormalizer::trimmedStringOrNull($async['evidence_id'] ?? null) : null;
        if ($asyncEvidenceId !== null && (string) ($forgeLive['evidence_id'] ?? '') === $asyncEvidenceId) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string,mixed>  $metadata
     * @return array<string,mixed>|null
     */
    private function findLatestReview(array $metadata, ?string $historyId, ?string $executionId): ?array
    {
        $reviews = (array) data_get($metadata, 'atlas_code_forge_reviews', (array) data_get($metadata, 'atlas_code_forge_review_history', []));
        foreach ($reviews as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            if ($historyId !== null && (string) ($entry['history_id'] ?? '') === $historyId) {
                return $entry;
            }
            if ($executionId !== null && (string) ($entry['execution_id'] ?? '') === $executionId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>|null  $review
     * @return array<string,mixed>
     */
    private function resolveReviewState(array $run, ?array $async, array $forgeLive, ?array $review): array
    {
        $forgeStatus = (string) ($forgeLive['status'] ?? 'missing');
        $asyncStatus = $async ? (string) ($async['status'] ?? 'queued') : null;
        $completionAllowed = (bool) data_get($forgeLive, 'diff_scope.completion_gate.completion_claim_allowed', false);

        $required = $forgeStatus === 'passed';
        $reviewStatus = $review !== null
            ? (string) ($review['decision'] ?? $review['status'] ?? 'pending')
            : 'not_required';

        if ($required && $review === null) {
            $reviewStatus = 'pending';
        }
        if (! $required && $reviewStatus === 'not_required' && $asyncStatus !== null) {
            $reviewStatus = 'not_required';
        }

        return [
            'schema_version' => 'atlas.code.forge_fast_path_run_status.review_gate.v1',
            'review_required' => $required,
            'review_status' => $reviewStatus,
            'completion_claim_allowed' => $completionAllowed,
            'review_record_present' => $review !== null,
            'review_id' => $review !== null ? (string) ($review['review_id'] ?? $review['id'] ?? '') : null,
            'approval_api' => 'POST /atlas-code/works/'.(string) ($run['obra_id'] ?? '').'/forge/reviews',
            'no_auto_completion_without_review' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>|null  $async
     * @return array<string,mixed>
     */
    private function resolveRepairState(array $forgeLive, ?array $async): array
    {
        $forgeStatus = (string) ($forgeLive['status'] ?? 'missing');
        $repairLoop = is_array($forgeLive['repair_loop'] ?? null) ? $forgeLive['repair_loop'] : [];
        $blockers = array_values((array) ($forgeLive['remaining_blockers'] ?? []));

        $degraded = in_array($forgeStatus, ['blocked', 'degraded', 'failed'], true)
            || ($async !== null && in_array((string) ($async['status'] ?? ''), ['failed', 'blocked'], true));

        $failurePacket = is_array($forgeLive['failure_packet'] ?? null)
            ? $forgeLive['failure_packet']
            : (is_array(data_get($repairLoop, 'failure_packet')) ? (array) data_get($repairLoop, 'failure_packet') : null);

        $repairAvailable = $degraded && $blockers !== [];

        return [
            'schema_version' => 'atlas.code.forge_fast_path_run_status.repair.v1',
            'repair_available' => $repairAvailable,
            'repair_loop_status' => (string) ($repairLoop['status'] ?? ($degraded ? 'pending' : 'skipped_not_needed')),
            'repair_loop_triggered' => (bool) ($repairLoop['triggered'] ?? false),
            'failure_packet' => $failurePacket,
            'suggested_repair_command' => $repairAvailable
                ? 'php artisan atlas:forge:live-execute --obra=<uuid> --simulate-failure --json'
                : null,
            'blockers' => $blockers,
            'fail_closed_without_evidence' => $repairAvailable && $failurePacket === null,
        ];
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $repair
     */
    private function resolveLifecycleStatus(array $run, ?array $async, array $forgeLive, array $review, array $repair): string
    {
        $runStatus = (string) ($run['status'] ?? 'unknown');
        $forgeStatus = (string) ($forgeLive['status'] ?? 'missing');
        $asyncStatus = $async ? (string) ($async['status'] ?? 'queued') : null;

        if ($runStatus === 'blocked' || $forgeStatus === 'blocked') {
            return 'blocked';
        }

        if (in_array($asyncStatus, ['failed'], true) || $forgeStatus === 'failed') {
            return 'failed';
        }

        if ($repair['repair_available'] === true) {
            return $forgeStatus === 'degraded' ? 'degraded' : 'failed';
        }

        if ($asyncStatus === 'queued') {
            return 'queued';
        }
        if ($asyncStatus === 'running') {
            return 'running';
        }

        if ($forgeStatus === 'passed' && ($review['review_status'] ?? '') === 'pending') {
            return 'review_required';
        }

        if ($forgeStatus === 'passed' && ($review['review_status'] ?? '') === 'approved') {
            return 'completed';
        }

        if ($forgeStatus === 'passed') {
            return 'passed';
        }

        if ($forgeStatus === 'degraded') {
            return 'degraded';
        }

        return $runStatus;
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     * @param  array<string,mixed>  $forgeLive
     */
    private function resolveProgressPercent(array $run, ?array $async, array $forgeLive, string $lifecycleStatus): int
    {
        $base = (int) ($run['progress_percent'] ?? 0);
        if (in_array($lifecycleStatus, ['blocked', 'failed'], true)) {
            return $base;
        }
        if ($lifecycleStatus === 'completed') {
            return 100;
        }
        if ($lifecycleStatus === 'review_required' || $lifecycleStatus === 'passed') {
            return max($base, 90);
        }
        if ($lifecycleStatus === 'running' || $lifecycleStatus === 'queued') {
            return max($base, 60);
        }
        if ($lifecycleStatus === 'degraded') {
            return max($base, 75);
        }

        return $base;
    }

    /**
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $repair
     * @param  array<string,mixed>  $run
     */
    private function resolveNextAction(string $lifecycleStatus, array $review, array $repair, array $run): string
    {
        return match (true) {
            $lifecycleStatus === 'completed' => 'completed',
            $lifecycleStatus === 'review_required' => 'open_human_review',
            $lifecycleStatus === 'queued' || $lifecycleStatus === 'running' => 'wait_for_async_execution',
            $repair['repair_available'] === true => 'run_repair',
            $lifecycleStatus === 'passed' => 'approve_completion',
            $lifecycleStatus === 'blocked' || $lifecycleStatus === 'failed' => 'inspect_blocker',
            default => (string) ($run['next_action'] ?? 'inspect_blocker'),
        };
    }

    /**
     * @param  array<string,mixed>  $run
     */
    private function resolveCompletedAt(array $run, string $lifecycleStatus, ?array $async, array $forgeLive): ?string
    {
        if ($lifecycleStatus === 'completed') {
            return (string) ($run['updated_at'] ?? $forgeLive['last_run_at'] ?? now()->toIso8601String());
        }
        if ($lifecycleStatus === 'passed') {
            return (string) ($forgeLive['last_run_at'] ?? $run['updated_at'] ?? '');
        }

        return $run['completed_at'] ?? null;
    }

    /**
     * @param  array<string,mixed>|null  $async
     * @return array<string,mixed>|null
     */
    private function asyncProjection(?array $async): ?array
    {
        if ($async === null) {
            return null;
        }

        return [
            'execution_id' => $async['execution_id'] ?? null,
            'status' => $async['status'] ?? null,
            'queued_at' => $async['queued_at'] ?? null,
            'started_at' => $async['started_at'] ?? null,
            'finished_at' => $async['finished_at'] ?? null,
            'snapshot_status' => $async['snapshot_status'] ?? null,
            'run_id' => $async['run_id'] ?? null,
            'evidence_id' => $async['evidence_id'] ?? null,
            'error' => $async['error'] ?? null,
        ];
    }

    /**
     * @param  array<string,mixed>  $forgeLive
     * @return array<string,mixed>|null
     */
    private function forgeLiveProjection(array $forgeLive): ?array
    {
        if ($forgeLive === []) {
            return null;
        }

        return [
            'status' => $forgeLive['status'] ?? null,
            'last_run_at' => $forgeLive['last_run_at'] ?? null,
            'run_id' => $forgeLive['run_id'] ?? null,
            'evidence_id' => $forgeLive['evidence_id'] ?? null,
            'evidence_ref_count' => (int) ($forgeLive['evidence_ref_count'] ?? 0),
            'ledger_event_count' => (int) ($forgeLive['ledger_event_count'] ?? 0),
            'remaining_blockers' => array_values((array) ($forgeLive['remaining_blockers'] ?? [])),
            'completion_claim_allowed' => (bool) data_get($forgeLive, 'diff_scope.completion_gate.completion_claim_allowed', false),
        ];
    }

    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>  $review
     * @param  array<string,mixed>  $repair
     * @return array<string,string>
     */
    private function commandsFor(AtlasProject $project, array $run, array $review, array $repair, string $lifecycleStatus): array
    {
        $obraId = (string) $project->getKey();
        $runId = (string) ($run['fast_path_run_id'] ?? '');
        $commands = [
            'fast_path_status_api' => "GET /atlas-code/works/{$obraId}/forge/fast-path/{$runId}/status",
            'fast_path_resume_api' => "POST /atlas-code/works/{$obraId}/forge/fast-path/{$runId}/resume",
            'fast_path_status_cli' => "php artisan atlas:code:forge-fast-path-status --obra={$obraId} --run={$runId} --json --strict",
            'forge_state_api' => "GET /atlas-code/works/{$obraId}/state",
        ];

        if (($run['execution_id'] ?? null) !== null) {
            $commands['forge_async_show'] = "GET /atlas-code/works/{$obraId}/forge/live-executions/".((string) $run['execution_id']);
        }
        if ($lifecycleStatus === 'review_required') {
            $commands['review_api'] = "POST /atlas-code/works/{$obraId}/forge/reviews";
        }
        if ($repair['repair_available'] === true) {
            $commands['repair_simulation'] = "php artisan atlas:forge:live-execute --obra={$obraId} --simulate-failure --json";
        }

        return $commands;
    }
}
