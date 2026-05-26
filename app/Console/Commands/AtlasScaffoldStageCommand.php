<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionScaffoldStagingExecutorService;
use Illuminate\Console\Command;

class AtlasScaffoldStageCommand extends Command
{
    protected $signature = 'atlas:scaffold:stage
        {--action=stage : stage|list}
        {--proposal-id= : proposal_id from ASCB to stage}
        {--proposal-hash= : proposal_hash matching the proposal}
        {--actor=operator : actor recording the staging}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Self-Construction Scaffold Staging Executor — promote an APPROVED proposal into the staging directory (operator promotes from staging to source by hand).';

    public function handle(AtlasSelfConstructionScaffoldStagingExecutorService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        try {
            switch ($action) {
                case 'stage':
                    $pid = (string) ($this->option('proposal-id') ?? '');
                    $phash = (string) ($this->option('proposal-hash') ?? '');
                    if ($pid === '' || $phash === '') {
                        $this->error('--proposal-id and --proposal-hash obrigatórios.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->stage($pid, $phash, (string) $this->option('actor')), $json);
                case 'list':
                    $list = $svc->listReceipts();
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
