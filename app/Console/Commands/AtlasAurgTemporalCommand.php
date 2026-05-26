<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Console\Command;

class AtlasAurgTemporalCommand extends Command
{
    protected $signature = 'atlas:aurg:temporal
        {--at= : ISO-8601 timestamp; returns state-at}
        {--from= : ISO-8601 start (range)}
        {--to= : ISO-8601 end (range)}
        {--limit=100 : max ticks in timeline}
        {--json : JSON output}';

    protected $description = 'AURG · temporal timeline · state-at or range query (read-only).';

    public function handle(AtlasUnifiedRealityGraphTemporalService $svc): int
    {
        $at = $this->option('at');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($at !== null && $at !== '') {
            $payload = ['action' => 'state-at', 'at' => $at, 'tick' => $svc->stateAt((string) $at)];
        } elseif ($from !== null && $to !== null) {
            $payload = ['action' => 'traverse', 'from' => $from, 'to' => $to, 'ticks' => $svc->traverseTime((string) $from, (string) $to)];
        } else {
            $payload = ['action' => 'timeline', 'timeline' => $svc->timeline((int) ($this->option('limit') ?? 100))];
        }

        if ($this->option('json')) {
            $this->line(json_encode(['ok' => true] + $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return 0;
        }
        $this->line('[atlas:aurg:temporal] action='.$payload['action']);
        if ($payload['action'] === 'timeline') {
            $tl = $payload['timeline'];
            $this->line('ticks='.$tl['tick_count'].' chain_intact='.($tl['chain_intact'] ? 'yes' : 'no'));
        }

        return 0;
    }
}
