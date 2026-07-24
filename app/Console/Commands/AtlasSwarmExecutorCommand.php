<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use App\Services\Ai\AtlasDecide\AtlasSwarmExecutorService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Swarm Executor CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-swarm-executor.md
 *
 *   execute --task-category --role [--framework] [--parallelism=3]
 *           Dispatches via SwarmConductor + executes via SwarmExecutor in a single turn.
 *           Production resolver invokes real providers; in CLI default uses
 *           a synthetic synchronous resolver (deterministic for smoke).
 *   latest
 *   list
 */
class AtlasSwarmExecutorCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:swarm:execute
        {--action=execute : execute|list|latest}
        {--task-category=}
        {--role=}
        {--framework=}
        {--parallelism=3}
        {--privacy=public}
        {--input=}
        {--json}';

    protected $description = 'Atlas Swarm Executor — fan-out cross-provider arms, fan-in reconciliation, deterministic tie-break.';

    public function handle(AtlasSwarmConductorService $conductor, AtlasSwarmExecutorService $executor): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'execute':
                $taskCategory = (string) ($this->option('task-category') ?? '');
                $role = (string) ($this->option('role') ?? '');
                if ($taskCategory === '' || $role === '') {
                    $this->error('execute requires --task-category and --role.');

                    return self::FAILURE;
                }
                $dispatch = $conductor->dispatch([
                    'task_category' => $taskCategory,
                    'role' => $role,
                    'framework' => $this->option('framework') ?: null,
                    'parallelism' => (int) $this->option('parallelism'),
                    'scope' => ['privacy_class' => (string) $this->option('privacy')],
                    'requested_autonomy' => 'execute_with_approval',
                ]);

                // Default smoke resolver: deterministic synthetic outcome per arm.
                // Operator can wire a real resolver via AppServiceProvider for production.
                $executor->setResolver(function (array $arm, array $ctx): array {
                    $provider = (string) ($arm['provider'] ?? '');
                    $rank = (int) ($arm['rank'] ?? 0);
                    // Synthetic: rank 1 wins with quality 0.85, others 0.6 / 0.5
                    $quality = match (true) {
                        $rank === 1 => 0.85,
                        $rank === 2 => 0.6,
                        default => 0.5,
                    };

                    return [
                        'result' => 'success',
                        'latency_ms' => 100 + $rank * 50,
                        'quality_score' => $quality,
                        'output' => "synthetic output from {$provider} rank={$rank}",
                    ];
                });

                $env = $executor->execute($dispatch, [
                    'task_category' => $taskCategory,
                    'role' => $role,
                    'framework' => $this->option('framework') ?: null,
                    'input' => (string) ($this->option('input') ?? ''),
                ]);

                return $this->emit($env, $json);

            case 'latest':
                return $this->emit($executor->lastExecution() ?? ['note' => 'no execution yet'], $json);

            case 'list':
                $list = $executor->listExecutions();

                return $this->emit(['count' => count($list), 'executions' => $list], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
