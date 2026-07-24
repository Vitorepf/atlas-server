<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Compounding\AtlasCompoundingLevel8DistillationService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasCompoundingLevel8Command extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:compounding:level8
        {--action=distill : distill|list}
        {--no-persist : do not append to ledger}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Compounding L7→L8→L9 distillation — read runtime evidence and project current compounding level.';

    public function handle(AtlasCompoundingLevel8DistillationService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        switch ($action) {
            case 'distill':
                $persist = ! (bool) $this->option('no-persist');

                return $this->emit($svc->distill($persist), $json);
            case 'list':
                $list = $svc->listDistillations();
                $limit = max(1, (int) $this->option('limit'));

                return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);
            default:
                $this->error("Unknown action '{$action}'.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line($this->encode($payload));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) ? "{$k}: {$v}" : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
