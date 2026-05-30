<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use Illuminate\Console\Command;

/**
 * Pilar 1 · Plan Execution · decompose stage.
 *
 * Turns a build-plan markdown doc into a decomposed_plan.v1 via the orchestrator
 * (which composes BuildPlanDecomposerService). Read-only: no provider, no branch,
 * no git, no merge, no deploy, no secrets.
 */
class AtlasPlanExecutionDecomposeCommand extends Command
{
    protected $signature = 'atlas:plan-execution:decompose
        {--doc= : Path to the build-plan markdown document}
        {--scope-profile= : Slice planner scope profile (balanced|factory_max)}
        {--json : Emit JSON}';

    protected $description = 'Atlas Plan Execution (Pilar 1) · decompose a build-plan doc into an executable decomposed plan. No provider, no merge, no deploy, no secrets.';

    public function handle(PlanExecutionOrchestratorService $orchestrator): int
    {
        $doc = trim((string) $this->option('doc'));
        if ($doc === '') {
            $this->error('--doc=<path> is required.');

            return self::FAILURE;
        }

        $plan = $orchestrator->decompose([
            'doc_path' => $doc,
            'scope_profile' => (string) $this->option('scope-profile'),
        ]);

        $status = (string) ($plan['decomposition_status'] ?? '');

        if ((bool) $this->option('json')) {
            $this->line(json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            $this->components->twoColumnDetail('Plan Execution', 'decompose');
            $this->components->twoColumnDetail('Plan', (string) ($plan['plan_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', strtoupper($status !== '' ? $status : 'unknown'));
            $this->components->twoColumnDetail('Slices', (string) count((array) ($plan['slices'] ?? [])));
            foreach ((array) ($plan['blockers'] ?? []) as $blocker) {
                $this->line('  blocker: '.(string) $blocker);
            }
        }

        return $status === BuildPlanDecomposerService::STATUS_BLOCKED ? self::FAILURE : self::SUCCESS;
    }
}
