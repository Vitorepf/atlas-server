<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Models\AiTrace;
use App\Models\AtlasEngineeringControlResult;
use App\Models\AtlasEngineeringFileReviewDecision;
use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasEngineeringRunOperatorAction;
use App\Models\AtlasEngineeringTestRun;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Public, trace-scoped projection for a recorded engineering review.
 *
 * An engineering run is only eligible when its trace_id exactly matches the
 * interaction trace. Multiple matches are deliberately unavailable: selecting
 * a "latest" run would let a client review a different execution than the
 * conversation that rendered it.
 */
final class AiTraceEngineeringReviewProjection
{
    /**
     * @return array<string, mixed>
     */
    public function forTrace(AiTrace $trace): array
    {
        $runs = AtlasEngineeringRun::query()
            ->where('trace_id', $trace->id)
            ->limit(2)
            ->get();

        if ($runs->count() !== 1) {
            return $this->unavailable($trace, $runs->isEmpty() ? 'no_linked_run' : 'ambiguous_linked_runs');
        }

        /** @var AtlasEngineeringRun $run */
        $run = $runs->sole();
        $relations = [
            'patchArtifacts',
            'controlResults',
            'testRuns',
            'reviewFindings',
            'operatorActions',
        ];
        if (DatabaseTableAvailability::has('atlas_engineering_file_review_decisions')) {
            $relations[] = 'patchArtifacts.fileReviewDecisions';
        }
        $run->load($relations);

        return [
            'schema_version' => 'atlas.trace_change_review.v1',
            'state' => 'available',
            'trace_id' => $trace->id,
            'run' => [
                'status' => $run->status,
                'decision' => $run->decision,
                'score' => $run->score,
                'started_at' => $run->started_at?->toIso8601String(),
                'finished_at' => $run->finished_at?->toIso8601String(),
            ],
            'patches' => $run->patchArtifacts
                ->map(fn (AtlasEngineeringPatchArtifact $patch): array => $this->patch($trace, $patch))
                ->values()
                ->all(),
            'controls' => $run->controlResults
                ->map(fn (AtlasEngineeringControlResult $control): array => $this->control($control))
                ->values()
                ->all(),
            'test_runs' => $run->testRuns
                ->map(fn (AtlasEngineeringTestRun $testRun): array => $this->testRun($testRun))
                ->values()
                ->all(),
            'review' => [
                'findings' => $run->reviewFindings
                    ->map(fn (AtlasEngineeringReviewFinding $finding): array => $this->finding($finding))
                    ->values()
                    ->all(),
                'operator_actions' => $run->operatorActions
                    ->map(fn (AtlasEngineeringRunOperatorAction $action): array => $this->operatorAction($action))
                    ->values()
                    ->all(),
                // These are the only run-level decisions implemented by the
                // existing engineering action service. Per-file actions are
                // intentionally absent until the server owns that state.
                'available_actions' => ['accept', 'reject'],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function unavailable(AiTrace $trace, string $reason): array
    {
        return [
            'schema_version' => 'atlas.trace_change_review.v1',
            'state' => 'unavailable',
            'reason' => $reason,
            'trace_id' => $trace->id,
            'patches' => [],
            'controls' => [],
            'test_runs' => [],
            'review' => [
                'findings' => [],
                'operator_actions' => [],
                'available_actions' => [],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function patch(AiTrace $trace, AtlasEngineeringPatchArtifact $patch): array
    {
        return [
            'id' => $patch->id,
            'base_ref' => $patch->base_ref,
            'head_ref' => $patch->head_ref,
            'diff_hash' => $patch->diff_hash,
            'changed_files' => $patch->changed_files_json ?? [],
            'created_files' => $patch->created_files_json ?? [],
            'deleted_files' => $patch->deleted_files_json ?? [],
            'risk_flags' => $patch->risk_flags_json ?? [],
            'file_reviews' => $patch->relationLoaded('fileReviewDecisions')
                ? $patch->fileReviewDecisions
                    ->filter(fn (AtlasEngineeringFileReviewDecision $decision): bool => $decision->patch_diff_hash === $patch->diff_hash)
                    ->map(fn (AtlasEngineeringFileReviewDecision $decision): array => $this->fileReview($decision))
                    ->values()
                    ->all()
                : [],
            'created_at' => $patch->created_at?->toIso8601String(),
            'diff_url' => sprintf(
                '/ai/interactions/%s/change-review/patches/%s/diff',
                rawurlencode($trace->id),
                rawurlencode($patch->id),
            ),
        ];
    }

    /** @return array<string,mixed> */
    private function fileReview(AtlasEngineeringFileReviewDecision $decision): array
    {
        return [
            'file_path' => $decision->file_path,
            'action' => $decision->action,
            'actor' => $decision->actor,
            'note' => $decision->note,
            'decided_at' => $decision->decided_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function control(AtlasEngineeringControlResult $control): array
    {
        return [
            'id' => $control->id,
            'slug' => $control->control_slug,
            'status' => $control->status,
            'signal_summary' => $control->signal_summary,
            'duration_ms' => $control->duration_ms,
            'created_at' => $control->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function testRun(AtlasEngineeringTestRun $testRun): array
    {
        return [
            'id' => $testRun->id,
            'command' => $testRun->command,
            'status' => $testRun->status,
            'exit_code' => $testRun->exit_code,
            'duration_ms' => $testRun->duration_ms,
            'created_at' => $testRun->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function finding(AtlasEngineeringReviewFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'source' => $finding->source,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'confidence' => $finding->confidence,
            'category' => $finding->category,
            'title' => $finding->title,
            'file_path' => $finding->file_path,
            'start_line' => $finding->start_line,
            'end_line' => $finding->end_line,
            'recommendation' => $finding->recommendation,
            'detected_at' => $finding->detected_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function operatorAction(AtlasEngineeringRunOperatorAction $action): array
    {
        return [
            'id' => $action->id,
            'action' => $action->action,
            'actor' => $action->actor,
            'status_before' => $action->status_before,
            'status_after' => $action->status_after,
            'acted_at' => $action->acted_at?->toIso8601String(),
        ];
    }
}
