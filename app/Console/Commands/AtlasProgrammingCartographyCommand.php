<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Programming\Cartography\AtlasProgrammingCartographyPublisherService;
use Illuminate\Console\Command;

class AtlasProgrammingCartographyCommand extends Command
{
    protected $signature = 'atlas:programming:cartography
        {--action=publish : publish|list|latest}
        {--workspace= : optional workspace filter}
        {--limit=100 : node limit (1..500)}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Programming Cartography Publisher — read-only graph builder over work items + specs + tasks + evidence + drift reports.';

    public function handle(AtlasProgrammingCartographyPublisherService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        switch ($action) {
            case 'publish':
                $ctx = ['limit' => (int) $this->option('limit')];
                $ws = (string) ($this->option('workspace') ?? '');
                if ($ws !== '') {
                    $ctx['workspace'] = $ws;
                }

                return $this->emit($svc->publish($ctx), $json);
            case 'list':
                $list = $svc->listSnapshots();

                return $this->emit(['count' => count($list), 'tail' => array_slice($list, -10)], $json);
            case 'latest':
                $latest = $svc->latestSnapshot();

                return $this->emit($latest ?? ['empty' => true], $json);
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
