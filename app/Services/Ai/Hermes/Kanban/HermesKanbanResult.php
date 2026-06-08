<?php

namespace App\Services\Ai\Hermes\Kanban;

/**
 * Outcome of one `hermes kanban …` invocation. Pure data; no behaviour. The
 * decoded `json` is whatever `--json` emitted (object, or one-object-per-line
 * decoded into a list), so the orchestrator never re-parses human stdout.
 */
class HermesKanbanResult
{
    /**
     * @param  array<string,mixed>|array<int,mixed>  $json
     */
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $exitCode,
        public readonly string $stdout,
        public readonly string $stderr,
        public readonly array $json = [],
    ) {}
}
