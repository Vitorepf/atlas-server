<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\Support\ForgeFastPathLifecycleSupport;
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
 * Lifecycle state machine + pure projections: ForgeFastPathLifecycleSupport.
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
                'reason' => "Fast Path run [{$runId}] pertence a obra [{$runObraId}], nao a [".(string) $project->getKey().'].',
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

        $reviewState = ForgeFastPathLifecycleSupport::resolveReviewState($run, $async, $forgeLive, $review);
        $repair = ForgeFastPathLifecycleSupport::resolveRepairState($forgeLive, $async);
        $lifecycleStatus = ForgeFastPathLifecycleSupport::resolveLifecycleStatus($run, $async, $forgeLive, $reviewState, $repair);
        $progressPercent = ForgeFastPathLifecycleSupport::resolveProgressPercent($run, $async, $forgeLive, $lifecycleStatus);
        $nextAction = ForgeFastPathLifecycleSupport::resolveNextAction($lifecycleStatus, $reviewState, $repair, $run);

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
            'completed_at' => ForgeFastPathLifecycleSupport::resolveCompletedAt(
                $run,
                $lifecycleStatus,
                $async,
                $forgeLive,
                now()->toIso8601String(),
            ),
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
            'async_execution' => ForgeFastPathLifecycleSupport::asyncProjection($async),
            'forge_live_execution' => ForgeFastPathLifecycleSupport::forgeLiveProjection($forgeLive),
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
}
