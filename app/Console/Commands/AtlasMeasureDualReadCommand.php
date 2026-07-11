<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\EngineeringKernel\Adapters\JsonlReceiptStore;
use App\Services\Ai\Kernel\Evidence\AtlasEvidenceLedger;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Console\Command;

/**
 * ACOS Excellence MED-01 — minimal dual-read recorder for meter transitions.
 *
 * Records {medidor, valor_antigo, valor_novo, commit, justificativa} to the
 * Evidence Ledger (or append-only JSONL when the ledger table is unavailable).
 */
final class AtlasMeasureDualReadCommand extends Command
{
    public const SCHEMA_VERSION = 'atlas.measure.dual_read.v1';

    public const JSONL_RELATIVE_PATH = 'app/atlas/evidence/measure-dual-read.jsonl';

    protected $signature = 'atlas:measure:dual-read
        {medidor : Medidor id (slice or metric name)}
        {valor_antigo : Value under the old meter}
        {valor_novo : Value under the new meter}
        {justificativa : Written justification for the meter change}
        {--commit= : Git commit sha (default: git rev-parse --short HEAD)}
        {--json : Emit canonical JSON}';

    protected $description = 'ACOS MED-01 — record dual-read meter transition to the Evidence Ledger.';

    public function handle(AtlasEvidenceLedger $ledger): int
    {
        $medidor = trim((string) $this->argument('medidor'));
        $valorAntigo = trim((string) $this->argument('valor_antigo'));
        $valorNovo = trim((string) $this->argument('valor_novo'));
        $justificativa = trim((string) $this->argument('justificativa'));
        $commit = $this->resolveCommit();

        if ($medidor === '' || $valorAntigo === '' || $valorNovo === '' || $justificativa === '') {
            return $this->emit(['ok' => false, 'reason' => 'missing_required_field'], self::FAILURE);
        }

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'medidor' => $medidor,
            'valor_antigo' => $valorAntigo,
            'valor_novo' => $valorNovo,
            'commit' => $commit,
            'justificativa' => $justificativa,
            'recorded_at' => now()->toIso8601String(),
        ];

        $storage = 'atlas_ledger_events';
        $jsonlPath = storage_path(self::JSONL_RELATIVE_PATH);
        $event = null;

        if (DatabaseTableAvailability::has('atlas_ledger_events')) {
            $event = $ledger->record(LedgerEventType::MeasureDualReadRecorded, $payload, [
                'envelope_id' => 'measure:dual_read:'.$medidor,
                'correlation_id' => 'measure:dual_read:'.$medidor.':'.$commit,
                'scope_type' => 'measure',
                'scope_id' => $medidor,
                'emitter_stage' => 'atlas.measure',
                'emitter_version' => self::SCHEMA_VERSION,
            ]);
        }

        if (! $event instanceof AtlasLedgerEvent) {
            $storage = 'jsonl';
            (new JsonlReceiptStore($jsonlPath))->append($payload);
        }

        return $this->emit([
            'ok' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'storage' => $storage,
            'medidor' => $medidor,
            'valor_antigo' => $valorAntigo,
            'valor_novo' => $valorNovo,
            'commit' => $commit,
            'justificativa' => $justificativa,
            'event_id' => $event?->event_id,
            'jsonl_path' => $storage === 'jsonl' ? $jsonlPath : null,
        ], self::SUCCESS);
    }

    private function resolveCommit(): string
    {
        $option = $this->option('commit');
        if (is_string($option) && trim($option) !== '') {
            return trim($option);
        }

        $short = trim((string) @shell_exec('git -C '.escapeshellarg(base_path()).' rev-parse --short HEAD 2>/dev/null'));

        return $short !== '' ? $short : 'unknown';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $code): int
    {
        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(
                $payload,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } else {
            foreach (['schema_version', 'storage', 'medidor', 'commit', 'event_id'] as $key) {
                if (isset($payload[$key])) {
                    $this->components->twoColumnDetail($key, (string) $payload[$key]);
                }
            }
        }

        return $code;
    }
}
