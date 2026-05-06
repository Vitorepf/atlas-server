<?php

namespace App\Services\Ai\Context;

final class SemanticContextInput
{
    public const DEFAULT_CONTEXT_NOTE_LIMIT = 5;

    public const MAX_CONTEXT_NOTE_LIMIT = 30;

    public const DEFAULT_CONTEXT_EXCERPT_CHARS = 1200;

    public const MIN_CONTEXT_EXCERPT_CHARS = 120;

    public const MAX_CONTEXT_EXCERPT_CHARS = 8000;

    public function contextNoteLimit(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.context_note_limit', self::DEFAULT_CONTEXT_NOTE_LIMIT),
            self::DEFAULT_CONTEXT_NOTE_LIMIT,
            0,
            self::MAX_CONTEXT_NOTE_LIMIT,
        );
    }

    public function contextExcerptChars(mixed $value = null): int
    {
        return $this->limit(
            $value ?? config('atlas.ai.context_excerpt_chars', self::DEFAULT_CONTEXT_EXCERPT_CHARS),
            self::DEFAULT_CONTEXT_EXCERPT_CHARS,
            self::MIN_CONTEXT_EXCERPT_CHARS,
            self::MAX_CONTEXT_EXCERPT_CHARS,
        );
    }

    public function limit(mixed $value, int $default, int $min, int $max): int
    {
        if (! is_numeric($value)) {
            $value = $default;
        }

        return max($min, min($max, (int) $value));
    }
}
