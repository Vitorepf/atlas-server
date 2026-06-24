<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Receipts\AtlasLoopCycleReceiptSigner;
use Illuminate\Console\Command;

/**
 * Operator front door for the cycle-receipt chain. Three subcommands:
 *
 *   latest                Print the latest entry (or empty-state).
 *   verify [--cycle=ID]   Verify the chain and (when --cycle given) ALSO verify that specific signed receipt.
 *                         Exit 0 = ok, 1 = broken.
 *   chain --limit=N       Print the last N entries (oldest first within the window).
 *
 * READ-ONLY: this command NEVER writes to the chain. --json yields jq-pipeable deterministic output.
 */
final class AtlasLoopCycleReceiptCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_BROKEN = 1;

    protected $signature = 'atlas:loop:receipt {action : latest|verify|chain} {--cycle= : cycle_id for verify} {--limit=20 : chain page size} {--json}';

    protected $description = 'Read-only operator CLI for the cycle-receipt chain: latest | verify | chain.';

    public function handle(AtlasLoopCycleReceiptLedger $ledger, AtlasLoopCycleReceiptSigner $signer): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'latest' => $this->latest($ledger),
            'verify' => $this->verify($ledger, $signer),
            'chain' => $this->chain($ledger),
            default => $this->emitError('unknown action: '.$action),
        };
    }

    private function latest(AtlasLoopCycleReceiptLedger $ledger): int
    {
        $entry = $ledger->latest();
        if ($entry === null) {
            $this->emit(['state' => 'empty'], "empty\n");

            return self::EXIT_OK;
        }
        $this->emit($entry, $this->renderEntryLine($entry));

        return self::EXIT_OK;
    }

    private function verify(AtlasLoopCycleReceiptLedger $ledger, AtlasLoopCycleReceiptSigner $signer): int
    {
        $report = $ledger->verifyChain();
        $cycleId = trim((string) $this->option('cycle'));

        $signedReceiptOk = null;
        if ($cycleId !== '' && ($report['ok'] ?? false)) {
            foreach ($ledger->all() as $entry) {
                $body = (array) ($entry['signed_receipt']['body'] ?? []);
                if ((string) ($body['cycle_id'] ?? '') === $cycleId) {
                    $signedReceiptOk = $signer->verify((array) $entry['signed_receipt']);
                    break;
                }
            }
            if ($signedReceiptOk === null) {
                $signedReceiptOk = false; // cycle_id not on the chain ⇒ verification fails
            }
        }

        $ok = (bool) ($report['ok'] ?? false) && ($signedReceiptOk !== false);
        $payload = [
            'ok' => $ok,
            'chain_report' => $report,
            'cycle_id' => $cycleId !== '' ? $cycleId : null,
            'signed_receipt_ok' => $signedReceiptOk,
        ];

        if ($ok) {
            $this->emit($payload, 'ok');

            return self::EXIT_OK;
        }
        $brokenAt = $report['broken_at'] ?? null;
        $this->emit($payload, 'broken at seq='.($brokenAt === null ? 'n/a' : (string) $brokenAt));

        return self::EXIT_BROKEN;
    }

    private function chain(AtlasLoopCycleReceiptLedger $ledger): int
    {
        $limit = max(1, (int) $this->option('limit'));
        $rows = iterator_to_array($ledger->all());
        $window = array_values(array_slice($rows, -$limit));

        $rendered = array_map(fn (array $e): array => $this->renderChainRow($e), $window);

        if ($this->option('json')) {
            $this->line((string) json_encode(['entries' => $rendered], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::EXIT_OK;
        }
        foreach ($rendered as $r) {
            $this->line(sprintf('seq=%d cycle=%s body=%s chain=%s', $r['seq'], $r['cycle_id'], $r['body_canonical_sha256'], $r['chain_hash']));
        }

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $entry
     * @return array{seq:int, cycle_id:string, body_canonical_sha256:string, chain_hash:string}
     */
    private function renderChainRow(array $entry): array
    {
        $signed = (array) ($entry['signed_receipt'] ?? []);
        $body = (array) ($signed['body'] ?? []);

        return [
            'seq' => (int) ($entry['seq'] ?? 0),
            'cycle_id' => (string) ($body['cycle_id'] ?? ''),
            'body_canonical_sha256' => substr((string) ($signed['body_canonical_sha256'] ?? ''), 0, 12),
            'chain_hash' => substr((string) ($entry['chain_hash'] ?? ''), 0, 12),
        ];
    }

    /**
     * @param  array<string,mixed>  $entry
     */
    private function renderEntryLine(array $entry): string
    {
        $signed = (array) ($entry['signed_receipt'] ?? []);
        $body = (array) ($signed['body'] ?? []);

        return sprintf(
            'seq=%d cycle=%s body=%s',
            (int) ($entry['seq'] ?? 0),
            (string) ($body['cycle_id'] ?? ''),
            substr((string) ($signed['body_canonical_sha256'] ?? ''), 0, 12),
        );
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line($humanLine);
    }

    private function emitError(string $message): int
    {
        $this->error($message);

        return self::EXIT_BROKEN;
    }
}
