<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Quaternity\IntentIngest;

/**
 * Immutable, typed record of a single malformed line in the operator intent stream. It carries the EXACT line
 * number (1-based within the parsed chunk) so a bad line can be located precisely — and, because the reader
 * collects these instead of throwing, a malformed line never corrupts the parsing of the lines that follow it.
 */
final class IntentParseError
{
    public function __construct(
        public readonly int $lineNumber,
        public readonly string $rawLine,
        public readonly string $reason,
    ) {}

    /**
     * @return array{line_number:int, raw_line:string, reason:string}
     */
    public function toArray(): array
    {
        return [
            'line_number' => $this->lineNumber,
            'raw_line' => $this->rawLine,
            'reason' => $this->reason,
        ];
    }
}
