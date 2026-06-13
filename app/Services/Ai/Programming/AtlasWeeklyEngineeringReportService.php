<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\AutonomousEvolution\AtlasLoopWeeklyAgendaProposalService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Throwable;

/**
 * L5-14: weekly, human-readable Atlas engineering report.
 *
 * This composes existing resolved reports only. It does not dispatch providers,
 * approve agenda items, create Obras, or mutate the source tree.
 */
final class AtlasWeeklyEngineeringReportService
{
    public const SCHEMA_VERSION = 'atlas.weekly_engineering_report.v1';

    public const DEFAULT_REPORT_PATH = 'app/atlas/evidence/weekly-engineering-report.json';

    public const DEFAULT_MARKDOWN_PATH = 'app/atlas/evidence/weekly-engineering-report.md';

    public function __construct(
        private readonly AtlasFableFinalReportService $finalReport,
        private readonly AtlasLoopWeeklyAgendaProposalService $weeklyAgenda,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function report(array $options = []): array
    {
        $cfg = (array) config('atlas.loop.weekly_report', []);
        $enabled = (bool) ($options['enabled'] ?? $cfg['enabled'] ?? true);
        $hours = max(24, min(336, (int) ($options['hours'] ?? $cfg['hours'] ?? 168)));
        $maxWords = max(120, min(600, (int) ($options['max_words'] ?? $cfg['max_words'] ?? 420)));

        if (! $enabled) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'disabled',
                'reason' => 'weekly_report_disabled',
                'claim_policy' => $this->claimPolicy(),
            ];
        }

        $final = $this->arrayOptionOr($options, 'final_report', fn (): array => $this->finalReport->report([
            'baseline_path' => $this->stringOrNull($options['baseline_path'] ?? null),
            'series_path' => $this->stringOrNull($options['series_path'] ?? null),
            'date' => $this->stringOrNull($options['date'] ?? null),
            'hours' => $hours,
            'dev_beat_evidence_path' => $this->stringOrNull($options['dev_beat_evidence_path'] ?? null),
            'forge_evidence_path' => $this->stringOrNull($options['forge_evidence_path'] ?? null),
        ]));

        $agenda = $this->arrayOptionOr($options, 'weekly_agenda', fn (): array => $this->weeklyAgenda->propose([
            'hours' => $hours,
            'max_items' => (int) config('atlas.loop.weekly_agenda.max_items', 5),
        ]));

        $markdown = $this->markdown($final, $agenda, $hours);
        $wordCount = $this->wordCount($markdown);
        $readable = $wordCount <= $maxWords;
        $agendaFeed = $this->agendaFeed($agenda);
        $finalReady = in_array((string) ($final['status'] ?? ''), ['ready', 'ready_with_operator_gated_external_proofs'], true);
        $agendaReady = (string) ($agenda['status'] ?? '') === 'ready_for_operator_review';
        $status = $finalReady && $agendaReady && $readable ? 'ready' : 'blocked';

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'generated_at' => Carbon::now()->toIso8601String(),
            'title' => 'Atlas weekly engineering report',
            'window' => [
                'hours' => $hours,
                'max_words' => $maxWords,
                'word_count' => $wordCount,
                'readable_in_two_minutes' => $readable,
            ],
            'source_status' => [
                'final_report' => (string) ($final['status'] ?? 'unknown'),
                'weekly_agenda' => (string) ($agenda['status'] ?? 'unknown'),
                'morning_digest' => (string) data_get($final, 'source_reports.morning_digest.status', 'unknown'),
                'delta_series' => (string) data_get($final, 'source_reports.delta_series.status', 'unknown'),
            ],
            'metrics' => $this->metrics($final),
            'risks' => $this->risks($final, $agenda),
            'agenda_feed' => $agendaFeed,
            'brief_markdown' => $markdown,
            'claim_policy' => $this->claimPolicy(),
        ];

        if ((bool) ($options['write_report'] ?? false)) {
            $path = $this->stringOrNull($options['report_path'] ?? null)
                ?? (string) ($cfg['report_path'] ?? storage_path(self::DEFAULT_REPORT_PATH));
            $this->writeJson($path, $payload);
            $payload['written_report_path'] = $path;
        }

        if ((bool) ($options['write_markdown'] ?? false)) {
            $path = $this->stringOrNull($options['markdown_path'] ?? null)
                ?? (string) ($cfg['markdown_path'] ?? storage_path(self::DEFAULT_MARKDOWN_PATH));
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $markdown."\n");
            $payload['written_markdown_path'] = $path;
        }

        return $payload;
    }

    /**
     * @param  array<string,mixed>  $final
     * @param  array<string,mixed>  $agenda
     */
    private function markdown(array $final, array $agenda, int $hours): string
    {
        $metrics = $this->metrics($final);
        $risks = $this->risks($final, $agenda);
        $agendaItems = array_slice(array_values(array_filter((array) ($agenda['agenda'] ?? []), 'is_array')), 0, 5);

        $lines = [
            '# Atlas Weekly Engineering Report',
            '',
            sprintf('Window: last %d hours. Status: %s.', $hours, (string) ($final['status'] ?? 'unknown')),
            sprintf(
                'Loop moved %d merge(s), cost coverage is %.1f%%, semantic recall is %s, and the latest digest reports %d red canary signal(s).',
                (int) ($metrics['merged'] ?? 0),
                (float) ($metrics['cost_coverage_pct'] ?? 0.0),
                (bool) ($metrics['semantic_recall_real'] ?? false) ? 'live' : 'not proven live',
                (int) ($metrics['red_canaries'] ?? 0),
            ),
            '',
            '## Decisions',
            '- Keep weekly agenda suggest-only; operator approval remains required before execution.',
            '- Treat provider spend and external benchmark claims as operator-gated unless a real receipt is supplied.',
            '- Use the generated agenda feed as the next L5-1 input, not as an authorization.',
            '',
            '## Risks',
        ];

        foreach ($risks as $risk) {
            $lines[] = '- '.$risk;
        }

        $lines[] = '';
        $lines[] = '## Agenda Feed';
        foreach ($agendaItems as $item) {
            $lines[] = sprintf(
                '- [%s] %s: %s',
                (string) ($item['score'] ?? '-'),
                (string) ($item['title'] ?? 'Untitled priority'),
                (string) ($item['recommended_action'] ?? 'Review with operator.'),
            );
        }

        return rtrim(implode("\n", $lines));
    }

    /**
     * @param  array<string,mixed>  $final
     * @return array<string,mixed>
     */
    private function metrics(array $final): array
    {
        return [
            'merged' => (int) (
                data_get($final, 'n_x_m.merges_per_day.merged_in_digest_window')
                ?? data_get($final, 'source_reports.morning_digest.sections.merges.merged_24h')
                ?? 0
            ),
            'red_canaries' => (int) data_get($final, 'source_reports.morning_digest.sections.canaries.failed_24h', 0),
            'cost_coverage_pct' => (float) (
                data_get($final, 'n_x_m.measured_cost.digest_cost_coverage_pct_24h')
                ?? data_get($final, 'source_reports.morning_digest.sections.cost.coverage_pct_24h')
                ?? 0.0
            ),
            'total_cost_usd' => (float) data_get($final, 'n_x_m.measured_cost.total_cost_usd_24h', 0.0),
            'scorecard_delta' => data_get($final, 'n_x_m.scorecard.delta'),
            'semantic_recall_real' => (bool) data_get($final, 'n_x_m.recall.semantic_recall_real', false),
            'semantic_lift_status' => (string) data_get($final, 'n_x_m.recall.semantic_lift_status', 'unknown'),
            'impact_receipt_coverage_pct' => (float) data_get($final, 'source_reports.morning_digest.sections.merges.impact_receipt_coverage_pct_24h', 0.0),
        ];
    }

    /**
     * @param  array<string,mixed>  $final
     * @param  array<string,mixed>  $agenda
     * @return list<string>
     */
    private function risks(array $final, array $agenda): array
    {
        $risks = [];
        $redCanaries = (int) data_get($final, 'source_reports.morning_digest.sections.canaries.failed_24h', 0);
        if ($redCanaries > 0) {
            $risks[] = $redCanaries.' red canary signal(s) still need fix-forward treatment.';
        }
        $costCoverage = (float) data_get($final, 'source_reports.morning_digest.sections.cost.coverage_pct_24h', 0.0);
        if ($costCoverage <= 0.0) {
            $risks[] = 'Cost coverage is not currently measured in the digest window.';
        }
        foreach ((array) ($agenda['blocked_candidates'] ?? []) as $blocked) {
            if (is_array($blocked)) {
                $risks[] = (string) ($blocked['title'] ?? 'Blocked candidate').': '.(string) ($blocked['reason'] ?? $blocked['status'] ?? 'operator-gated');
            }
        }

        return $risks !== [] ? array_slice($risks, 0, 5) : ['No blocking report risk was detected in the resolved sources.'];
    }

    /**
     * @param  array<string,mixed>  $agenda
     * @return array<string,mixed>
     */
    private function agendaFeed(array $agenda): array
    {
        $items = array_values(array_filter((array) ($agenda['agenda'] ?? []), 'is_array'));
        $ids = array_values(array_map(static fn (array $item): string => (string) ($item['id'] ?? ''), $items));

        return [
            'schema_version' => 'atlas.weekly_engineering_report.agenda_feed.v1',
            'feed_status' => (string) ($agenda['status'] ?? '') === 'ready_for_operator_review' && $items !== []
                ? 'ready_for_l5_1'
                : 'blocked',
            'agenda_priority_ids' => array_values(array_filter($ids, static fn (string $id): bool => $id !== '')),
            'agenda_item_count' => count($items),
            'operator_approval_required' => (bool) data_get($agenda, 'operator_approval.required', true),
            'auto_execute_allowed' => (bool) data_get($agenda, 'operator_approval.auto_execute_allowed', false),
            'weekly_agenda_command' => 'atlas:loop:weekly-agenda --json --strict',
        ];
    }

    /**
     * @return array<string,bool|string>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only_sources_only' => true,
            'provider_calls_made' => false,
            'provider_tokens_spent' => false,
            'obra_created' => false,
            'merged_to_main' => false,
            'weekly_report_is_authorization' => false,
            'operator_approval_required_for_agenda_execution' => true,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeJson(string $path, array $payload): void
    {
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function arrayOptionOr(array $options, string $key, callable $fallback): array
    {
        $value = $options[$key] ?? null;
        if (is_array($value)) {
            return $value;
        }

        try {
            $fallbackValue = $fallback();

            return is_array($fallbackValue) ? $fallbackValue : [];
        } catch (Throwable $e) {
            return [
                'status' => 'unavailable',
                'reason' => $key.'_fallback_failed',
                'error' => mb_substr($e->getMessage(), 0, 160),
            ];
        }
    }

    private function wordCount(string $markdown): int
    {
        $plain = trim((string) preg_replace('/[`#*_[\]():.,;%]/', ' ', $markdown));
        if ($plain === '') {
            return 0;
        }

        return count(preg_split('/\s+/', $plain) ?: []);
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
