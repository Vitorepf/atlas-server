<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Ports;

/**
 * Foundry · Frontier Generator Port (AP-C, generator_port).
 *
 * AP-C INVIOLABLE RULES enforced by this port + its impls:
 *  - PROPOSAL-ONLY: this port emits candidate proposals ONLY. It NEVER writes
 *    canon/docs/code, NEVER merges, NEVER executes, NEVER auto-approves. Nothing
 *    it returns is canonical — survivors are admitted to a curation inbox by the
 *    orchestrator as pending_operator_review.
 *  - REAL-OR-BLOCKED: the REAL impl (AtlasDecideFrontierGeneratorService) calls a
 *    premium provider via the ProviderDriver seam. The driver ceiling today is
 *    prepare_only (no provider real execution), so the real generator returns
 *    'generated' content NEVER and BLOCKS honestly with the single blocker
 *    'premium_provider_real_execution_bridge_missing'. It NEVER fabricates.
 *  - DOSSIER-ONLY INPUT: generate() receives ONLY the Harvester dossier (plus a
 *    count + a context bag for resolved-identity handoff). No raw input / ledger
 *    leakage.
 *  - The FIXTURE impl (DeterministicFixtureFrontierGeneratorService) is TEST-ONLY:
 *    its generator_label always starts with 'fixture:' (never 'real:'), so the
 *    orchestrator can refuse fixture-labelled proposals in production.
 *
 * Canonical proposal identity (SINGLE source of truth, reused for proposal_hash,
 * the I6 dedup key, and inbox candidate_hash) is FrontierProposalIdentity::of().
 */
interface FrontierGeneratorPort
{
    /**
     * Generate up to $count candidate proposals from the Harvester dossier ONLY.
     *
     * @param  array<string,mixed>  $dossier  the AP-A harvester dossier (sole input)
     * @param  int  $count  requested proposal count
     * @param  array<string,mixed>  $context  resolved-identity handoff bag (no raw input)
     * @return array{
     *     status:string,
     *     proposals:list<array<string,mixed>>,
     *     provenance:array<string,array<string,mixed>>,
     *     generator_label:string,
     *     generator_provider_resolved:?string,
     *     generator_model_resolved:?string,
     *     generator_blocked_reasons:list<string>,
     *     claim_policy:array<string,bool>
     * }
     */
    public function generate(array $dossier, int $count, array $context = []): array;
}
