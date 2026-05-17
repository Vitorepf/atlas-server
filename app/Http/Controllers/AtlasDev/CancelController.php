<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * POST /ai/interactions/atlas-dev/runs/{run_id}/cancel
 *
 * Operator cancellation is an explicit persisted artifact, not a transient UI
 * flag. The worker checks it before spending provider tokens; when the process
 * PID is known, this controller also attempts best-effort SIGTERM.
 */
final class CancelController extends Controller
{
    public function __construct(
        private readonly ReceiptStorage $storage,
        private readonly ConfigRepository $config,
        private readonly AtlasDevRunIndexRepository $runIndex,
    ) {}

    public function __invoke(Request $request, string $runId): JsonResponse
    {
        if (! $this->config->get('atlas_dev.efficient.run_enabled', false)) {
            return response()->json([
                'error' => [
                    'code' => 'ATLAS_DEV_RUN_DISABLED',
                    'message' => 'Atlas Dev run endpoint is disabled by feature flag.',
                ],
            ], 503);
        }

        if (! $this->storage->exists($runId, ArtifactNames::OPERATION_ENVELOPE)) {
            return response()->json([
                'error' => [
                    'code' => 'RUN_NOT_FOUND',
                    'message' => "No persisted plan for run_id '{$runId}'.",
                ],
            ], 404);
        }

        $reason = $request->input('reason');
        $reason = is_string($reason) && trim($reason) !== '' ? mb_substr(trim($reason), 0, 240) : 'operator_cancelled';
        $latestState = $this->storage->readLatestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);
        $alreadyCancelled = $this->storage->read($runId, ArtifactNames::RUN_CANCELLATION);

        if (! is_array($alreadyCancelled)) {
            try {
                $this->storage->writeAtomic($runId, ArtifactNames::RUN_CANCELLATION, [
                    'schema_version' => 'atlas.dev.run_cancellation.v1',
                    'run_id' => $runId,
                    'reason' => $reason,
                    'requested_at' => now()->toISOString(),
                    'requested_by' => 'operator',
                ]);
            } catch (RuntimeException) {
                // Another request may have won the idempotency race. Read it
                // back and continue with the same terminal state projection.
                $alreadyCancelled = $this->storage->read($runId, ArtifactNames::RUN_CANCELLATION);
            }
        }

        $signal = $this->terminateWorker($latestState);
        $this->storage->writeMonotonic($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE, array_filter([
            'schema_version' => 'atlas.dev.run_execution_state.v1',
            'run_id' => $runId,
            'status' => 'cancelled',
            'recorded_at' => now()->toISOString(),
            'reason' => is_array($alreadyCancelled) && is_string($alreadyCancelled['reason'] ?? null)
                ? $alreadyCancelled['reason']
                : $reason,
            'worker_pid' => $signal['worker_pid'],
            'process_group_id' => $signal['process_group_id'],
            'signal_sent' => $signal['signal_sent'],
            'signal' => $signal['signal'],
            'signal_target' => $signal['signal_target'],
        ], static fn (mixed $value): bool => $value !== null));
        $this->runIndex->updateCompletion($runId, 'cancelled');

        return response()->json([
            'data' => [
                'ok' => true,
                'run_id' => $runId,
                'state' => 'complete',
                'completion_state' => 'cancelled',
                'reason' => is_array($alreadyCancelled) && is_string($alreadyCancelled['reason'] ?? null)
                    ? $alreadyCancelled['reason']
                    : $reason,
                'worker_pid' => $signal['worker_pid'],
                'process_group_id' => $signal['process_group_id'],
                'signal_sent' => $signal['signal_sent'],
                'signal_target' => $signal['signal_target'],
                'persisted_artifact_refs' => [
                    'run_cancellation' => "receipts/{$runId}/".ArtifactNames::RUN_CANCELLATION,
                ],
            ],
        ], 200);
    }

    /**
     * @param  array<string, mixed>|null  $latestState
     * @return array{worker_pid: int|null, process_group_id: int|null, signal_sent: bool, signal: string|null, signal_target: string|null}
     */
    private function terminateWorker(?array $latestState): array
    {
        $pid = is_array($latestState) && is_numeric($latestState['worker_pid'] ?? null)
            ? (int) $latestState['worker_pid']
            : 0;
        $processGroupId = is_array($latestState) && is_numeric($latestState['process_group_id'] ?? null)
            ? (int) $latestState['process_group_id']
            : 0;

        if ($pid <= 0 || ! function_exists('posix_kill')) {
            return [
                'worker_pid' => $pid > 0 ? $pid : null,
                'process_group_id' => $processGroupId > 0 ? $processGroupId : null,
                'signal_sent' => false,
                'signal' => null,
                'signal_target' => null,
            ];
        }

        $signal = defined('SIGTERM') ? (int) constant('SIGTERM') : 15;
        $target = $pid;
        $targetKind = 'worker_pid';

        if ($processGroupId > 0 && $this->canSignalProcessGroup($processGroupId)) {
            $target = -$processGroupId;
            $targetKind = 'process_group';
        }

        return [
            'worker_pid' => $pid,
            'process_group_id' => $processGroupId > 0 ? $processGroupId : null,
            'signal_sent' => @posix_kill($target, $signal),
            'signal' => 'SIGTERM',
            'signal_target' => $targetKind,
        ];
    }

    private function canSignalProcessGroup(int $processGroupId): bool
    {
        if ($processGroupId <= 0) {
            return false;
        }

        if (function_exists('posix_getpgrp')) {
            $currentGroup = @posix_getpgrp();
            if (is_int($currentGroup) && $currentGroup === $processGroupId) {
                return false;
            }
        }

        return true;
    }
}
