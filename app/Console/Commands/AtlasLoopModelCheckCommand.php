<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\AutonomousEvolution\ModelCheck\AtlasLoopCycleModelCheckCli;
use Illuminate\Console\Command;

/**
 * Operator surface for the Loop cycle model-check.
 *   atlas:loop:model-check extract  [--json]
 *   atlas:loop:model-check deadlock [--json]
 *   atlas:loop:model-check history  [--limit=N] [--json]
 *
 * Write paths refuse silently when atlas.loop.master_enabled is false.
 */
final class AtlasLoopModelCheckCommand extends Command
{
    public const EXIT_OK = 0;

    public const EXIT_USAGE = 2;

    public const EXIT_REFUSED = 3;

    protected $signature = 'atlas:loop:model-check {action : extract|deadlock|history}
        {--limit=20}
        {--json}';

    protected $description = 'Loop cycle model-check CLI (extract | deadlock | history).';

    public function handle(AtlasLoopCycleModelCheckCli $cli): int
    {
        $action = (string) $this->argument('action');
        $result = match ($action) {
            'extract' => $cli->extract(),
            'deadlock' => $cli->deadlock(),
            'history' => $cli->history((int) ($this->option('limit') ?? 20)),
            default => null,
        };
        if ($result === null) {
            return $this->refuse('unknown_action:'.$action);
        }
        if (($result['refused'] ?? false) === true) {
            $this->emit($result);

            return self::EXIT_REFUSED;
        }
        $this->emit($result);

        return self::EXIT_OK;
    }

    /**
     * @param  array<int|string,mixed>  $payload
     */
    private function emit(array $payload): void
    {
        // JSON is the only mode (single-line for --json contract); table mode is plain --json off.
        $line = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $this->getOutput()->writeln($line);
    }

    private function refuse(string $reason): int
    {
        $this->emit(['refused' => true, 'reason' => $reason]);

        return self::EXIT_USAGE;
    }
}
