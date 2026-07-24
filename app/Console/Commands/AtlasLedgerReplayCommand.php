<?php

namespace App\Console\Commands;

use App\Services\Ai\Kernel\Evidence\KernelLedgerEnvelopeReportService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasLedgerReplayCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:ledger:replay
        {--envelope= : Operation envelope id to replay}
        {--limit=100 : Maximum number of events to render}
        {--slo : Include SLO observation summary for the envelope}
        {--repair : Include Repair Loop summary for the envelope}
        {--kernel : Include Kernel Pipeline contract summary for the envelope}
        {--json : Print machine-readable JSON}';

    protected $description = 'Replay Atlas AI Evidence Ledger events for one operation envelope.';

    public function handle(KernelLedgerEnvelopeReportService $reports): int
    {
        $envelopeId = $this->envelopeId();
        if ($envelopeId === null) {
            return $this->render([
                'status' => 'invalid_input',
                'error' => 'envelope_required',
                'envelope_id' => null,
                'event_count' => 0,
                'filters' => [],
                'events' => [],
            ]);
        }

        return $this->render($reports->report(
            envelopeId: $envelopeId,
            limit: $this->option('limit'),
            includeSlo: (bool) $this->option('slo'),
            includeRepair: (bool) $this->option('repair'),
            includeKernel: (bool) $this->option('kernel'),
        ));
    }

    private function envelopeId(): ?string
    {
        $value = $this->option('envelope');

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $this->exitCode($payload);
        }

        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Ledger Replay</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Envelope', (string) ($payload['envelope_id'] ?? '-'));
        $this->components->twoColumnDetail('Events', (string) ($payload['event_count'] ?? 0));

        if (is_array($payload['slo'] ?? null)) {
            $this->components->twoColumnDetail('SLO review signal', (string) data_get($payload, 'slo.review_signal.status', 'unknown'));
        }
        if (is_array($payload['repair'] ?? null)) {
            $this->components->twoColumnDetail('Repair review signal', (string) data_get($payload, 'repair.review_signal.status', 'unknown'));
        }
        if (is_array($payload['kernel_pipeline'] ?? null)) {
            $this->components->twoColumnDetail('Kernel health', (string) data_get($payload, 'kernel_pipeline.health.status', 'unknown'));
        }

        $rows = collect((array) ($payload['events'] ?? []))
            ->map(fn (array $event): array => [
                $event['event_type'] ?? '-',
                $event['emitter_stage'] ?? '-',
                $event['receipt_id'] ?? '-',
                $event['payload_hash'] ?? '-',
                $event['occurred_at'] ?? '-',
            ])
            ->all();

        if ($rows !== []) {
            $this->table(['event', 'stage', 'receipt', 'payload hash', 'occurred at'], $rows);
        }

        return $this->exitCode($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function exitCode(array $payload): int
    {
        return in_array($payload['status'] ?? null, ['invalid_input', 'ledger_table_missing'], true)
            ? self::FAILURE
            : self::SUCCESS;
    }
}
