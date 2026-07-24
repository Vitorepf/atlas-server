<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Concerns\EmitsCanonicalJson;
use App\Models\AiJob;
use App\Services\Ai\AgenticWorkcell\Contracts\WorkcellAdapter;
use App\Services\Ai\Hermes\Mesh\HermesMeshProcessWorkerFactory;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use App\Support\YesNo;

/**
 * Operator + automation surface for the Atlas Executive Mesh — the governed
 * many-agent Hermes fleet.
 *
 * Default-safe: `status` and `plan` are READ-ONLY (plan composes + seals a
 * dispatch plan but launches nothing). `dispatch` actually fans out a fleet of
 * real `hermes` worktree processes and is FAIL-CLOSED twice over: it refuses
 * unless providers.hermes_cli.workcell.policy === 'atlas_adapter' AND the operator
 * passes --confirm. Raw subtask objectives are held only transiently to build
 * the child commands; every emitted plan/receipt carries objective hashes only.
 * Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-hermes-executive-mesh.md
 */
class AtlasHermesMeshCommand extends Command
{
    use EmitsCanonicalJson;

    /** Compat alias: the retired command name `atlas:hermes:mesh` still resolves. */
    protected $aliases = ['atlas:hermes:mesh'];

    protected $signature = 'atlas:hermes:workcell
        {action=status : status|plan|dispatch}
        {--file= : JSON file: {"permission_mode":"write","subtasks":[{"objective":"...","role":"coder","toolsets":["file"],"worktree":true}]}}
        {--json : Emit JSON}
        {--dry-run : Build + print the exact per-child hermes argv WITHOUT launching}
        {--confirm : Required to actually dispatch the live fleet}';

    protected $description = 'Atlas Workcell Adapter surface (Hermes runtime): status (config + readiness), plan (compose + seal a governed many-agent plan, read-only), dispatch (fan out a real hermes worktree fleet — requires workcell.policy=atlas_adapter AND --confirm).';

    public function handle(WorkcellAdapter $service, HermesMeshProcessWorkerFactory $factory): int
    {
        return match ($this->action()) {
            'plan' => $this->handlePlan($service),
            'dispatch' => $this->handleDispatch($service, $factory),
            default => $this->handleStatus(),
        };
    }

    private function handleStatus(): int
    {
        $policy = (string) config('atlas.ai.providers.hermes_cli.workcell.policy', 'off');
        $payload = [
            'action' => 'status',
            'authority' => 'atlas',
            'hermes_mesh_can_decide' => false,
            'mesh_policy' => $policy,
            'dispatch_enabled' => $policy === 'atlas_adapter',
            'max_parallel_workers' => (int) config('atlas.ai.providers.hermes_cli.workcell.max_parallel_workers', 8),
            'max_children' => (int) config('atlas.ai.providers.hermes_cli.workcell.max_children', 64),
            'checkpoint_policy' => (string) config('atlas.ai.providers.hermes_cli.workcell.checkpoint_policy', 'off'),
            'worktree_fleet' => (bool) config('atlas.ai.providers.hermes_cli.workcell.worktree_fleet', true),
            'delegation_concurrency_ceiling' => (int) config('atlas.ai.providers.hermes_cli.delegation_max_concurrent_children', 3),
        ];

        if ($this->wantsJson()) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        foreach ($payload as $key => $value) {
            $this->components->twoColumnDetail((string) $key, is_bool($value) ? (YesNo::trueFalse($value)) : (string) $value);
        }

        return self::SUCCESS;
    }

    private function handlePlan(WorkcellAdapter $service): int
    {
        [$mission, $subtasks, $error] = $this->load();
        if ($error !== null) {
            return $this->failWith($error);
        }

        $plan = $service->plan($this->transientJob(), $mission, $subtasks, $this->policy());

        if ($this->wantsJson()) {
            $this->jsonLine(['action' => 'plan', 'plan' => $plan]);

            return self::SUCCESS;
        }

        $this->renderPlan('plan', $plan);

        return self::SUCCESS;
    }

    private function handleDispatch(WorkcellAdapter $service, HermesMeshProcessWorkerFactory $factory): int
    {
        [$mission, $subtasks, $error] = $this->load();
        if ($error !== null) {
            return $this->failWith($error);
        }

        $objectivesByIndex = [];
        foreach (array_values($subtasks) as $i => $subtask) {
            $objectivesByIndex[$i] = (string) ($subtask['objective'] ?? '');
        }

        // Dry-run: prove the live dispatch path constructs the real argv, with the
        // raw objective masked to a hash so even the preview stays redaction-clean.
        // Safe (launches nothing), so it bypasses the --confirm gate.
        if ((bool) $this->option('dry-run')) {
            return $this->renderDryRun($service, $factory, $mission, $subtasks, $objectivesByIndex);
        }

        if (config('atlas.ai.providers.hermes_cli.workcell.policy') !== 'atlas_adapter') {
            return $this->failWith('dispatch refused: providers.hermes_cli.workcell.policy is not atlas_adapter (default-safe). Set ATLAS_AI_HERMES_WORKCELL_POLICY=atlas_adapter to enable.');
        }

        if (! (bool) $this->option('confirm')) {
            return $this->failWith('dispatch refused: pass --confirm to launch a live hermes fleet.');
        }

        $worker = $factory->workerFor($objectivesByIndex);
        $result = $service->run($this->transientJob(), $mission, $subtasks, $worker, $this->policy());

        if ($this->wantsJson()) {
            $this->jsonLine([
                'action' => 'dispatch',
                'run' => $result['run'],
                'reconciliation' => $result['reconciliation'],
            ]);

            return self::SUCCESS;
        }

        $this->renderPlan('dispatch', $result['plan']);
        $this->newLine();
        $this->components->twoColumnDetail('dispatched', (string) ($result['run']['dispatched_count'] ?? 0));
        $this->components->twoColumnDetail('aggregate status', (string) ($result['reconciliation']['aggregate_status'] ?? 'empty'));
        $this->components->twoColumnDetail('reconciliation allowed', YesNo::format($result['reconciliation']['reconciliation_allowed_now'] ?? false));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $mission
     * @param  array<int,array<string,mixed>>  $subtasks
     * @param  array<int,string>  $objectivesByIndex
     */
    private function renderDryRun(WorkcellAdapter $service, HermesMeshProcessWorkerFactory $factory, array $mission, array $subtasks, array $objectivesByIndex): int
    {
        // Force-enable the plan for the preview so the operator sees the real
        // fleet that WOULD launch (dispatch itself still requires policy+confirm).
        $plan = $service->plan($this->transientJob(), $mission, $subtasks, ['enabled' => true]);

        $commands = [];
        foreach (($plan['children'] ?? []) as $i => $child) {
            $idx = (int) ($child['index'] ?? $i);
            $argv = $factory->previewArgs($child, (string) ($objectivesByIndex[$idx] ?? ''));
            $commands[] = [
                'child_index' => $idx,
                'role' => $child['role'] ?? '',
                'argv' => $this->maskObjective($argv),
            ];
        }

        $payload = [
            'action' => 'dry_run',
            'launched' => false,
            'live_dispatch_would_be_allowed' => config('atlas.ai.providers.hermes_cli.workcell.policy') === 'atlas_adapter',
            'max_parallel_workers' => $plan['max_parallel_workers'] ?? 0,
            'commands' => $commands,
        ];

        if ($this->wantsJson()) {
            $this->jsonLine($payload);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('action', 'dry_run (nothing launched)');
        $this->components->twoColumnDetail('children', (string) count($commands));
        foreach ($commands as $c) {
            $this->line('  ['.$c['child_index'].'] '.$c['role'].': '.implode(' ', $c['argv']));
        }

        return self::SUCCESS;
    }

    /**
     * Mask the raw objective (the element after -z) to a hash so a dry-run
     * preview never prints raw task text.
     *
     * @param  array<int,string>  $argv
     * @return array<int,string>
     */
    private function maskObjective(array $argv): array
    {
        $zIndex = array_search('-z', $argv, true);
        if ($zIndex !== false && isset($argv[$zIndex + 1])) {
            $raw = (string) $argv[$zIndex + 1];
            $argv[$zIndex + 1] = 'objective:masked(len='.strlen($raw).',sha256='.substr(hash('sha256', $raw), 0, 12).')';
        }

        return $argv;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderPlan(string $action, array $plan): void
    {
        $this->components->twoColumnDetail('action', $action);
        $this->components->twoColumnDetail('mesh enabled', YesNo::format($plan['mesh_enabled'] ?? false));
        $this->components->twoColumnDetail('dispatch allowed now', YesNo::format($plan['dispatch_allowed_now'] ?? false));
        $this->components->twoColumnDetail('blocked reason', (string) ($plan['blocked_reason'] ?? '—'));
        $this->components->twoColumnDetail('child count', (string) ($plan['child_count'] ?? 0));
        $this->components->twoColumnDetail('max parallel workers', (string) ($plan['max_parallel_workers'] ?? 0));

        $children = is_array($plan['children'] ?? null) ? $plan['children'] : [];
        if ($children === []) {
            return;
        }

        $this->table(
            ['#', 'role', 'worktree', 'checkpoint', 'toolsets'],
            collect($children)->map(static fn (array $c): array => [
                (string) ($c['index'] ?? ''),
                Str::limit((string) ($c['role'] ?? ''), 20),
                YesNo::format($c['assigned_worktree'] ?? false),
                YesNo::format($c['checkpoint_before'] ?? false),
                Str::limit(implode(',', (array) data_get($c, 'profile.toolsets', [])), 30),
            ])->all(),
        );
    }

    /**
     * @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>,2:?string}
     */
    private function load(): array
    {
        $file = $this->option('file');
        if (! is_string($file) || trim($file) === '') {
            return [[], [], 'missing --file=<path to subtasks json>'];
        }
        if (! is_file($file)) {
            return [[], [], "file not found: {$file}"];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            return [[], [], 'file is not valid JSON object'];
        }

        $subtasks = $decoded['subtasks'] ?? null;
        if (! is_array($subtasks) || $subtasks === []) {
            return [[], [], 'json must contain a non-empty "subtasks" array'];
        }

        $mode = is_string($decoded['permission_mode'] ?? null) ? (string) $decoded['permission_mode'] : 'read';
        $mission = [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => 'mesh-cli-'.substr(hash('sha256', (string) json_encode($subtasks)), 0, 16),
            'scope' => ['permission_mode' => $mode],
        ];

        return [$mission, array_values($subtasks), null];
    }

    /**
     * @return array<string,mixed>
     */
    private function policy(): array
    {
        return ['enabled' => config('atlas.ai.providers.hermes_cli.workcell.policy') === 'atlas_adapter'];
    }

    private function transientJob(): AiJob
    {
        return new AiJob([
            'trace_id' => 'mesh-cli-'.substr(hash('sha256', (string) microtime(false)), 0, 12),
            'payload' => ['hermes' => []],
        ]);
    }

    private function action(): string
    {
        $action = strtolower(trim((string) $this->argument('action')));

        return in_array($action, ['status', 'plan', 'dispatch'], true) ? $action : 'status';
    }

    private function wantsJson(): bool
    {
        return (bool) $this->option('json');
    }

    private function failWith(string $message): int
    {
        if ($this->wantsJson()) {
            $this->jsonLine(['action' => $this->action(), 'error' => $message, 'authority' => 'atlas']);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
}
