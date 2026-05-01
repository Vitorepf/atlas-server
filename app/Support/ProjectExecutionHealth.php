<?php

namespace App\Support;

use App\Models\AtlasProject;
use App\Models\AtlasProjectBlocker;
use Illuminate\Support\Facades\Schema;

class ProjectExecutionHealth
{
    /**
     * @return array<string, mixed>
     */
    public static function for(AtlasProject $project): array
    {
        $reasons = [];
        $now = now();
        $lastTouchedDays = $project->last_touched_at ? $project->last_touched_at->diffInDays($now) : null;
        $reviewDue = $project->next_review_at && $project->next_review_at->lte($now);
        $overdue = $project->deadline_at && $project->deadline_at->lt($now) && ! in_array($project->status, ['completed', 'archived'], true);
        $missingNextAction = $project->status === 'active' && ! $project->active_next_task_id;
        $activeTask = $project->activeNextTask;
        $deferredAction = $project->status === 'active'
            && $activeTask
            && $activeTask->planning_status === 'deferred';
        $deferredReady = $deferredAction
            && (! $activeTask->planned_for_date || $activeTask->planned_for_date->lte($now));
        $openBlockersCount = self::openBlockersCount($project);

        if ($project->status === 'blocked' || $openBlockersCount > 0) {
            $reasons[] = 'blocked';
        }
        if ($missingNextAction) {
            $reasons[] = 'missing_next_action';
        }
        if ($reviewDue && ! in_array($project->status, ['completed', 'archived'], true)) {
            $reasons[] = 'review_due';
        }
        if ($deferredReady) {
            $reasons[] = 'deferred_ready';
        }
        if ($overdue) {
            $reasons[] = 'overdue';
        }
        if ($project->status === 'active' && $lastTouchedDays !== null && $lastTouchedDays >= 7) {
            $reasons[] = 'stale';
        }

        $status = match (true) {
            in_array($project->status, ['completed', 'archived'], true) => $project->status,
            $project->status === 'paused' => 'paused',
            $reasons !== [] => 'attention',
            default => 'healthy',
        };

        return [
            'status' => $status,
            'reasons' => $reasons,
            'score' => max(0, 100 - (count($reasons) * 22)),
            'missing_next_action' => $missingNextAction,
            'review_due' => $reviewDue,
            'overdue' => $overdue,
            'deferred_action' => $deferredAction,
            'deferred_ready' => $deferredReady,
            'open_blockers_count' => $openBlockersCount,
            'has_open_blockers' => $openBlockersCount > 0,
            'last_touched_days' => $lastTouchedDays,
        ];
    }

    private static function openBlockersCount(AtlasProject $project): int
    {
        if ($project->relationLoaded('openBlockers')) {
            return $project->openBlockers->count();
        }

        if (! Schema::hasTable('atlas_project_blockers')) {
            return 0;
        }

        return AtlasProjectBlocker::query()
            ->where('project_id', $project->id)
            ->where('status', 'open')
            ->count();
    }
}
