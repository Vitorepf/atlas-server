<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Aael\Execution\Debugger;

final class InspectorSnapshot
{
    public const SCHEMA = 'atlas.aael.execution.debugger.inspector_snapshot.v1';

    /** @param array<string,mixed> $body */
    public function __construct(
        public readonly array $body,
        public readonly string $envelopeWallClockIso,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA,
            'body' => $this->body,
            'envelope' => [
                'wall_clock_iso' => $this->envelopeWallClockIso,
            ],
        ];
    }

    public function canonicalBytes(): string
    {
        return (string) json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
