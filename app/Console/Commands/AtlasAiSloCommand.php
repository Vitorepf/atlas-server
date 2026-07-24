<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\AtlasLedgerReplayService;
use App\Services\Ai\Kernel\Evidence\KernelReplayReportInput;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasAiSloCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ai:slo
        {--hours=24 : Window size in hours}
        {--domain= : Filter by SLO domain dimension}
        {--flow= : Filter by SLO flow dimension}
        {--surface= : Filter by SLO surface_id dimension}
        {--provider= : Filter by SLO provider dimension}
        {--model= : Filter by SLO model dimension}
        {--runtime= : Filter by SLO runtime dimension}
        {--tool= : Filter by SLO tool_id dimension}
        {--json : Print machine-readable JSON}';

    protected $description = 'Summarize Atlas AI kernel SLO observations for a recent time window.';

    public function handle(AtlasLedgerReplayService $replay, KernelReplayReportInput $input): int
    {
        $hours = $input->hours($this->option('hours'));
        $since = now()->subHours($hours);
        $filters = $this->filters($input);
        $payload = [
            'status' => 'ok',
            'hours' => $hours,
            'filters' => $filters,
            'kernel_slo' => $replay->sloReportForWindow($since, null, $filters),
        ];

        if (($payload['kernel_slo']['available'] ?? false) !== true) {
            $payload['status'] = 'ledger_unavailable';
        }

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
        }

        $slo = (array) ($payload['kernel_slo'] ?? []);
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI SLO</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Hours', (string) ($payload['hours'] ?? '-'));
        if (($payload['filters'] ?? []) !== []) {
            $this->components->twoColumnDetail('Filters', json_encode($payload['filters'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->components->twoColumnDetail('Observations', (string) ($slo['observation_count'] ?? 0));
        $this->components->twoColumnDetail('Envelopes', (string) ($slo['envelope_count'] ?? 0));
        $this->components->twoColumnDetail('Worst status', (string) ($slo['worst_status'] ?? '-'));
        $this->components->twoColumnDetail('Worst severity', (string) ($slo['worst_severity'] ?? '-'));
        $this->components->twoColumnDetail('Review signal', (string) data_get($slo, 'review_signal.status', '-'));
        $this->components->twoColumnDetail('Review severity', (string) data_get($slo, 'review_signal.severity', '-'));
        $this->components->twoColumnDetail('Recommended action', (string) data_get($slo, 'review_signal.recommended_action', '-'));

        $stageRows = collect((array) ($slo['stages'] ?? []))
            ->map(fn (array $stage, string $name): array => [
                $name,
                $stage['count'] ?? 0,
                $stage['worst_status'] ?? '-',
                $stage['worst_severity'] ?? '-',
                $stage['p50_ms'] ?? 0,
                $stage['p95_ms'] ?? 0,
                $stage['max_ms'] ?? 0,
            ])
            ->all();

        if ($stageRows !== []) {
            $this->table(['stage', 'count', 'status', 'severity', 'p50 ms', 'p95 ms', 'max ms'], $stageRows);
        }

        $dimensionRows = collect((array) ($slo['dimensions'] ?? []))
            ->flatMap(fn (array $counts, string $dimension): array => collect($counts)
                ->map(fn (int $count, string $value): array => [$dimension, $value, $count])
                ->values()
                ->all())
            ->values()
            ->all();

        if ($dimensionRows !== []) {
            $this->table(['dimension', 'value', 'count'], $dimensionRows);
        }

        return ($payload['status'] ?? null) === 'ok' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string,string>
     */
    private function filters(KernelReplayReportInput $input): array
    {
        return $input->aliasedScalarFilters($this->options(), [
            'domain' => ['domain'],
            'flow' => ['flow'],
            'surface_id' => ['surface'],
            'provider' => ['provider'],
            'model' => ['model'],
            'runtime' => ['runtime'],
            'tool_id' => ['tool'],
        ]);
    }
}
