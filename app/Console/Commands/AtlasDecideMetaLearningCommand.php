<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService;
use Illuminate\Console\Command;
use Throwable;
use App\Support\YesNo;

/**
 * Atlas Decide · Meta-Learning recommendations CLI.
 *
 *   php artisan atlas:atlas-decide:meta-learning
 *   php artisan atlas:atlas-decide:meta-learning --json
 *   php artisan atlas:atlas-decide:meta-learning --task-category=frontend --role=builder
 *
 * Read-only. Does NOT mutate routing state. Use the `:activate` /
 * `:deactivate` / `:reset` commands (Doctor 3-Tier) to change routing.
 */
class AtlasDecideMetaLearningCommand extends Command
{
    protected $signature = 'atlas:atlas-decide:meta-learning
        {--task-category= : Filter to one task category}
        {--role= : Filter to one role}
        {--framework= : Filter to one framework (optional)}
        {--difficulty= : Filter advisory map to one difficulty level (L1-L5)}
        {--map : Emit the Rivals decide-map shaped for Atlas Decide advisory consumption}
        {--json : JSON output}';

    protected $description = 'Atlas Decide · meta-learning recommendations · derives read-only routing recommendations/advisory maps from the Provider Performance Ledger.';

    public function handle(AtlasDecideMetaLearningService $svc): int
    {
        try {
            $task = (string) ($this->option('task-category') ?? '');
            $role = (string) ($this->option('role') ?? '');
            $framework = $this->option('framework');
            $framework = $framework === null ? null : (string) $framework;
            $difficulty = (string) ($this->option('difficulty') ?? '');

            if ((bool) $this->option('map')) {
                $payload = $svc->rivalsAdvisoryMap([
                    'task_category' => $task,
                    'role' => $role,
                    'framework' => $framework,
                    'difficulty' => $difficulty,
                ]);
            } elseif ($task !== '' && $role !== '') {
                $payload = [$svc->recommend([
                    'task_category' => $task,
                    'role' => $role,
                    'framework' => $framework,
                ])];
            } else {
                $payload = $svc->recommendAll();
            }
        } catch (Throwable $e) {
            $this->error('[atlas:atlas-decide:meta-learning] '.$e->getMessage());

            return 1;
        }

        if ($this->option('json')) {
            $this->line(json_encode([
                'ok' => true,
                'action' => (bool) $this->option('map') ? 'meta-learning:advisory-map' : 'meta-learning:list',
                (bool) $this->option('map') ? 'advisory_map' : 'recommendations' => $payload,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return 0;
        }

        if ((bool) $this->option('map')) {
            $this->line('[atlas:atlas-decide:meta-learning]');
            $this->line('advisory map segments: '.($payload['segment_count'] ?? 0));
            $this->line('routing effect: '.($payload['routing_effect'] ?? 'none'));

            return 0;
        }

        $this->line('[atlas:atlas-decide:meta-learning]');
        $this->line('recommendations: '.count($payload));
        foreach ($payload as $r) {
            $this->line(sprintf(
                '  %s/%s (%s) → %s/%s · signal=%s confidence=%s actionable=%s mode=%s',
                $r['scope']['task_category'] ?? '—',
                $r['scope']['role'] ?? '—',
                $r['scope']['framework'] ?? '*',
                $r['recommended_provider'] ?? '—',
                $r['recommended_model'] ?? '—',
                $r['signal'] ?? '—',
                $r['confidence'] ?? '—',
                YesNo::format($r['actionable'] ?? false),
                $r['mode'] ?? '—',
            ));
        }

        return 0;
    }
}
