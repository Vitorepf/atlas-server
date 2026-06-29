<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Trinity\ThreeWay;

/**
 * The result of a Trinity feedback audit for one cycle: the NEW facts each primitive produced vs the prior
 * cycle, whether the cycle was degenerate (static), and the named violations. Co-located with the auditor.
 */
final class TrinityFeedbackAuditResult
{
    /**
     * @param  list<array<string,mixed>>  $newLoopFacts
     * @param  list<array<string,mixed>>  $newCortexFacts
     * @param  list<array<string,mixed>>  $newMaestroFacts
     * @param  list<string>  $violations
     */
    public function __construct(
        public readonly string $cycleId,
        public readonly array $newLoopFacts,
        public readonly array $newCortexFacts,
        public readonly array $newMaestroFacts,
        public readonly bool $isStatic,
        public readonly array $violations,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'cycle_id' => $this->cycleId,
            'new_loop_fact_ids' => array_column($this->newLoopFacts, 'factId'),
            'new_cortex_fact_ids' => array_column($this->newCortexFacts, 'factId'),
            'new_maestro_fact_ids' => array_column($this->newMaestroFacts, 'factId'),
            'is_static' => $this->isStatic,
            'violations' => $this->violations,
        ];
    }
}

/**
 * TRINITY FEEDBACK AUDITOR — the ANTI-COSMETIC sentinel proving the three-way coupling is RECURSIVE, not
 * decorative. Consuming the merger's TrinityFactStream, it asserts every completed cycle produced at least one
 * NEW FACT in EACH primitive (Loop, Cortex, Maestro) — "new" iff the factId did not appear in the prior cycle.
 * A primitive with zero new facts makes the cycle STATIC (degenerate) and is flagged
 * 'static_cycle_violation:<primitive>'.
 *
 * RECURSIVE: each audit result is itself persisted as a TrinityFact seeded for the NEXT cycle's Loop emission
 * (the audit IS a fact the loop consumes), so the coupling closes on itself.
 */
final class AtlasLoopTrinityFeedbackAuditor
{
    /** @var list<array<string,mixed>> audit facts seeded for the next cycle */
    private array $seed = [];

    /**
     * @param  list<array<string,mixed>>  $stream  TrinityFacts from AtlasLoopTrinityFactStreamMerger::stream()
     */
    public function __construct(private readonly array $stream)
    {
    }

    public function audit(string $cycleId): TrinityFeedbackAuditResult
    {
        $priorCycleId = $this->priorCycleId($cycleId);
        $priorIds = $priorCycleId === null ? [] : $this->factIdsOfCycle($priorCycleId);

        $current = $this->factsOfCycleBySource($cycleId);
        $newLoop = $this->newFacts($current['loop'] ?? [], $priorIds);
        $newCortex = $this->newFacts($current['cortex'] ?? [], $priorIds);
        $newMaestro = $this->newFacts($current['maestro'] ?? [], $priorIds);

        $violations = [];
        if ($newLoop === []) {
            $violations[] = 'static_cycle_violation:loop';
        }
        if ($newCortex === []) {
            $violations[] = 'static_cycle_violation:cortex';
        }
        if ($newMaestro === []) {
            $violations[] = 'static_cycle_violation:maestro';
        }

        $result = new TrinityFeedbackAuditResult($cycleId, $newLoop, $newCortex, $newMaestro, $violations !== [], $violations);

        // RECURSIVE: persist the audit itself as a TrinityFact seeded for the next cycle's Loop emission.
        $payload = $result->toArray();
        $this->seed[] = [
            'factId' => hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES).'|'.$cycleId.'|audit'),
            'source' => 'loop',
            'cycleId' => $cycleId,
            'payload' => $payload,
            'parentFactIds' => array_merge(
                array_column($newLoop, 'factId'),
                array_column($newCortex, 'factId'),
                array_column($newMaestro, 'factId'),
            ),
        ];

        return $result;
    }

    /**
     * The audit facts seeded for the next cycle (the recursive closure).
     *
     * @return list<array<string,mixed>>
     */
    public function seedFacts(): array
    {
        return $this->seed;
    }

    /**
     * @param  list<array<string,mixed>>  $facts
     * @param  array<string,bool>  $priorContentKeys
     * @return list<array<string,mixed>>
     */
    private function newFacts(array $facts, array $priorContentKeys): array
    {
        return array_values(array_filter($facts, static fn (array $f): bool => ! isset($priorContentKeys[self::contentKey($f)])));
    }

    private static function contentKey(array $fact): string
    {
        return hash('sha256', (string) json_encode($fact['payload'] ?? null, JSON_UNESCAPED_SLASHES).'|'.(string) ($fact['source'] ?? ''));
    }

    private function priorCycleId(string $cycleId): ?string
    {
        $order = [];
        foreach ($this->stream as $fact) {
            $cid = (string) ($fact['cycleId'] ?? '');
            if ($cid !== '' && ! in_array($cid, $order, true)) {
                $order[] = $cid;
            }
        }
        $pos = array_search($cycleId, $order, true);

        return is_int($pos) && $pos > 0 ? $order[$pos - 1] : null;
    }

    /**
     * @return array<string,bool> content-keyed (cycleId-independent) — not factId-keyed
     */
    private function factIdsOfCycle(string $cycleId): array
    {
        $keys = [];
        foreach ($this->stream as $fact) {
            if ((string) ($fact['cycleId'] ?? '') === $cycleId) {
                $keys[self::contentKey($fact)] = true;
            }
        }

        return $keys;
    }

    /**
     * @return array<string,list<array<string,mixed>>>
     */
    private function factsOfCycleBySource(string $cycleId): array
    {
        $bySource = [];
        foreach ($this->stream as $fact) {
            if ((string) ($fact['cycleId'] ?? '') === $cycleId) {
                $bySource[(string) ($fact['source'] ?? '')][] = $fact;
            }
        }

        return $bySource;
    }
}
