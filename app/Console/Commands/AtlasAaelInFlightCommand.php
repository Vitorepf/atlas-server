<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightDriftAuditor;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightReceiptLedger;
use App\Services\Ai\AutonomousEvolution\Aael\Execution\InFlight\AtlasAaelInFlightStepValidator;
use Illuminate\Console\Command;

/**
 * Operator-facing FACT surface over the AAEL in-flight subsystem.
 *   atlas:aael:inflight validate <run_id> <step_index> [--json]
 *   atlas:aael:inflight drift    <run_id> [--json]
 *   atlas:aael:inflight history  <run_id> [--json]
 *
 * Surfaces FACTs only — never a score/quality/grade summary.
 */
final class AtlasAaelInFlightCommand extends Command
{
    protected $signature = 'atlas:aael:inflight {action : validate|drift|history}
        {run_id}
        {step_index? : required for validate}
        {--json}';

    protected $description = 'AAEL in-flight FACT surface (validate | drift | history).';

    public function handle(
        AtlasAaelInFlightReceiptLedger $ledger,
        AtlasAaelInFlightStepValidator $validator,
        AtlasAaelInFlightDriftAuditor $auditor,
    ): int {
        $action = (string) $this->argument('action');
        $runId = (string) $this->argument('run_id');

        return match ($action) {
            'validate' => $this->validate($ledger, $validator, $runId),
            'drift' => $this->drift($ledger, $auditor, $runId),
            'history' => $this->history($ledger, $runId),
            default => $this->failWith('unknown_action:'.$action),
        };
    }

    private function validate(AtlasAaelInFlightReceiptLedger $ledger, AtlasAaelInFlightStepValidator $validator, string $runId): int
    {
        $stepIndex = $this->argument('step_index');
        if ($stepIndex === null) {
            return $this->failWith('missing_step_index');
        }
        $stepIndex = (int) $stepIndex;

        $rows = $ledger->forRun($runId);
        $matches = array_values(array_filter($rows, static fn (array $r): bool =>
            (string) ($r['kind'] ?? '') === AtlasAaelInFlightReceiptLedger::KIND_VALIDATION
            && (int) ($r['step_index'] ?? -1) === $stepIndex));

        if ($matches === []) {
            // Fall through: invoke the validator with empty inputs so the FACT shape is still surfaced.
            $fact = $validator->validate([], [], $stepIndex);
        } else {
            $latest = end($matches);
            $fact = (array) ($latest['fact'] ?? []);
        }

        $this->emit($fact);

        return 0;
    }

    private function drift(AtlasAaelInFlightReceiptLedger $ledger, AtlasAaelInFlightDriftAuditor $auditor, string $runId): int
    {
        $rows = $ledger->forRun($runId);
        $validationRecords = [];
        foreach ($rows as $row) {
            if ((string) ($row['kind'] ?? '') !== AtlasAaelInFlightReceiptLedger::KIND_VALIDATION) {
                continue;
            }
            $fact = (array) ($row['fact'] ?? []);
            $facts = (array) ($fact['facts'] ?? []);
            $validationRecords[] = ['facts' => $facts];
        }

        $driftFact = $auditor->audit($validationRecords);
        $this->emit($driftFact);

        return 0;
    }

    private function history(AtlasAaelInFlightReceiptLedger $ledger, string $runId): int
    {
        $rows = $ledger->forRun($runId);
        $this->emit($rows);

        return 0;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->getOutput()->writeln((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

            return;
        }
        $this->emitHuman($payload);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emitHuman(array $payload, string $prefix = ''): void
    {
        if ($this->isList($payload)) {
            foreach ($payload as $i => $value) {
                if (is_array($value)) {
                    $this->getOutput()->writeln($prefix.'['.$i.']');
                    $this->emitHuman($value, $prefix.'  ');
                } else {
                    $this->getOutput()->writeln($prefix.'['.$i.'] = '.$this->stringify($value));
                }
            }

            return;
        }
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $this->getOutput()->writeln($prefix.$key.':');
                $this->emitHuman($value, $prefix.'  ');
            } else {
                $this->getOutput()->writeln($prefix.$key.' = '.$this->stringify($value));
            }
        }
    }

    private function stringify(mixed $v): string
    {
        if ($v === null) {
            return 'null';
        }
        if (is_bool($v)) {
            return $v ? 'true' : 'false';
        }

        return is_scalar($v) ? (string) $v : (string) json_encode($v, JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function isList(array $payload): bool
    {
        return $payload === [] || array_keys($payload) === range(0, count($payload) - 1);
    }

    private function failWith(string $reason): int
    {
        $this->getOutput()->writeln($reason);

        return 2;
    }
}
