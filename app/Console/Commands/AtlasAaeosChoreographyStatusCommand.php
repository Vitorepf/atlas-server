<?php

namespace App\Console\Commands;

use App\Services\Ai\Aaeos\AtlasCrossDepartmentChoreographyService;
use Illuminate\Console\Command;

/**
 * Runtime surface for the AAEOS Cross-Department Choreography state machine —
 * the command the canonical doc names (atlas:aaeos:choreography-status). Without
 * args it prints the handoff kinds + veto rules; with --veto / --repair-iteration
 * it evaluates a real transition.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-cross-department-choreography.md
 */
class AtlasAaeosChoreographyStatusCommand extends Command
{
    protected $signature = 'atlas:aaeos:choreography-status
        {--veto= : Evaluate a veto raised by a department (security|architect|review|operator)}
        {--repair-iteration= : Evaluate the repair-loop decision at this iteration}
        {--json : Print machine-readable JSON}';

    protected $description = 'Inspect and evaluate the AAEOS cross-department choreography (veto/repair/handoff) runtime.';

    public function handle(AtlasCrossDepartmentChoreographyService $choreography): int
    {
        $veto = $this->option('veto');
        $repair = $this->option('repair-iteration');

        if (is_string($veto) && trim($veto) !== '') {
            return $this->emit($choreography->evaluateVeto($veto));
        }
        if (is_numeric($repair)) {
            return $this->emit($choreography->evaluateRepairLoop((int) $repair));
        }

        $payload = [
            'schema_version' => AtlasCrossDepartmentChoreographyService::HANDOFF_SCHEMA,
            'handoff_kinds' => AtlasCrossDepartmentChoreographyService::HANDOFF_KINDS,
            'veto_sla_seconds' => AtlasCrossDepartmentChoreographyService::VETO_SLA_SECONDS,
            'repair_max_iterations' => AtlasCrossDepartmentChoreographyService::REPAIR_MAX_ITERATIONS,
            'veto_examples' => [
                'security' => $choreography->evaluateVeto('security'),
                'operator' => $choreography->evaluateVeto('operator'),
            ],
        ];

        return $this->emit($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload): int
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_scalar($value) ? (string) $value : json_encode($value));
        }

        return self::SUCCESS;
    }
}
