<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * A real signal back to the operator: the message was too vague to act on, naming WHICH axis failed so the
 * operator can sharpen it — a missing verb, a missing object, or both.
 */
final class VagueIntentRejection
{
    public const AXIS_VERB_MISSING = 'verb_missing';

    public const AXIS_OBJECT_MISSING = 'object_missing';

    public const AXIS_BOTH = 'both';

    public function __construct(public readonly string $axis) {}

    public static function verbMissing(): self
    {
        return new self(self::AXIS_VERB_MISSING);
    }

    public static function objectMissing(): self
    {
        return new self(self::AXIS_OBJECT_MISSING);
    }

    public static function both(): self
    {
        return new self(self::AXIS_BOTH);
    }
}
