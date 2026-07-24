<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceComposer;
use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\Provenance\AtlasMaestroPacketProvenanceVerifier;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

final class AtlasTaskMaestroProvenanceCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:task:maestro:provenance
        {action : trace|verify|history}
        {--packet= : packet id}
        {--receipt= : receipt id}
        {--limit=20}
        {--json}';

    protected $description = 'Atlas Maestro packet provenance CLI: trace|verify|history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'trace' => $this->doTrace(),
            'verify' => $this->doVerify(),
            'history' => $this->doHistory(),
            default => $this->errExit('unknown action: '.$action),
        };
    }

    private function doTrace(): int
    {
        $packet = (string) ($this->option('packet') ?? '');
        if ($packet === '') {
            return $this->errExit('--packet required for trace');
        }
        $record = $this->latestRecordFor($packet);
        if ($record === null) {
            return $this->errExit('no provenance for packet: '.$packet);
        }
        $canonical = $this->canonicalRecordJson($record);
        if ($this->option('json')) {
            $this->line($canonical);
        } else {
            $this->info('packet_id='.$record['packet_id']);
            $this->info('origin_kind='.$record['origin_kind']);
            $this->info('origin_id='.$record['origin_id']);
            foreach ((array) ($record['chain'] ?? []) as $i => $link) {
                $this->info(sprintf('  [%d] %s parent=%s', $i, (string) ($link['source_kind'] ?? ''), (string) ($link['parent_id'] ?? '-')));
            }
        }

        return 0;
    }

    private function doVerify(): int
    {
        $packet = (string) ($this->option('packet') ?? '');
        $receiptId = (string) ($this->option('receipt') ?? '');
        if ($packet === '' && $receiptId === '') {
            return $this->errExit('--packet or --receipt required for verify');
        }
        $record = $packet !== ''
            ? $this->latestRecordFor($packet)
            : $this->recordFromReceiptId($receiptId);
        if ($record === null) {
            return $this->errExit('record not found');
        }

        $verifier = new AtlasMaestroPacketProvenanceVerifier();
        $verdict = $verifier->verify($record);

        if ($this->option('json')) {
            $this->line($this->encode($verdict));
        } else {
            $this->info('ok='.(YesNo::trueFalse($verdict['ok'])).' reason_code='.$verdict['reason_code']
                .(isset($verdict['broken_link_id']) ? ' broken_link_id='.$verdict['broken_link_id'] : ''));
        }

        return (bool) ($verdict['ok'] ?? false) ? 0 : 1;
    }

    private function doHistory(): int
    {
        $packet = (string) ($this->option('packet') ?? '');
        $limit = max(0, (int) $this->option('limit'));
        $ledger = $this->ledger();

        if ($packet !== '') {
            $rows = $ledger->listForPacket($packet);
            $rows = array_slice($rows, max(0, count($rows) - $limit));
        } else {
            $rows = $ledger->tail($limit);
        }
        if ($this->option('json')) {
            $this->line($this->encode($rows));
        } else {
            foreach ($rows as $r) {
                $this->info(sprintf('%s seq=%s packet=%s', (string) ($r['receipt_id'] ?? ''), (string) ($r['sequence_no'] ?? ''), (string) ($r['packet_id'] ?? '')));
            }
        }

        return 0;
    }

    /** @return array<string,mixed>|null */
    private function latestRecordFor(string $packet): ?array
    {
        $rows = $this->ledger()->listForPacket($packet);
        if ($rows === []) {
            return null;
        }
        $last = $rows[count($rows) - 1];
        $record = $last['verdict'] ?? null;

        return is_array($record) ? $record : null;
    }

    /** @return array<string,mixed>|null */
    private function recordFromReceiptId(string $receiptId): ?array
    {
        $receipt = $this->ledger()->getReceipt($receiptId);
        if ($receipt === null) {
            return null;
        }
        $record = $receipt['verdict'] ?? null;

        return is_array($record) ? $record : null;
    }

    private function ledger(): AtlasMaestroPacketProvenanceReceiptLedger
    {
        if (app()->bound(AtlasMaestroPacketProvenanceReceiptLedger::class)) {
            return app(AtlasMaestroPacketProvenanceReceiptLedger::class);
        }

        return new AtlasMaestroPacketProvenanceReceiptLedger(
            storage_path('atlas/maestro/provenance/receipts.jsonl'),
        );
    }

    /** @param array<string,mixed> $record */
    private function canonicalRecordJson(array $record): string
    {
        $canonical = [
            'chain' => $record['chain'] ?? [],
            'origin_id' => (string) ($record['origin_id'] ?? ''),
            'origin_kind' => (string) ($record['origin_kind'] ?? ''),
            'packet_id' => (string) ($record['packet_id'] ?? ''),
        ];

        return (string) json_encode($this->sortRecursive($canonical), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function sortRecursive(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $i): mixed => $this->sortRecursive($i), $value);
        }
        ksort($value, SORT_STRING);
        $sorted = [];
        foreach ($value as $k => $v) {
            $sorted[$k] = $this->sortRecursive($v);
        }

        return $sorted;
    }

    private function errExit(string $msg): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode(['status' => 'refused', 'reason' => $msg], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        } else {
            $this->error($msg);
        }

        return 2;
    }
}
