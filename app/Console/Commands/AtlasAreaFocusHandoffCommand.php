<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxHandoffService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusLoopOperationalOrchestratorService;
use Illuminate\Console\Command;

/**
 * Area Focus Loop · Branch Sandbox Preflight + Handoff CLI (AP-726).
 *
 * Atlas Software Company Stewardship Stack é stack/capability family dentro do
 * Atlas Autonomous Software Company Runtime, não OS novo. Runs the AP-722
 * orchestrator (read-only) to obtain work orders, then preflights the branch
 * sandbox plan + Dev/Forge handoff. Without operator accept receipts every
 * handoff is `awaiting_operator_approval`. It creates no branch, applies no fix,
 * dispatches nothing and never merges/deploys/touches secrets.
 */
class AtlasAreaFocusHandoffCommand extends Command
{
    protected $signature = 'atlas:software-company-stewardship:area-focus-handoff
        {--area=agentic_engineering_os : Canonical area_id}
        {--json : Emit JSON}';

    protected $description = 'Atlas Software Company Stewardship Stack · Area Focus branch sandbox preflight + governed Dev/Forge handoff (read-only; branch metadata only, no creation, no fix, no dispatch, gated by operator accept receipts).';

    public function handle(
        AreaFocusLoopOperationalOrchestratorService $orchestrator,
        AreaFocusBranchSandboxHandoffService $handoff,
    ): int {
        $area = (string) $this->option('area');

        $cycle = $orchestrator->run(['area_id' => $area]);
        $workOrders = $cycle['stages']['work_orders']['work_orders'] ?? [];

        $report = $handoff->preflight([
            'area_id' => $area,
            'work_orders' => is_array($workOrders) ? $workOrders : [],
            'operator_receipts' => [],
        ]);

        $this->emit($report, function (array $p): void {
            $this->components->twoColumnDetail('Area Focus Loop', 'branch sandbox preflight + handoff (read-only) · AP-726');
            $this->components->twoColumnDetail('Area', (string) ($p['area_id'] ?? '?'));
            $this->components->twoColumnDetail('Mode', (string) ($p['mode'] ?? '?'));
            $this->components->twoColumnDetail('Status', (string) ($p['status'] ?? 'unknown'));
            if (($p['status'] ?? '') === AreaFocusBranchSandboxHandoffService::STATUS_BLOCKED) {
                $this->warn('  blocked: '.(string) ($p['reason'] ?? '?').' · '.(string) ($p['detail'] ?? ''));

                return;
            }
            $this->components->twoColumnDetail('Gate decision', (string) ($p['gate_decision'] ?? '?'));
            foreach (($p['counts'] ?? []) as $st => $n) {
                if ((int) $n > 0) {
                    $this->components->twoColumnDetail('  '.$st, (string) $n);
                }
            }
            foreach ($p['handoffs'] ?? [] as $h) {
                $branch = $h['branch_plan']['proposed_branch_name'] ?? '(no branch plan)';
                $this->line(sprintf(
                    '  [%s] %s → %s · %s',
                    (string) ($h['target_owner'] ?? '?'),
                    (string) ($h['title'] ?? ''),
                    (string) ($h['handoff_status'] ?? '?'),
                    (string) $branch,
                ));
            }
        });

        return ($report['status'] ?? '') === AreaFocusBranchSandboxHandoffService::STATUS_BLOCKED
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function emit(array $payload, callable $human): void
    {
        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return;
        }
        $human($payload);
    }
}
