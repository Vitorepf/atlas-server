<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Programming\AtlasDevBeatTestReportService;
use Illuminate\Console\Command;

/**
 * L4-9 · Honest Atlas Dev beat-test report.
 */
final class AtlasDevBeatTestReportCommand extends Command
{
    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:dev:beat-test
        {--evidence= : JSON file with atlas.programming.dev_beat_test_evidence.v1 tasks}
        {--max-seconds=900 : Fixed duration threshold per medium task}
        {--manifest-path= : Backlog manifest path for emitted gaps}
        {--enqueue-backlog-gaps : Write gaps into the Loop backlog manifest}
        {--dry-run : Force backlog actions to dry-run even when enqueue flag is present}
        {--write-report : Persist the report JSON}
        {--report-path= : Explicit report path when --write-report is used}
        {--receipt-root= : Atlas Dev receipt root for read-only autopsy}
        {--receipt-limit=50 : Maximum receipt directories to inspect}
        {--json : Emit canonical JSON}';

    protected $description = 'Score supplied Atlas Dev beat-test evidence without dispatching providers; optionally enqueue concrete gaps.';

    public function handle(AtlasDevBeatTestReportService $service): int
    {
        $report = $service->report([
            'evidence_path' => $this->stringOption('evidence'),
            'max_seconds' => $this->intOption('max-seconds') ?? AtlasDevBeatTestReportService::DEFAULT_MAX_SECONDS,
            'manifest_path' => $this->stringOption('manifest-path'),
            'write_backlog' => (bool) $this->option('enqueue-backlog-gaps') && ! (bool) $this->option('dry-run'),
            'write_report' => (bool) $this->option('write-report'),
            'report_path' => $this->stringOption('report-path'),
            'receipt_root' => $this->stringOption('receipt-root'),
            'receipt_limit' => $this->intOption('receipt-limit') ?? AtlasDevBeatTestReportService::DEFAULT_RECEIPT_LIMIT,
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->components->info('Atlas Dev beat-test report');
        $this->components->twoColumnDetail('Status', (string) ($report['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Atlas passed', (string) data_get($report, 'summary.atlas_dev_passed_count', 0));
        $this->components->twoColumnDetail('Comparable cases', (string) data_get($report, 'summary.comparable_external_count', 0));
        $this->components->twoColumnDetail('Receipt candidates', (string) data_get($report, 'receipt_autopsy.summary.candidate_repo_receipt_count', 0));
        $this->components->twoColumnDetail('External superiority claim', data_get($report, 'claim_policy.external_superiority_claim_allowed') ? 'allowed' : 'blocked');
        $this->components->twoColumnDetail('Provider dispatch', data_get($report, 'claim_policy.provider_dispatches_now') ? 'yes' : 'no');
        foreach ((array) ($report['tasks'] ?? []) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $this->line(sprintf(
                '- %s: %s (%s)',
                (string) ($task['task_type'] ?? 'task'),
                (string) ($task['status'] ?? 'unknown'),
                (string) data_get($task, 'comparison.reason', 'no_comparison'),
            ));
        }
        $this->line((string) data_get($report, 'claim_policy.honest_operator_answer', ''));

        return self::SUCCESS;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?: ''));

        return $raw === '' || ! ctype_digit($raw) ? null : (int) $raw;
    }

}
