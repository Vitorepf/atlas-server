<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;

class AtlasAiKernelPipelineReportCommand extends Command
{
    protected $signature = 'atlas:ai:kernel-pipeline-report
        {--hours=24 : Window size in hours}
        {--status= : Filter by pipeline contract status}
        {--surface= : Filter by surface id}
        {--flow= : Filter by Atlas AI flow}
        {--input-mode= : Filter by input mode}
        {--contract-source= : Filter by surface contract source}
        {--emitter-stage= : Filter by ledger emitter stage}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI Kernel Pipeline contract evidence for a recent time window.';

    public function handle(AtlasLedgerReplayService $replay, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $filters = $this->filters($input);
        $report = $replay->kernelPipelineReportForWindow(now()->subHours($hours), filters: $filters);
        $payload = [
            'status' => ($report['available'] ?? false) ? 'ok' : 'ledger_unavailable',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_pipeline' => $report,
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
            'surface_id' => ['surface'],
            'flow' => ['flow'],
            'input_mode' => ['input-mode'],
            'surface_contract_source' => ['contract-source'],
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

        $pipeline = (array) ($payload['kernel_pipeline'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Kernel Pipeline Report</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        $this->components->twoColumnDetail('Filters', json_encode($payload['filters'] ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
        $this->components->twoColumnDetail('Kernel pipeline events', (string) ($pipeline['kernel_pipeline_event_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($pipeline['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Accepted', (string) ($pipeline['accepted_count'] ?? 0));
        $this->components->twoColumnDetail('Rejected', (string) ($pipeline['rejected_count'] ?? 0));
        $this->components->twoColumnDetail('Latest status', (string) ($pipeline['latest_status'] ?? '-'));
        $this->components->twoColumnDetail('Has rejections', ($pipeline['has_rejections'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Health', (string) data_get($pipeline, 'health.status', 'unknown'));
        $this->components->twoColumnDetail('Rejection rate', (string) data_get($pipeline, 'health.rejection_rate', 0));
        $this->components->twoColumnDetail('Review required', data_get($pipeline, 'health.review_required', false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Review signal', (string) data_get($pipeline, 'review_signal.status', 'unknown'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($pipeline, 'review_signal.severity', 'unknown'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($pipeline, 'review_signal.recommended_action', 'none'));

        $surfaceRows = collect((array) ($pipeline['surface_counts'] ?? []))
            ->map(fn (int $count, string $surface): array => [$surface, $count])
            ->values()
            ->all();
        if ($surfaceRows !== []) {
            $this->table(['surface', 'count'], $surfaceRows);
        }

        $contractRows = collect((array) ($pipeline['surface_contract_source_counts'] ?? []))
            ->map(fn (int $count, string $source): array => [$source, $count])
            ->values()
            ->all();
        if ($contractRows !== []) {
            $this->table(['contract source', 'count'], $contractRows);
        }

        $emitterRows = collect((array) ($pipeline['emitter_stage_counts'] ?? []))
            ->map(fn (int $count, string $stage): array => [$stage, $count])
            ->values()
            ->all();
        if ($emitterRows !== []) {
            $this->table(['emitter stage', 'count'], $emitterRows);
        }

        $recentRows = collect((array) ($pipeline['recent_events'] ?? []))
            ->map(fn (array $event): array => [
                $event['event_type'] ?? '-',
                $event['status'] ?? '-',
                $event['surface_id'] ?? '-',
                $event['surface_contract_source'] ?? '-',
                $event['flow'] ?? '-',
                $event['input_mode'] ?? '-',
                $event['envelope_id'] ?? '-',
            ])
            ->all();
        if ($recentRows !== []) {
            $this->table(['event', 'status', 'surface', 'contract', 'flow', 'input', 'envelope'], $recentRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
