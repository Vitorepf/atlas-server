<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetGate;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroBudgetReceiptLedger;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use Illuminate\Console\Command;
use Throwable;

/**
 * Operator-facing observability surface for Maestro cost facts.
 *
 * Frozen-schema envelope:
 *   { schema: 'atlas.maestro.cost.<action>.v1', status: 'ok'|'empty'|'disabled'|'error',
 *     payload: <array> }
 *
 * NO writes. Master switch OFF ⇒ status='disabled' with empty payload (zero DB reads).
 */
final class AtlasTaskMaestroCostCommand extends Command
{
    /** @var string */
    protected $signature = 'atlas:task:maestro:cost
        {action : ledger|aggregate|budget|history}
        {--task=}
        {--cycle=}
        {--provider=}
        {--task-class=}
        {--by=task_class : task_class|provider|cycle (for aggregate)}
        {--ledger-path=}
        {--budget-receipt-path=}
        {--json}';

    /** @var string */
    protected $description = 'Maestro cost observability CLI: ledger | aggregate | budget | history.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');
        $schema = 'atlas.maestro.cost.'.$action.'.v1';

        if (! in_array($action, ['ledger', 'aggregate', 'budget', 'history'], true)) {
            return $this->envelope('atlas.maestro.cost.unknown.v1', 'error', ['error' => 'unknown_action:'.$action], self::FAILURE);
        }

        if (! AtlasLoopMasterSwitch::enabled()) {
            return $this->envelope($schema, 'disabled', []);
        }

        try {
            $payload = match ($action) {
                'ledger' => $this->ledger(),
                'aggregate' => $this->aggregate(),
                'budget' => $this->budget(),
                'history' => $this->history(),
                default => [],
            };
        } catch (Throwable $e) {
            return $this->envelope($schema, 'error', ['error' => $e->getMessage()], self::FAILURE);
        }

        $status = $payload === [] || $payload === ['rows' => []] ? 'empty' : 'ok';

        return $this->envelope($schema, $status, $payload);
    }

    /**
     * @return array<string,mixed>
     */
    private function ledger(): array
    {
        $ledger = $this->costLedger();
        $task = (string) $this->option('task');
        $cycle = (string) $this->option('cycle');
        $rows = $task !== '' ? $ledger->queryForTask($task) : ($cycle !== '' ? $ledger->queryForCycle($cycle) : $ledger->all());

        return ['rows' => $this->canonicalRows($rows)];
    }

    /**
     * @return array<string,mixed>
     */
    private function aggregate(): array
    {
        $aggregator = new AtlasMaestroCostAggregator($this->costLedger());
        $by = (string) $this->option('by');
        $cycle = $this->option('cycle') !== null && (string) $this->option('cycle') !== '' ? (string) $this->option('cycle') : null;
        $rows = match ($by) {
            'task_class' => $aggregator->aggregateByTaskClass($cycle),
            'provider' => $aggregator->aggregateByProvider($cycle),
            'cycle' => $aggregator->aggregateByCycle($cycle),
            default => $aggregator->aggregateByTaskClass($cycle),
        };

        return ['by' => $by, 'rows' => $this->canonicalRows((array) $rows)];
    }

    /**
     * @return array<string,mixed>
     */
    private function budget(): array
    {
        $gate = new AtlasMaestroBudgetGate(new AtlasMaestroCostAggregator($this->costLedger()));
        $verdict = $gate->decide(
            (string) $this->option('task'),
            (string) $this->option('provider'),
            (string) $this->option('task-class'),
            $this->option('cycle') !== null && (string) $this->option('cycle') !== '' ? (string) $this->option('cycle') : null,
        );

        return ['verdict' => $this->canonicalRow((array) $verdict)];
    }

    /**
     * @return array<string,mixed>
     */
    private function history(): array
    {
        $ledger = $this->budgetReceiptLedger();
        $task = (string) $this->option('task');
        $cycle = (string) $this->option('cycle');
        $rows = $task !== '' ? $ledger->receiptsForTask($task) : ($cycle !== '' ? $ledger->receiptsForCycle($cycle) : $ledger->all());

        return ['rows' => $this->canonicalRows((array) $rows)];
    }

    private function costLedger(): AtlasMaestroCostLedger
    {
        $path = (string) ($this->option('ledger-path') ?? '');
        $resolved = $path !== '' ? $path : storage_path('atlas/maestro/cost_ledger.jsonl');

        return new AtlasMaestroCostLedger($resolved);
    }

    private function budgetReceiptLedger(): AtlasMaestroBudgetReceiptLedger
    {
        $path = (string) ($this->option('budget-receipt-path') ?? '');
        $resolved = $path !== '' ? $path : storage_path('atlas/maestro/budget_receipts.jsonl');

        return new AtlasMaestroBudgetReceiptLedger($resolved);
    }

    /**
     * @param  array<int|string,mixed>  $rows
     * @return list<array<string,mixed>>
     */
    private function canonicalRows(array $rows): array
    {
        $out = [];
        foreach (array_values($rows) as $row) {
            $out[] = is_array($row) ? $this->canonicalRow($row) : ['value' => $row];
        }

        return $out;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function canonicalRow(array $row): array
    {
        ksort($row);
        foreach ($row as &$v) {
            if (is_array($v)) {
                $v = $this->canonicalRow($v);
            }
        }
        unset($v);

        return $row;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function envelope(string $schema, string $status, array $payload, int $exit = self::SUCCESS): int
    {
        $envelope = ['payload' => $payload, 'schema' => $schema, 'status' => $status];
        ksort($envelope);
        $this->line((string) json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $exit;
    }
}
