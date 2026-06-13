<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\AtlasFableFinalCaptureService;
use Illuminate\Console\Command;

/**
 * L4-14: final M capture and cold-session recovery proof.
 */
final class AtlasFableFinalCaptureCommand extends Command
{
    protected $signature = 'atlas:fable:final-capture
        {--workspace= : Workspace root for KB/code index and provider projection commands}
        {--baseline= : Optional Marco Zero JSON path used when refreshing L4-13 from operator evidence}
        {--series= : Optional delta-series JSONL path used when refreshing L4-13 from operator evidence}
        {--date= : Optional L4-13 snapshot date YYYY-MM-DD when refreshing from operator evidence}
        {--hours=24 : Digest lookback window when refreshing L4-13 from operator evidence}
        {--dev-beat-evidence= : Optional L4-9 Atlas Dev/external comparison evidence JSON; refreshes L4-13 before capture}
        {--forge-evidence= : Optional real L4-10 Forge receipt JSON; refreshes L4-13 before capture}
        {--final-report= : Existing L4-13 final report JSON path}
        {--packet= : Existing L4-13 handoff packet JSON path}
        {--run-ritual : Run KB sync, code index, projection write/status, docs-health and context-pack receipts}
        {--context-task= : Task used for the cold-session context-pack receipt}
        {--knowledge-sync-receipt= : Existing or output knowledge-sync receipt path}
        {--code-index-receipt= : Existing or output code-index receipt path}
        {--projection-write-receipt= : Existing or output projection-write receipt path}
        {--projection-status-receipt= : Existing or output projection-status receipt path}
        {--docs-health-receipt= : Existing or output docs-health receipt path}
        {--context-pack-receipt= : Existing or output context-pack receipt path}
        {--write : Persist the final L4-14 capture JSON}
        {--capture-path= : Explicit final capture JSON path}
        {--write-operator-proof-request : Persist the operator-gated L4-9/L4-10 proof request JSON}
        {--operator-proof-request-path= : Explicit operator proof request JSON path}
        {--strict : Exit non-zero unless the final capture is ready}
        {--require-list-4-complete : Exit non-zero unless L4-9/L4-10 operator-gated proofs make Lista 4 claimable}
        {--json : Emit canonical JSON}';

    protected $description = 'Build the Fable L4-14 final M capture receipt from canonical docs, frozen tests, KB/index/projection receipts and a cold-session context pack.';

    public function handle(AtlasFableFinalCaptureService $service): int
    {
        $payload = $service->capture([
            'workspace' => $this->stringOption('workspace'),
            'baseline_path' => $this->stringOption('baseline'),
            'series_path' => $this->stringOption('series'),
            'date' => $this->stringOption('date'),
            'hours' => $this->intOption('hours') ?? 24,
            'dev_beat_evidence_path' => $this->stringOption('dev-beat-evidence'),
            'forge_evidence_path' => $this->stringOption('forge-evidence'),
            'final_report_path' => $this->stringOption('final-report'),
            'packet_path' => $this->stringOption('packet'),
            'run_ritual' => (bool) $this->option('run-ritual'),
            'context_task' => $this->stringOption('context-task'),
            'knowledge_sync_receipt_path' => $this->stringOption('knowledge-sync-receipt'),
            'code_index_receipt_path' => $this->stringOption('code-index-receipt'),
            'projection_write_receipt_path' => $this->stringOption('projection-write-receipt'),
            'projection_status_receipt_path' => $this->stringOption('projection-status-receipt'),
            'docs_health_receipt_path' => $this->stringOption('docs-health-receipt'),
            'context_pack_receipt_path' => $this->stringOption('context-pack-receipt'),
            'write_capture' => (bool) $this->option('write'),
            'capture_path' => $this->stringOption('capture-path'),
            'write_operator_proof_request' => (bool) $this->option('write-operator-proof-request'),
            'operator_proof_request_path' => $this->stringOption('operator-proof-request-path'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->renderHuman($payload);
        }

        if ((bool) $this->option('strict') && ! $this->strictReady($payload)) {
            return self::FAILURE;
        }

        if ((bool) $this->option('require-list-4-complete') && ! $this->list4CompleteReady($payload)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function renderHuman(array $payload): void
    {
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Fable L4-14</>', (string) ($payload['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Final report', (string) data_get($payload, 'final_report.status', 'unknown'));
        $this->components->twoColumnDetail('Ritual receipts', (string) data_get($payload, 'ritual.status', 'unknown'));
        $this->components->twoColumnDetail('Cold session', (string) data_get($payload, 'cold_session_recovery.status', 'unknown'));
        if (($path = data_get($payload, 'written_capture_path')) !== null) {
            $this->components->twoColumnDetail('Capture path', (string) $path);
        }
        if (($path = data_get($payload, 'written_operator_proof_request_path')) !== null) {
            $this->components->twoColumnDetail('Operator proof request', (string) $path);
        }
        foreach ((array) ($payload['blockers'] ?? []) as $blocker) {
            $this->warn((string) $blocker);
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function strictReady(array $payload): bool
    {
        return in_array((string) ($payload['status'] ?? ''), ['ready', 'ready_with_operator_gated_external_proofs'], true)
            && (array) ($payload['blockers'] ?? []) === []
            && data_get($payload, 'cold_session_recovery.status') === 'ready';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function list4CompleteReady(array $payload): bool
    {
        return $this->strictReady($payload)
            && (bool) data_get($payload, 'claim_policy.list_4_completion_claim_allowed', false)
            && data_get($payload, 'operator_gated_external_proofs.status') === 'ready';
    }

    private function stringOption(string $key): ?string
    {
        $value = $this->option($key);
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return $value === '' ? null : $value;
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
