<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\LoadsFactsJsonOption;
use Illuminate\Console\Command;
use Throwable;

/**
 * Read-only operator surface for the Self-Construction receipt brain.
 *
 *   index         echoes a deterministic index over receipt facts grouped by class.
 *   bind          binds a receipt to a task/lease pair; rejects unverified worker claims.
 *   reality       reports completion reality: compares claimed_outcome vs verified_evidence.
 *   export-plan   plans a bounded memory export from receipts; NEVER writes memory.
 *
 * Every action is read-only. NEVER writes memory, NEVER persists, NEVER calls providers.
 * Exit codes: 0 ok, 2 invalid facts.
 */
final class AtlasSelfConstructionReceiptsCommand extends Command
{
    use LoadsFactsJsonOption;

    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    protected $signature = 'atlas:self-construction:receipts {action : index|bind|reality|export-plan} {--facts= : path to a JSON facts payload} {--json}';

    protected $description = 'Read-only Self-Construction receipts CLI: index | bind | reality | export-plan.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $facts = $this->loadFacts();
        if ($facts === null) {
            return self::EXIT_USAGE;
        }

        $payload = match ($action) {
            'index' => $this->index($facts),
            'bind' => $this->bind($facts),
            'reality' => $this->reality($facts),
            'export-plan' => $this->exportPlan($facts),
            default => null,
        };
        if ($payload === null) {
            $this->refuseUsage('unknown action: '.$action);

            return self::EXIT_USAGE;
        }

        $this->emit($payload);

        return self::EXIT_OK;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function index(array $facts): array
    {
        $receipts = (array) ($facts['receipts'] ?? []);
        $byClass = [];
        foreach ($receipts as $r) {
            $class = (string) ((array) $r)['class'] ?? 'unknown';
            $byClass[$class] = ($byClass[$class] ?? 0) + 1;
        }
        ksort($byClass);

        return ['total' => count($receipts), 'by_class' => $byClass];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function bind(array $facts): array
    {
        $taskId = (string) ($facts['task_id'] ?? '');
        $leaseId = (string) ($facts['lease_id'] ?? '');
        $receiptId = (string) ($facts['receipt_id'] ?? '');
        $verified = (bool) ($facts['verified'] ?? false);

        if (! $verified) {
            return [
                'bound' => false,
                'reason' => 'unverified_worker_claim',
                'task_id' => $taskId,
                'lease_id' => $leaseId,
                'receipt_id' => $receiptId,
            ];
        }
        if ($taskId === '' || $leaseId === '' || $receiptId === '') {
            return [
                'bound' => false,
                'reason' => 'missing_binding_keys',
                'task_id' => $taskId,
                'lease_id' => $leaseId,
                'receipt_id' => $receiptId,
            ];
        }

        return [
            'bound' => true,
            'task_id' => $taskId,
            'lease_id' => $leaseId,
            'receipt_id' => $receiptId,
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function reality(array $facts): array
    {
        $claimed = (string) ($facts['claimed_outcome'] ?? 'unknown');
        $verifiedEvidence = array_values((array) ($facts['verified_evidence'] ?? []));
        $isReal = $verifiedEvidence !== [] && $claimed === ((string) ($facts['verified_outcome'] ?? ''));

        return [
            'claimed_outcome' => $claimed,
            'verified_outcome' => (string) ($facts['verified_outcome'] ?? ''),
            'verified_evidence_count' => count($verifiedEvidence),
            'completion_real' => $isReal,
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return array<string,mixed>
     */
    private function exportPlan(array $facts): array
    {
        $receipts = array_values((array) ($facts['receipts'] ?? []));
        $maxItems = max(1, (int) ($facts['max_items'] ?? 50));
        $sliced = array_slice($receipts, 0, $maxItems);

        return [
            'planned_export_count' => count($sliced),
            'truncated' => count($receipts) > $maxItems,
            'kinds' => array_values(array_unique(array_map(static fn ($r): string => (string) (((array) $r)['kind'] ?? 'unknown'), $sliced))),
            'note' => 'export_plan_only_never_writes_memory',
        ];
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
            $this->line($k.': '.(is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)));
        }
    }
}
