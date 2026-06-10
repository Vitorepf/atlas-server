<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

/**
 * AOBG N4.F1 — the BRAIN-RECORD seam (cross-domain compounding).
 *
 * Each domain proposal is recorded into the brain as a provider-safe node so the NEXT
 * cross-domain mission can SEE prior proposals (the M× across width — compounding across
 * domains, the same way N3 recorded obra outcomes via
 * {@see \App\Services\Ai\Reality\AtlasRealityGraphIngestionService::recordObraOutcome}).
 *
 * PRIVACY / SENSITIVE: the recorded node carries provider-safe LABELS + refs + the honest
 * validation NUMBERS only — never the proposal's on-machine `payload`, never source, never
 * secrets. A SENSITIVE proposal (finance et al.) is recorded with `sensitive => true` and
 * stays ON-MACHINE; it is never crossed to a provider.
 *
 * The production binding writes to the reality/AURG graph (fail-open: returns
 * recorded:false when the store is absent — honest, never fabricated). Tests inject a fake
 * that captures the node in-memory — sqlite-safe + cost-free.
 */
interface OrganismProposalRecorder
{
    /**
     * Record a validated proposal as a brain node.
     *
     * @param  array<string,mixed>  $validation  the honest-metric verdict (numbers only)
     * @return array{recorded:bool, node_ref:string, reason?:string}
     */
    public function record(DomainProposal $proposal, array $validation): array;

    /**
     * Prior cross-domain proposals the brain already holds — fed into the next mission's
     * context as `prior_proposals` (COMPOUNDING). Provider-safe labels only.
     *
     * @return list<array<string,mixed>>
     */
    public function priorProposals(?string $domain = null, int $limit = 10): array;
}
