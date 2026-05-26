<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AtlasForge\AtlasForgeParallelDurableCoordinatorService;
use Illuminate\Console\Command;

/**
 * Atlas Forge Parallel Durable CLI (AP-704 integration shim).
 *
 * Reads tickets / agents / reservations from JSON files and proposes a
 * canonical assignment envelope. Optionally appends the assignment to a
 * durable JSONL ledger — this is the concrete caller-side persistence
 * that removes the "persistence layer responsabilidade do caller"
 * caveat from AP-704.
 *
 * Usage:
 *   php artisan atlas:forge:parallel-durable \
 *     --tickets=/tmp/tickets.json \
 *     --agents=/tmp/agents.json \
 *     --existing=/tmp/reservations.jsonl \
 *     --append-to=/tmp/reservations.jsonl \
 *     --json
 */
final class AtlasForgeParallelDurableCommand extends Command
{
    protected $signature = 'atlas:forge:parallel-durable
        {--tickets= : path to JSON file with the tickets list}
        {--agents= : path to JSON file with the agents list}
        {--existing= : optional JSONL file with existing reservations (one per line)}
        {--append-to= : optional JSONL file to append accepted assignments to (durable persistence)}
        {--json : machine-readable JSON output}';

    protected $description = 'Propose a Forge parallel-durable assignment from JSON inputs (AP-704).';

    public function handle(AtlasForgeParallelDurableCoordinatorService $coordinator): int
    {
        $tickets = $this->loadJsonArray('tickets');
        if ($tickets === null) {
            return self::FAILURE;
        }
        $agents = $this->loadJsonArray('agents');
        if ($agents === null) {
            return self::FAILURE;
        }
        $existing = $this->loadJsonlArray('existing') ?? [];

        $envelope = $coordinator->propose($tickets, $agents, $existing);

        $appendPath = (string) ($this->option('append-to') ?? '');
        if ($appendPath !== '' && $envelope['assignments'] !== []) {
            foreach ($envelope['assignments'] as $assignment) {
                $line = json_encode($assignment, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($line !== false) {
                    file_put_contents($appendPath, $line."\n", FILE_APPEND | LOCK_EX);
                }
            }
        }

        if ((bool) $this->option('json')) {
            $this->line(json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $this->info('Forge Parallel Durable Proposal');
        $this->line('  assignments: '.((int) $envelope['counts']['assignments']));
        $this->line('  unassigned: '.((int) $envelope['counts']['unassigned']));
        $this->line('  released: '.((int) $envelope['counts']['released']));
        $this->line('  proposal_hash: '.((string) $envelope['proposal_hash']));

        return self::SUCCESS;
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function loadJsonArray(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '' || ! is_file($path)) {
            $this->error("--{$optionName}=<path> required and file must exist");

            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            $this->error("unable to read {$path}");

            return null;
        }
        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            $this->error("{$path} must contain a JSON array");

            return null;
        }

        return array_values($decoded);
    }

    /**
     * @return list<array<string,mixed>>|null
     */
    private function loadJsonlArray(string $optionName): ?array
    {
        $path = (string) ($this->option($optionName) ?? '');
        if ($path === '') {
            return [];
        }
        if (! is_file($path)) {
            return [];
        }
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return [];
        }
        $out = [];
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded)) {
                $out[] = $decoded;
            }
        }

        return $out;
    }
}
