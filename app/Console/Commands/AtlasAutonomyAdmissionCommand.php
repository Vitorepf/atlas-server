<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use Illuminate\Console\Command;

class AtlasAutonomyAdmissionCommand extends Command
{
    protected $signature = 'atlas:autonomy:admit
        {--change-json= : JSON envelope of the proposed change}
        {--list : List recorded tickets instead of admitting a new change}
        {--limit=20 : Tail size for --list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Autonomy Admission — compose Constitutional Kernel + risk + autonomy into a single admission envelope.';

    public function handle(AtlasAutonomyAdmissionService $svc): int
    {
        $json = (bool) $this->option('json');

        if ($this->option('list')) {
            $tickets = $svc->listTickets();
            $limit = max(1, (int) $this->option('limit'));
            $tail = array_slice($tickets, -$limit);

            return $this->emit(['count' => count($tickets), 'tail' => $tail], $json);
        }

        $raw = (string) ($this->option('change-json') ?? '');
        if ($raw === '') {
            $this->error('--change-json=... obrigatório (ou use --list).');

            return self::FAILURE;
        }
        $change = json_decode($raw, true);
        if (! is_array($change)) {
            $this->error('change-json inválido.');

            return self::FAILURE;
        }

        try {
            $env = $svc->admit($change);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

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
