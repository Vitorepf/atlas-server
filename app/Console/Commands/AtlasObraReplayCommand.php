<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasForge\AtlasObraDeterministicReplayService;
use Illuminate\Console\Command;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * Atlas Obra Deterministic Replay CLI (AP-705 integration shim).
 *
 * Reads an events JSONL file and prints the canonical replay snapshot.
 * This is the concrete caller-side wiring that removes the
 * "Evidence Ledger reader é responsabilidade do caller" caveat: the
 * operator (or a cron) can produce events.jsonl from any source and
 * pipe it through replay for a deterministic audit snapshot.
 *
 * Usage:
 *   php artisan atlas:obra:replay --events=/tmp/events.jsonl --json
 *   php artisan atlas:obra:replay --events=/tmp/events.jsonl --decision=dec-001
 */
final class AtlasObraReplayCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:obra:replay
        {--events= : path to JSONL file with one envelope per line}
        {--decision= : optional decision_id to extract its lineage}
        {--json : machine-readable JSON output}';

    protected $description = 'Replay an Obra event sequence deterministically (AP-705).';

    public function handle(AtlasObraDeterministicReplayService $replay): int
    {
        $eventsPath = (string) ($this->option('events') ?? '');
        if ($eventsPath === '' || ! is_file($eventsPath)) {
            $this->error('--events=<path> required and file must exist');

            return self::FAILURE;
        }

        $lines = file($eventsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            $this->error("unable to read {$eventsPath}");

            return self::FAILURE;
        }

        $events = [];
        foreach ($lines as $i => $line) {
            $decoded = json_decode($line, true);
            if (! is_array($decoded)) {
                $this->error("invalid JSON at line ".($i + 1));

                return self::FAILURE;
            }
            $events[] = $decoded;
        }

        $decisionId = (string) ($this->option('decision') ?? '');
        $payload = $decisionId !== ''
            ? ['decision_id' => $decisionId, 'lineage' => $replay->lineage($events, $decisionId)]
            : $replay->replay($events);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        if ($decisionId !== '') {
            $this->info("Decision lineage · {$decisionId}");
            foreach ((array) $payload['lineage'] as $entry) {
                $idx = (int) ($entry['event_index'] ?? 0);
                $phase = (string) ($entry['event']['phase_out'] ?? '');
                $this->line(sprintf('  [%02d] %s', $idx, $phase));
            }

            return self::SUCCESS;
        }

        $this->info('Obra Replay Snapshot');
        $this->line('  events: '.((int) $payload['counts']['events']));
        $this->line('  phases_executed: '.implode(', ', (array) $payload['phases_executed']));
        $this->line('  decisions: '.((int) $payload['counts']['decisions']));
        $this->line('  blockers_open_at_end: '.((int) $payload['counts']['blockers_open_at_end']));
        $this->line('  replay_hash: '.((string) $payload['replay_hash']));

        return self::SUCCESS;
    }
}
