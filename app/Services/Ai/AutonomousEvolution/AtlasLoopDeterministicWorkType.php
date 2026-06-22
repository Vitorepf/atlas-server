<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * A DETERMINISTIC, provider-LESS loop work-type: it MILLS→AUTHORS→CERTIFIES a real, value-bearing change to
 * one file with ZERO provider calls (so it is the hermes-free path to a live certified delivery). Implemented
 * by the surgical removers ({@see AtlasLoopDeterministicDeadCodeWorkType} dead private members,
 * {@see AtlasLoopUnusedImportWorkType} unused imports, …). The single {@see AtlasLoopDeadCodeProducer}
 * persists ANY of them — so arming a new deterministic work-type is mostly free (implement this one method).
 */
interface AtlasLoopDeterministicWorkType
{
    /**
     * Produce a CERTIFIED removal proposal for one file, or null (fail-closed: nothing to do / author refused
     * / cert refused). The shape is the contract the producer + scorecard consume.
     *
     * @return array{rel_path:string, original:string, proposed:string, removed:list<mixed>, certified:true, provider_used:false}|null
     */
    public function produceCertifiedRemoval(string $repoRoot, string $relPath): ?array;
}
