<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptChain;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptExporter;
use App\Services\Ai\AutonomousEvolution\UnifiedReceipts\AtlasLoopUnifiedReceiptVerifier;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator surface for the unified receipt chain.
 *
 *   inspect [--json]               head_hash + total_nodes + per-source counts + last 10 nodes.
 *   verify                         AtlasLoopUnifiedReceiptVerifier — exit 0 ok, 1 tampered (CI-grade fail-closed).
 *   export --out=PATH [--from=N --to=N | --since-hash=H] [--force]
 *                                  Round-trip-safe export. Refuses to overwrite without --force.
 */
final class AtlasLoopUnifiedReceiptCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_BROKEN = 1;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:receipt:unified {action : inspect|verify|export} {--from=} {--to=} {--since-hash=} {--out=} {--force} {--json}';

    protected $description = 'Operator CLI for the unified receipt chain: inspect | verify | export.';

    public function handle(
        AtlasLoopUnifiedReceiptChain $chain,
        AtlasLoopUnifiedReceiptVerifier $verifier,
        AtlasLoopUnifiedReceiptExporter $exporter,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($chain),
            'verify' => $this->verify($verifier),
            'export' => $this->export($exporter),
            default => $this->usage('unknown action: '.$action),
        };
    }

    private function inspect(AtlasLoopUnifiedReceiptChain $chain): int
    {
        $nodes = [];
        foreach ($chain->all() as $node) {
            $nodes[] = $node;
        }
        $totalNodes = count($nodes);
        $perSource = [];
        foreach ($nodes as $node) {
            $key = $node->source_ledger;
            $perSource[$key] = ($perSource[$key] ?? 0) + 1;
        }
        ksort($perSource);

        $tailRows = array_slice($nodes, -10);
        $tail = array_map(static fn ($n): array => [
            'seq' => $n->seq,
            'source_ledger' => $n->source_ledger,
            'source_receipt_id' => $n->source_receipt_id,
            'recorded_at' => $n->recorded_at,
        ], $tailRows);

        $payload = [
            'head_hash' => $chain->headHash(),
            'total_nodes' => $totalNodes,
            'per_source' => $perSource,
            'tail' => $tail,
        ];

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::EXIT_OK;
        }
        $this->line('head_hash='.$payload['head_hash']);
        $this->line('total_nodes='.$payload['total_nodes']);
        foreach ($payload['per_source'] as $src => $count) {
            $this->line('  '.$src.' = '.$count);
        }
        foreach ($payload['tail'] as $row) {
            $this->line(sprintf('  seq=%d %s %s @%d', $row['seq'], $row['source_ledger'], $row['source_receipt_id'], $row['recorded_at']));
        }

        return self::EXIT_OK;
    }

    private function verify(AtlasLoopUnifiedReceiptVerifier $verifier): int
    {
        $report = $verifier->verify();
        $payload = [
            'ok' => $report->ok,
            'total_nodes' => $report->totalNodes,
            'first_break_seq' => $report->firstBreakSeq,
            'break_reason' => $report->breakReason,
        ];
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('ok='.($report->ok ? 'true' : 'false').' total='.$report->totalNodes.' first_break='.($report->firstBreakSeq ?? 'null').' reason='.($report->breakReason ?? 'none'));
        }

        return $report->ok ? self::EXIT_OK : self::EXIT_BROKEN;
    }

    private function export(AtlasLoopUnifiedReceiptExporter $exporter): int
    {
        $out = (string) $this->option('out');
        if ($out === '') {
            return $this->usage('--out=<path> is required for export');
        }
        $force = (bool) $this->option('force');

        try {
            $from = $this->option('from');
            $to = $this->option('to');
            $sinceHash = (string) $this->option('since-hash');

            if ($sinceHash !== '') {
                $manifest = $exporter->sinceHash($out, $sinceHash, $force);
            } elseif ($from !== null && $to !== null) {
                $manifest = $exporter->range($out, (int) $from, (int) $to, $force);
            } else {
                $manifest = $exporter->full($out, $force);
            }
        } catch (Throwable $e) {
            $this->error('export failed: '.mb_substr($e->getMessage(), 0, 200));

            return self::EXIT_USAGE;
        }

        if ($this->option('json')) {
            $this->line((string) json_encode(['out' => $out, 'manifest' => method_exists($manifest, 'toArray') ? $manifest->toArray() : []], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        } else {
            $this->line('exported='.$out);
        }

        return self::EXIT_OK;
    }

    private function usage(string $message): int
    {
        $this->error($message);

        return self::EXIT_USAGE;
    }
}
