<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Console\Command;
use App\Support\YesNo;

class AtlasAurgTemporalCommand extends Command
{
    protected $signature = 'atlas:aurg:temporal
        {--at= : ISO-8601 timestamp; returns state-at (transaction time)}
        {--valid-at= : ISO-8601 timestamp; returns state as-of VALID time (T4-S1 bi-temporal)}
        {--from= : ISO-8601 start (range)}
        {--to= : ISO-8601 end (range)}
        {--limit=100 : max ticks in timeline}
        {--json : JSON output}';

    protected $description = 'AURG · temporal timeline · state-at (transaction) / state-as-of-valid (T4-S1) / range query (read-only).';

    public function handle(AtlasUnifiedRealityGraphTemporalService $svc): int
    {
        $at = $this->option('at');
        $validAt = $this->option('valid-at');
        $from = $this->option('from');
        $to = $this->option('to');

        if ($validAt !== null && $validAt !== '') {
            // T4-S1: as-of VALID time — "what the code truth was at this instant".
            // Read the DEDICATED code-truth log (the git-history axis), not the
            // reality-graph tick log.
            $svc->setLogPath($svc->codeTruthLogPath());
            $payload = ['action' => 'state-as-of-valid', 'valid_at' => $validAt, 'tick' => $svc->stateAtValid((string) $validAt)];
        } elseif ($at !== null && $at !== '') {
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
            $this->line('ticks='.$tl['tick_count'].' chain_intact='.(YesNo::format($tl['chain_intact'])));
        }

        return 0;
    }
}
