<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Cartography\CartographyTruthGuardService;
use Illuminate\Console\Command;

/**
 * Atlas Cartography Truth Guard CLI.
 *
 * Doc canon: docs/engineering-knowledge-base/atlas-cartography-truth-guard.md
 *
 * Actions:
 *   sweep   --actor=<name>          Probe Cartografia vs canonical docs (default)
 *   latest                          Show last sweep envelope
 *   list                            List all sweeps
 *   canonical-index                 Dump current {slug→hash} canonical map
 */
class AtlasCartographyTruthGuardCommand extends Command
{
    protected $signature = 'atlas:cartography:truth-guard
        {--action=sweep : sweep|latest|list|canonical-index}
        {--actor=cartography_truth_guard}
        {--json}';

    protected $description = 'Atlas Cartography Truth Guard — detect drift between Cartografia render and canonical .md docs.';

    public function handle(CartographyTruthGuardService $svc): int
    {
        $action = (string) $this->option('action');
        $json = (bool) $this->option('json');

        switch ($action) {
            case 'sweep':
                $env = $svc->sweep((string) $this->option('actor'));

                return $this->emit($env, $json);

            case 'latest':
                $last = $svc->lastSweep();

                return $this->emit($last ?? ['note' => 'no sweep yet'], $json);

            case 'list':
                $list = $svc->listSweeps();

                return $this->emit(['count' => count($list), 'sweeps' => $list], $json);

            case 'canonical-index':
                $idx = $svc->canonicalIndex();

                return $this->emit([
                    'count' => count($idx),
                    'index' => $idx,
                ], $json);

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
