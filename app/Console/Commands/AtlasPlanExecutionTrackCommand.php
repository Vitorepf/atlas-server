<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Pilar 1 · Plan Execution · track stage.
 *
 * Read-only projection of the append-only JSONL completion ledger for a
 * plan/area via the orchestrator (which composes PlanCompletionTrackerService).
 * With no decomposed plan supplied, the rollup honestly reports zero slices
 * (status=blocked) — the correct real-or-blocked floor for a bare track query.
 * No provider, no branch, no git, no merge.
 */
class AtlasPlanExecutionTrackCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:plan-execution:track
        {--plan= : Decomposed plan_id}
        {--area=agentic_engineering_os : Canonical area_id}
        {--json : Emit JSON}';

    protected $description = 'Atlas Plan Execution (Pilar 1) · project the JSONL completion ledger for a plan/area. Read-only; no provider, no merge.';

    public function handle(PlanExecutionOrchestratorService $orchestrator): int
    {
        $planId = trim((string) $this->option('plan'));
        $areaId = trim((string) $this->option('area'));
        if ($planId === '') {
            $this->error('--plan=<id> is required.');

            return self::FAILURE;
        }

        $ledger = $orchestrator->track($planId, $areaId, ['plan_id' => $planId]);
        $status = (string) ($ledger['status'] ?? '');

        if ((bool) $this->option('json')) {
            $this->line($this->encode($ledger));
        } else {
            $this->components->twoColumnDetail('Plan Execution', 'track');
            $this->components->twoColumnDetail('Plan', (string) ($ledger['plan_id'] ?? $planId));
            $this->components->twoColumnDetail('Status', strtoupper($status !== '' ? $status : 'unknown'));
            $this->components->twoColumnDetail('Completion', (string) ($ledger['completion_pct'] ?? 0).'%');
            foreach ((array) ($ledger['blockers'] ?? []) as $blocker) {
                $this->line('  blocker: '.(string) $blocker);
            }
        }

        return $status === 'blocked' ? self::FAILURE : self::SUCCESS;
    }
}
