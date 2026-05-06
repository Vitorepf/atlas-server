<?php

namespace App\Services\Ai\Context;

final class ConversationContextInput
{
    public const DEFAULT_RECENT_TURN_LIMIT = 12;

    public const MIN_RECENT_TURN_LIMIT = 2;

    public const MAX_RECENT_TURN_LIMIT = 40;

    public const DEFAULT_PAYLOAD_TURN_LIMIT = 8;

    public const MIN_PAYLOAD_TURN_LIMIT = 1;

    public const MAX_PAYLOAD_TURN_LIMIT = 20;

    public function recentTurnLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.context_recent_turn_limit', self::DEFAULT_RECENT_TURN_LIMIT),
            self::DEFAULT_RECENT_TURN_LIMIT,
            self::MIN_RECENT_TURN_LIMIT,
            self::MAX_RECENT_TURN_LIMIT,
        );
    }

    public function payloadTurnLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.context_payload_turn_limit', self::DEFAULT_PAYLOAD_TURN_LIMIT),
            self::DEFAULT_PAYLOAD_TURN_LIMIT,
            self::MIN_PAYLOAD_TURN_LIMIT,
            self::MAX_PAYLOAD_TURN_LIMIT,
        );
    }

    private function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
