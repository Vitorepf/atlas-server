<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticBootstrapper;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticBootstrapReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\AtlasLoopAutopoieticContractVerifier;
use App\Services\Ai\AutonomousEvolution\Autopoiesis\SelfExtension\ScopeOverlapsLoopCoreException;
use Illuminate\Console\Command;
use Throwable;

/**
 * WAVE-410 · AUTOPOIETIC BOOTSTRAP CLI — the operator surface that wires the three Autopoiesis primitives into
 * one observable command. Honors the master switch (fail-closed when OFF unless --dry-run), refuses to overlap
 * Loop core, runs the verifier on the bootstrapped manifest, and ONLY appends a receipt when
 * verifier_ok=true AND not --dry-run.
 *
 * EXIT CODES:
 *   0 — full success (and --dry-run paths that are not blocked by other gates)
 *   2 — verifier violation
 *   3 — master switch OFF without --dry-run
 *   4 — scope roots overlap Loop core
 *
 * JSON ENVELOPE: --json emits a canonical {scope_id, manifest_sha256, verifier_ok, violations, receipt_id_or_null,
 * dry_run, exit_reason}.
 */
final class AtlasLoopAutopoieticBootstrapCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:loop:autopoiesis:bootstrap {scope} {--roots=*} {--namespace=} {--intent=} {--dry-run} {--json}';

    /** @var string */
    protected $description = 'Bootstrap an autopoietic scope: install primitives, verify the contracts, and chain a receipt.';

    public function handle(
        AtlasLoopAutopoieticBootstrapper $bootstrapper,
        AtlasLoopAutopoieticContractVerifier $verifier,
        AtlasLoopAutopoieticBootstrapReceiptLedger $ledger,
    ): int {
        $scopeId = trim((string) $this->argument('scope'));
        $namespace = trim((string) $this->option('namespace'));
        $intent = trim((string) $this->option('intent'));
        $rootsRaw = (array) $this->option('roots');
        $roots = array_values(array_filter(array_map('strval', $rootsRaw), static fn (string $r): bool => $r !== ''));
        $dryRun = (bool) $this->option('dry-run');
        $json = (bool) $this->option('json');

        if ($intent === '') {
            return $this->emit(['exit_reason' => 'INTENT_REQUIRED', 'scope_id' => $scopeId, 'dry_run' => $dryRun], 1, $json);
        }

        $masterOn = AtlasLoopMasterSwitch::enabled();
        if (! $masterOn && ! $dryRun) {
            return $this->emit([
                'exit_reason' => 'MASTER_SWITCH_OFF',
                'scope_id' => $scopeId,
                'manifest_sha256' => null,
                'verifier_ok' => false,
                'violations' => [],
                'receipt_id' => null,
                'dry_run' => $dryRun,
            ], 3, $json);
        }

        $scopeDescriptor = [
            'scope_id' => $scopeId,
            'namespace' => $namespace,
            'roots' => $roots,
            'operator_intent' => ['rationale' => $intent, 'scope_id' => $scopeId],
        ];

        try {
            $bootstrap = $bootstrapper->bootstrap($scopeDescriptor);
        } catch (ScopeOverlapsLoopCoreException $e) {
            return $this->emit([
                'exit_reason' => 'SCOPE_OVERLAPS_LOOP_CORE',
                'scope_id' => $scopeId,
                'manifest_sha256' => null,
                'verifier_ok' => false,
                'violations' => [['code' => 'SCOPE_OVERLAPS_LOOP_CORE', 'detail' => $e->getMessage()]],
                'receipt_id' => null,
                'dry_run' => $dryRun,
            ], 4, $json);
        } catch (Throwable $e) {
            return $this->emit([
                'exit_reason' => 'BOOTSTRAP_FAILED',
                'scope_id' => $scopeId,
                'manifest_sha256' => null,
                'verifier_ok' => false,
                'violations' => [['code' => 'BOOTSTRAP_FAILED', 'detail' => $e->getMessage()]],
                'receipt_id' => null,
                'dry_run' => $dryRun,
            ], 1, $json);
        }

        $manifestSha = (string) ($bootstrap['manifest_sha256'] ?? '');
        $report = $verifier->verify($bootstrap['manifest']);
        $reportArray = $report->toArray();
        $verifierOk = (bool) ($reportArray['ok'] ?? false);
        $violations = (array) ($reportArray['violations'] ?? []);

        if (! $verifierOk) {
            // Under --dry-run, surface the verifier verdict but DO NOT gate the exit on it — the operator
            // wants to see what would happen end-to-end. A real (non-dry-run) verifier violation is exit 2.
            return $this->emit([
                'exit_reason' => $dryRun ? 'DRY_RUN_VERIFIER_VIOLATION' : 'VERIFIER_VIOLATION',
                'scope_id' => $scopeId,
                'manifest_sha256' => $manifestSha,
                'verifier_ok' => false,
                'violations' => $violations,
                'receipt_id' => null,
                'dry_run' => $dryRun,
            ], $dryRun ? 0 : 2, $json);
        }

        $receiptId = null;
        if (! $dryRun) {
            $atomicSeq = $this->nextAtomicSeq($ledger);
            $receipt = $ledger->append([
                'atomic_seq' => $atomicSeq,
                'scope_id' => $scopeId,
                'manifest_sha256' => $manifestSha,
                'verifier_ok' => true,
                'verifier_violations' => $violations,
                'operator_intent_digest' => hash('sha256', $intent),
            ]);
            $receiptId = (string) ($receipt['receipt_id'] ?? '');
        }

        return $this->emit([
            'exit_reason' => $dryRun ? 'DRY_RUN_OK' : 'OK',
            'scope_id' => $scopeId,
            'manifest_sha256' => $manifestSha,
            'verifier_ok' => true,
            'violations' => [],
            'receipt_id' => $receiptId,
            'dry_run' => $dryRun,
        ], 0, $json);
    }

    private function nextAtomicSeq(AtlasLoopAutopoieticBootstrapReceiptLedger $ledger): int
    {
        $path = $ledger->path();
        if (! is_file($path)) {
            return 1;
        }
        $lastSeq = 0;
        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $raw) {
            $row = json_decode((string) $raw, true);
            if (is_array($row) && isset($row['atomic_seq'])) {
                $lastSeq = max($lastSeq, (int) $row['atomic_seq']);
            }
        }

        return $lastSeq + 1;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, int $exitCode, bool $json): int
    {
        ksort($payload);
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->line(sprintf(
                '[%s] scope=%s exit=%d manifest_sha=%s verifier_ok=%s receipt=%s dry_run=%s',
                (string) ($payload['exit_reason'] ?? '?'),
                (string) ($payload['scope_id'] ?? ''),
                $exitCode,
                (string) ($payload['manifest_sha256'] ?? ''),
                ($payload['verifier_ok'] ?? false) ? 'true' : 'false',
                (string) ($payload['receipt_id'] ?? ''),
                ($payload['dry_run'] ?? false) ? 'true' : 'false',
            ));
        }

        return $exitCode;
    }
}
