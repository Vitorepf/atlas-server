<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\Bdd\AtlasBddAcceptanceRuntimeService;
use Illuminate\Console\Command;

class AtlasBddCommand extends Command
{
    protected $signature = 'atlas:bdd
        {--action=report : compile|execute|report|list-scenarios|list-executions}
        {--gherkin= : Gherkin source text for compile}
        {--scenario-id= : scenario_id for execute}
        {--context-json= : JSON context for execute}
        {--limit=10 : tail size for list-*}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas BDD Acceptance Runtime — compile Gherkin scenarios, execute with registered step definitions, report honest pass/fail/pending_definition.';

    public function handle(AtlasBddAcceptanceRuntimeService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');
        $limit = max(1, (int) $this->option('limit'));

        try {
            switch ($action) {
                case 'compile':
                    $g = (string) ($this->option('gherkin') ?? '');
                    if ($g === '') {
                        $this->error('--gherkin=... obrigatório.');

                        return self::FAILURE;
                    }

                    return $this->emit($svc->compile($g), $json);

                case 'execute':
                    $sid = (string) ($this->option('scenario-id') ?? '');
                    if ($sid === '') {
                        $this->error('--scenario-id=... obrigatório.');

                        return self::FAILURE;
                    }
                    $rawCtx = (string) ($this->option('context-json') ?? '');
                    $ctx = $rawCtx !== '' ? json_decode($rawCtx, true) : [];
                    if (! is_array($ctx)) {
                        $ctx = [];
                    }

                    return $this->emit($svc->execute($sid, $ctx), $json);

                case 'report':
                    return $this->emit($svc->report(), $json);

                case 'list-scenarios':
                    $list = $svc->listScenarios();

                    return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);

                case 'list-executions':
                    $list = $svc->listExecutions();

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
