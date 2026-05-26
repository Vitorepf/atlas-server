<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideTeosI4LookaheadService;
use Illuminate\Console\Command;

class AtlasDecideLookaheadCommand extends Command
{
    protected $signature = 'atlas:atlas-decide:lookahead
        {--action=run : run|list}
        {--task-category= : task_category to query}
        {--role=primary : role}
        {--framework= : optional framework hint}
        {--privacy=public : privacy_class}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide × TEOS-I4 Lookahead — explore counterfactual routing alternatives before adopting.';

    public function handle(AtlasDecideTeosI4LookaheadService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            switch ($action) {
                case 'run':
                    $env = $svc->lookahead([
                        'task_category' => (string) ($this->option('task-category') ?? ''),
                        'role' => (string) $this->option('role'),
                        'framework' => (string) ($this->option('framework') ?? ''),
                        'privacy_class' => (string) $this->option('privacy'),
                    ]);

                    return $this->emit($env, $json);
                case 'list':
                    $list = $svc->listLookaheads();
                    $limit = max(1, (int) $this->option('limit'));

                    return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);
                default:
                    $this->error("Unknown action '{$action}'.");

                    return self::FAILURE;
            }
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
