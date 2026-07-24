<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsRawStringOption;

use App\Support\YesNo;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Obra\AtlasObraExecutor;
use App\Services\Ai\Obra\AtlasObraPlanService;
use App\Services\Ai\Obra\AtlasObraService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ProviderObraDecomposer;
use App\Services\Ai\Obra\ProviderObraNodeDelivery;
use App\Services\Ai\RealExecution\AtlasLiveCodeDeliveryService;
use App\Services\Ai\RealExecution\GovernedBranchMaterializationService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AOBG N3.F4 — `atlas:obra:deliver`: ONE command commissions an obra.
 *
 * THE INVERSION made a single human gesture. Until now the operator hand-stepped
 * `atlas:obra:plan` then `atlas:obra:run`. F4 collapses that into ONE command: declare
 * an INTENT in natural language and Atlas DRIVES — decompose (F1) → execute the
 * plan-DAG onto ONE accumulating branch (F2) → certify the assembled branch as a whole
 * (F3) → record into the brain (compounding) — and hands back ONE ready-to-merge
 * branch. The UNIT OF WORK is the obra, not the edit. Atlas stops at the branch; the
 * OPERATOR reviews + merges.
 *
 *     atlas:obra:deliver "add a Foo service; then wire it into Bar; then test it"
 *     atlas:obra:deliver "..." --workspace=/path --provider=codex_cli --json
 *     atlas:obra:deliver "..." --integrated-check="php artisan test --filter=Foo"
 *
 * NOTE: the default path makes REAL provider calls (one per DAG node — operator spend).
 * Use it when you intend to spend; the resulting obra branch is yours to inspect and
 * merge or drop (atlas:obra:run obra-<id> --discard). A node that fails certification
 * HALTS the obra (the partial branch is kept, marked NOT-certified — never silently
 * merged). A per-step-green obra whose integrated check did not pass is honestly
 * delivered needs_review (certified=false). Reading status is the cost-free
 * atlas:obra:status command.
 */
class AtlasObraDeliverCommand extends Command
{
    use ReadsRawStringOption;

    use EmitsCanonicalJson;

    public const SCHEMA = 'atlas.obra.deliver_command.v1';

    protected $signature = 'atlas:obra:deliver
        {intent : the operator intent in natural language — Atlas decomposes + executes it as one obra}
        {--workspace= : workspace path or id to scope brain anchoring (defaults to the primary atlas-server)}
        {--provider= : provider key for the per-node generation step (defaults to the delivery default)}
        {--repo= : repo dir the obra branch is cut in (defaults to the app base path)}
        {--max= : override the max plan-DAG node cap (defaults to config atlas.obra.max_nodes)}
        {--integrated-check= : AOBG N3.F3 — a whole-branch test/measure run ON THE ASSEMBLED obra worktree after all steps pass (e.g. "php artisan test --filter=Foo"). Certified=true ONLY if it passes; defaults to atlas.obra.integrated_check}
        {--no-brain : bypass per-node brain context for this run (the outcome write-back still records)}
        {--json : machine-readable output}';

    protected $description = 'AOBG N3.F4: ONE command commissions an obra — decompose an intent, execute it onto ONE branch (Atlas delivers, you merge). Spends per node; halts on a failed step.';

    public function handle(
        CodeGraphWorkspaceIdentity $workspaceIdentity,
        AtlasOpenBrainContextPackService $contextPack,
        AtlasLiveCodeDeliveryService $delivery,
        GovernedBranchMaterializationService $materializer,
        AtlasRealityGraphIngestionService $brain,
    ): int {
        $intent = trim((string) ($this->argument('intent') ?? ''));
        if ($intent === '') {
            $this->error('intent is required');

            return self::INVALID;
        }

        // F1 planning (validated, brain-anchored, persisted plan-DAG). The provider
        // decomposer only runs when atlas.obra.decompose_provider is set (it degrades
        // honestly); the default deterministic decomposer is cost-free.
        $planService = new AtlasObraPlanService($this->resolveDecomposer(), $workspaceIdentity, $contextPack);

        // F2/F3 executor: the REAL per-node delivery (provider spend — the operator's
        // single command) onto ONE branch, whole-branch certification, brain write-back.
        $executor = new AtlasObraExecutor(
            new ProviderObraNodeDelivery($delivery),
            $materializer,
            $brain,
        );

        $service = new AtlasObraService($planService, $executor);

        $opts = [];
        foreach ([
            'workspace' => 'workspace',
            'provider' => 'provider',
            'integrated-check' => 'integrated_check',
        ] as $option => $key) {
            $value = $this->stringOption($option);
            if ($value !== null && $value !== '') {
                $opts[$key] = $value;
            }
        }
        $repoDir = $this->stringOption('repo');
        if ($repoDir !== null && $repoDir !== '') {
            $opts['repo_dir'] = $repoDir;
        }
        $max = $this->option('max');
        if (is_string($max) && is_numeric(trim($max))) {
            $opts['max_nodes'] = (int) max(1, (int) floor((float) trim($max)));
        }
        if ((bool) $this->option('no-brain')) {
            $opts['no_brain'] = true;
        }

        $obra = $service->commission($intent, $opts);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($obra));

            return $this->exitFor((string) ($obra['status'] ?? ''));
        }

        $this->render($obra);

        return $this->exitFor((string) ($obra['status'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $obra
     */
    private function render(array $obra): void
    {
        $status = (string) ($obra['status'] ?? '?');

        if ($status === AtlasObraService::STATUS_REFUSED) {
            $this->error('obra refused: '.((string) ($obra['reason'] ?? '?')));

            return;
        }

        $this->info(sprintf(
            'atlas:obra:deliver  obra=%s  status=%s  steps=%d/%d  branch=%s  certified=%s',
            (string) ($obra['obra_id'] ?? '?'),
            $status,
            (int) ($obra['delivered_nodes'] ?? 0),
            (int) ($obra['node_count'] ?? 0),
            (string) ($obra['branch'] ?? '?'),
            $this->yesNo($obra['certified'] ?? false),
        ));
        $this->line('  intent: '.(string) ($obra['intent'] ?? ''));
        $this->line('');

        // The plan-DAG with per-step status — the operator sees what Atlas built.
        $this->line('  plan-DAG (one branch, dependency order):');
        foreach ((array) ($obra['plan'] ?? []) as $node) {
            $deps = array_values((array) ($node['depends_on'] ?? []));
            $this->line(sprintf(
                '    [%d] %-10s %s  (%s)%s',
                (int) ($node['seq'] ?? 0),
                (string) ($node['status'] ?? '?'),
                (string) ($node['title'] ?? ''),
                (string) ($node['id'] ?? ''),
                $deps === [] ? '' : '  deps='.implode(',', $deps),
            ));
            if (! empty($node['reason'])) {
                $this->line('         reason: '.(string) $node['reason']);
            }
        }
        $this->line('');

        if ($status === AtlasObraExecutor::STATUS_FAILED) {
            $this->error('  obra HALTED — failed_node='.((string) ($obra['failed_node'] ?? '?'))
                .' reason='.((string) ($obra['reason'] ?? '?')));
            $this->comment('  The partial branch is kept for inspection (NOT certified as a whole). Discard with: atlas:obra:run '
                .((string) ($obra['obra_id'] ?? '<id>')).' --discard');

            return;
        }

        if ($status === AtlasObraExecutor::STATUS_NEEDS_REVIEW) {
            $integrated = (array) ($obra['integrated_test_result'] ?? []);
            $this->warn('  obra NEEDS REVIEW — all steps passed but the INTEGRATED check did not'
                .' (disposition='.((string) ($obra['disposition'] ?? '?')).').');
            $this->comment('  integrated_check: ran='.$this->yesNo($integrated['ran'] ?? false)
                .' passed='.$this->yesNo($integrated['passed'] ?? false)
                .' exit='.(string) ($integrated['exit_code'] ?? '?'));
        }

        $this->line('  main_untouched='.$this->yesNo($obra['main_untouched'] ?? false)
            .'  never_merged='.$this->yesNo($obra['never_merged'] ?? true)
            .'  brain_recorded='.$this->yesNo($obra['brain_recorded'] ?? false)
            .'  disposition='.(string) ($obra['disposition'] ?? '?'));
        $this->line('');
        $this->line('  Review + merge the whole obra (your sovereignty):');
        foreach ((array) ($obra['review_commands'] ?? []) as $cmd) {
            $this->line('    '.$cmd);
        }
        $this->line('');
        $this->comment('  Inspect anytime: atlas:obra:status --obra='.((string) ($obra['obra_id'] ?? '<id>')));
    }

    /**
     * done → SUCCESS; everything else (refused / failed / needs_review) → FAILURE so a
     * caller/script knows the obra is NOT a clean merge-ready pass.
     */
    private function exitFor(string $status): int
    {
        return $status === AtlasObraExecutor::STATUS_DONE ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Pick the decomposer: the provider-backed one when a decompose provider is
     * configured (the real path; itself degrades honestly), else the deterministic,
     * cost-free one. Mirrors atlas:obra:plan.
     */
    private function resolveDecomposer(): ObraDecomposer
    {
        $provider = trim((string) config('atlas.obra.decompose_provider', ''));
        if ($provider !== '') {
            return app(ProviderObraDecomposer::class);
        }

        return app(DeterministicObraDecomposer::class);
    }


    private function yesNo(mixed $v): string
    {
        return YesNo::format($v);
    }
}
