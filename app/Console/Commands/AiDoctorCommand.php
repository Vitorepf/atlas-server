<?php

namespace App\Console\Commands;

use App\Models\AiJob;
use App\Models\AiQualityAction;
use App\Models\AiQualityEvaluation;
use App\Models\AiThread;
use App\Models\AiTrace;
use Illuminate\Console\Command;
use App\Services\Ai\Support\DatabaseTableAvailability;

class AiDoctorCommand extends Command
{
    protected $signature = 'atlas:ai:doctor {--hours=24 : Observation window in hours} {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas operational health, quality scores and pending remediation actions.';

    public function handle(): int
    {
        $hours = max(1, (int) $this->option('hours'));
        $since = now()->subHours($hours);
        $data = [
            'window_hours' => $hours,
            'threads' => [
                'active' => AiThread::query()->where('status', 'active')->count(),
                'atlas_cli' => AiThread::query()->where('surface', 'atlas_cli')->count(),
            ],
            'traces' => [
                'total' => AiTrace::query()->where('created_at', '>=', $since)->count(),
                'queued' => AiTrace::query()->where('status', 'queued')->count(),
                'processing' => AiTrace::query()->where('status', 'processing')->count(),
                'failed' => AiTrace::query()->where('status', 'failed')->where('created_at', '>=', $since)->count(),
            ],
            'jobs' => [
                'queued' => AiJob::query()->where('status', 'queued')->count(),
                'processing' => AiJob::query()->where('status', 'processing')->count(),
                'failed' => AiJob::query()->where('status', 'failed')->where('updated_at', '>=', $since)->count(),
            ],
            'quality' => $this->quality($since),
            'actions' => $this->actions(),
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info("Atlas Doctor ({$hours}h)");
        $this->line("threads active={$data['threads']['active']} cli={$data['threads']['atlas_cli']}");
        $this->line("traces total={$data['traces']['total']} queued={$data['traces']['queued']} processing={$data['traces']['processing']} failed={$data['traces']['failed']}");
        $this->line("jobs queued={$data['jobs']['queued']} processing={$data['jobs']['processing']} failed={$data['jobs']['failed']}");

        if ($data['quality']['available']) {
            $this->line("quality avg={$data['quality']['average_score']} needs_review={$data['quality']['needs_review']} failed={$data['quality']['failed']}");
        }

        if ($data['actions']['available']) {
            $this->line("actions open={$data['actions']['open']} queued={$data['actions']['queued']} blocked={$data['actions']['blocked']} failed={$data['actions']['failed']}");
        }

        return self::SUCCESS;
    }

    private function quality($since): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_evaluations')) {
            return ['available' => false];
        }

        $averageScore = AiQualityEvaluation::query()
            ->where('created_at', '>=', $since)
            ->avg('score');

        return [
            'available' => true,
            'average_score' => $averageScore === null ? null : round((float) $averageScore, 2),
            'needs_review' => AiQualityEvaluation::query()->where('status', 'needs_review')->where('created_at', '>=', $since)->count(),
            'failed' => AiQualityEvaluation::query()->where('status', 'failed')->where('created_at', '>=', $since)->count(),
        ];
    }

    private function actions(): array
    {
        if (! DatabaseTableAvailability::has('ai_quality_actions')) {
            return ['available' => false];
        }

        return [
            'available' => true,
            'open' => AiQualityAction::query()->whereIn('status', ['queued', 'running', 'blocked', 'failed'])->count(),
            'queued' => AiQualityAction::query()->where('status', 'queued')->count(),
            'blocked' => AiQualityAction::query()->where('status', 'blocked')->count(),
            'failed' => AiQualityAction::query()->where('status', 'failed')->count(),
        ];
    }
}
