<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\YesNo;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use Illuminate\Console\Command;

/**
 * AOBG N3.F2 — `atlas:obra:run`: EXECUTE a planned obra onto ONE accumulating branch.
 *
 * THE INVERSION made operable. F1's `atlas:obra:plan` decomposed an intent into a
 * validated, brain-anchored plan-DAG (cost-free). THIS command walks that plan node
 * by node — each node's certified change applied onto the SAME branch
 * (atlas/obra/<plan_id>) in dependency order — and hands back ONE ready-to-merge
 * branch. Atlas stops at the branch; the OPERATOR reviews + merges.
 *
 *     atlas:obra:plan "add a Foo service; then wire it into Bar; then test it"   # plan (cheap)
 *     atlas:obra:run obra-<id>                                                    # execute (spends)
 *     atlas:obra:run obra-<id> --provider=codex_cli --json
 *     atlas:obra:run obra-<id> --discard                                          # drop the whole obra branch (reversible)
 *
 * NOTE: the default path makes REAL provider calls (one per node — operator spend).
 * Use it when you intend to spend; the resulting obra branch is yours to inspect and
 * merge or drop. A node that fails certification HALTS the obra (the partial branch is
 * kept for inspection, clearly marked not-certified — never silently merged).
 */
class AtlasObraRunCommand extends Command
{
    protected $signature = 'atlas:obra:run
        {plan : the persisted obra plan id (from atlas:obra:plan, e.g. obra-abc123)}
        {--provider= : provider key for the per-node generation step (defaults to the delivery default)}
        {--repo= : repo dir the obra branch is cut in (defaults to the app base path)}
        {--integrated-check= : AOBG N3.F3 — a whole-branch test/measure run ON THE ASSEMBLED obra worktree after all steps pass (e.g. "php artisan test --filter=Foo"). Certified=true ONLY if it passes; defaults to atlas.obra.integrated_check}
        {--no-brain : bypass per-node brain context for this run (the outcome write-back still records)}
        {--discard : discard the whole obra branch (atlas/obra/<plan>) — fully reversible, no execution}
        {--json : machine-readable output}';

    protected $description = 'AOBG N3.F2: execute a planned obra onto ONE accumulating branch (Atlas delivers, you merge). Spends per node; halts on a failed step.';

    public function handle(
        AtlasLiveCodeDeliveryService $delivery,
        GovernedBranchMaterializationService $materializer,
        AtlasRealityGraphIngestionService $brain,
    ): int {
        $planId = trim((string) $this->argument('plan'));
        $repoDir = $this->stringOption('repo');

        $executor = new AtlasObraExecutor(
            new ProviderObraNodeDelivery($delivery),
            $materializer,
            $brain,
        );

        // --discard: drop the whole obra branch, reversibly. No execution, no spend.
        if ((bool) $this->option('discard')) {
            $r = $executor->discardObra($repoDir ?? base_path(), $planId);
            if ((bool) $this->option('json')) {
                $this->line((string) json_encode($r, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return ($r['discarded'] ?? false) ? self::SUCCESS : self::FAILURE;
            }
            if (! ($r['discarded'] ?? false)) {
                $this->error('discard failed: '.($r['reason'] ?? '?').' (branch '.($r['branch'] ?? '?').')');

                return self::FAILURE;
            }
            $this->info('✓ discarded obra branch '.($r['branch'] ?? '?').' (reversible — main untouched).');

            return self::SUCCESS;
        }

        $opts = [];
        if ($repoDir !== null && $repoDir !== '') {
            $opts['repo_dir'] = $repoDir;
        }
        $provider = $this->stringOption('provider');
        if ($provider !== null && $provider !== '') {
            $opts['provider'] = $provider;
        }
        // AOBG N3.F3 — the whole-branch integrated certification check (explicit per-run
        // override of atlas.obra.integrated_check). When given, certified=true requires
        // it to pass on the ASSEMBLED branch (not just per step).
        $integratedCheck = $this->stringOption('integrated-check');
        if ($integratedCheck !== null && $integratedCheck !== '') {
            $opts['integrated_check'] = $integratedCheck;
        }
        if ((bool) $this->option('no-brain')) {
            $opts['no_brain'] = true;
        }

        $result = $executor->executePlanId($planId, $opts);

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return ($result['status'] ?? '') === AtlasObraExecutor::STATUS_DONE ? self::SUCCESS : self::FAILURE;
        }

        $status = (string) ($result['status'] ?? '?');
        $halted = $status === AtlasObraExecutor::STATUS_FAILED;
        $needsReview = $status === AtlasObraExecutor::STATUS_NEEDS_REVIEW;

        $this->info(sprintf(
            'atlas:obra:run  plan=%s  status=%s  steps=%d/%d  branch=%s  certified=%s',
            (string) ($result['plan_id'] ?? $planId),
            $status,
            (int) ($result['delivered_nodes'] ?? 0),
            (int) ($result['node_count'] ?? 0),
            (string) ($result['branch'] ?? '?'),
            $this->yesNo($result['certified'] ?? false),
        ));

        if ($halted) {
            $this->error('  obra HALTED — failed_node='.($result['failed_node'] ?? '?').' reason='.($result['reason'] ?? '?'));
            $this->comment('  The partial branch is kept for inspection (NOT certified as a whole). Discard with --discard.');

            return self::FAILURE;
        }

        if ($needsReview) {
            // AOBG N3.F3 — every step passed but the obra did NOT certify as a whole
            // (the integrated check failed / could not run). The branch is kept; it is
            // honestly NOT stamped green. The operator reviews before merging.
            $this->warn('  obra NEEDS REVIEW — all steps passed but the INTEGRATED check did not'
                .' (disposition='.($result['disposition'] ?? '?').' reason='.($result['reason'] ?? '?').').');
            $integrated = (array) ($result['integrated_test_result'] ?? []);
            $this->comment('  integrated_check: ran='.$this->yesNo($integrated['ran'] ?? false)
                .' passed='.$this->yesNo($integrated['passed'] ?? false)
                .' exit='.(string) ($integrated['exit_code'] ?? '?'));
            $this->comment('  The whole obra branch is kept (certified=false). Review it, fix, or discard with --discard.');
            $this->line('');
            $this->line('  Review the obra (NOT certified):');
            foreach ((array) ($result['review_commands'] ?? []) as $cmd) {
                $this->line('    '.$cmd);
            }

            return self::FAILURE;
        }

        $this->line('  main_untouched='.$this->yesNo($result['main_untouched'] ?? false)
            .'  never_merged='.$this->yesNo($result['never_merged'] ?? true)
            .'  brain_recorded='.$this->yesNo($result['brain_recorded'] ?? false)
            .'  disposition='.(string) ($result['disposition'] ?? '?'));
        $this->line('');
        $this->line('  Review + merge the whole obra (your sovereignty):');
        foreach ((array) ($result['review_commands'] ?? []) as $cmd) {
            $this->line('    '.$cmd);
        }

        return self::SUCCESS;
    }

    private function stringOption(string $key): ?string
    {
        $v = $this->option($key);

        return is_string($v) ? $v : null;
    }

    private function yesNo(mixed $v): string
    {
        return YesNo::format($v);
    }
}
