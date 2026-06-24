<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

use RuntimeException;

/**
 * Thrown when a merged FACT breaks the Trinity lineage invariant (a Cortex FACT with no Loop parent, or a
 * Maestro FACT with no Cortex parent). Co-located with the merger so the single allowed_file owns the contract.
 */
final class TrinityLineageBrokenException extends RuntimeException
{
}

/**
 * TRINITY 3-WAY FACT-STREAM MERGER — fuses the FACT streams of Loop (origination), Cortex (enrichment), and
 * Maestro (reshape) into ONE canonical TrinityFact stream {factId, source, cycleId, payload, parentFactIds}.
 *
 * The recursive-coupling PROOF is lineage: every Cortex FACT MUST reference at least one Loop parent FACT, and
 * every Maestro FACT MUST reference at least one Cortex parent — fail-closed (throws
 * {@see TrinityLineageBrokenException}) on any break. factId = sha256(canonical payload | cycleId | source),
 * deterministic. Emission order is preserved: Loop, then Cortex, then Maestro. The merger IS the stream.
 */
final class AtlasLoopTrinityFactStreamMerger
{
    /** @var list<array<string,mixed>> */
    private array $facts = [];

    /**
     * @param  list<array<string,mixed>>  $loopFacts
     * @param  list<array<string,mixed>>  $cortexFacts
     * @param  list<array<string,mixed>>  $maestroFacts
     */
    public function merge(array $loopFacts, array $cortexFacts, array $maestroFacts): self
    {
        $this->facts = [];

        $loopIds = [];
        foreach ($loopFacts as $fact) {
            $trinity = $this->canonicalFact($fact, 'loop', $this->parentIds($fact)); // Loop facts are roots; parents optional
            $loopIds[$trinity['factId']] = true;
            $this->facts[] = $trinity;
        }

        $cortexIds = [];
        foreach ($cortexFacts as $fact) {
            $parents = $this->parentIds($fact);
            if (array_intersect($parents, array_keys($loopIds)) === []) {
                throw new TrinityLineageBrokenException('Cortex FACT has no Loop parent in lineage: cycleId='.$this->cycleId($fact));
            }
            $trinity = $this->canonicalFact($fact, 'cortex', $parents);
            $cortexIds[$trinity['factId']] = true;
            $this->facts[] = $trinity;
        }

        foreach ($maestroFacts as $fact) {
            $parents = $this->parentIds($fact);
            if (array_intersect($parents, array_keys($cortexIds)) === []) {
                throw new TrinityLineageBrokenException('Maestro FACT has no Cortex parent in lineage: cycleId='.$this->cycleId($fact));
            }
            $this->facts[] = $this->canonicalFact($fact, 'maestro', $parents);
        }

        return $this;
    }

    /**
     * @return iterable<array<string,mixed>>
     */
    public function stream(): iterable
    {
        return $this->facts;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public static function computeFactId(array $payload, string $cycleId, string $source): string
    {
        return hash('sha256', (string) json_encode(self::canonicalize($payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).'|'.$cycleId.'|'.$source);
    }

    /**
     * @param  array<string,mixed>  $fact
     * @param  list<string>  $parents
     * @return array<string,mixed>
     */
    private function canonicalFact(array $fact, string $source, array $parents): array
    {
        $cycleId = $this->cycleId($fact);
        $payload = (array) ($fact['payload'] ?? []);

        return [
            'factId' => self::computeFactId($payload, $cycleId, $source),
            'source' => $source,
            'cycleId' => $cycleId,
            'payload' => $payload,
            'parentFactIds' => $parents,
        ];
    }

    private function cycleId(array $fact): string
    {
        return (string) ($fact['cycleId'] ?? ($fact['cycle_id'] ?? ''));
    }

    /**
     * @param  array<string,mixed>  $fact
     * @return list<string>
     */
    private function parentIds(array $fact): array
    {
        $raw = (array) ($fact['parentFactIds'] ?? ($fact['parent_fact_ids'] ?? []));

        return array_values(array_filter(array_map(static fn ($id): string => (string) $id, $raw), static fn (string $id): bool => $id !== ''));
    }

    private static function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        $isList = array_is_list($value);
        $out = [];
        foreach ($value as $k => $v) {
            $out[$k] = self::canonicalize($v);
        }
        if (! $isList) {
            ksort($out);
        }

        return $out;
    }
}
