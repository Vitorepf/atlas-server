<?php

namespace App\Services\Ai\Programming\Governance;

use App\Models\AtlasProgrammingGateRun;
use App\Models\AtlasProgrammingWorkItem;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingGateContract;
use App\Services\Ai\Programming\Governance\Gates\ProgrammingGateOutcome;
use App\Services\Ai\Support\DatabaseTableAvailability;

/**
 * Runs the gate pipeline for a work item, persisting each evaluation as an
 * AtlasProgrammingGateRun row.
 *
 * Gates are registered via the service container with the tag
 * `programming.governance.gate` (wired in AtlasProgrammingGovernanceServiceProvider).
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
class ProgrammingGateRunner
{
    /** @var array<string,ProgrammingGateContract> */
    private array $gatesByName;

    /**
     * @param  iterable<ProgrammingGateContract>  $gates
     */
    public function __construct(iterable $gates)
    {
        $byName = [];
        foreach ($gates as $gate) {
            $byName[$gate->name()] = $gate;
        }
        $this->gatesByName = $byName;
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string,mixed>
     */
    public function run(AtlasProgrammingWorkItem $workItem, ?array $only = null): array
    {
        $mode = ProgrammingScopeMode::from($workItem->scope_mode);
        $names = $only ?? $mode->requiredGates();

        $records = [];
        $blockingFailures = [];
        $passed = 0;
        $failed = 0;
        $skipped = 0;
        $waived = 0;
        $missing = [];

        foreach ($names as $name) {
            $gate = $this->gatesByName[$name] ?? null;
            $isBlocking = $mode->isBlocking($name);

            if ($gate === null) {
                $missing[] = $name;
                // A missing required gate is a configuration failure: we
                // cannot prove the contract was honoured, so it must NOT
                // count as a green skip when the gate is blocking.
                if ($isBlocking) {
                    $outcome = ProgrammingGateOutcome::failed(
                        "gate_not_registered:{$name}",
                        ['gate_name' => $name],
                        blocking: true,
                    );
                } else {
                    $outcome = ProgrammingGateOutcome::skipped(
                        "gate_not_registered:{$name}",
                        ['gate_name' => $name],
                        blocking: false,
                    );
                }
            } else {
                try {
                    $outcome = $gate->evaluate($workItem);
                } catch (Throwable $e) {
                    $outcome = ProgrammingGateOutcome::failed(
                        'gate_threw_exception:'.$e::class,
                        ['exception_message' => $e->getMessage()],
                        $isBlocking,
                    );
                }
            }

            $blocking = $isBlocking && $outcome->blocking;
            $record = $this->persist($workItem, $name, $outcome, $blocking);

            $records[] = $record;
            match ($outcome->status) {
                'passed' => $passed++,
                'failed' => $failed++,
                'skipped' => $skipped++,
                'waived' => $waived++,
                default => null,
            };
            if ($outcome->status === 'failed' && $blocking) {
                $blockingFailures[] = $name;
            }
        }

        $blockingMissing = array_values(array_filter(
            $missing,
            static fn (string $name): bool => $mode->isBlocking($name),
        ));

        return [
            'schema_version' => 'atlas.programming.gate_summary.v1',
            'work_item_id' => $workItem->id,
            'scope_mode' => $workItem->scope_mode,
            'gates_evaluated' => array_values($names),
            'gates_missing' => $missing,
            'gates_missing_blocking' => $blockingMissing,
            'totals' => [
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => $skipped,
                'waived' => $waived,
            ],
            'blocking_failures' => $blockingFailures,
            'all_green' => $blockingFailures === [] && $failed === 0 && $blockingMissing === [],
            'gate_runs' => $records,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function persist(
        AtlasProgrammingWorkItem $workItem,
        string $gateName,
        ProgrammingGateOutcome $outcome,
        bool $blocking,
    ): array {
        $payload = [
            'schema_version' => 'atlas.programming.gate_run.v1',
            'work_item_id' => $workItem->id,
            'gate_name' => $gateName,
            'status' => $outcome->status,
            'blocking' => $blocking,
            'reason' => $outcome->reason,
            'waiver_reason' => $outcome->waiverReason,
            'payload' => $outcome->payload,
            'evaluated_at' => now()->toJSON(),
        ];

        if (! $this->storageAvailable()) {
            return array_merge($payload, ['storage' => ['persisted' => false, 'reason' => 'atlas_programming_gate_runs_table_missing']]);
        }

        $row = AtlasProgrammingGateRun::query()->create([
            'work_item_id' => $workItem->id,
            'gate_name' => $gateName,
            'status' => $outcome->status,
            'blocking' => $blocking,
            'input_hash' => hash('sha256', $workItem->id.'|'.$gateName.'|'.($workItem->spec_hash ?? '').'|'.($workItem->plan_hash ?? '')),
            'output_hash' => hash('sha256', json_encode($outcome->toArray(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: ''),
            'reason' => $outcome->reason,
            'waiver_reason' => $outcome->waiverReason,
            'decided_by' => null,
            'decided_at' => now(),
            'payload_json' => $outcome->payload,
        ]);

        return array_merge($payload, ['id' => $row->id, 'storage' => ['persisted' => true, 'table' => 'atlas_programming_gate_runs']]);
    }

    private function storageAvailable(): bool
    {
        return DatabaseTableAvailability::has('atlas_programming_gate_runs');
    }
}
