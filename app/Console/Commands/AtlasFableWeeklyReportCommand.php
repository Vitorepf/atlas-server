<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasWeeklyEngineeringReportService;
use Illuminate\Console\Command;

/**
 * L5-14: weekly Atlas engineering report from resolved sources.
 */
final class AtlasFableWeeklyReportCommand extends Command
{
    protected $signature = 'atlas:fable:weekly-report
        {--baseline= : Marco Zero JSON path}
        {--series= : Fable delta-series JSONL path}
        {--date= : Snapshot date YYYY-MM-DD}
        {--hours= : Lookback window in hours}
        {--max-words= : Maximum brief word count for two-minute readability}
        {--dev-beat-evidence= : Optional Atlas Dev beat-test evidence JSON}
        {--forge-evidence= : Optional real L4-10 Forge receipt JSON}
        {--write-report : Persist the JSON report}
        {--report-path= : Explicit JSON report path}
        {--write-markdown : Persist the readable Markdown report}
        {--markdown-path= : Explicit Markdown report path}
        {--strict : Exit non-zero unless the report is ready and two-minute readable}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the weekly Atlas engineering report and L5-1 agenda feed from resolved sources.';

    public function handle(AtlasWeeklyEngineeringReportService $service): int
    {
        if (! (bool) config('atlas.loop.weekly_report.enabled', true)) {
            return $this->emit([
                'schema_version' => AtlasWeeklyEngineeringReportService::SCHEMA_VERSION,
                'status' => 'disabled',
                'reason' => 'weekly_report_disabled',
            ], self::FAILURE);
        }

        $payload = $service->report(array_filter([
            'baseline_path' => $this->stringOption('baseline'),
            'series_path' => $this->stringOption('series'),
            'date' => $this->stringOption('date'),
            'hours' => $this->intOption('hours'),
            'max_words' => $this->intOption('max-words'),
            'dev_beat_evidence_path' => $this->stringOption('dev-beat-evidence'),
            'forge_evidence_path' => $this->stringOption('forge-evidence'),
            'write_report' => (bool) $this->option('write-report'),
            'report_path' => $this->stringOption('report-path'),
            'write_markdown' => (bool) $this->option('write-markdown'),
            'markdown_path' => $this->stringOption('markdown-path'),
        ], static fn (mixed $value): bool => $value !== null));

        $ready = ($payload['status'] ?? null) === 'ready'
            && (bool) data_get($payload, 'window.readable_in_two_minutes', false)
            && data_get($payload, 'agenda_feed.feed_status') === 'ready_for_l5_1';
        $exit = (bool) $this->option('strict') && ! $ready ? self::FAILURE : self::SUCCESS;

        return $this->emit($payload, $exit);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $exit;
        }

        $this->components->info((string) ($payload['title'] ?? 'Atlas weekly engineering report'));
        $this->components->twoColumnDetail('Status', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Words', (string) data_get($payload, 'window.word_count', 0));
        $this->components->twoColumnDetail('Agenda feed', (string) data_get($payload, 'agenda_feed.feed_status', 'unknown'));
        $this->line((string) ($payload['brief_markdown'] ?? ''));

        return $exit;
    }

    private function intOption(string $key): ?int
    {
        $raw = trim((string) ($this->option($key) ?? ''));

        return $raw !== '' && ctype_digit($raw) ? (int) $raw : null;
    }

    private function stringOption(string $key): ?string
    {
        $raw = $this->option($key);
        if (! is_string($raw)) {
            return null;
        }

        $raw = trim($raw);

        return $raw === '' ? null : $raw;
    }
}
