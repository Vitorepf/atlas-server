<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reconciliation\AtlasAutonomousReconciliationRuntimeService;
use Illuminate\Console\Command;

class AtlasReconciliationCommand extends Command
{
    protected $signature = 'atlas:reconciliation
        {--action=tick : tick|summary|list|last}
        {--privacy=normal : privacy_class for tick}
        {--autonomy=execute_with_approval : requested autonomy for tick}
        {--force-group= : force tick to target this group even if no gap detected}
        {--force-gap-size=1 : gap size to attribute when --force-group is used}
        {--limit=20 : limit for list}
        {--json : Emit JSON envelope}';

    protected $description = 'Atlas Autonomous Reconciliation Runtime — fire ticks, inspect summary/history. Each tick chains: CFA → Admission → AURG-4D → ASCB.';

    public function handle(AtlasAutonomousReconciliationRuntimeService $svc): int
    {
        $json = (bool) $this->option('json');
        $action = (string) $this->option('action');

        switch ($action) {
            case 'tick':
                $ctx = [
                    'privacy_class' => (string) $this->option('privacy'),
                    'requested_autonomy' => (string) $this->option('autonomy'),
                ];
                $forceGroup = (string) ($this->option('force-group') ?? '');
                if ($forceGroup !== '') {
                    $ctx['force_group'] = $forceGroup;
                    $ctx['force_gap_size'] = (int) $this->option('force-gap-size');
                }
                $tick = $svc->tick($ctx);

                return $this->emit($tick, $json);
            case 'summary':
                return $this->emit($svc->summary(), $json);
            case 'list':
                $ticks = $svc->listTicks();
                $limit = max(1, (int) $this->option('limit'));
                $tail = array_slice($ticks, -$limit);

                return $this->emit(['count' => count($ticks), 'tail' => $tail], $json);
            case 'last':
                $last = $svc->lastTick();

                return $this->emit($last ?? ['empty' => true], $json);
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
