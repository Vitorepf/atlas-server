<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AiDecision;
use App\Models\AiThread;
use App\Models\AiTrace;
use App\Models\AtlasEngineeringEvidence;
use App\Models\AtlasProject;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Atlas Code Checkpoint/Resume artifact.
 *
 * A checkpoint is the operational handoff for long programming sessions:
 * what is safe to resume, which Obra/thread/Forge evidence anchors the state,
 * and which blockers or approvals prevent completion.
 */
final class AtlasCodeCheckpointController extends Controller
{
    public function store(Request $request, AtlasProject $project): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:120'],
        ]);

        $checkpoint = $this->checkpointFor($project, (string) ($data['reason'] ?? 'manual'));
        $evidence = $this->persistEvidence($project, $checkpoint);
        $checkpoint['evidence_id'] = $evidence?->id;

        $this->rememberCheckpoint($project, $checkpoint);

        return response()->json([
            'schema_version' => 'atlas.code.checkpoint_response.v1',
            'work_id' => (string) $project->getKey(),
            'checkpoint' => $checkpoint,
            'persistence' => [
                'project_metadata_persisted' => true,
                'engineering_evidence_id' => $evidence?->id,
                'engineering_evidence_persisted' => $evidence !== null,
            ],
        ], 201);
    }

    /**
     * @return array<string,mixed>
     */
    private function checkpointFor(AtlasProject $project, string $reason): array
    {
        $threads = AiThread::query()
            ->where(function ($query) use ($project): void {
                $query->where('source_id', (string) $project->getKey())
                    ->orWhere('workspace', (string) $project->getKey());
            })
            ->orderByDesc('last_message_at')
            ->limit(20)
            ->get();

        $activeThread = $threads->first(fn (AiThread $thread): bool => in_array((string) $thread->status, ['active', 'running', 'streaming'], true))
            ?? $threads->first();

        $traceIds = AiTrace::query()
            ->where('source_id', (string) $project->getKey())
            ->pluck('id')
            ->map(fn (mixed $id): string => (string) $id)
            ->all();

        $latestDecision = $traceIds === []
            ? null
            : AiDecision::query()->whereIn('trace_id', $traceIds)->latest('created_at')->first();

        $forge = (array) data_get($project->metadata, 'latest_forge_live_execution', []);
        $blockers = array_values((array) ($forge['remaining_blockers'] ?? []));
        $completionAllowed = (bool) data_get($forge, 'diff_scope.completion_gate.completion_claim_allowed', false);
        $forgeStatus = (string) ($forge['status'] ?? 'missing');
        $nextSafeAction = $this->nextSafeAction($forgeStatus, $completionAllowed, $blockers);

        $checkpoint = [
            'schema_version' => 'atlas.code.checkpoint_artifact.v1',
            'checkpoint_id' => (string) Str::ulid(),
            'obra_id' => (string) $project->getKey(),
            'reason' => $reason !== '' ? $reason : 'manual',
            'status' => $nextSafeAction === 'address_blockers_before_resume' ? 'blocked' : 'ready',
            'created_at' => now()->toJSON(),
            'resume' => [
                'resume_ready' => $nextSafeAction !== 'address_blockers_before_resume',
                'next_safe_action' => $nextSafeAction,
                'summary' => $this->resumeSummary($project, $forgeStatus, $completionAllowed, $nextSafeAction),
                'source_authority' => 'AtlasProject.metadata.latest_atlas_code_checkpoint',
            ],
            'state_refs' => [
                'active_thread_id' => $activeThread ? (string) $activeThread->getKey() : null,
                'session_count' => $threads->count(),
                'message_count' => (int) $threads->sum(fn (AiThread $thread): int => (int) ($thread->message_count ?? 0)),
                'decision_receipt_id' => $latestDecision ? (string) $latestDecision->getKey() : null,
                'project_status' => (string) ($project->status ?? 'active'),
                'project_updated_at' => $project->updated_at?->toJSON(),
            ],
            'forge_live_execution' => [
                'status' => $forgeStatus,
                'last_run_at' => $forge['last_run_at'] ?? null,
                'context_pack_hash' => data_get($forge, 'context_pack.context_pack_hash'),
                'context_completeness' => data_get($forge, 'context_pack.context_completeness'),
                'diff_scope_status' => data_get($forge, 'diff_scope.status'),
                'scope_status' => data_get($forge, 'diff_scope.scope_status'),
                'completion_claim_allowed' => $completionAllowed,
                'evidence_ref_count' => (int) ($forge['evidence_ref_count'] ?? 0),
                'ledger_event_count' => (int) ($forge['ledger_event_count'] ?? 0),
            ],
            'risk' => [
                'remaining_blockers' => $blockers,
                'pending_approvals' => $latestDecision && empty($latestDecision->signed_at) ? ['decision_receipt_signature'] : [],
                'residual_risk' => $blockers === [] ? 'low' : 'blocked',
            ],
        ];

        return $checkpoint;
    }

    /**
     * @param  array<int,string>  $blockers
     */
    private function nextSafeAction(string $forgeStatus, bool $completionAllowed, array $blockers): string
    {
        if ($blockers !== [] || $forgeStatus === 'blocked') {
            return 'address_blockers_before_resume';
        }
        if ($forgeStatus === 'passed' && $completionAllowed) {
            return 'resume_from_verified_forge_live_execution';
        }
        if ($forgeStatus === 'passed') {
            return 'review_diff_scope_before_completion';
        }

        return 'run_forge_live_execution';
    }

    private function resumeSummary(
        AtlasProject $project,
        string $forgeStatus,
        bool $completionAllowed,
        string $nextSafeAction,
    ): string {
        return sprintf(
            'Obra %s · Forge Live %s · completion %s · next %s',
            (string) ($project->title ?? $project->getKey()),
            $forgeStatus,
            $completionAllowed ? 'allowed' : 'not_allowed',
            $nextSafeAction,
        );
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     */
    private function rememberCheckpoint(AtlasProject $project, array $checkpoint): void
    {
        $metadata = is_array($project->metadata) ? $project->metadata : [];
        $history = array_values((array) ($metadata['atlas_code_checkpoint_history'] ?? []));
        array_unshift($history, $checkpoint);
        $metadata['latest_atlas_code_checkpoint'] = $checkpoint;
        $metadata['atlas_code_checkpoint_history'] = array_slice($history, 0, 10);

        $project->forceFill([
            'metadata' => $metadata,
            'last_touched_at' => now(),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $checkpoint
     */
    private function persistEvidence(AtlasProject $project, array $checkpoint): ?AtlasEngineeringEvidence
    {
        if (! Schema::hasTable('atlas_engineering_evidence')) {
            return null;
        }

        $fields = $this->onlyExistingColumns('atlas_engineering_evidence', [
            'project_id' => (string) $project->getKey(),
            'task_id' => (string) $project->getKey(),
            'evidence_type' => 'atlas_code_checkpoint',
            'target_id' => (string) ($checkpoint['checkpoint_id'] ?? $project->getKey()),
            'status' => (string) ($checkpoint['status'] ?? 'ready'),
            'confidence' => 1.0,
            'summary' => (string) data_get($checkpoint, 'resume.summary', 'Atlas Code checkpoint'),
            'command' => 'POST /atlas-code/works/{obra}/checkpoints',
            'output_excerpt' => (string) data_get($checkpoint, 'resume.next_safe_action', ''),
            'files' => [],
            'metadata' => [
                'schema_version' => 'atlas.code.checkpoint_evidence.v1',
                'checkpoint' => $checkpoint,
            ],
            'source' => 'atlas_code_checkpoint',
            'recorded_at' => now(),
        ]);

        return AtlasEngineeringEvidence::query()->create($fields);
    }

    /**
     * @param  array<string,mixed>  $values
     * @return array<string,mixed>
     */
    private function onlyExistingColumns(string $table, array $values): array
    {
        return collect($values)
            ->filter(fn (mixed $_, string $column): bool => Schema::hasColumn($table, $column))
            ->all();
    }
}
