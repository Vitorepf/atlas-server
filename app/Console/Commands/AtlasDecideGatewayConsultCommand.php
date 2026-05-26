<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasDecide\AtlasDecideGatewayConsultationService;
use Illuminate\Console\Command;

class AtlasDecideGatewayConsultCommand extends Command
{
    protected $signature = 'atlas:atlas-decide:gateway-consult
        {--task-category= : task_category to query}
        {--role=primary : role}
        {--framework= : optional framework hint}
        {--privacy=public : privacy_class}
        {--list : list past consultations instead of consulting}
        {--limit=10 : tail size for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Decide → Gateway Consultation hook. Query learned routing under Kernel + Admission gates before letting any provider call go through.';

    public function handle(AtlasDecideGatewayConsultationService $svc): int
    {
        $json = (bool) $this->option('json');

        if ($this->option('list')) {
            $list = $svc->listConsultations();
            $limit = max(1, (int) $this->option('limit'));

            return $this->emit(['count' => count($list), 'tail' => array_slice($list, -$limit)], $json);
        }

        $task = (string) ($this->option('task-category') ?? '');
        if ($task === '') {
            $this->error('--task-category=... obrigatório (ou use --list).');

            return self::FAILURE;
        }

        $env = $svc->consult([
            'task_category' => $task,
            'role' => (string) $this->option('role'),
            'framework' => (string) ($this->option('framework') ?? ''),
            'privacy_class' => (string) $this->option('privacy'),
            'actor' => 'cli_operator',
        ]);

        return $this->emit($env, $json);
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
