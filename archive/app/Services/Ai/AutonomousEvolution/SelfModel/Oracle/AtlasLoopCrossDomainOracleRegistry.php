<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Oracle;

/**
 * CROSS-DOMAIN ORACLE REGISTRY — binds a domain name to its honest {@see AtlasLoopModelOutcomeOracle} so the
 * SAME recursive self-model machinery runs over ANY domain (code, marketing, finance, …) without knowing the
 * domain. Deterministic + fail-soft: an unregistered domain returns null (never throws); re-registering a
 * domain replaces it (last wins); domains() is sorted.
 */
final class AtlasLoopCrossDomainOracleRegistry
{
    /** @var array<string, AtlasLoopModelOutcomeOracle> domain => oracle */
    private array $oracles = [];

    public function register(AtlasLoopModelOutcomeOracle $oracle): void
    {
        $this->oracles[$oracle->domain()] = $oracle; // last wins for a given domain
    }

    public function oracleFor(string $domain): ?AtlasLoopModelOutcomeOracle
    {
        return $this->oracles[$domain] ?? null; // unregistered ⇒ null, never throws
    }

    /**
     * @return list<string>  registered domain names, sorted
     */
    public function domains(): array
    {
        $domains = array_keys($this->oracles);
        sort($domains, SORT_STRING);

        return $domains;
    }
}
