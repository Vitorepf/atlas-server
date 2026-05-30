<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDeliveryCertificationService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use Illuminate\Console\Command;

/**
 * Pilar 1 · Plan Execution · certify stage.
 *
 * Renders the PLAN-scope delivery verdict via the orchestrator (which composes
 * PlanDeliveryCertificationService: operational pre-gate + tracker rollup +
 * AP-786 real-cycle certification). Real-or-blocked; never fabricates
 * completion. No provider, no branch, no git, no merge.
 */
class AtlasPlanExecutionCertifyCommand extends Command
{
    protected $signature = 'atlas:plan-execution:certify
        {--plan= : Decomposed plan_id}
        {--area=agentic_engineering_os : Canonical area_id}
        {--session=* : AP-786 session_id(s) for real-cycle certification}
        {--integration-green : Mark the integration check green}
        {--json : Emit JSON}';

    protected $description = 'Atlas Plan Execution (Pilar 1) · certify PLAN-scope delivery (operational gate + tracker + real-cycle cert). No provider, no merge.';

    public function handle(PlanExecutionOrchestratorService $orchestrator): int
    {
        $planId = trim((string) $this->option('plan'));
        $areaId = trim((string) $this->option('area'));
        if ($planId === '') {
            $this->error('--plan=<id> is required.');

            return self::FAILURE;
        }

        $cert = $orchestrator->certify([
            'decomposed_plan' => ['plan_id' => $planId],
            'area_id' => $areaId,
            'session_ids' => $this->sessionIds(),
            'integration_check' => ['green' => (bool) $this->option('integration-green')],
        ]);

        $status = (string) ($cert['status'] ?? '');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($cert, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Plan Execution', 'certify');
            $this->components->twoColumnDetail('Plan', (string) ($cert['plan_id'] ?? $planId));
            $this->components->twoColumnDetail('Status', strtoupper($status !== '' ? $status : 'unknown'));
            $this->components->twoColumnDetail('Delivered', (string) ($cert['delivered_slices'] ?? 0).'/'.(string) ($cert['total_slices'] ?? 0));
            foreach ((array) ($cert['blockers'] ?? []) as $blocker) {
                $this->line('  blocker: '.(string) $blocker);
            }
        }

        return $status === PlanDeliveryCertificationService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function sessionIds(): array
    {
        return array_values(array_filter(array_map(
            static fn ($v): string => is_string($v) ? trim($v) : '',
            (array) $this->option('session'),
        ), static fn (string $v): bool => $v !== ''));
    }
}
