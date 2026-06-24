<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Telemetry;

use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final class AtlasLoopTelemetryFactStreamEmitter
{
    private const SCHEMA_VERSION = 'atlas.loop.telemetry.fact.v1';

    /** @var array<int, true> */
    private const ALLOWED_KINDS = [
        'claim' => true,
        'lease' => true,
        'serve' => true,
        'report' => true,
        'merge' => true,
    ];

    /** @var list<array{cycle_id:string,kind:string,occurred_at_iso:string,scope:string,schema_version:string,payload:array<string,mixed>}> */
    private array $facts = [];

    /** @var \Closure(array{cycle_id:string,kind:string,occurred_at_iso:string,scope:string,schema_version:string,payload:array<string,mixed>}): void */
    private \Closure $sink;

    /** @var \Closure(): DateTimeImmutable */
    private \Closure $clock;

    public function __construct(?callable $sink = null, ?callable $clock = null)
    {
        $this->sink = $sink instanceof \Closure ? $sink : \Closure::fromCallable($sink ?? static fn (array $fact): null => null);
        $this->clock = $clock instanceof \Closure ? $clock : \Closure::fromCallable($clock ?? static fn (): DateTimeImmutable => new DateTimeImmutable('now', new DateTimeZone('UTC')));
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{cycle_id:string,kind:string,occurred_at_iso:string,scope:string,schema_version:string,payload:array<string,mixed>}|null
     */
    public function emit(string $kind, array $payload, string $cycleId): ?array
    {
        $kind = trim($kind);
        $cycleId = trim($cycleId);

        if (! isset(self::ALLOWED_KINDS[$kind]) || $cycleId === '' || $this->containsForbiddenMetricKeys($payload)) {
            return null;
        }

        $fact = [
            'cycle_id' => $cycleId,
            'kind' => $kind,
            'occurred_at_iso' => ($this->clock)()->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM),
            'scope' => trim((string) ($payload['scope'] ?? 'global')),
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => $payload,
        ];

        $this->facts[] = $fact;

        try {
            ($this->sink)($fact);
        } catch (Throwable) {
            // Fail-open by contract: transport issues must never unwind the loop cycle.
        }

        return $fact;
    }

    /**
     * @return list<array{cycle_id:string,kind:string,occurred_at_iso:string,scope:string,schema_version:string,payload:array<string,mixed>}>
     */
    public function listFacts(): array
    {
        return $this->facts;
    }

    /**
     * @param  array<string,mixed>|list<mixed>  $payload
     */
    private function containsForbiddenMetricKeys(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && preg_match('/(?:score|rank)/i', $key) === 1) {
                return true;
            }

            if (is_array($value) && $this->containsForbiddenMetricKeys($value)) {
                return true;
            }
        }

        return false;
    }
}
