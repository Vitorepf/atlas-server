<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiInboxActionReportCommand extends Command
{
    protected $signature = 'atlas:ai:inbox-action-report
        {--hours=24 : Window size in hours}
        {--action= : Filter by Inbox action}
        {--actor-type= : Filter by actor type}
        {--inbox-type= : Filter by Inbox item type}
        {--recommended-action= : Filter by recommended action}
        {--result= : Filter by action result}
        {--emitter-stage= : Filter by ledger emitter stage}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI Inbox action evidence for a recent time window.';

    public function handle(AtlasLedgerReplayService $replay, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $filters = $this->filters($input);
        $report = $replay->inboxActionReportForWindow(now()->subHours($hours), filters: $filters);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'inbox_actions' => $report,
        ];

        return $this->render($payload);
    }

    /**
     * @return array<string,string>
     */
    private function filters(KernelReplayReportInput $input): array
    {
        return $input->aliasedScalarFilters($this->options(), [
            'action' => ['action'],
            'actor_type' => ['actor-type'],
            'inbox_type' => ['inbox-type'],
            'recommended_action' => ['recommended-action'],
            'result' => ['result'],
            'emitter_stage' => ['emitter-stage'],
        ]);
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

        $actions = (array) ($payload['inbox_actions'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Inbox Action Report</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Filters', json_encode($payload['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->components->twoColumnDetail('Inbox action events', (string) ($actions['inbox_action_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($actions['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Reviewed patches', (string) ($actions['reviewed_patch_count'] ?? 0));
        $this->components->twoColumnDetail('With diff refs', (string) ($actions['with_diff_refs_count'] ?? 0));
        $this->components->twoColumnDetail('Review signal', (string) data_get($actions, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($actions, 'review_signal.severity', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($actions, 'review_signal.recommended_action', 'none'));

        $actionRows = collect((array) ($actions['action_counts'] ?? []))
            ->map(fn (int $count, string $action): array => [$action, $count])
            ->values()
            ->all();
        if ($actionRows !== []) {
            $this->table(['action', 'count'], $actionRows);
        }

        $actorRows = collect((array) ($actions['actor_type_counts'] ?? []))
            ->map(fn (int $count, string $actor): array => [$actor, $count])
            ->values()
            ->all();
        if ($actorRows !== []) {
            $this->table(['actor type', 'count'], $actorRows);
        }

        $recentRows = collect((array) ($actions['recent_events'] ?? []))
            ->map(fn (array $event): array => [
                $event['action'] ?? '-',
                $event['actor_type'] ?? '-',
                $event['inbox_type'] ?? '-',
                $event['recommended_action'] ?? '-',
                $event['result'] ?? '-',
                $event['inbox_item_id'] ?? '-',
                $event['envelope_id'] ?? '-',
            ])
            ->all();
        if ($recentRows !== []) {
            $this->table(['action', 'actor', 'inbox type', 'recommended', 'result', 'item', 'envelope'], $recentRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
