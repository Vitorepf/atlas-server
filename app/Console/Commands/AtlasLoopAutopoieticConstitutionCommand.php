<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionAuditReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionDriftDetector;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\Constitution\AtlasLoopAutopoieticConstitutionRegistry;
use Illuminate\Console\Command;
use Throwable;

/**
 * Pure-observability CLI for the autopoietic constitutional floor. Three actions:
 *
 *   inspect   Print registry fingerprint + forbidden_scopes + approval thresholds (JSON when --json).
 *   verify    Delegate to the drift detector — exit 2 + drift_detected on drift; 3 on missing anchor.
 *   audit     Print the last N receipts from the audit ledger + tail summary.
 *
 * INVARIANT: this command NEVER writes the fingerprint file, NEVER appends to the receipt ledger, NEVER
 * mutates the registry. The static-grep test asserts the source carries no write-path call sites over the
 * fingerprint file or receipt ledger storage paths.
 *
 * Exit codes: 0 clean / 2 drift_detected / 3 missing_fingerprint_anchor / 4 ledger_io_error.
 */
final class AtlasLoopAutopoieticConstitutionCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_DRIFT = 2;

    public const EXIT_MISSING_ANCHOR = 3;

    public const EXIT_LEDGER_IO = 4;

    protected $signature = 'atlas:loop:autopoiesis:constitution {action : inspect|verify|audit} {--json} {--limit=20}';

    protected $description = 'Pure observability for the autopoietic constitutional floor: inspect | verify | audit.';

    public function handle(
        AtlasLoopAutopoieticConstitutionRegistry $registry,
        AtlasLoopAutopoieticConstitutionDriftDetector $detector,
        AtlasLoopAutopoieticConstitutionAuditReceiptLedger $ledger,
    ): int {
        $action = (string) $this->argument('action');

        return match ($action) {
            'inspect' => $this->inspect($registry),
            'verify' => $this->verify($detector),
            'audit' => $this->audit($ledger),
            default => $this->failure('unknown action: '.$action),
        };
    }

    private function inspect(AtlasLoopAutopoieticConstitutionRegistry $registry): int
    {
        $payload = [
            'fingerprint' => $registry->fingerprint(),
            'forbidden_scopes' => $registry->forbiddenScopes(),
            'approval_thresholds' => $this->approvalThresholds($registry),
        ];
        $this->emit($payload, 'fingerprint='.$payload['fingerprint']);

        return self::EXIT_OK;
    }

    private function verify(AtlasLoopAutopoieticConstitutionDriftDetector $detector): int
    {
        try {
            $report = $detector->detect();
        } catch (Throwable $e) {
            $this->error('error: '.mb_substr($e->getMessage(), 0, 200));

            return self::EXIT_LEDGER_IO;
        }

        $payload = $report->toArray();
        if ($report->lastKnownFingerprint === null) {
            $this->emit($payload + ['reason' => 'missing_fingerprint_anchor'], 'missing_fingerprint_anchor');

            return self::EXIT_MISSING_ANCHOR;
        }
        if ($report->drifted) {
            $this->emit($payload + ['reason' => 'drift_detected'], 'drift_detected current='.$report->currentFingerprint.' last_known='.$report->lastKnownFingerprint);

            return self::EXIT_DRIFT;
        }
        $this->emit($payload + ['reason' => 'clean'], 'clean');

        return self::EXIT_OK;
    }

    private function audit(AtlasLoopAutopoieticConstitutionAuditReceiptLedger $ledger): int
    {
        try {
            $receipts = $ledger->all();
        } catch (Throwable $e) {
            $this->error('error: '.mb_substr($e->getMessage(), 0, 200));

            return self::EXIT_LEDGER_IO;
        }

        $limit = max(1, (int) $this->option('limit'));
        $window = array_slice($receipts, -$limit);
        $tail = $receipts === []
            ? ['total_receipts' => 0, 'last_receipt_at' => null, 'last_operator_signature' => null]
            : [
                'total_receipts' => count($receipts),
                'last_receipt_at' => end($receipts)->recordedAt,
                'last_operator_signature' => end($receipts)->operatorSignature,
            ];

        $payload = [
            'receipts' => array_map(static fn ($r): array => $r->toArray(), $window),
            'tail_summary' => $tail,
        ];
        $this->emit($payload, 'total_receipts='.$tail['total_receipts'].' last_at='.((string) $tail['last_receipt_at']));

        return self::EXIT_OK;
    }

    /**
     * @return array<string,string>
     */
    private function approvalThresholds(AtlasLoopAutopoieticConstitutionRegistry $registry): array
    {
        // The known categories the registry knows about — pulled deterministically from forbiddenScopes()
        // (a registry view) plus a default lookup. The registry contract exposes one threshold per category.
        $thresholds = [];
        foreach (array_unique(['scope_origination', 'frozen_organ_edit', 'governance_edit', 'standard']) as $category) {
            $thresholds[$category] = $registry->approvalThresholdFor($category);
        }

        return $thresholds;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, string $humanLine): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $this->line($humanLine);
    }

    private function failure(string $message): int
    {
        $this->error($message);

        return self::EXIT_LEDGER_IO;
    }
}
