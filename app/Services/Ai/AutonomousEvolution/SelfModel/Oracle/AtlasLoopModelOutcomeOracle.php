<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle;

/**
 * MODEL-OUTCOME ORACLE — the domain-agnostic leap: a pluggable scorer that turns a domain delivery into an
 * HONEST outcome score re-computable purely from the delivery's FACTS. Each domain (code, marketing, finance,
 * …) supplies its own oracle; the loop's self-model consumes the common contract without knowing the domain.
 *
 * INVARIANT (every implementation): the score is derived ONLY from objective delivery facts and NEVER from a
 * self-declared field (e.g. self_score) — a delivery cannot grade itself. When the facts an oracle needs are
 * absent, it returns grounded=false (score 0.0) rather than guessing.
 */
interface AtlasLoopModelOutcomeOracle
{
    /** The domain this oracle scores (e.g. "code"). */
    public function domain(): string;

    /**
     * Score one delivery from its facts.
     *
     * @param  array<string,mixed>  $delivery
     * @return array{schema:string, score:float, grounded:bool, basis:string}
     */
    public function scoreOutcome(array $delivery): array;
}
