<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Teos\AtlasTeosI4CounterfactualTreeService;
use Illuminate\Console\Command;

class AtlasTeosI4Command extends Command
{
    protected $signature = 'atlas:teos-i4
        {--action=expand : expand|best-path|list}
        {--input-json= : JSON input for expand}
        {--tree-id= : tree_id for best-path}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas TEOS-I4 Counterfactual Tree — greedy BFS over TEOS-I3 branches with Kernel + Admission gates.';

    public function handle(AtlasTeosI4CounterfactualTreeService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        switch ($action) {
            case 'expand':
                $raw = (string) ($this->option('input-json') ?? '');
                if ($raw === '') {
                    $this->error('--input-json=... obrigatório para expand.');

                    return self::FAILURE;
                }
                $input = json_decode($raw, true);
                if (! is_array($input)) {
                    $this->error('input-json inválido.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->expand($input);
                } catch (\InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);
            case 'best-path':
                $treeId = (string) ($this->option('tree-id') ?? '');
                if ($treeId === '') {
                    $this->error('--tree-id=... obrigatório.');

                    return self::FAILURE;
                }

                return $this->emit(['tree_id' => $treeId, 'best_path' => $svc->bestPath($treeId)], $json);
            case 'list':
                $trees = $svc->listTrees();
                $limit = max(1, (int) $this->option('limit'));

                return $this->emit(['count' => count($trees), 'tail' => array_slice($trees, -$limit)], $json);
            default:
                $this->error("Unknown action '{$action}'.");

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
