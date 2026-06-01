<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\Generated\AtlasDevEfficientProgrammingFlowRunbookV1Part04Service;
use Illuminate\Console\Command;
use Throwable;

/**
 * Atlas Dev Efficient Programming Flow Runbook v1 · Parte 4 — PR-level decider CLI
 * for the §8.2/§8.3/§9.1 slice (error ledger, telemetry, receipt persistence,
 * prompt quality, plan-only objective).
 *
 *   php artisan atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part04 [--json]
 *
 * Read-only, deterministic, zero side effect. Exercises the documented rules with
 * safe defaults: a clean passed run (no ledger entry), a failed run with the
 * missed-escalation heuristic pending reviewer, the once-per-run telemetry mapping,
 * the canonical receipt path + permissions, the prompt quality checks and the
 * plan-only stop point, then emits the verdicts plus the manifest as JSON.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-runbook-v1-part-04.md
 */
class AtlasDevEfficientProgrammingFlowRunbookV1Part04Command extends Command
{
    protected $signature = 'atlas:aaeos:atlas-dev-efficient-programming-flow-runbook-v1-part04 {--json}';

    protected $description = 'Atlas Dev efficient programming flow runbook (Parte 4) · error ledger, telemetry, receipt path/perms, prompt quality and plan-only contract.';

    public function handle(AtlasDevEfficientProgrammingFlowRunbookV1Part04Service $service): int
    {
        try {
            $payload = [
                'ok' => true,
                'manifest' => $service->manifest(),
                'ledger_passed_clean' => $service->errorLedgerDecision('passed', false, false, []),
                'ledger_failed' => $service->errorLedgerDecision('failed', true, true, []),
                'ledger_missed_escalation' => $service->errorLedgerDecision(
                    'failed',
                    true,
                    false,
                    ['repeated_repair_attempts', 'unverified_patch']
                ),
                'ledger_signed_entry_immutable' => $service->canMutateLedgerEntry(true),
                'telemetry_passed' => $service->telemetryDecision('passed'),
                'telemetry_failed' => $service->telemetryDecision('failed'),
                'telemetry_blocked' => $service->telemetryDecision('blocked'),
                'receipt_path' => $service->receiptPathContract('run_demo', 'verification_receipt'),
                'prompt_quality_ok' => $service->promptQualityChecks(
                    ['plan green', 'tests pass'],
                    ['app/Foo.php'],
                    ['app/Bar.php'],
                    'implement the documented contract'
                ),
                'prompt_quality_conflict' => $service->promptQualityChecks(
                    [],
                    ['app/Foo.php'],
                    ['app/Foo.php'],
                    'implement the documented contract'
                ),
                'plan_only_valid' => $service->planOnlyContract('task_contract_ready', false),
                'plan_only_provider_called' => $service->planOnlyContract('task_contract_ready', true),
            ];

            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->line((string) json_encode([
                'ok' => false,
                'error' => 'atlas_dev_flow_runbook_v1_part04_failed',
                'message' => $e->getMessage(),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::FAILURE;
        }
    }
}
