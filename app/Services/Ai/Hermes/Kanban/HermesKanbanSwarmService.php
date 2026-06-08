<?php

namespace App\Services\Ai\Hermes\Kanban;

use App\Services\Ai\Hermes\HermesAdapterReceipt;

/**
 * Atlas-governed driver for the Hermes Kanban SWARM substrate — a DURABLE
 * workers→verifier→synthesizer task graph, complementary to (not a duplicate of)
 * the EPHEMERAL Executive Mesh fan-out.
 *
 * Sovereignty model (Atlas is the brain, Hermes is the muscle):
 *  - Atlas COMPOSES the graph (worker cards + verifier + synthesizer profiles) —
 *    the decomposition decision stays in Atlas, never delegated to
 *    `hermes kanban decompose`.
 *  - Atlas OWNS the board lifecycle: a per-mission ephemeral board slug it creates
 *    and (by default) deletes, with the SQLite DB rooted under an Atlas-chosen
 *    HERMES_HOME — never a persistent Hermes board.
 *  - Atlas drives dispatch ONE-SHOT (`hermes kanban dispatch`, bounded passes) —
 *    never the deprecated `daemon` / gateway dispatcher loop.
 *  - Fail-closed + default-off: a live run needs `kanban.policy=atlas_adapter` AND
 *    an explicit confirm; otherwise it only composes/previews (no spawn, no spend).
 *  - Every judgement is sealed (`atlas.hermes.kanban_swarm_*.v1`); `authority` is
 *    always Atlas and `hermes_kanban_can_decide` is always false.
 *
 * All process I/O goes through {@see HermesKanbanCli} so the orchestration is
 * proven with a fake CLI (no spawn, no tokens); the live round-trip is operator-
 * authorized like `atlas:hermes:mesh dispatch --confirm`.
 */
class HermesKanbanSwarmService
{
    use HermesAdapterReceipt;

    public function __construct(
        private readonly HermesKanbanCli $cli = new HermesKanbanProcessCli(),
    ) {}

    /**
     * Pure, side-effect-free plan: validates the spec, derives the Atlas-owned
     * board slug, and seals whether a live dispatch is allowed now.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function compose(array $spec): array
    {
        $goal = $this->cleanString($spec['goal'] ?? null);
        $workers = $this->workerCards($spec);
        $verifier = $this->cleanString($spec['verifier'] ?? null);
        $synthesizer = $this->cleanString($spec['synthesizer'] ?? null);
        $policy = $this->policy();

        $structuralBlock = match (true) {
            $goal === null => 'goal_missing',
            $workers === [] => 'workers_missing',
            $verifier === null => 'verifier_missing',
            $synthesizer === null => 'synthesizer_missing',
            default => null,
        };
        $blockedReason = $structuralBlock ?? ($policy !== 'atlas_adapter' ? 'kanban_policy_off' : null);

        $boardSlug = $this->boardSlug($spec, $goal, $workers, $verifier, $synthesizer);

        return $this->withReceiptHash([
            'schema_version' => 'atlas.hermes.kanban_swarm_plan.v1',
            'authority' => 'atlas',
            'hermes_kanban_can_decide' => false,
            'kanban_policy' => $policy,
            'board_slug' => $boardSlug,
            'permission_mode' => $this->permissionMode($spec),
            'goal_hash' => $goal !== null ? hash('sha256', $goal) : null,
            'worker_count' => count($workers),
            'worker_profiles' => array_values(array_map(static fn (array $w): string => $w['profile'], $workers)),
            'verifier' => $verifier,
            'synthesizer' => $synthesizer,
            'max_workers' => $this->maxWorkers(),
            'structural_valid' => $structuralBlock === null,
            'dispatch_allowed_now' => $blockedReason === null,
            'blocked_reason' => $blockedReason,
        ]);
    }

    /**
     * Pure preview of the exact argv a live run WOULD execute, with the goal and
     * worker titles MASKED to hashes so a preview never prints raw task text.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function previewArgv(array $spec): array
    {
        $plan = $this->compose($spec);
        $goal = $this->cleanString($spec['goal'] ?? null) ?? '';
        $workers = $this->workerCards($spec);

        $swarmArgv = array_merge(
            ['kanban', '--board', (string) $plan['board_slug'], 'swarm', $this->mask($goal)],
            $this->workerArgs($workers, masked: true),
            ['--verifier', (string) ($plan['verifier'] ?? ''), '--synthesizer', (string) ($plan['synthesizer'] ?? ''), '--idempotency-key', (string) $plan['board_slug']],
        );

        return [
            'plan' => $plan,
            'board_create_argv' => ['kanban', 'boards', 'create', (string) $plan['board_slug']],
            'swarm_argv' => $swarmArgv,
            'dispatch_dry_run_argv' => ['kanban', '--board', (string) $plan['board_slug'], 'dispatch', '--dry-run', '--max', (string) $this->maxSpawnsPerPass()],
        ];
    }

    /**
     * Live, fail-closed run: create the Atlas-owned board → seed the swarm graph →
     * bounded one-shot dispatch passes → reconcile from board stats → delete the
     * board. Returns a sealed `atlas.hermes.kanban_swarm_run.v1` receipt. Spawns
     * real workers (token spend) ONLY when policy=atlas_adapter AND $confirm.
     *
     * @param  array<string,mixed>  $spec
     * @param  array{hermes_home?:string,timeout?:int}  $options
     * @return array<string,mixed>
     */
    public function run(array $spec, bool $confirm = false, array $options = []): array
    {
        $plan = $this->compose($spec);

        if (! ($plan['structural_valid'] ?? false)) {
            return $this->runReceipt($plan, 'not_run', (string) $plan['blocked_reason'], []);
        }
        if (($plan['kanban_policy'] ?? 'off') !== 'atlas_adapter') {
            return $this->runReceipt($plan, 'not_run', 'kanban_policy_off', []);
        }
        if (! $confirm) {
            return $this->runReceipt($plan, 'not_run', 'confirm_required', []);
        }

        $slug = (string) $plan['board_slug'];
        $cliOptions = array_filter([
            'board' => $slug,
            'hermes_home' => $this->cleanString($options['hermes_home'] ?? null),
            'timeout' => (int) ($options['timeout'] ?? 120),
        ], static fn ($v): bool => $v !== null);
        $homeOnly = array_filter(['hermes_home' => $cliOptions['hermes_home'] ?? null, 'timeout' => $cliOptions['timeout']], static fn ($v): bool => $v !== null);

        // 1) Atlas-owned ephemeral board (best-effort: re-create is harmless).
        $this->cli->invoke(['boards', 'create', $slug], $homeOnly + ['json' => false]);

        // 2) Seed the swarm graph (workers → verifier → synthesizer).
        $swarm = $this->cli->invoke(array_merge(
            ['swarm', (string) ($this->cleanString($spec['goal'] ?? null) ?? '')],
            $this->workerArgs($this->workerCards($spec), masked: false),
            ['--verifier', (string) $plan['verifier'], '--synthesizer', (string) $plan['synthesizer'], '--idempotency-key', $slug],
        ), $cliOptions + ['json' => true]);

        $graph = is_array($swarm->json) ? $swarm->json : [];
        if (! $swarm->ok || ! is_string($graph['root_id'] ?? null)) {
            $this->deleteBoard($slug, $homeOnly);

            return $this->runReceipt($plan, 'failed', 'swarm_seed_failed', []);
        }

        // 3) Bounded one-shot dispatch passes until the board is terminal.
        $passes = 0;
        $lastStats = [];
        $maxPasses = $this->maxDispatchPasses();
        while ($passes < $maxPasses) {
            $this->cli->invoke(['dispatch', '--max', (string) $this->maxSpawnsPerPass()], $cliOptions + ['json' => true]);
            $passes++;
            $stats = $this->cli->invoke(['stats'], $cliOptions + ['json' => true]);
            $lastStats = is_array($stats->json) ? $stats->json : [];
            if ($this->isTerminal($lastStats)) {
                break;
            }
            $this->pause();
        }

        $reconciliation = $this->reconcile($lastStats, $passes, $passes >= $maxPasses);

        // 4) Atlas deletes the board (no persistent Hermes state).
        $this->deleteBoard($slug, $homeOnly);

        return $this->runReceipt($plan, $reconciliation['aggregate_status'], null, [
            'graph' => [
                'root_id_present' => is_string($graph['root_id'] ?? null),
                'worker_count' => is_array($graph['worker_ids'] ?? null) ? count($graph['worker_ids']) : 0,
                'verifier_present' => is_string($graph['verifier_id'] ?? null),
                'synthesizer_present' => is_string($graph['synthesizer_id'] ?? null),
            ],
            'reconciliation' => $reconciliation,
        ]);
    }

    private function deleteBoard(string $slug, array $homeOnly): void
    {
        if ($this->deleteBoardAfterRun()) {
            $this->cli->invoke(['boards', 'rm', $slug, '--delete'], $homeOnly + ['json' => false]);
        }
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function runReceipt(array $plan, string $status, ?string $blockedReason, array $extra): array
    {
        return $this->withReceiptHash(array_merge([
            'schema_version' => 'atlas.hermes.kanban_swarm_run.v1',
            'authority' => 'atlas',
            'hermes_kanban_can_decide' => false,
            'board_slug' => $plan['board_slug'] ?? null,
            'plan_receipt_hash' => $plan['receipt_hash'] ?? null,
            'aggregate_status' => $status,
            'ran' => ! in_array($status, ['not_run'], true),
            'blocked_reason' => $blockedReason,
        ], $extra));
    }

    /**
     * Terminal when nothing is left that a dispatch pass could advance — no ready,
     * todo, running or scheduled tasks (only done/blocked remain).
     *
     * @param  array<string,mixed>  $stats
     */
    private function isTerminal(array $stats): bool
    {
        $byStatus = is_array($stats['by_status'] ?? null) ? $stats['by_status'] : [];
        $active = 0;
        foreach (['ready', 'todo', 'running', 'scheduled'] as $key) {
            $active += (int) ($byStatus[$key] ?? 0);
        }

        return $active === 0;
    }

    /**
     * @param  array<string,mixed>  $stats
     * @return array<string,mixed>
     */
    private function reconcile(array $stats, int $passes, bool $passCapHit): array
    {
        $byStatus = is_array($stats['by_status'] ?? null) ? $stats['by_status'] : [];
        $done = (int) ($byStatus['done'] ?? 0);
        $blocked = (int) ($byStatus['blocked'] ?? 0);
        $total = array_sum(array_map('intval', $byStatus));
        $active = $total - $done - $blocked;

        $aggregate = match (true) {
            $total === 0 => 'empty',
            $done === $total => 'all_completed',
            $active > 0 => 'incomplete',
            $blocked > 0 => 'blocked',
            default => 'partial',
        };

        return $this->withReceiptHash([
            'schema_version' => 'atlas.hermes.kanban_reconciliation.v1',
            'authority' => 'atlas',
            'aggregate_status' => $aggregate,
            'task_total' => $total,
            'done' => $done,
            'blocked' => $blocked,
            'active_remaining' => max(0, $active),
            'dispatch_passes' => $passes,
            'pass_cap_hit' => $passCapHit,
        ]);
    }

    /**
     * @param  array<string,mixed>  $spec
     * @return array<int,array{profile:string,title:string,skills:array<int,string>}>
     */
    private function workerCards(array $spec): array
    {
        $raw = $spec['workers'] ?? null;
        if (! is_array($raw)) {
            return [];
        }

        $cards = [];
        foreach ($raw as $worker) {
            if (! is_array($worker)) {
                continue;
            }
            $profile = $this->cleanString($worker['profile'] ?? null);
            $title = $this->cleanString($worker['title'] ?? null);
            if ($profile === null || $title === null) {
                continue;
            }
            $skills = [];
            foreach ((array) ($worker['skills'] ?? []) as $skill) {
                $s = $this->cleanString($skill);
                if ($s !== null) {
                    $skills[] = str_replace([':', ','], '-', $s);
                }
            }
            $cards[] = [
                'profile' => str_replace([':', ' '], ['-', '-'], $profile),
                // titles must not break the PROFILE:TITLE[:SKILL] grammar.
                'title' => str_replace(':', '-', $title),
                'skills' => $skills,
            ];
            if (count($cards) >= $this->maxWorkers()) {
                break;
            }
        }

        return $cards;
    }

    /**
     * Build the repeatable `--worker PROFILE:TITLE[:SKILL,SKILL]` args.
     *
     * @param  array<int,array{profile:string,title:string,skills:array<int,string>}>  $cards
     * @return array<int,string>
     */
    private function workerArgs(array $cards, bool $masked): array
    {
        $args = [];
        foreach ($cards as $card) {
            $title = $masked ? $this->mask($card['title']) : $card['title'];
            $value = $card['profile'].':'.$title;
            if ($card['skills'] !== []) {
                $value .= ':'.implode(',', $card['skills']);
            }
            $args[] = '--worker';
            $args[] = $value;
        }

        return $args;
    }

    /**
     * @param  array<string,mixed>  $spec
     * @param  array<int,array{profile:string,title:string,skills:array<int,string>}>  $workers
     */
    private function boardSlug(array $spec, ?string $goal, array $workers, ?string $verifier, ?string $synthesizer): string
    {
        $mission = $this->cleanString($spec['mission_id'] ?? null);
        $seed = ($mission ?? '').'|'.($goal ?? '').'|'.json_encode($workers).'|'.($verifier ?? '').'|'.($synthesizer ?? '');

        return $this->boardPrefix().'-'.substr(hash('sha256', $seed), 0, 16);
    }

    private function mask(string $raw): string
    {
        return 'masked(len='.strlen($raw).',sha256='.substr(hash('sha256', $raw), 0, 12).')';
    }

    /**
     * @param  array<string,mixed>  $spec
     */
    private function permissionMode(array $spec): string
    {
        $mode = $this->cleanString($spec['permission_mode'] ?? null);

        return in_array($mode, ['read', 'write', 'danger'], true) ? $mode : 'read';
    }

    private function policy(): string
    {
        $value = config('atlas.ai.providers.hermes_cli.kanban.policy', 'off');

        return is_string($value) && trim($value) !== '' ? strtolower(trim($value)) : 'off';
    }

    private function boardPrefix(): string
    {
        $value = $this->cleanString(config('atlas.ai.providers.hermes_cli.kanban.board_prefix'));

        return $value !== null ? str_replace([' ', ':'], '-', $value) : 'atlas-mission';
    }

    private function maxWorkers(): int
    {
        return max(1, (int) config('atlas.ai.providers.hermes_cli.kanban.max_workers', 8));
    }

    private function maxDispatchPasses(): int
    {
        return max(1, (int) config('atlas.ai.providers.hermes_cli.kanban.max_dispatch_passes', 40));
    }

    private function maxSpawnsPerPass(): int
    {
        return max(1, (int) config('atlas.ai.providers.hermes_cli.kanban.max_spawns_per_pass', 4));
    }

    private function deleteBoardAfterRun(): bool
    {
        return (bool) config('atlas.ai.providers.hermes_cli.kanban.delete_board_after_run', true);
    }

    private function pause(): void
    {
        $micros = (int) config('atlas.ai.providers.hermes_cli.kanban.dispatch_poll_microseconds', 1000000);
        if ($micros > 0) {
            usleep($micros);
        }
    }

    private function cleanString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
