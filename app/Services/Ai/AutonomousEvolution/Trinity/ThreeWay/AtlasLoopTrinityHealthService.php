<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

use RuntimeException;

/**
 * Thrown when the Trinity health service is asked to compute over a window larger than the chain history it has,
 * or over a malformed chain entry — it FAILS CLOSED rather than fabricating latency/coverage numbers from thin
 * air. Co-located with the service so the single allowed_file owns the contract.
 */
final class TrinityChainMissingException extends RuntimeException
{
}

/**
 * The FACTs the trinity computes ABOUT ITSELF for one health window: per-primitive latency (p50/p95),
 * per-primitive drift (fraction of cycles a primitive produced zero new facts), mutual coverage (fraction of
 * Loop facts with both a Cortex and a Maestro descendant), and chain-integrity. Purely descriptive — nothing is
 * scored, ranked or averaged into a verdict. Co-located with the service.
 */
final class TrinityHealthSnapshot
{
    /**
     * @param  array<string,float>  $latencyP50  per-primitive (loop/cortex/maestro) median latency
     * @param  array<string,float>  $latencyP95  per-primitive 95th-percentile latency
     * @param  array<string,float>  $drift       per-primitive fraction of windowed cycles with zero new facts
     * @param  list<string>  $parentFactIds       the last cycle's Loop+Maestro factIds (lineage for the next cycle)
     */
    public function __construct(
        public readonly int $window,
        public readonly string $cycleId,
        public readonly array $latencyP50,
        public readonly array $latencyP95,
        public readonly array $drift,
        public readonly float $mutualCoverage,
        public readonly bool $chainIntegrityOk,
        public readonly array $parentFactIds,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'window' => $this->window,
            'cycle_id' => $this->cycleId,
            'latency_p50' => $this->latencyP50,
            'latency_p95' => $this->latencyP95,
            'drift' => $this->drift,
            'mutual_coverage' => $this->mutualCoverage,
            'chain_integrity_ok' => $this->chainIntegrityOk,
        ];
    }

    /**
     * The snapshot shaped as a Cortex TrinityFact for the NEXT cycle's merge — source=cortex (it is enrichment
     * ABOUT the trinity) with parents referencing the last cycle's Loop+Maestro facts. This is the recursive
     * input: the trinity inspects itself and the inspection re-enters the stream.
     *
     * @return array<string,mixed>
     */
    public function toCortexFact(): array
    {
        return [
            'source' => 'cortex',
            'cycleId' => $this->cycleId,
            'payload' => $this->toArray(),
            'parentFactIds' => $this->parentFactIds,
        ];
    }
}

/**
 * TRINITY HEALTH SERVICE — the THIRD recursion: the trinity turns its OWN behavior into FACTs (trinity audits
 * trinity). Over the last N cycles of the receipt chain history it computes per-primitive latency (p50/p95),
 * per-primitive drift (how often a primitive emitted zero NEW facts vs the prior cycle), mutual coverage (the
 * fraction of Loop facts that have BOTH a Cortex descendant AND — through that Cortex — a Maestro descendant,
 * traced via parentFactIds), and chain-integrity status.
 *
 * It then emits the snapshot as a source=cortex TrinityFact into the merger (duck-typed: any object exposing
 * append(array)) so the self-inspection feeds the next cycle — closing the recursion. FAILS CLOSED
 * ({@see TrinityChainMissingException}) when fewer than `window` entries exist or an entry is malformed, instead
 * of fabricating numbers.
 *
 * The cycle history is the receipt chain ENRICHED with what the chain proves happened: each entry is
 * ['cycleId'=>string, 'integrityOk'=>bool, 'startedAt'=>float, 'completedAt'=>['loop'|'cortex'|'maestro'=>float],
 * 'facts'=>list<['factId'=>string,'source'=>string,'parentFactIds'=>list<string>]>], ordered oldest→newest.
 */
final class AtlasLoopTrinityHealthService
{
    private const PRIMITIVES = ['loop', 'cortex', 'maestro'];

    /**
     * @param  list<array<string,mixed>>  $cycles  receipt-chain cycle history, oldest→newest
     * @param  object|null  $merger  duck-typed sink exposing append(array $fact): void (the next-cycle merger)
     */
    public function __construct(
        private readonly array $cycles,
        private readonly ?object $merger = null,
    ) {
    }

    public function health(int $window = 20): TrinityHealthSnapshot
    {
        if ($window < 1) {
            throw new TrinityChainMissingException('Trinity health window must be >= 1, got '.$window);
        }
        if (count($this->cycles) < $window) {
            throw new TrinityChainMissingException(sprintf('Trinity chain has %d entries, fewer than window %d', count($this->cycles), $window));
        }

        $windowed = array_slice($this->cycles, -$window);
        $this->assertWellFormed($windowed);

        $latency = $this->latencyPercentiles($windowed);
        $snapshot = new TrinityHealthSnapshot(
            $window,
            (string) $windowed[array_key_last($windowed)]['cycleId'],
            $latency['p50'],
            $latency['p95'],
            $this->drift($windowed),
            $this->mutualCoverage($windowed),
            $this->chainIntegrityOk($windowed),
            $this->lastCycleParentFactIds($windowed),
        );

        $this->merger?->append($snapshot->toCortexFact());

        return $snapshot;
    }

    /**
     * @param  list<array<string,mixed>>  $windowed
     */
    private function assertWellFormed(array $windowed): void
    {
        foreach ($windowed as $cycle) {
            $completed = $cycle['completedAt'] ?? null;
            if (! isset($cycle['startedAt']) || ! is_array($completed) || ! array_key_exists('facts', $cycle)) {
                throw new TrinityChainMissingException('Malformed Trinity chain entry: cycleId='.(string) ($cycle['cycleId'] ?? '?'));
            }
            foreach (self::PRIMITIVES as $primitive) {
                if (! isset($completed[$primitive])) {
                    throw new TrinityChainMissingException(sprintf('Trinity chain entry %s missing completedAt[%s]', (string) ($cycle['cycleId'] ?? '?'), $primitive));
                }
            }
        }
    }

    /**
     * @param  list<array<string,mixed>>  $windowed
     * @return array{p50:array<string,float>,p95:array<string,float>}
     */
    private function latencyPercentiles(array $windowed): array
    {
        $byPrimitive = ['loop' => [], 'cortex' => [], 'maestro' => []];
        foreach ($windowed as $cycle) {
            $start = (float) $cycle['startedAt'];
            foreach (self::PRIMITIVES as $primitive) {
                $byPrimitive[$primitive][] = (float) $cycle['completedAt'][$primitive] - $start;
            }
        }

        $p50 = [];
        $p95 = [];
        foreach (self::PRIMITIVES as $primitive) {
            $p50[$primitive] = $this->percentile($byPrimitive[$primitive], 50);
            $p95[$primitive] = $this->percentile($byPrimitive[$primitive], 95);
        }

        return ['p50' => $p50, 'p95' => $p95];
    }

    /**
     * Nearest-rank percentile — deterministic, no interpolation.
     *
     * @param  list<float>  $values
     */
    private function percentile(array $values, int $p): float
    {
        if ($values === []) {
            return 0.0;
        }
        sort($values);
        $rank = (int) ceil(($p / 100) * count($values));
        $rank = max(1, min($rank, count($values)));

        return $values[$rank - 1];
    }

    /**
     * Per-primitive drift: the fraction of windowed cycles in which the primitive produced ZERO new factIds vs
     * the immediately preceding cycle (in the full history, so the first windowed cycle still has a prior when
     * one exists). The earliest cycle of all has no prior ⇒ all its facts are new (no drift charged).
     *
     * @param  list<array<string,mixed>>  $windowed
     * @return array<string,float>
     */
    private function drift(array $windowed): array
    {
        $firstWindowIndex = count($this->cycles) - count($windowed);

        $events = ['loop' => 0, 'cortex' => 0, 'maestro' => 0];
        foreach ($windowed as $offset => $cycle) {
            $absoluteIndex = $firstWindowIndex + $offset;
            $priorIds = $absoluteIndex > 0 ? $this->factIdsBySource($this->cycles[$absoluteIndex - 1]) : ['loop' => [], 'cortex' => [], 'maestro' => []];
            $currentIds = $this->factIdsBySource($cycle);

            foreach (self::PRIMITIVES as $primitive) {
                $new = array_diff($currentIds[$primitive], $priorIds[$primitive] ?? []);
                if ($new === []) {
                    $events[$primitive]++;
                }
            }
        }

        $count = count($windowed);
        $drift = [];
        foreach (self::PRIMITIVES as $primitive) {
            $drift[$primitive] = $count > 0 ? (float) $events[$primitive] / $count : 0.0;
        }

        return $drift;
    }

    /**
     * Mutual coverage: fraction of distinct Loop factIds (across the window) that have a Cortex descendant AND,
     * through that Cortex, a Maestro descendant — traced via parentFactIds (Cortex⇒Loop, Maestro⇒Cortex).
     *
     * @param  list<array<string,mixed>>  $windowed
     */
    private function mutualCoverage(array $windowed): float
    {
        $loopIds = [];
        $cortex = [];   // factId => list<parent factId>
        $maestro = [];  // list of parent-factId lists
        foreach ($windowed as $cycle) {
            foreach ($this->facts($cycle) as $fact) {
                $factId = (string) ($fact['factId'] ?? '');
                $source = (string) ($fact['source'] ?? '');
                $parents = array_map('strval', (array) ($fact['parentFactIds'] ?? []));
                if ($source === 'loop' && $factId !== '') {
                    $loopIds[$factId] = true;
                } elseif ($source === 'cortex' && $factId !== '') {
                    $cortex[$factId] = $parents;
                } elseif ($source === 'maestro') {
                    $maestro[] = $parents;
                }
            }
        }

        if ($loopIds === []) {
            return 0.0;
        }

        $covered = 0;
        foreach (array_keys($loopIds) as $loopId) {
            $cortexKids = [];
            foreach ($cortex as $cortexId => $parents) {
                if (in_array((string) $loopId, $parents, true)) {
                    $cortexKids[] = (string) $cortexId;
                }
            }
            if ($cortexKids === []) {
                continue; // no Cortex descendant
            }
            foreach ($maestro as $parents) {
                if (array_intersect($cortexKids, $parents) !== []) {
                    $covered++;
                    break; // has both a Cortex and (through it) a Maestro descendant
                }
            }
        }

        return (float) $covered / count($loopIds);
    }

    /**
     * @param  list<array<string,mixed>>  $windowed
     */
    private function chainIntegrityOk(array $windowed): bool
    {
        foreach ($windowed as $cycle) {
            if (($cycle['integrityOk'] ?? true) !== true) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  list<array<string,mixed>>  $windowed
     * @return list<string>
     */
    private function lastCycleParentFactIds(array $windowed): array
    {
        $last = $windowed[array_key_last($windowed)];
        $ids = [];
        foreach ($this->facts($last) as $fact) {
            $source = (string) ($fact['source'] ?? '');
            $factId = (string) ($fact['factId'] ?? '');
            if ($factId !== '' && ($source === 'loop' || $source === 'maestro')) {
                $ids[] = $factId;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return array<string,list<string>>
     */
    private function factIdsBySource(array $cycle): array
    {
        $ids = ['loop' => [], 'cortex' => [], 'maestro' => []];
        foreach ($this->facts($cycle) as $fact) {
            $source = (string) ($fact['source'] ?? '');
            if (isset($ids[$source])) {
                $ids[$source][] = (string) ($fact['factId'] ?? '');
            }
        }

        return $ids;
    }

    /**
     * @param  array<string,mixed>  $cycle
     * @return list<array<string,mixed>>
     */
    private function facts(array $cycle): array
    {
        $facts = $cycle['facts'] ?? [];

        return is_array($facts) ? array_values($facts) : [];
    }
}
