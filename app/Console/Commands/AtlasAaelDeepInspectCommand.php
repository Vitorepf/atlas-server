<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\AtlasAaelExecutionReceiptLedger;
use Illuminate\Console\Command;

/**
 * Read-only deep-dive lens into ONE AAEL execution receipt.
 *
 *   atlas:aael:inspect <execution_id> [--json]   Read a specific receipt.
 *   atlas:aael:inspect --latest [--json]         Read the most recent receipt.
 *
 * Emits four sections: plan_prover, runner_result, drift_audit, opportunities. NEVER mutates state.
 */
final class AtlasAaelDeepInspectCommand extends Command
{
    public const SCHEMA = 'atlas.aael.deep_inspect.v1';

    public const REASON_USAGE = 'usage';

    public const REASON_NOT_FOUND = 'execution_not_found';

    public const EXIT_OK = 0;

    public const EXIT_FAIL = 1;

    protected $signature = 'atlas:aael:inspect {execution_id? : the execution_id to inspect} {--latest : pick the most recent receipt} {--json}';

    protected $description = 'Read-only deep inspect of one AAEL execution receipt.';

    public function handle(AtlasAaelExecutionReceiptLedger $ledger): int
    {
        $executionId = (string) ($this->argument('execution_id') ?? '');
        $latest = (bool) $this->option('latest');

        if ($executionId === '' && ! $latest) {
            $this->error('Usage: atlas:aael:inspect <execution_id> [--json]  OR  atlas:aael:inspect --latest [--json]');

            return self::EXIT_FAIL;
        }

        $receipts = $ledger->list();
        if ($latest && $executionId === '') {
            $receipt = $receipts[0] ?? null;
        } else {
            $receipt = null;
            foreach ($receipts as $row) {
                if ((string) ($row['execution_id'] ?? '') === $executionId) {
                    $receipt = $row;
                    break;
                }
            }
        }

        if ($receipt === null) {
            $payload = ['schema_version' => self::SCHEMA, 'reason' => self::REASON_NOT_FOUND, 'execution_id' => $executionId];
            if ($this->option('json')) {
                $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            } else {
                $this->error('execution_not_found: '.$executionId);
            }

            return self::EXIT_FAIL;
        }

        $view = $this->buildDeepView($receipt);
        if ($this->option('json')) {
            $this->line((string) json_encode($view, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->humanRender($view);
        }

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function buildDeepView(array $receipt): array
    {
        $proverVerdict = (array) ($receipt['prover_verdict'] ?? []);
        $runnerResult = (array) ($receipt['runner_result'] ?? []);
        $driftAudit = (array) ($receipt['drift_audit'] ?? []);

        $opportunities = [];
        foreach ($driftAudit as $taskId => $audit) {
            $opportunities[] = ['objective' => (string) $taskId, 'opportunity_id' => null];
        }
        foreach ((array) ($proverVerdict['rejected'] ?? []) as $rej) {
            if (is_array($rej)) {
                $opportunities[] = [
                    'objective' => (string) ($rej['objective'] ?? ''),
                    'opportunity_id' => $rej['opportunity_id'] ?? null,
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA,
            'execution_id' => (string) ($receipt['execution_id'] ?? ''),
            'recorded_at' => (string) ($receipt['recorded_at'] ?? ''),
            'plan_prover' => [
                'rejected_count' => (int) ($proverVerdict['rejected_count'] ?? 0),
                'rejected' => (array) ($proverVerdict['rejected'] ?? []),
            ],
            'runner_result' => [
                'tasks_processed' => (int) ($runnerResult['tasks_processed'] ?? 0),
                'proposals_certified_for_review' => (int) ($runnerResult['proposals_certified_for_review'] ?? 0),
                'stop_reason' => (string) ($runnerResult['stop_reason'] ?? ''),
                'deferred_count' => (int) ($runnerResult['deferred_count'] ?? 0),
            ],
            'drift_audit' => $driftAudit,
            'opportunities' => $opportunities,
        ];
    }

    /**
     * @param  array<string,mixed>  $view
     */
    private function humanRender(array $view): void
    {
        $this->line('AAEL execution: '.$view['execution_id']);
        $this->line('  recorded_at: '.$view['recorded_at']);
        $this->line('  plan_prover.rejected_count: '.$view['plan_prover']['rejected_count']);
        $this->line('  runner_result.tasks_processed: '.$view['runner_result']['tasks_processed']);
        $this->line('  runner_result.proposals_certified_for_review: '.$view['runner_result']['proposals_certified_for_review']);
        $this->line('  runner_result.stop_reason: '.$view['runner_result']['stop_reason']);
        $this->line('  drift_audit.task_count: '.count($view['drift_audit']));
        $this->line('  opportunities: '.count($view['opportunities']));
    }
}
