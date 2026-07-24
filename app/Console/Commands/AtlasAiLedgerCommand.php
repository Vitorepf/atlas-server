<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeReportService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAiLedgerCommand extends Command
{
    protected $signature = 'atlas:ai:ledger
        {envelope : Operation envelope id to replay}
        {--limit=100 : Maximum number of events to render}
        {--slo : Include SLO observation summary for the envelope}
        {--repair : Include Repair Loop summary for the envelope}
        {--kernel : Include Kernel Pipeline contract summary for the envelope}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay Atlas AI kernel evidence events for one operation envelope.';

    public function handle(KernelLedgerEnvelopeReportService $reports): int
    {
        $envelopeId = trim((string) $this->argument('envelope'));
        if ($envelopeId === '') {
            $this->error('Envelope vazio.');

            return self::FAILURE;
        }

        $payload = $reports->report(
            envelopeId: $envelopeId,
            limit: $this->option('limit'),
            includeSlo: (bool) $this->option('slo'),
            includeRepair: (bool) $this->option('repair'),
            includeKernel: (bool) $this->option('kernel'),
        );

        return $this->render($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($payload['status'] ?? null) === 'ledger_table_missing' ? self::FAILURE : self::SUCCESS;
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas AI Ledger</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Envelope', (string) ($payload['envelope_id'] ?? '-'));
        $this->components->twoColumnDetail('Events', (string) ($payload['event_count'] ?? 0));
        if (is_array($payload['slo'] ?? null)) {
            $slo = $payload['slo'];
            $this->components->twoColumnDetail('SLO observations', (string) ($slo['observation_count'] ?? 0));
            $this->components->twoColumnDetail('SLO worst status', (string) ($slo['worst_status'] ?? '-'));
            $this->components->twoColumnDetail('SLO worst severity', (string) ($slo['worst_severity'] ?? '-'));
        }
        if (is_array($payload['repair'] ?? null)) {
            $repair = $payload['repair'];
            $this->components->twoColumnDetail('Repair events', (string) ($repair['repair_event_count'] ?? 0));
            $this->components->twoColumnDetail('Repair latest status', (string) ($repair['latest_status'] ?? '-'));
            $this->components->twoColumnDetail('Repair latest strategy', (string) ($repair['latest_strategy'] ?? '-'));
            $this->components->twoColumnDetail('Repair review signal', (string) data_get($repair, 'review_signal.status', 'unknown'));
            $this->components->twoColumnDetail('Repair review severity', (string) data_get($repair, 'review_signal.severity', 'unknown'));
        }
        if (is_array($payload['kernel_pipeline'] ?? null)) {
            $kernel = $payload['kernel_pipeline'];
            $this->components->twoColumnDetail('Kernel pipeline events', (string) ($kernel['kernel_pipeline_event_count'] ?? 0));
            $this->components->twoColumnDetail('Kernel accepted', (string) ($kernel['accepted_count'] ?? 0));
            $this->components->twoColumnDetail('Kernel rejected', (string) ($kernel['rejected_count'] ?? 0));
            $this->components->twoColumnDetail('Kernel health', (string) data_get($kernel, 'health.status', 'unknown'));
            $this->components->twoColumnDetail('Kernel rejection rate', (string) data_get($kernel, 'health.rejection_rate', 0));
        }

        $rows = collect((array) ($payload['events'] ?? []))
            ->map(fn (array $event): array => [
                $event['event_type'] ?? '-',
                $event['emitter_stage'] ?? '-',
                $event['trace_id'] ?? '-',
                $event['payload_hash'] ?? '-',
                $event['occurred_at'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->table(['event', 'stage', 'trace', 'payload hash', 'occurred at'], $rows);
        }

        if (is_array($payload['slo'] ?? null) && is_array(data_get($payload, 'slo.stages'))) {
            $sloRows = collect((array) data_get($payload, 'slo.stages'))
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

            if ($sloRows !== []) {
                $this->table(['stage', 'count', 'status', 'severity', 'p50 ms', 'p95 ms', 'max ms'], $sloRows);
            }
        }

        if (is_array($payload['repair'] ?? null) && is_array(data_get($payload, 'repair.events'))) {
            $repairRows = collect((array) data_get($payload, 'repair.events'))
                ->map(fn (array $event): array => [
                    $event['event_type'] ?? '-',
                    $event['status'] ?? '-',
                    $event['strategy'] ?? '-',
                    $event['failure_domain'] ?? '-',
                    YesNo::format($event['repair_executed'] ?? false),
                    $event['occurred_at'] ?? '-',
                ])
                ->all();

            if ($repairRows !== []) {
                $this->table(['event', 'status', 'strategy', 'failure', 'executed', 'occurred at'], $repairRows);
            }
        }

        if (is_array($payload['kernel_pipeline'] ?? null) && is_array(data_get($payload, 'kernel_pipeline.events'))) {
            $kernelRows = collect((array) data_get($payload, 'kernel_pipeline.events'))
                ->map(fn (array $event): array => [
                    $event['event_type'] ?? '-',
                    $event['status'] ?? '-',
                    $event['surface_id'] ?? '-',
                    $event['flow'] ?? '-',
                    $event['input_mode'] ?? '-',
                    count((array) ($event['violations'] ?? [])),
                    $event['occurred_at'] ?? '-',
                ])
                ->all();

            if ($kernelRows !== []) {
                $this->table(['event', 'status', 'surface', 'flow', 'input', 'violations', 'occurred at'], $kernelRows);
            }
        }

        return ($payload['status'] ?? null) === 'ledger_table_missing' ? self::FAILURE : self::SUCCESS;
    }
}
