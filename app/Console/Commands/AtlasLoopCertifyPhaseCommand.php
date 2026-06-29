<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\LiveCycle\AtlasLoopCertifyPhaseRunner;
use App\Services\Ai\AutonomousEvolution\LiveCycle\CertificationFailedException;
use Illuminate\Console\Command;

/**
 * Arms the dormant pure {@see AtlasLoopCertifyPhaseRunner::run()} at the operator surface: runs the certify
 * phase over a supplied implement receipt and emits the certify verdict (certified / regressed / inconclusive
 * + status + reason) as deterministic facts. Pure (io=0), read-only: the certifier reads the receipt's own
 * declared certifier_result and the receipt-chain appender is a no-op, so nothing is written.
 *
 * --receipt accepts inline JSON or a path to a JSON file.
 */
final class AtlasLoopCertifyPhaseCommand extends Command
{
    protected $signature = 'atlas:loop:certify-phase {--receipt=} {--json}';

    protected $description = 'Read-only: run the loop certify phase over an implement receipt (pure, io=0).';

    public function handle(): int
    {
        $receiptOption = $this->option('receipt');
        if ($receiptOption === null || trim((string) $receiptOption) === '') {
            $this->line((string) json_encode(['status' => 'usage_error', 'reason' => 'receipt_required'], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        $raw = is_file((string) $receiptOption) ? (string) file_get_contents((string) $receiptOption) : (string) $receiptOption;
        $receipt = json_decode($raw, true);
        if (! is_array($receipt)) {
            $this->line((string) json_encode(['status' => 'invalid_json', 'receipt' => (string) $receiptOption], JSON_UNESCAPED_SLASHES));

            return self::INVALID;
        }

        // Pure: the certifier reads the receipt's OWN declared verdict; the chain appender writes nothing.
        $runner = new AtlasLoopCertifyPhaseRunner(
            static fn (array $implementReceipt): array => (array) ($implementReceipt['certifier_result'] ?? []),
            static fn (array $certifyReceipt): null => null,
        );

        try {
            $verdict = $runner->run($receipt);
        } catch (CertificationFailedException $e) {
            $verdict = $e->receipt; // a regressed verdict raises but still carries the recorded receipt
        }

        $this->line((string) json_encode($verdict, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
