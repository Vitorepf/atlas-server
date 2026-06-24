<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Quaternity\Receipts\AtlasLoopQuaternityCycleReceiptComposer;
use App\Services\Ai\AutonomousEvolution\Quaternity\Receipts\AtlasLoopQuaternityCycleReceiptLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * ATLAS LOOP QUATERNITY CLI — three actions over the Quaternity receipt chain:
 *   status  : prints the ledger's last seq, last envelope_hash, total count, and the ledger file path.
 *   cycle   : reads the four FACT JSON files (Loop, Cortex, Maestro, Operator-Intent), composes a signed
 *             envelope via {@see AtlasLoopQuaternityCycleReceiptComposer}, appends it via
 *             {@see AtlasLoopQuaternityCycleReceiptLedger}, and prints {seq, envelope_hash, signature}.
 *             Fail-closed on any missing/unreadable/non-JSON FACT file — nothing is appended.
 *   verify  : re-walks the ledger and prints {total, ok_count, broken:[{seq,reason}]}. Exit 1 if any broken
 *             so cron can detect tamper.
 */
final class AtlasLoopQuaternityCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:quaternity {action : status|cycle|verify}
        {--loop-fact=}
        {--cortex-fact=}
        {--maestro-fact=}
        {--operator-intent-fact=}
        {--cycle-id=}';

    /** @var string */
    protected $description = 'Quaternity receipt chain CLI (status|cycle|verify) — composes and verifies the 4-FACT signed envelopes';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $ledger = new AtlasLoopQuaternityCycleReceiptLedger($this->ledgerFile());

        return match ($action) {
            'status' => $this->status($ledger),
            'cycle' => $this->cycle($ledger),
            'verify' => $this->verify($ledger),
            default => $this->emit(['error' => 'unknown action', 'action' => $action], 2),
        };
    }

    private function status(AtlasLoopQuaternityCycleReceiptLedger $ledger): int
    {
        $rows = $ledger->verify(); // walks the file; safe to reuse for tallying
        $lines = $this->readLedgerLines();
        $last = $lines === [] ? null : $lines[array_key_last($lines)];

        return $this->emit([
            'total' => count($rows),
            'last_seq' => $last === null ? 0 : (int) ($last['seq'] ?? 0),
            'last_envelope_hash' => $last === null ? '' : (string) ($last['envelope']['envelope_hash'] ?? ''),
            'ledger_file' => $this->ledgerFile(),
        ], 0);
    }

    private function cycle(AtlasLoopQuaternityCycleReceiptLedger $ledger): int
    {
        $slots = [
            'loop' => (string) $this->option('loop-fact'),
            'cortex' => (string) $this->option('cortex-fact'),
            'maestro' => (string) $this->option('maestro-fact'),
            'operator_intent' => (string) $this->option('operator-intent-fact'),
        ];

        $parts = [];
        foreach ($slots as $slot => $path) {
            if ($path === '' || ! is_file($path) || ! is_readable($path)) {
                return $this->emit(['error' => 'fact_file_missing_or_unreadable', 'slot' => $slot, 'path' => $path], 1);
            }
            $decoded = json_decode((string) file_get_contents($path), true);
            if (! is_array($decoded) || $decoded === []) {
                return $this->emit(['error' => 'fact_file_not_json_object', 'slot' => $slot, 'path' => $path], 1);
            }
            $parts[$slot] = $decoded;
        }

        $cycleId = (string) ($this->option('cycle-id') ?: 'cycle-'.bin2hex(random_bytes(6)));

        try {
            $envelope = (new AtlasLoopQuaternityCycleReceiptComposer)->compose($cycleId, $parts);
            $seq = $ledger->append($envelope);
        } catch (Throwable $e) {
            return $this->emit(['error' => 'compose_or_append_failed', 'message' => $e->getMessage()], 1);
        }

        return $this->emit([
            'seq' => $seq,
            'envelope_hash' => (string) $envelope['envelope_hash'],
            'signature' => (string) $envelope['signature'],
            'cycle_id' => $cycleId,
        ], 0);
    }

    private function verify(AtlasLoopQuaternityCycleReceiptLedger $ledger): int
    {
        $rows = $ledger->verify();
        $broken = array_values(array_filter($rows, static fn (array $r): bool => ($r['ok'] ?? false) === false));
        $okCount = count($rows) - count($broken);

        $payload = [
            'total' => count($rows),
            'ok_count' => $okCount,
            'broken' => array_map(static fn (array $r): array => ['seq' => (int) $r['seq'], 'reason' => (string) ($r['reason'] ?? '')], $broken),
        ];

        return $this->emit($payload, $broken === [] ? 0 : 1);
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function readLedgerLines(): array
    {
        $path = $this->ledgerFile();
        if (! is_file($path)) {
            return [];
        }
        $out = [];
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $decoded = json_decode((string) $raw, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }

    private function ledgerFile(): string
    {
        $override = (string) (function_exists('env') ? env('ATLAS_QUATERNITY_LEDGER_FILE', '') : '');
        if ($override !== '') {
            return $override;
        }

        $base = function_exists('storage_path')
            ? storage_path('atlas/loop/quaternity')
            : sys_get_temp_dir().'/atlas/loop/quaternity';

        return $base.'/cycle-receipts.jsonl';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exitCode): int
    {
        $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exitCode;
    }
}
