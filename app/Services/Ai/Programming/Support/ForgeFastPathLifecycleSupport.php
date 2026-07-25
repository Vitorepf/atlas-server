<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\Support;

/**
 * Pure Fast Path lifecycle state machine + projections.
 *
 * Extracted from AtlasCodeForgeFastPathStatusService private resolvers.
 * No I/O, no provider calls, no model access, no time side effects
 * (callers pass $nowIso when a wall-clock fallback is required).
 */
final class ForgeFastPathLifecycleSupport
{
    /**
     * @param  array<string,mixed>  $run
     * @param  array<string,mixed>|null  $async
     * @param  array<string,mixed>  $forgeLive
     * @param  array<string,mixed>|null  $review
     * @return array<string,mixed>
     */
    public static function resolveReviewState(array $run, ?array $async, array $forgeLive, ?array $review): array
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
    public static function resolveRepairState(array $forgeLive, ?array $async): array
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
    public static function resolveLifecycleStatus(array $run, ?array $async, array $forgeLive, array $review, array $repair): string
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
    public static function resolveProgressPercent(array $run, ?array $async, array $forgeLive, string $lifecycleStatus): int
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
    public static function resolveNextAction(string $lifecycleStatus, array $review, array $repair, array $run): string
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
     * @param  array<string,mixed>|null  $async
     * @param  array<string,mixed>  $forgeLive
     * @param  string|null  $nowIso  Wall-clock fallback for completed status when timestamps missing (caller-supplied; no now() here).
     */
    public static function resolveCompletedAt(
        array $run,
        string $lifecycleStatus,
        ?array $async,
        array $forgeLive,
        ?string $nowIso = null,
    ): ?string {
        if ($lifecycleStatus === 'completed') {
            $value = $run['updated_at'] ?? $forgeLive['last_run_at'] ?? $nowIso;

            return $value !== null ? (string) $value : null;
        }
        if ($lifecycleStatus === 'passed') {
            $value = $forgeLive['last_run_at'] ?? $run['updated_at'] ?? '';

            return (string) $value;
        }

        $completedAt = $run['completed_at'] ?? null;

        return $completedAt !== null ? (string) $completedAt : null;
    }

    /**
     * @param  array<string,mixed>|null  $async
     * @return array<string,mixed>|null
     */
    public static function asyncProjection(?array $async): ?array
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
    public static function forgeLiveProjection(array $forgeLive): ?array
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
}
