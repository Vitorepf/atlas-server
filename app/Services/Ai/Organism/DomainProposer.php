<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the per-domain PROPOSE seam (the cost-free test boundary).
 *
 * This is the N4 generalization of {@see \App\Services\Ai\Obra\ObraNodeDelivery}: where
 * N3's per-node delivery turned a step into CERTIFIED files, N4's per-node proposer
 * turns an intent into a {@see DomainProposal} for ONE domain (a trade idea, a campaign
 * draft, an audit plan). The single responsibility is "intent + brain context → a
 * domain proposal".
 *
 * COST: a REAL proposer MAY call a provider (the finance one runs on-machine; a future
 * marketing one might draft via a provider) — so it is always GATED and, in tests,
 * STUBBED. The {@see AtlasOrganismService} never knows whether the proposal came from a
 * provider, a deterministic generator, or a fixture — it only consumes a DomainProposal.
 * This is what makes the whole organism provable cost-free.
 *
 * SENSITIVE: a proposer for a sensitive/secret/cyber domain (finance is sensitive) MUST
 * keep generation ON-MACHINE — it must NOT cross the intent or any payload to an external
 * provider. The organism additionally refuses to provider-serialize a sensitive proposal,
 * so a buggy proposer cannot leak it downstream.
 */
interface DomainProposer
{
    /**
     * Generate ONE domain proposal for an intent, anchored to the supplied brain context.
     *
     * @param  string  $intent  the operator intent / cross-domain node this answers
     * @param  array<string,mixed>  $brainContext  the curated, provider-safe context pack
     *         the organism assembled (see {@see \App\Services\Ai\AtlasOpenBrainContextPackService}):
     *         {code_graph?, reality_graph_paths?, memory?, prior_proposals?, ...}.
     *         `prior_proposals` carries earlier cross-domain proposals → COMPOUNDING.
     * @param  array<string,mixed>  $opts  per-domain options {domain?, payload?, ...}
     * @return DomainProposal the proposal (provider-safe content + on-machine payload)
     */
    public function propose(string $intent, array $brainContext = [], array $opts = []): DomainProposal;

    /** The canonical domain id this proposer serves (e.g. "finance"). */
    public function domain(): string;

    /**
     * A short, stable label recorded into the result so a reader knows HOW the proposal
     * was generated (provider vs deterministic vs stub) without re-running it.
     */
    public function label(): string;
}
