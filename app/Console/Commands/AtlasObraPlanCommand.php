<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Obra\AtlasObraPlanService;
use App\Services\Ai\Obra\DeterministicObraDecomposer;
use App\Services\Ai\Obra\ObraDecomposer;
use App\Services\Ai\Obra\ProviderObraDecomposer;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * AOBG N3.F1 — `atlas:obra:plan`: decompose an INTENT into a plan-DAG (PLAN ONLY).
 *
 * THE INVERSION made operable + cheap: the operator declares an intent in natural
 * language and Atlas shows the OBRA it would build — the validated, brain-anchored
 * plan-DAG of steps in dependency order — WITHOUT executing anything. No git, no
 * branch, no merge, no provider spend (the default deterministic decomposer spends
 * nothing; a provider decomposer only runs if `atlas.obra.decompose_provider` is set,
 * and even then degrades honestly). The persisted plan is what the (operator's
 * single) obra-run command later walks node by node onto ONE accumulating branch.
 *
 *     atlas:obra:plan "add a Foo service; then wire it into Bar; then test it"
 *     atlas:obra:plan "..." --workspace=/path/to/project --max=8 --json
 *
 * Plan only — cheap. Execution (which spends, branch-only, never main) is a separate
 * operator command.
 */
class AtlasObraPlanCommand extends Command
{
    public const SCHEMA = 'atlas.obra.plan_command.v1';

    protected $signature = 'atlas:obra:plan
        {intent : The operator intent in natural language to decompose into an obra}
        {--workspace= : Workspace path or id to scope brain anchoring (defaults to the primary atlas-server)}
        {--max= : Override the max plan-DAG node cap (defaults to config atlas.obra.max_nodes)}
        {--json : Output the plan-DAG as JSON instead of a rendered tree}';

    protected $description = 'AOBG N3.F1: decompose an intent into a validated, brain-anchored plan-DAG (PLAN ONLY — no execution, no branch, cost-free by default).';

    public function handle(
        CodeGraphWorkspaceIdentity $workspaceIdentity,
        AtlasOpenBrainContextPackService $brain,
    ): int {
        $intent = trim((string) ($this->argument('intent') ?? ''));
        if ($intent === '') {
            $this->error('intent is required');

            return self::INVALID;
        }

        $opts = [];
        $workspace = $this->option('workspace');
        if (is_string($workspace) && trim($workspace) !== '') {
            $opts['workspace'] = trim($workspace);
        }
        $max = $this->option('max');
        if (is_string($max) && is_numeric(trim($max))) {
            $opts['max_nodes'] = (int) max(1, (int) floor((float) trim($max)));
        }

        $service = new AtlasObraPlanService($this->resolveDecomposer(), $workspaceIdentity, $brain);

        try {
            $plan = $service->decompose($intent, $opts);
        } catch (InvalidArgumentException $e) {
            // A refused plan (cycle / dangling dep / over-cap / empty) is an honest,
            // explained failure — never a fabricated partial plan.
            $this->error('obra plan refused: '.$e->getMessage());

            return self::FAILURE;
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->renderTree($plan);

        return self::SUCCESS;
    }

    /**
     * Pick the decomposer: the provider-backed one when a decompose provider is
     * configured (the real path; itself degrades honestly), else the deterministic,
     * cost-free one. Both honour `atlas.obra.max_nodes`.
     */
    private function resolveDecomposer(): ObraDecomposer
    {
        $provider = trim((string) config('atlas.obra.decompose_provider', ''));
        if ($provider !== '') {
            return app(ProviderObraDecomposer::class);
        }

        return app(DeterministicObraDecomposer::class);
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderTree(array $plan): void
    {
        $this->info(sprintf(
            'atlas:obra:plan  plan_id=%s  workspace=%s  decomposer=%s  status=%s  nodes=%d',
            (string) ($plan['plan_id'] ?? ''),
            (string) ($plan['workspace_id'] ?? ''),
            (string) ($plan['decomposer'] ?? ''),
            (string) ($plan['status'] ?? ''),
            (int) ($plan['node_count'] ?? 0),
        ));
        $this->line('');

        foreach ((array) ($plan['nodes'] ?? []) as $node) {
            $deps = array_values((array) ($node['depends_on'] ?? []));
            $depLabel = $deps === [] ? 'roots' : implode(', ', $deps);
            $refs = (array) ($node['brain_refs'] ?? []);
            $sources = array_values((array) ($refs['sources_present'] ?? []));

            $this->line(sprintf(
                '  [%d] %s  (%s)',
                (int) ($node['seq'] ?? 0),
                (string) ($node['title'] ?? ''),
                (string) ($node['id'] ?? ''),
            ));
            $this->line('      request: '.(string) ($node['request'] ?? ''));
            if (! empty($node['target_area'])) {
                $this->line('      target:  '.(string) $node['target_area']);
            }
            $this->line('      depends: '.$depLabel);
            $this->line('      brain:   '.($sources === [] ? 'no anchor' : 'src='.implode(',', $sources)));
            $this->line('');
        }

        $this->comment('Plan only — no execution, no branch. The obra-run command walks these nodes onto ONE branch (never main).');
    }
}
