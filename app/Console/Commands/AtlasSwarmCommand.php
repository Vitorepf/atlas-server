<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasSwarmConductorService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasSwarmCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:swarm
        {--action=dispatch : dispatch|list|last|record-outcome|outcome-summary}
        {--work-json= : JSON envelope of the work unit for dispatch}
        {--outcome-json= : JSON envelope of the outcome to record}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Swarm Conductor — multi-arm dispatch composer over ADML + Kernel + Admission.';

    public function handle(AtlasSwarmConductorService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        switch ($action) {
            case 'dispatch':
                $raw = (string) ($this->option('work-json') ?? '');
                if ($raw === '') {
                    $this->error('--work-json=... obrigatório.');

                    return self::FAILURE;
                }
                $work = json_decode($raw, true);
                if (! is_array($work)) {
                    $this->error('work-json inválido.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->dispatch($work);
                } catch (\InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);
            case 'list':
                $list = $svc->listDispatches();
                $limit = max(1, (int) $this->option('limit'));

                return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);
            case 'last':
                $last = $svc->lastDispatch();

                return $this->emit($last ?? ['empty' => true], $json);
            case 'record-outcome':
                $raw = (string) ($this->option('outcome-json') ?? '');
                if ($raw === '') {
                    $this->error('--outcome-json=... obrigatório.');

                    return self::FAILURE;
                }
                $payload = json_decode($raw, true);
                if (! is_array($payload)) {
                    $this->error('outcome-json inválido.');

                    return self::FAILURE;
                }
                try {
                    $env = $svc->recordOutcome($payload);
                } catch (\InvalidArgumentException $e) {
                    $this->error($e->getMessage());

                    return self::FAILURE;
                }

                return $this->emit($env, $json);
            case 'outcome-summary':
                return $this->emit($svc->outcomeSummary(), $json);
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
