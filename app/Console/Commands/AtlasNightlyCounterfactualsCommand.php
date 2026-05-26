<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Patamar4\AtlasNightlyCounterfactualsService;
use Illuminate\Console\Command;

/**
 * Atlas Nightly Counterfactuals CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-nightly-counterfactuals.md
 *
 *   run [--actor=cron_nightly]    selects last 24h major decisions + projects alternatives
 *   inbox [--limit=10]            top N recommendations ordered by projected_improvement
 *   list                          all sweeps
 *   latest                        last sweep envelope
 */
class AtlasNightlyCounterfactualsCommand extends Command
{
    protected $signature = 'atlas:nightly:counterfactuals
        {--action=run : run|inbox|list|latest}
        {--actor=cron_nightly}
        {--limit=10}
        {--json}';

    protected $description = 'Atlas Nightly Counterfactuals — background TEOS-I4 projections of yesterday major decisions.';

    public function handle(AtlasNightlyCounterfactualsService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'run':
                return $this->emit($svc->run((string) $this->option('actor')), $json);

            case 'inbox':
                $limit = (int) $this->option('limit');

                return $this->emit([
                    'count' => count($svc->inbox($limit)),
                    'inbox' => $svc->inbox($limit),
                ], $json);

            case 'latest':
                return $this->emit($svc->lastSweep() ?? ['note' => 'no sweep yet'], $json);

            case 'list':
                $list = $svc->listSweeps();

                return $this->emit(['count' => count($list), 'sweeps' => $list], $json);

            default:
                $this->error("action '{$action}' desconhecida.");

                return self::FAILURE;
        }
    }

    private function emit(array $payload, bool $json): int
    {
        if ($json) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        } else {
            foreach ($payload as $k => $v) {
                $this->line(is_scalar($v) || $v === null ? "{$k}: ".var_export($v, true) : "{$k}: ".json_encode($v));
            }
        }

        return self::SUCCESS;
    }
}
