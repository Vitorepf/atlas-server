<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\SelfModel\Training;

/**
 * CROSS-DOMAIN TRAINING SAMPLER — builds a balanced training sample across domains so no single domain can
 * dominate the self-model corpus. Deterministic by construction: domains are processed in ALPHABETICAL order
 * and each contributes at most $perDomainCap of its FIRST examples. Pure — no I/O.
 */
final class AtlasLoopCrossDomainTrainingSampler
{
    public const SCHEMA = 'atlas.loop.cross_domain_sample.v1';

    /**
     * @param  array<string, list<mixed>>  $examplesByDomain  domain => examples
     * @return array{schema:string, sampled:list<mixed>, per_domain_counts:array<string,int>, total:int}
     */
    public function sample(array $examplesByDomain, int $perDomainCap): array
    {
        $cap = max(0, $perDomainCap);

        $domains = array_map('strval', array_keys($examplesByDomain));
        sort($domains, SORT_STRING); // alphabetical, for determinism

        $sampled = [];
        $counts = [];
        foreach ($domains as $domain) {
            $taken = array_slice(array_values((array) $examplesByDomain[$domain]), 0, $cap);
            foreach ($taken as $example) {
                $sampled[] = $example;
            }
            $counts[$domain] = count($taken);
        }

        return [
            'schema' => self::SCHEMA,
            'sampled' => $sampled,
            'per_domain_counts' => $counts,
            'total' => count($sampled),
        ];
    }
}
