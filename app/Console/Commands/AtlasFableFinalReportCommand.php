<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsNonEmptyStringOption;
use App\Services\Ai\Programming\AtlasFableFinalReportService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * L4-13: final Fable N x M report plus tested cold-session handoff packet.
 */
final class AtlasFableFinalReportCommand extends Command
{
    use EmitsCanonicalJson;

    use ReadsNonEmptyStringOption;

    protected $signature = 'atlas:fable:final-report
        {--baseline= : Marco Zero JSON path}
        {--series= : Fable delta-series JSONL path}
        {--date= : Snapshot date YYYY-MM-DD (default today)}
        {--hours=24 : Digest lookback window}
        {--dev-beat-evidence= : Optional Atlas Dev beat-test evidence JSON}
        {--forge-evidence= : Optional real L4-10 Forge receipt JSON}
        {--write-report : Persist the final report JSON}
        {--report-path= : Explicit report JSON path}
        {--write-packet : Persist the handoff packet JSON}
        {--packet-path= : Explicit packet JSON path}
        {--verify-packet= : Verify an existing handoff packet path as a cold-session resume packet}
        {--strict : Exit non-zero unless report and packet verification are ready}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the Fable L4-13 final N x M report and tested cold-session handoff packet from resolved sources.';

    public function handle(AtlasFableFinalReportService $service): int
    {
        $payload = $service->report([
            'baseline_path' => $this->stringOption('baseline'),
            'series_path' => $this->stringOption('series'),
            'date' => $this->stringOption('date'),
            'hours' => $this->intOption('hours') ?? 24,
            'dev_beat_evidence_path' => $this->stringOption('dev-beat-evidence'),
            'forge_evidence_path' => $this->stringOption('forge-evidence'),
            'write_report' => (bool) $this->option('write-report'),
            'report_path' => $this->stringOption('report-path'),
            'write_packet' => (bool) $this->option('write-packet'),
            'packet_path' => $this->stringOption('packet-path'),
            'verify_packet_path' => $this->stringOption('verify-packet'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));
        } else {
            $this->renderHuman($payload);
        }

        if ((bool) $this->option('strict') && ! $this->strictReady($payload)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fable L4-13</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Scorecard delta', (string) data_get($payload, 'n_x_m.scorecard.delta', 'unknown'));
        $this->components->twoColumnDetail('Merged delta', (string) data_get($payload, 'n_x_m.merges_per_day.total_merged_delta', 'unknown'));
        $this->components->twoColumnDetail('Cost coverage 24h', (string) data_get($payload, 'n_x_m.measured_cost.digest_cost_coverage_pct_24h', 'unknown').'%');
        $this->components->twoColumnDetail('Semantic lift', (string) data_get($payload, 'n_x_m.recall.average_lift', 'unknown'));
        $this->components->twoColumnDetail('Packet', (string) data_get($payload, 'cold_session_verification.status', 'unknown'));
        if (($path = data_get($payload, 'written_report_path')) !== null) {
            $this->components->twoColumnDetail('Report path', (string) $path);
        }
        if (($path = data_get($payload, 'written_packet_path')) !== null) {
            $this->components->twoColumnDetail('Packet path', (string) $path);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function strictReady(array $payload): bool
    {
        $status = (string) ($payload['status'] ?? '');
        $generatedPacketReady = data_get($payload, 'cold_session_verification.status') === 'ready';
        $verifiedPath = data_get($payload, 'verified_packet_from_path.status');

        return $status === 'ready_with_operator_gated_external_proofs'
            && $generatedPacketReady
            && ($verifiedPath === null || $verifiedPath === 'ready');
    }

    private function intOption(string $key): ?int
    {
        $value = $this->option($key);
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return ctype_digit($value) ? (int) $value : null;
    }

}
