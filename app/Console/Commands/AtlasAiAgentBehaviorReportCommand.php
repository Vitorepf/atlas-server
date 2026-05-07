<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiAgentBehaviorReportCommand extends Command
{
    protected $signature = 'atlas:ai:agent-behavior-report
        {--hours=24 : Window size in hours}
        {--status= : Filter by gate status}
        {--provider= : Filter by provider}
        {--model= : Filter by model}
        {--agent-slug= : Filter by agent slug}
        {--finding-code= : Filter by agent behavior finding code}
        {--contract-id= : Filter by behavior contract id}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI agent behavior gate findings for a recent time window.';

    public function handle(AtlasLedgerReplayService $replay, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $filters = $this->filters($input);
        $report = $replay->agentBehaviorReportForWindow(now()->subHours($hours), filters: $filters);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'agent_behavior' => $report,
        ];

        return $this->render($payload);
    }

    /**
     * @return array<string,string>
     */
    private function filters(KernelReplayReportInput $input): array
    {
        return $input->aliasedScalarFilters($this->options(), [
            'status' => ['status'],
            'provider' => ['provider'],
            'model' => ['model'],
            'agent_slug' => ['agent-slug'],
            'finding_code' => ['finding-code'],
            'contract_id' => ['contract-id'],
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

        $behavior = (array) ($payload['agent_behavior'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Agent Behavior Report</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Filters', json_encode($payload['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->components->twoColumnDetail('Agent behavior events', (string) ($behavior['agent_behavior_event_count'] ?? 0));
        $this->components->twoColumnDetail('Findings', (string) ($behavior['finding_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($behavior['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Average score', (string) ($behavior['average_score'] ?? '-'));
        $this->components->twoColumnDetail('Review signal', (string) data_get($behavior, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($behavior, 'review_signal.severity', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($behavior, 'review_signal.recommended_action', 'none'));

        $this->renderCountTable('finding code', (array) ($behavior['finding_code_counts'] ?? []));
        $this->renderCountTable('provider', (array) ($behavior['provider_counts'] ?? []));
        $this->renderCountTable('agent slug', (array) ($behavior['agent_slug_counts'] ?? []));

        $recentRows = collect((array) ($behavior['recent_events'] ?? []))
            ->map(fn (array $event): array => [
                $event['status'] ?? '-',
                $event['score'] ?? '-',
                $event['provider'] ?? '-',
                $event['model'] ?? '-',
                $event['agent_slug'] ?? '-',
                implode(',', (array) ($event['finding_codes'] ?? [])) ?: '-',
                $event['envelope_id'] ?? '-',
            ])
            ->all();
        if ($recentRows !== []) {
            $this->table(['status', 'score', 'provider', 'model', 'agent', 'findings', 'envelope'], $recentRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  array<string,int>  $counts
     */
    private function renderCountTable(string $label, array $counts): void
    {
        $rows = collect($counts)
            ->map(fn (int $count, string $key): array => [$key, $count])
            ->values()
            ->all();

        if ($rows !== []) {
            $this->table([$label, 'count'], $rows);
        }
    }
}
