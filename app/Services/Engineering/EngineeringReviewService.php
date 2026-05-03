<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringReviewFinding;
use App\Models\AtlasEngineeringRun;
use App\Models\AtlasTask;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class EngineeringReviewService
{
    public function __construct(
        private readonly EngineeringReviewFindingService $findings,
        private readonly EngineeringRunArtifactService $artifacts,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function deepReview(AtlasTask $task, array $options = []): array
    {
        $run = $this->latestOrSyntheticRun($task);
        $inputFindings = collect((array) ($options['findings'] ?? []))
            ->filter(fn (mixed $finding): bool => is_array($finding))
            ->values();
        $recorded = [];

        foreach ($inputFindings as $finding) {
            $model = $this->findings->record($run, [
                ...$finding,
                'source' => $finding['source'] ?? 'deep_review',
                'recorded_by' => $options['recorded_by'] ?? 'atlas_review',
            ]);
            if ($model) {
                $recorded[] = $this->findingPayload($model);
            }
        }

        $run->loadMissing('reviewFindings');
        $summary = $this->gateSummary($run->reviewFindings);
        $status = $summary['blocking_count'] > 0 ? 'failed' : 'passed';

        $evidence = $this->artifacts->recordEvidence($task, [
            'evidence_type' => 'deep_code_review',
            'target_id' => 'deep_code_review',
            'status' => $status,
            'confidence' => $summary['confidence'],
            'summary' => $summary['blocking_count'] > 0
                ? 'Deep review encontrou findings bloqueantes.'
                : 'Deep review registrado sem findings bloqueantes.',
            'metadata' => [
                'run_id' => $run->id,
                'summary' => $summary,
                'recorded_findings' => $recorded,
            ],
        ], 'atlas:review:deep');

        return [
            'task_id' => $task->id,
            'run_id' => $run->id,
            'summary' => $summary,
            'recorded_findings' => $recorded,
            'evidence' => $evidence,
            'blocking' => $summary['blocking_count'] > 0,
        ];
    }

    /**
     * @param  iterable<AtlasEngineeringReviewFinding>  $findings
     * @return array<string,mixed>
     */
    public function gateSummary(iterable $findings): array
    {
        $items = collect($findings);
        $open = $items->filter(fn (AtlasEngineeringReviewFinding $finding): bool => (string) $finding->status === 'open');
        $blocking = $open->filter(fn (AtlasEngineeringReviewFinding $finding): bool => $this->blocks($finding))->values();

        return [
            'total_count' => $items->count(),
            'open_count' => $open->count(),
            'blocking_count' => $blocking->count(),
            'confidence' => $items->max(fn (AtlasEngineeringReviewFinding $finding): float => $finding->confidence === null ? 0.7 : (float) $finding->confidence) ?: 0.9,
            'blocking_findings' => $blocking->map(fn (AtlasEngineeringReviewFinding $finding): array => $this->findingPayload($finding))->all(),
            'by_category' => $open->groupBy(fn (AtlasEngineeringReviewFinding $finding): string => (string) ($finding->category ?: data_get($finding->metadata, 'category', 'uncategorized')))
                ->map->count()
                ->all(),
            'threshold' => [
                'p0_open_blocks' => true,
                'p1_confidence_blocks_at' => 0.8,
            ],
        ];
    }

    private function blocks(AtlasEngineeringReviewFinding $finding): bool
    {
        $status = (string) $finding->status;
        if (in_array($status, ['fixed', 'false_positive', 'accepted_risk', 'resolved', 'dismissed'], true)) {
            return false;
        }

        $severity = (string) $finding->severity;
        $confidence = $finding->confidence === null ? 1.0 : (float) $finding->confidence;

        return $severity === 'p0' || ($severity === 'p1' && $confidence >= 0.8);
    }

    private function latestOrSyntheticRun(AtlasTask $task): AtlasEngineeringRun
    {
        if (Schema::hasTable('atlas_engineering_runs')) {
            $latest = AtlasEngineeringRun::query()
                ->where('task_id', $task->id)
                ->latest('created_at')
                ->first();
            if ($latest) {
                return $latest;
            }

            return AtlasEngineeringRun::query()->create([
                'task_id' => $task->id,
                'project_id' => $task->project_id,
                'project_step_id' => $task->project_step_id,
                'workspace_path_hash' => hash('sha256', 'manual_review:'.$task->id),
                'workspace_label' => 'manual-review',
                'provider_strategy_json' => ['source' => 'atlas_review'],
                'status' => 'passed',
                'decision' => 'partial',
                'score' => null,
                'max_attempts' => 0,
                'attempt_count' => 0,
                'started_at' => now(),
                'finished_at' => now(),
                'metadata' => ['synthetic' => true, 'source' => 'deep_review'],
            ]);
        }

        $run = new AtlasEngineeringRun;
        $run->forceFill(['id' => (string) Str::orderedUuid(), 'task_id' => $task->id]);

        return $run;
    }

    /**
     * @return array<string,mixed>
     */
    private function findingPayload(AtlasEngineeringReviewFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'engineering_run_id' => $finding->engineering_run_id,
            'task_id' => $finding->task_id,
            'severity' => $finding->severity,
            'status' => $finding->status,
            'confidence' => $finding->confidence === null ? null : round((float) $finding->confidence, 3),
            'category' => $finding->category ?: data_get($finding->metadata, 'category'),
            'title' => $finding->title,
            'body' => $finding->body,
            'file_path' => $finding->file_path,
            'start_line' => $finding->start_line,
            'end_line' => $finding->end_line,
            'evidence' => $finding->evidence_json,
            'recommendation' => $finding->recommendation,
        ];
    }
}
