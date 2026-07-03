<?php

namespace App\Services\Ai\Rivals2\Support;

/** events.jsonl append-only por run: uma linha = um evento auditável. */
class EventStream
{
    public static function append(string $runId, string $eventType, array $data = []): void
    {
        RunPaths::ensureDir(RunPaths::runDir($runId));
        $line = json_encode([
            'timestamp' => now()->toIso8601String(),
            'event_type' => $eventType,
            'data' => $data,
        ], JSON_UNESCAPED_SLASHES);
        file_put_contents(RunPaths::eventsPath($runId), $line.PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
