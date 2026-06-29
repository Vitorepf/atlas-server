<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\LoopIntentDrift;

use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\AtlasLoopOperatorIntentStreamReader;
use App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest\OperatorIntentMessage;

/**
 * Production {@see AtlasLoopOperatorIntentSource}. REUSES the existing
 * {@see AtlasLoopOperatorIntentStreamReader} (one byte-deterministic ingest pipeline — no second parser) and
 * returns its last $limit messages as plain arrays, oldest → newest, the shape the drift detector consumes.
 */
final class AtlasLoopIntentStreamOperatorIntentSource implements AtlasLoopOperatorIntentSource
{
    public function __construct(private readonly AtlasLoopOperatorIntentStreamReader $reader) {}

    /**
     * @return list<array<string,mixed>>
     */
    public function recent(int $limit): array
    {
        $limit = max(0, $limit);
        if ($limit === 0) {
            return [];
        }

        $messages = $this->reader->read();        // oldest → newest, deterministic across reads
        $window = array_slice($messages, -$limit); // last N, order preserved

        return array_map(static fn (OperatorIntentMessage $message): array => $message->toArray(), $window);
    }
}
