<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyCaseRegistrar;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReadModel;
use App\Services\Ai\Kernel\Architecture\AtlasRivalsStrategyReviewRecorder;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;
use InvalidArgumentException;

class AtlasAiRivalsStrategyCommand extends Command
{
    protected $signature = 'atlas:ai:rivals-strategy
        {action=report : report, due-reviews, register-case, or record-review}
        {--hours=8760 : Window size in hours}
        {--due-days=30 : Include pending reviews due within this many days}
        {--limit=20 : Max due reviews to show}
        {--title= : Decision case title for register-case}
        {--baseline= : Direct/baseline decision}
        {--atlas= : Atlas-assisted decision}
        {--horizon=90 : Primary horizon in days}
        {--review-id= : Review UUID for record-review}
        {--case-id= : Case UUID for record-review when review-id is omitted}
        {--review-horizon= : Review horizon in days for record-review}
        {--regret= : Regret score 0-100}
        {--alignment= : Values alignment score 0-100}
        {--agency= : Operator agency preservation score 0-100}
        {--outcome= : Outcome summary for record-review}
        {--json : Print machine-readable JSON}';

    protected $description = 'Track Rivals Strategy evidence for strategic decisions without executing decisions.';

    public function handle(
        AtlasRivalsStrategyReadModel $rivals,
        AtlasRivalsStrategyCaseRegistrar $registrar,
        AtlasRivalsStrategyReviewRecorder $recorder,
        KernelReplayReportInput $input,
    ): int {
        $action = strtolower(trim((string) $this->argument('action')));
        if ($action === 'register-case') {
            return $this->registerCase($rivals, $registrar);
        }
        if ($action === 'record-review') {
            return $this->recordReview($rivals, $recorder);
        }
        if ($action === 'due-reviews') {
            return $this->render([
                'status' => 'ok',
                'due_reviews' => $rivals->dueReviews((int) $this->option('due-days'), (int) $this->option('limit')),
            ]);
        }
        if ($action !== 'report') {
            $this->error("Unsupported action: {$action}. Supported: report, due-reviews, register-case, record-review.");

            return self::FAILURE;
        }

        $hours = $input->hours($this->option('hours'));
        $report = $rivals->report(now()->subHours($hours));

        return $this->render([
            'status' => ($report['available'] ?? false) ? 'ok' : 'storage_unavailable',
            'hours' => $hours,
            'rivals_strategy' => $report,
        ]);
    }

    private function recordReview(AtlasRivalsStrategyReadModel $rivals, AtlasRivalsStrategyReviewRecorder $recorder): int
    {
        try {
            $recording = $recorder->record([
                'review_id' => $this->stringOption('review-id'),
                'case_id' => $this->stringOption('case-id'),
                'review_horizon' => $this->option('review-horizon'),
                'regret_score' => $this->option('regret'),
                'alignment_score' => $this->option('alignment'),
                'agency_score' => $this->option('agency'),
                'outcome_summary' => $this->stringOption('outcome'),
                'recorded_by' => 'atlas:ai:rivals-strategy',
            ]);
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        return $this->render([
            'status' => 'ok',
            'recorded_review' => $recording,
            'rivals_strategy' => $rivals->report(now()->subDays(365), now()->addDays(365)),
        ]);
    }

    private function registerCase(AtlasRivalsStrategyReadModel $rivals, AtlasRivalsStrategyCaseRegistrar $registrar): int
    {
        $title = trim((string) $this->option('title'));
        if ($title === '') {
            $this->error('--title is required for register-case.');

            return self::FAILURE;
        }

        $registration = $registrar->register([
            'title' => $title,
            'baseline_choice' => $this->stringOption('baseline'),
            'atlas_assisted_choice' => $this->stringOption('atlas'),
            'horizon_days' => max(1, min(3650, (int) $this->option('horizon'))),
            'source' => 'atlas:ai:rivals-strategy',
            'mode' => 'manual_registration',
        ]);

        $report = $rivals->report(now()->subHours(1), now()->addDays(365));

        return $this->render([
            'status' => 'ok',
            'registered_case_id' => $registration['case_id'],
            'case_created' => $registration['created'],
            'source_hash' => $registration['source_hash'],
            'scheduled_reviews' => $registration['scheduled_reviews'],
            'rivals_strategy' => $report,
        ]);
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $report = (array) ($payload['rivals_strategy'] ?? []);
        if ($report === [] && isset($payload['due_reviews'])) {
            $report = (array) $payload['due_reviews'];
        }
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Rivals Strategy</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Cases', (string) ($report['case_count'] ?? 0));
        $this->components->twoColumnDetail('Scheduled reviews', (string) ($report['scheduled_review_count'] ?? 0));
        $this->components->twoColumnDetail('Scored reviews', (string) ($report['scored_review_count'] ?? 0));
        $this->components->twoColumnDetail('Due reviews', (string) ($report['due_review_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($report, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($report, 'review_signal.recommended_action', 'none'));

        $this->table(
            ['gate', 'status', 'reason'],
            collect((array) ($report['gates'] ?? []))
                ->map(fn (array $gate): array => [$gate['id'], $gate['status'], $gate['reason']])
                ->all(),
        );

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
