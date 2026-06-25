<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditCoherenceScanner;
use App\Services\Ai\AutonomousEvolution\Coherence\AtlasLoopPostEditOrphanedTestDetector;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

/**
 * Thin orchestrator CLI for post-edit coherence: wires Scanner + OrphanDetector + Ledger.
 * Contains ZERO scanning/parsing logic; delegates everything to the three primitives.
 */
final class AtlasLoopCoherenceCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_FAIL = 1;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:loop:coherence {action : inspect|scan|history}
        {--edit-set=* : files in the edit set (scan); defaults to git diff --name-only HEAD~1}
        {--id= : receipt_id (inspect)}
        {--limit=20 : history cap}
        {--json : machine-readable output}';

    protected $description = 'Post-edit coherence orchestrator: scan | inspect | history.';

    public function handle(
        AtlasLoopPostEditCoherenceScanner $scanner,
        AtlasLoopPostEditOrphanedTestDetector $orphanDetector,
        AtlasLoopPostEditCoherenceReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'scan' => $this->scan($scanner, $orphanDetector, $ledger),
            'inspect' => $this->inspect($ledger),
            'history' => $this->history($ledger),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function scan(
        AtlasLoopPostEditCoherenceScanner $scanner,
        AtlasLoopPostEditOrphanedTestDetector $orphanDetector,
        AtlasLoopPostEditCoherenceReceiptLedger $ledger,
    ): int {
        $editSet = (array) $this->option('edit-set');
        $editSet = array_values(array_filter(array_map('strval', $editSet), static fn (string $p): bool => $p !== ''));
        if ($editSet === []) {
            $editSet = $this->gitDiffHead();
        }
        sort($editSet, SORT_STRING);

        $sourceFiles = array_values(array_filter($editSet, static fn (string $p): bool => ! str_starts_with($p, 'tests/')));
        $testFiles = array_values(array_filter($editSet, static fn (string $p): bool => str_starts_with($p, 'tests/')));

        $scannerFindings = $scanner->scan($sourceFiles);
        $orphanFindings = $orphanDetector->detect($testFiles);

        $editSetSha = hash('sha256', implode("\n", $editSet));
        $receipt = $ledger->append(
            gitHeadSha: $this->gitHeadSha(),
            editSetSha: $editSetSha,
            scannerFindings: ['findings' => $scannerFindings],
            orphanFindings: ['findings' => $orphanFindings],
        );

        $this->emit($receipt);

        return self::EXIT_OK;
    }

    private function inspect(AtlasLoopPostEditCoherenceReceiptLedger $ledger): int
    {
        $id = (string) ($this->option('id') ?? '');
        if ($id === '') {
            $recent = $ledger->history(1);
            if ($recent === []) {
                return $this->failWith('no_receipts_recorded');
            }
            $this->emit($recent[0]);

            return self::EXIT_OK;
        }
        $receipt = $ledger->receiptById($id);
        if ($receipt === null) {
            return $this->failWith('receipt_not_found:'.$id);
        }
        $this->emit($receipt);

        return self::EXIT_OK;
    }

    private function history(AtlasLoopPostEditCoherenceReceiptLedger $ledger): int
    {
        $limit = max(1, (int) ($this->option('limit') ?? 20));
        $rows = $ledger->history($limit);
        $this->emit(['rows' => $rows]);

        return self::EXIT_OK;
    }

    /**
     * @return list<string>
     */
    private function gitDiffHead(): array
    {
        $proc = new Process(['git', 'diff', '--name-only', 'HEAD~1'], base_path());
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [];
        }
        $lines = preg_split('/\r?\n/', trim($proc->getOutput())) ?: [];

        return array_values(array_filter($lines, static fn (string $l): bool => $l !== ''));
    }

    private function gitHeadSha(): string
    {
        $proc = new Process(['git', 'rev-parse', 'HEAD'], base_path());
        $proc->run();

        return $proc->isSuccessful() ? trim($proc->getOutput()) : 'unknown';
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        foreach ($payload as $k => $v) {
            $this->line($k.': '.(is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }

    private function failWith(string $reason): int
    {
        $this->line($reason);

        return self::EXIT_USAGE;
    }
}
