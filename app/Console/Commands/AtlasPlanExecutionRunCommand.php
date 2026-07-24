<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\DeterministicPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanDrivenLoopRunnerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanExecutionOrchestratorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceCycleExecutor;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Pilar 1 · Plan Execution · DRIVE a build-plan to completion.
 *
 * Decomposes the doc, then drives the plan one ready slice at a time through the loop's
 * owner flow (atlas_dev / forge), recording each real cycle and re-rolling completion until
 * the plan is delivered, blocks honestly, or a budget stops it.
 *
 * Honesty:
 *  - Default (real) mode uses the AP-786 owner flow. With no provider capacity / no governed
 *    authority the run blocks honestly (no fabricated merge).
 *  - --simulate uses a labelled SIMULATION executor to prove the wiring drives 0 -> N/N. The
 *    report is stamped simulated=true and NEVER claims real delivery.
 */
class AtlasPlanExecutionRunCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:plan-execution:run
        {--doc= : Path to the build-plan markdown document}
        {--area=agentic_engineering_os : Canonical area_id}
        {--scope-profile= : Slice planner scope profile (balanced|factory_max)}
        {--focus=dev_forge : Area focus slice}
        {--repo-root= : Git repository root (defaults to the app base path)}
        {--max-cycles= : Hard cap on cycles (default slices + 2)}
        {--provider= : Override the atlas_dev codegen provider (e.g. claude_cli, codex_cli, minimax_m27_cli). Defaults to config atlas_dev.provider.default_provider}
        {--model= : Override the provider model family (paired with --provider)}
        {--execute : Drive the REAL owner runtime (provider/sandbox/commit/merge). Without it the real path plans only (execute=false) — proves the chain with zero provider burn}
        {--simulate : Use the labelled simulation executor (proves wiring; never real)}
        {--json : Emit JSON}';

    protected $description = 'Atlas Plan Execution (Pilar 1) · drive a decomposed build-plan to honest completion via the loop owner flow. Real-or-blocked; --simulate proves wiring only.';

    public function handle(
        PlanExecutionOrchestratorService $orchestrator,
        PlanDrivenLoopRunnerService $runner,
    ): int {
        $doc = trim((string) $this->option('doc'));
        if ($doc === '') {
            $this->error('--doc=<path> is required.');

            return self::FAILURE;
        }

        $plan = $orchestrator->decompose([
            'doc_path' => $doc,
            'scope_profile' => (string) $this->option('scope-profile'),
        ]);

        if ((string) ($plan['decomposition_status'] ?? '') === 'blocked') {
            $this->renderBlockedDecomposition($plan);

            return self::FAILURE;
        }

        $simulate = (bool) $this->option('simulate');
        $execute = (bool) $this->option('execute');
        $executor = $this->resolveExecutor($simulate, $execute);
        if ($executor === null) {
            $this->error('Real owner-flow session unavailable; rerun with --simulate to prove wiring, or bind AutonomousEvolutionSessionService.');

            return self::FAILURE;
        }

        $repoRoot = trim((string) $this->option('repo-root'));
        $context = [
            'scope_profile' => (string) $this->option('scope-profile'),
            'focus' => (string) $this->option('focus'),
            // A non-executing real run is a zero-provider planning probe. It may prove the
            // owner-flow seam, but it must not mutate the real completion ledger or create
            // "stuck" history for slices that were never actually attempted.
            'record_plan_completion' => $simulate || $execute,
        ];
        if ($repoRoot !== '') {
            $context['repo_root'] = $repoRoot;
        }
        // Provider routing override: lets the operator drive the loop's codegen with a more
        // reliable provider (e.g. claude_cli / codex_cli) instead of the config default. The
        // OwnerFlowPlanSliceCycleExecutor passes context['provider']/['model'] straight to the
        // session; absent it falls back to atlas_dev.provider.default_provider. No fabrication.
        $provider = trim((string) $this->option('provider'));
        if ($provider !== '') {
            $context['provider'] = $provider;
            $model = trim((string) $this->option('model'));
            if ($model !== '') {
                $context['model'] = $model;
            }
        }

        $runInput = [
            'decomposed_plan' => $plan,
            'area_id' => (string) $this->option('area'),
            'executor' => $executor,
            'context' => $context,
        ];
        $maxCycles = $this->option('max-cycles');
        if ($maxCycles !== null && $maxCycles !== '') {
            $runInput['max_cycles'] = (int) $maxCycles;
        }

        $result = $runner->run($runInput);
        $status = (string) ($result['status'] ?? '');

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));
        } else {
            $this->components->twoColumnDetail('Plan Execution', $simulate ? 'run (SIMULATED)' : 'run (real owner flow)');
            $this->components->twoColumnDetail('Plan', (string) ($result['plan_id'] ?? '?'));
            $this->components->twoColumnDetail('Status', strtoupper($status !== '' ? $status : 'unknown'));
            $this->components->twoColumnDetail('Completion', ($result['delivered_count'] ?? 0).'/'.($result['total_slices'] ?? 0).' ('.($result['completion_pct'] ?? 0).'%)');
            $this->components->twoColumnDetail('Cycles run', (string) ($result['cycles_run'] ?? 0));
            $this->components->twoColumnDetail('Simulated', YesNo::format($result['simulated'] ?? false));
            foreach ((array) ($result['blockers'] ?? []) as $blocker) {
                $this->line('  blocker: '.(string) $blocker);
            }
        }

        return $status === PlanDrivenLoopRunnerService::STATUS_COMPLETE ? self::SUCCESS : self::FAILURE;
    }

    private function resolveExecutor(bool $simulate, bool $execute): ?PlanSliceCycleExecutor
    {
        if ($simulate) {
            return new DeterministicPlanSliceCycleExecutor;
        }

        try {
            $session = app(AutonomousEvolutionSessionService::class);
        } catch (\Throwable) {
            return null;
        }

        return new OwnerFlowPlanSliceCycleExecutor($session, $execute);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderBlockedDecomposition(array $plan): void
    {
        if ((bool) $this->option('json')) {
            $this->line($this->encode($plan));

            return;
        }
        $this->error('Decomposition blocked; cannot run.');
        foreach ((array) ($plan['blockers'] ?? []) as $blocker) {
            $this->line('  blocker: '.(string) $blocker);
        }
    }
}
