<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Hermes\Kanban\HermesKanbanSwarmService;
use Illuminate\Console\Command;

/**
 * Operator + automation surface for the Atlas-governed Hermes Kanban SWARM
 * substrate — a DURABLE workers→verifier→synthesizer graph (complementary to the
 * EPHEMERAL Executive Mesh; see atlas:hermes:mesh).
 *
 * Default-safe: `status` and `plan` are READ-ONLY (plan composes + seals a plan,
 * touches nothing). `dispatch --dry-run` prints the exact masked argv WITHOUT
 * creating a board or spawning. A real `dispatch` creates an Atlas-owned ephemeral
 * board, seeds the swarm graph, runs bounded one-shot dispatch passes (real
 * `hermes` workers — token spend), reconciles, then deletes the board — and is
 * FAIL-CLOSED twice over: it refuses unless providers.hermes_cli.kanban.policy ===
 * 'atlas_adapter' AND the operator passes --confirm. Goals/titles are masked to
 * hashes in every preview/receipt. Auto-discovered from app/Console/Commands.
 *
 * @see docs/engineering-knowledge-base/atlas-hermes-kanban-substrate.md
 */
class AtlasHermesKanbanCommand extends Command
{
    protected $signature = 'atlas:hermes:kanban
        {action=status : status|plan|dispatch}
        {--file= : JSON file: {"goal":"...","workers":[{"profile":"coder","title":"...","skills":["file"]}],"verifier":"verifier","synthesizer":"synthesizer","permission_mode":"write"}}
        {--json : Emit JSON}
        {--dry-run : Print the exact masked swarm+dispatch argv WITHOUT creating a board or spawning}
        {--confirm : Required to actually run the live swarm}';

    protected $description = 'Atlas Hermes Kanban swarm surface: status (config + readiness), plan (compose + seal a governed swarm plan, read-only), dispatch (run a real governed swarm — requires kanban.policy=atlas_adapter AND --confirm).';

    public function handle(HermesKanbanSwarmService $service): int
    {
        return match ($this->action()) {
            'plan' => $this->handlePlan($service),
            'dispatch' => $this->handleDispatch($service),
            default => $this->handleStatus(),
        };
    }

    private function handleStatus(): int
    {
        $policy = (string) config('atlas.ai.providers.hermes_cli.kanban.policy', 'off');
        $payload = [
            'action' => 'status',
            'kanban_policy' => $policy,
            'dispatch_enabled' => $policy === 'atlas_adapter',
            'board_prefix' => (string) config('atlas.ai.providers.hermes_cli.kanban.board_prefix', 'atlas-mission'),
            'max_workers' => (int) config('atlas.ai.providers.hermes_cli.kanban.max_workers', 8),
            'max_dispatch_passes' => (int) config('atlas.ai.providers.hermes_cli.kanban.max_dispatch_passes', 40),
            'max_spawns_per_pass' => (int) config('atlas.ai.providers.hermes_cli.kanban.max_spawns_per_pass', 4),
            'delete_board_after_run' => (bool) config('atlas.ai.providers.hermes_cli.kanban.delete_board_after_run', true),
            'authority' => 'atlas',
        ];

        if ($this->wantsJson()) {
            $this->printJson($payload);

            return self::SUCCESS;
        }

        foreach ($payload as $k => $v) {
            $this->components->twoColumnDetail($k, is_bool($v) ? ($v ? 'yes' : 'no') : (string) $v);
        }

        return self::SUCCESS;
    }

    private function handlePlan(HermesKanbanSwarmService $service): int
    {
        [$spec, $error] = $this->load();
        if ($error !== null) {
            return $this->failWith($error);
        }

        $plan = $service->compose($spec);

        if ($this->wantsJson()) {
            $this->printJson(['action' => 'plan', 'plan' => $plan]);

            return self::SUCCESS;
        }

        $this->renderPlan($plan);

        return self::SUCCESS;
    }

    private function handleDispatch(HermesKanbanSwarmService $service): int
    {
        [$spec, $error] = $this->load();
        if ($error !== null) {
            return $this->failWith($error);
        }

        // Dry-run prints the masked argv preview, creates nothing, bypasses confirm.
        if ((bool) $this->option('dry-run')) {
            $preview = $service->previewArgv($spec);
            if ($this->wantsJson()) {
                $this->printJson(array_merge(['action' => 'dry_run', 'launched' => false], $preview));

                return self::SUCCESS;
            }
            $this->components->twoColumnDetail('action', 'dry_run (nothing created/launched)');
            $this->components->twoColumnDetail('board', (string) data_get($preview, 'plan.board_slug'));
            $this->line('  swarm: '.implode(' ', (array) $preview['swarm_argv']));
            $this->line('  dispatch: '.implode(' ', (array) $preview['dispatch_dry_run_argv']));

            return self::SUCCESS;
        }

        if (config('atlas.ai.providers.hermes_cli.kanban.policy') !== 'atlas_adapter') {
            return $this->failWith('dispatch refused: providers.hermes_cli.kanban.policy is not atlas_adapter (default-safe). Set ATLAS_AI_HERMES_KANBAN_POLICY=atlas_adapter to enable.');
        }
        if (! (bool) $this->option('confirm')) {
            return $this->failWith('dispatch refused: pass --confirm to run a live swarm (spawns real hermes workers).');
        }

        $result = $service->run($spec, true);

        if ($this->wantsJson()) {
            $this->printJson(['action' => 'dispatch', 'run' => $result]);

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('aggregate status', (string) ($result['aggregate_status'] ?? 'unknown'));
        $this->components->twoColumnDetail('ran', ($result['ran'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('blocked reason', (string) ($result['blocked_reason'] ?? '—'));
        $this->components->twoColumnDetail('done', (string) data_get($result, 'reconciliation.done', 0));
        $this->components->twoColumnDetail('task total', (string) data_get($result, 'reconciliation.task_total', 0));
        $this->components->twoColumnDetail('dispatch passes', (string) data_get($result, 'reconciliation.dispatch_passes', 0));

        return self::SUCCESS;
    }

    /**
     * @param  array<string,mixed>  $plan
     */
    private function renderPlan(array $plan): void
    {
        $this->components->twoColumnDetail('action', 'plan');
        $this->components->twoColumnDetail('board', (string) ($plan['board_slug'] ?? '—'));
        $this->components->twoColumnDetail('structural valid', ($plan['structural_valid'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('dispatch allowed now', ($plan['dispatch_allowed_now'] ?? false) ? 'yes' : 'no');
        $this->components->twoColumnDetail('blocked reason', (string) ($plan['blocked_reason'] ?? '—'));
        $this->components->twoColumnDetail('workers', (string) ($plan['worker_count'] ?? 0));
        $this->components->twoColumnDetail('verifier', (string) ($plan['verifier'] ?? '—'));
        $this->components->twoColumnDetail('synthesizer', (string) ($plan['synthesizer'] ?? '—'));
    }

    /**
     * @return array{0:array<string,mixed>,1:?string}
     */
    private function load(): array
    {
        $file = $this->option('file');
        if (! is_string($file) || trim($file) === '') {
            return [[], 'missing --file=<path to swarm spec json>'];
        }
        if (! is_file($file)) {
            return [[], "file not found: {$file}"];
        }

        $decoded = json_decode((string) file_get_contents($file), true);
        if (! is_array($decoded)) {
            return [[], 'file is not a valid JSON object'];
        }

        return [$decoded, null];
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
            $this->printJson(['action' => $this->action(), 'error' => $message, 'authority' => 'atlas']);
        } else {
            $this->error($message);
        }

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function printJson(array $payload): void
    {
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}');
    }
}
