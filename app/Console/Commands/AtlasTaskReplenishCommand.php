<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskQueueOrchestrator;
use App\Services\Ai\SelfConstruction\AtlasTaskBrainReplenisher;
use Illuminate\Console\Command;

/**
 * PART 2 — the RUNTIME that keeps the list full from the brain. The Atlas reads its complete comprehension of a
 * scope and STRUCTURES the task list itself, topping up the serving queue so the AIs never run dry. The
 * operator does not describe tasks here — the brain does.
 *
 *   php artisan atlas:task:replenish --scope=app/Services/Ai/AutonomousEvolution --target=20
 *   php artisan atlas:task:replenish --scope=... --dry-run            # show what it WOULD structure, enqueue nothing
 *   php artisan atlas:task:replenish --scope=... --watch --every=120  # keep the queue topped up forever
 */
class AtlasTaskReplenishCommand extends Command
{
    protected $signature = 'atlas:task:replenish
        {--scope= : the scope root the brain comprehends + structures tasks from (e.g. app/Services/Ai/AutonomousEvolution)}
        {--target=20 : keep the queue at >= this many claimable tasks}
        {--max=40 : max tasks to mint per pass}
        {--docs-root=* : optional docs roots for the comprehension (doc-stated gaps)}
        {--with-orphans : ALSO mint orphan-wiring tasks, shaped RESOLVABLE (allowed_files carry the orphan + its grounded integration site); OFF by default — this is the complete-list mode}
        {--dry-run : structure tasks and report, but enqueue nothing}
        {--watch : keep topping up on an interval (Ctrl-C to stop)}
        {--every=120 : seconds between passes in --watch}
        {--json : machine-readable output}';

    protected $description = 'Atlas structures the task list from its comprehension of a scope and keeps the serving queue full (the runtime that never dries).';

    public function handle(): int
    {
        // The comprehension build is memory-heavy; self-raise so the command never OOMs without a -d flag.
        if ((int) ini_get('memory_limit') !== -1) {
            @ini_set('memory_limit', '4096M');
        }
        $scope = trim((string) ($this->option('scope') ?? ''));
        if ($scope === '') {
            $this->error('--scope is required (the area the brain comprehends + structures tasks from)');

            return self::FAILURE;
        }
        $target = max(1, (int) $this->option('target'));
        $max = max(1, (int) $this->option('max'));
        $opts = [];
        $docRoots = array_values(array_filter((array) $this->option('docs-root')));
        if ($docRoots !== []) {
            $opts['docs_roots'] = $docRoots;
        }

        $replenisher = \App\Services\Ai\SelfConstruction\AtlasTaskServingStack::replenisher();

        if ($this->option('dry-run')) {
            return $this->dryRun($replenisher, $scope, $opts);
        }

        do {
            $result = $replenisher->replenish($scope, $target, $max, $opts, (bool) $this->option('with-orphans'));
            $this->report($result);
            if ($this->option('watch')) {
                sleep(max(10, (int) $this->option('every')));
            }
        } while ($this->option('watch'));

        return self::SUCCESS;
    }

    /** @param array{docs_roots?:list<string>} $opts */
    private function dryRun(AtlasTaskBrainReplenisher $replenisher, string $scope, array $opts): int
    {
        // Build the comprehension + structure tasks WITHOUT enqueuing.
        $query = new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionQuery(
            new \App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModelBuilder,
            base_path(),
            $opts,
        );
        try {
            $model = $query->model($scope);
        } catch (\Throwable $e) {
            $this->error('comprehension failed: '.$e->getMessage());

            return self::FAILURE;
        }
        $tasks = $replenisher->structureTasks($model, (bool) $this->option('with-orphans'));

        if ($this->option('json')) {
            $this->line((string) json_encode(['scope' => $scope, 'structured' => count($tasks), 'tasks' => $tasks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }
        $this->info("DRY RUN — the brain structured ".count($tasks)." task(s) from {$scope}:");
        foreach (array_slice($tasks, 0, 25) as $t) {
            $this->line('  • '.$t['task_packet_id'].'  '.mb_substr((string) $t['objective'], 0, 90));
        }

        return self::SUCCESS;
    }

    /** @param array<string,mixed> $result */
    private function report(array $result): void
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }
        $this->line(sprintf(
            '[%s] %s — claimable %d→%d (+%d minted; %d existed, %d deficient of %d candidates)',
            $result['scope_root'] ?? '?',
            $result['status'] ?? '?',
            $result['claimable_before'] ?? 0,
            $result['claimable_after'] ?? 0,
            $result['enqueued_count'] ?? 0,
            $result['skipped_existing'] ?? 0,
            $result['skipped_deficient'] ?? 0,
            $result['candidates_considered'] ?? 0,
        ));
    }
}
