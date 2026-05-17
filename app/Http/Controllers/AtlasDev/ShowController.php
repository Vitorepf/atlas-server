<?php

declare(strict_types=1);

namespace App\Http\Controllers\AtlasDev;

use App\Http\Controllers\Controller;
use App\Services\Ai\Programming\AtlasDev\Persistence\ArtifactNames;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\RunIndex\AtlasDevRunIndexRepository;
use App\Services\Ai\Programming\AtlasDev\Surface\HttpResponseRedactor;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\JsonResponse;

/**
 * GET /ai/interactions/atlas-dev/runs/{run_id}
 *
 * REST fallback used by surfaces that cannot keep an SSE connection alive.
 * Reads the persisted run directory and returns:
 *   - which canonical artifacts exist
 *   - completion_state of the verification_receipt if persisted
 *   - the full persisted verification receipt when available
 *   - hashes that downstream surfaces use as cache keys
 */
final class ShowController extends Controller
{
    private const PERSISTED_LOOKUP = [
        'operation_envelope' => ArtifactNames::OPERATION_ENVELOPE,
        'compact_sdd' => ArtifactNames::COMPACT_SDD,
        'context_retrieval_plan' => ArtifactNames::CONTEXT_RETRIEVAL_PLAN,
        'code_discovery_manifest' => ArtifactNames::CODE_DISCOVERY_MANIFEST,
        'open_brain_projection' => ArtifactNames::OPEN_BRAIN_PROJECTION,
        'mini_programming_spec' => ArtifactNames::MINI_PROGRAMMING_SPEC,
        'task_contract' => ArtifactNames::TASK_CONTRACT,
        'prompt_projection' => ArtifactNames::PROMPT_PROJECTION,
        'routing_decision' => ArtifactNames::ROUTING_DECISION,
        'provider_call_result' => ArtifactNames::PROVIDER_CALL_RESULT,
        'diff_parse_result' => ArtifactNames::DIFF_PARSE_RESULT,
        'patch_apply_result' => ArtifactNames::PATCH_APPLY_RESULT,
        'scope_guard_receipt' => ArtifactNames::SCOPE_GUARD_RECEIPT,
        'verification_receipt' => ArtifactNames::VERIFICATION_RECEIPT,
        'fast_path_telemetry' => ArtifactNames::FAST_PATH_TELEMETRY,
        'run_cancellation' => ArtifactNames::RUN_CANCELLATION,
    ];

    public function __construct(
        private readonly ReceiptStorage $storage,
        private readonly ConfigRepository $config,
        private readonly AtlasDevRunIndexRepository $runIndex,
        private readonly HttpResponseRedactor $redactor = new HttpResponseRedactor,
    ) {}

    public function __invoke(string $runId): JsonResponse
    {
        $runExecutionState = $this->storage->readLatestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);
        if ($this->isStaleRunningState($runExecutionState)) {
            $runExecutionState = $this->persistStaleRunningFailure($runId, $runExecutionState);
        }

        $artifactRefs = [];
        foreach (self::PERSISTED_LOOKUP as $key => $filename) {
            if ($this->storage->exists($runId, $filename)) {
                $artifactRefs[$key] = $this->redactor->artifactRef($runId, $filename);
            }
        }
        $stateVersion = $this->storage->latestVersion($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE);
        if ($stateVersion !== null) {
            $artifactRefs['run_execution_state'] = $this->redactor->artifactRef(
                $runId,
                ArtifactNames::RUN_EXECUTION_STATE_BASE.".v{$stateVersion}.json",
            );
        }

        if ($artifactRefs === []) {
            return response()->json([
                'error' => [
                    'code' => 'RUN_NOT_FOUND',
                    'message' => "No persisted artifacts for run_id '{$runId}'.",
                ],
            ], 404);
        }

        $envelope = $this->storage->read($runId, ArtifactNames::OPERATION_ENVELOPE);
        $taskContract = $this->storage->read($runId, ArtifactNames::TASK_CONTRACT);
        $routing = $this->storage->read($runId, ArtifactNames::ROUTING_DECISION);
        $receipt = $this->storage->read($runId, ArtifactNames::VERIFICATION_RECEIPT);
        $scope = $this->storage->read($runId, ArtifactNames::SCOPE_GUARD_RECEIPT);
        $diffParse = $this->storage->read($runId, ArtifactNames::DIFF_PARSE_RESULT);
        $diffPreview = $this->diffPreview($diffParse);
        if (is_array($receipt) && $diffPreview !== null) {
            $receipt['ui_hints'] = array_merge(
                is_array($receipt['ui_hints'] ?? null) ? $receipt['ui_hints'] : [],
                ['diff_preview' => $diffPreview],
            );
        }

        $completion = is_array($receipt) ? ($receipt['completion'] ?? null) : null;
        $completionState = is_array($completion) ? ($completion['status'] ?? null) : null;
        if (! is_string($completionState) || $completionState === '') {
            $completionState = match (is_array($runExecutionState) ? ($runExecutionState['status'] ?? null) : null) {
                'failed' => 'failed',
                'cancelled' => 'cancelled',
                default => null,
            };
        }

        $workspaceLabel = null;
        $workspaceHash = null;
        if (is_array($envelope)) {
            $workspaceRaw = $envelope['workspace'] ?? null;
            if (is_string($workspaceRaw)) {
                $workspaceLabel = $this->redactor->workspaceLabel($workspaceRaw);
            }
            $hashCandidate = $envelope['workspace_hash'] ?? null;
            $workspaceHash = is_string($hashCandidate) ? $hashCandidate : null;
        }

        return response()->json([
            'data' => [
                'run_id' => $runId,
                'state' => $this->phaseFromRunExecutionState($runExecutionState, $completionState),
                'completion_state' => $completionState,
                'run_execution' => is_array($runExecutionState) ? $runExecutionState : null,
                'has_receipt' => $receipt !== null,
                'has_scope_guard_receipt' => $scope !== null,
                'has_plan' => $taskContract !== null,
                'routing' => is_array($routing) ? [
                    'kind' => $routing['kind'] ?? null,
                    'reasons' => $routing['reasons'] ?? [],
                    'blockers' => $routing['blockers'] ?? [],
                ] : null,
                // F-04: absolute workspace is never exposed; surfaces use the
                // basename label + the provider-safe workspace_hash instead.
                'workspace_label' => $workspaceLabel,
                'workspace_hash' => $workspaceHash,
                'task_contract_hash' => is_array($taskContract) ? ($taskContract['task_contract_hash'] ?? null) : null,
                'envelope_hash' => is_array($envelope) ? ($envelope['envelope_hash'] ?? null) : null,
                'verification_receipt_hash' => is_array($receipt) ? ($receipt['receipt_hash'] ?? null) : null,
                'scope_guard_receipt_hash' => is_array($scope) ? ($scope['receipt_hash'] ?? null) : null,
                'persisted_artifact_refs' => $artifactRefs,
                'diff_preview' => $diffPreview,
                'receipt' => is_array($receipt) ? $receipt : null,
            ],
        ], 200);
    }

    /**
     * @param  array<string, mixed>|null  $diffParse
     */
    private function diffPreview(?array $diffParse): ?string
    {
        $diff = $diffParse['diff'] ?? null;
        if (! is_string($diff)) {
            return null;
        }
        $trimmed = trim($diff);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  array<string, mixed>|null  $runExecutionState
     */
    private function phaseFromRunExecutionState(?array $runExecutionState, ?string $completionState): string
    {
        if ($completionState === 'escalate_forge') {
            return 'escalation_triggered';
        }
        if (is_string($completionState) && $completionState !== '') {
            return 'complete';
        }

        $status = is_array($runExecutionState) ? ($runExecutionState['status'] ?? null) : null;

        return match ($status) {
            'running' => 'executing',
            'complete', 'failed', 'cancelled' => 'complete',
            default => 'queued',
        };
    }

    /**
     * @param  array<string, mixed>|null  $runExecutionState
     */
    private function isStaleRunningState(?array $runExecutionState): bool
    {
        if (! is_array($runExecutionState) || ($runExecutionState['status'] ?? null) !== 'running') {
            return false;
        }

        $recordedAt = $runExecutionState['recorded_at'] ?? null;
        if (! is_string($recordedAt) || trim($recordedAt) === '') {
            return false;
        }

        $timestamp = strtotime($recordedAt);
        if ($timestamp === false) {
            return false;
        }

        $staleAfter = max(60, (int) $this->config->get('atlas_dev.run_worker.stale_after_seconds', 900));

        return (time() - $timestamp) > $staleAfter;
    }

    /**
     * @param  array<string, mixed>  $runExecutionState
     * @return array<string, mixed>
     */
    private function persistStaleRunningFailure(string $runId, array $runExecutionState): array
    {
        $failed = array_filter([
            'schema_version' => 'atlas.dev.run_execution_state.v1',
            'run_id' => $runId,
            'status' => 'failed',
            'recorded_at' => now()->toISOString(),
            'stale' => true,
            'error_code' => 'ATLAS_DEV_RUN_WORKER_STALE',
            'previous_status' => $runExecutionState['status'] ?? null,
            'previous_recorded_at' => $runExecutionState['recorded_at'] ?? null,
            'task_contract_hash' => $runExecutionState['task_contract_hash'] ?? null,
            'worker_pid' => $runExecutionState['worker_pid'] ?? null,
            'process_group_id' => $runExecutionState['process_group_id'] ?? null,
        ], static fn (mixed $value): bool => $value !== null);

        $this->storage->writeMonotonic($runId, ArtifactNames::RUN_EXECUTION_STATE_BASE, $failed);
        $this->runIndex->updateCompletion($runId, 'failed');

        return $failed;
    }
}
