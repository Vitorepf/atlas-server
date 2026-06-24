<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Insights;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use JsonSerializable;

final class AtlasCortexInsightObservation implements JsonSerializable
{
    /**
     * @param  array<string,mixed>  $facts
     * @param  list<string>  $witnesses
     */
    public function __construct(
        public readonly string $axisId,
        public readonly string $observationKind,
        public readonly string $noticedAt,
        public readonly array $facts,
        public readonly array $witnesses = [],
    ) {}

    /**
     * @return array{
     *     axis_id:string,
     *     observation_kind:string,
     *     noticed_at:string,
     *     facts:array<string,mixed>,
     *     witnesses:list<string>
     * }
     */
    public function toArray(): array
    {
        return [
            'axis_id' => $this->axisId,
            'observation_kind' => $this->observationKind,
            'noticed_at' => $this->noticedAt,
            'facts' => $this->facts,
            'witnesses' => $this->witnesses,
        ];
    }

    /**
     * @return array{
     *     axis_id:string,
     *     observation_kind:string,
     *     noticed_at:string,
     *     facts:array<string,mixed>,
     *     witnesses:list<string>
     * }
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }

    public static function normalizeTimestamp(?string $value = null): string
    {
        $timestamp = $value === null || trim($value) === ''
            ? new DateTimeImmutable('now', new DateTimeZone('UTC'))
            : new DateTimeImmutable($value);

        return $timestamp
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }
}

final class AtlasCortexInsightObservationFactException extends InvalidArgumentException
{
    /**
     * @param  list<string>  $missingKeys
     */
    public static function missing(string $observer, array $missingKeys): self
    {
        sort($missingKeys, SORT_STRING);

        return new self(sprintf(
            '%s missing required fact keys: %s',
            $observer,
            implode(', ', $missingKeys),
        ));
    }
}
