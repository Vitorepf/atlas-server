<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantExpressionParser;
use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantProofReceiptLedger;
use App\Services\Ai\AutonomousEvolution\SelfMod\FormalProofs\AtlasLoopFormalInvariantSurvivalProver;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing CLI surface over the formal-proof primitives. Provider-free.
 *
 * Exit codes:
 *   0  survives, or history hit
 *   2  indeterminate
 *   3  broken or parse error
 *   1  argument/operator errors (missing flag, unknown action)
 *
 * Dormant by default — registration is conditional on
 * config('atlas.loop.formal_proofs_cli_enabled').
 *
 * IMPORTANT: this class is intentionally `abstract` so Laravel's path-based command
 * auto-discovery (Illuminate\Foundation\Console\Kernel::load) SKIPS it
 * ($command->isAbstract() === true short-circuits the filter). The concrete runner
 * {@see AtlasLoopFormalInvariantProofCliRunner} is registered ONLY when the operator
 * config flag is on — giving us a genuinely dormant CLI even though the source file
 * lives under the auto-discovered Console/Commands directory.
 */
abstract class AtlasLoopFormalInvariantProofCli extends Command
{
    public const PROVER_VERSION = 'atlas.loop.formal_invariant_prover.v1';

    public const LEDGER_BINDING = 'atlas.loop.formal_proof.ledger';

    public const RUNNER_CLASS = AtlasLoopFormalInvariantProofCliRunner::class;

    protected $description = 'Parses @invariant docblocks, proves invariant survival across pre/post sources, and inspects the append-only proof receipt ledger.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'parse' => $this->doParse(),
            'prove' => $this->doProve(),
            'history' => $this->doHistory(),
            default => $this->emit(['error' => 'unknown_action:'.$action], 1),
        };
    }

    private function doParse(): int
    {
        $file = (string) $this->option('file');
        if ($file === '' || ! is_file($file)) {
            return $this->emit(['action' => 'parse', 'error' => 'file_required'], 1);
        }
        $contents = (string) file_get_contents($file);
        $expressions = $this->extractInvariantExpressions($contents);
        $parser = $this->parser();

        $items = [];
        $hadError = false;
        foreach ($expressions as $entry) {
            $result = $parser->parse((string) $entry['expression']);
            if ($result['errors'] !== []) {
                $hadError = true;
            }
            $items[] = [
                'invariant_id' => $entry['invariant_id'],
                'expression' => $entry['expression'],
                'ast' => $result['ast'],
                'errors' => $result['errors'],
            ];
        }

        return $this->emit(['action' => 'parse', 'file' => $file, 'invariants' => $items], $hadError ? 3 : 0);
    }

    private function doProve(): int
    {
        $expression = (string) $this->option('invariant');
        $prePath = (string) $this->option('pre');
        $postPath = (string) $this->option('post');
        if ($expression === '' || $prePath === '' || $postPath === '' || ! is_file($prePath) || ! is_file($postPath)) {
            return $this->emit(['action' => 'prove', 'error' => 'missing_required_options'], 1);
        }

        $parsed = $this->parser()->parse($expression);
        if ($parsed['errors'] !== [] || ! is_array($parsed['ast'])) {
            return $this->emit([
                'action' => 'prove',
                'verdict' => 'parse_error',
                'errors' => $parsed['errors'],
            ], 3);
        }

        $invariantId = $this->invariantIdFor($expression);
        $preSource = (string) file_get_contents($prePath);
        $postSource = (string) file_get_contents($postPath);

        try {
            $proof = $this->prover()->prove($invariantId, $parsed['ast'], $preSource, $postSource);
        } catch (Throwable $e) {
            return $this->emit(['action' => 'prove', 'verdict' => 'broken', 'error' => $e->getMessage()], 3);
        }

        $receipt = $this->ledger()->record($proof, [
            'file_path' => $postPath,
            'pre_sha' => hash('sha256', $preSource),
            'post_sha' => hash('sha256', $postSource),
            'prover_version' => self::PROVER_VERSION,
        ]);

        $verdict = (string) $proof['verdict'];
        $exit = match ($verdict) {
            'survives' => 0,
            'indeterminate' => 2,
            default => 3,
        };

        return $this->emit([
            'action' => 'prove',
            'invariant_id' => $invariantId,
            'verdict' => $verdict,
            'receipt_id' => (string) ($receipt['receipt_id'] ?? ''),
            'witness_nodes' => $proof['witness_nodes'],
            'unsupported_reason' => $proof['unsupported_reason'] ?? null,
        ], $exit);
    }

    private function doHistory(): int
    {
        $invariant = (string) $this->option('invariant');
        $receiptId = (string) $this->option('receipt');
        $ledger = $this->ledger();

        if ($receiptId !== '') {
            $hit = $ledger->lookup($receiptId);

            return $this->emit([
                'action' => 'history',
                'mode' => 'receipt',
                'receipt' => $hit,
            ], $hit === null ? 2 : 0);
        }

        if ($invariant === '') {
            return $this->emit(['action' => 'history', 'error' => 'invariant_or_receipt_required'], 1);
        }
        $invariantId = $this->invariantIdFor($invariant);
        $rows = $ledger->history($invariantId);

        return $this->emit([
            'action' => 'history',
            'mode' => 'invariant',
            'invariant_id' => $invariantId,
            'count' => count($rows),
            'receipts' => $rows,
        ], $rows === [] ? 2 : 0);
    }

    /**
     * @return list<array{invariant_id:string,expression:string}>
     */
    private function extractInvariantExpressions(string $contents): array
    {
        $out = [];
        foreach (preg_split("/\r?\n/", $contents) ?: [] as $line) {
            $trimmed = trim($line, " \t\n\r\0\x0B*/");
            if (! str_starts_with($trimmed, '@invariant')) {
                continue;
            }
            $payload = trim(substr($trimmed, strlen('@invariant')));
            if (! preg_match('/^([A-Za-z0-9_.-]+)\s*:\s*(.+)$/', $payload, $m)) {
                $out[] = ['invariant_id' => '__parse_error__', 'expression' => $payload];

                continue;
            }
            $out[] = ['invariant_id' => $m[1], 'expression' => trim($m[2])];
        }

        return $out;
    }

    private function invariantIdFor(string $expression): string
    {
        return 'inv-'.substr(hash('sha256', $expression), 0, 16);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exit): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        }

        return $exit;
    }

    private function parser(): AtlasLoopFormalInvariantExpressionParser
    {
        return $this->getLaravel()->make(AtlasLoopFormalInvariantExpressionParser::class);
    }

    private function prover(): AtlasLoopFormalInvariantSurvivalProver
    {
        return $this->getLaravel()->make(AtlasLoopFormalInvariantSurvivalProver::class);
    }

    private function ledger(): AtlasLoopFormalInvariantProofReceiptLedger
    {
        if ($this->getLaravel()->bound(self::LEDGER_BINDING)) {
            $ledger = $this->getLaravel()->make(self::LEDGER_BINDING);
            if ($ledger instanceof AtlasLoopFormalInvariantProofReceiptLedger) {
                return $ledger;
            }
        }

        return $this->getLaravel()->make(AtlasLoopFormalInvariantProofReceiptLedger::class);
    }
}

/**
 * Concrete runner registered ONLY when config('atlas.loop.formal_proofs_cli_enabled') is true.
 * Lives in the same file as the abstract above to keep allowed_files single.
 */
final class AtlasLoopFormalInvariantProofCliRunner extends AtlasLoopFormalInvariantProofCli
{
    protected $signature = 'atlas:loop:formal-proof {action : parse|prove|history} {--file=} {--invariant=} {--pre=} {--post=} {--receipt=} {--json}';
}
