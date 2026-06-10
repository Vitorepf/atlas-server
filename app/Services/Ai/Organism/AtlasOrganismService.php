<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use InvalidArgumentException;

/**
 * AOBG N4.F1 — THE ORGANISM. The governed seam any domain plugs into to run the SAME
 * closed loop N3 ran for a software obra, now generalized to ANY of the 21 canonical
 * domains (finance, marketing, cyber, …): the thesis "substitute any function/company".
 *
 * propose(domain, intent, opts):
 *   1) BRAIN-ANCHOR — assemble the provider-safe Open-Brain context pack for the intent
 *      ({@see OrganismBrainAnchor}), folding in PRIOR cross-domain proposals from the
 *      brain (cross-domain COMPOUNDING — the next mission sees earlier proposals);
 *   2) PROPOSE — the domain's {@see DomainProposer} turns the intent + context into a
 *      {@see DomainProposal} (stubbable; a real proposer may use a provider, GATED);
 *   3) VALIDATE — the domain's {@see DomainValidator} scores it on a REAL honest metric
 *      (finance = DSR/PBO, win-rate FORBIDDEN); no self-declared success;
 *   4) RECORD — persist the proposal as a brain node ({@see OrganismProposalRecorder},
 *      provider-safe; SENSITIVE domains stay on-machine, never cross to a provider);
 *   5) RETURN — {proposal, validation, actuation_gate:'requires_operator'}.
 *
 * HONEST CEILING (declared, non-negotiable): the organism is PROPOSE-ONLY. It NEVER
 * executes a real-world side effect. The actuate boundary ({@see DomainActuator} /
 * {@see AbstractDomainActuator}) RECORDS + returns 'requires_operator' — the operator
 * executes in the real world themselves. Never claim "operating companies live".
 */
final class AtlasOrganismService
{
    public function __construct(
        private readonly AtlasOrganismRegistry $registry,
        private readonly OrganismBrainAnchor $brain,
        private readonly OrganismProposalRecorder $recorder,
        private readonly CrossDomainTaxonomyMap $taxonomy = new CrossDomainTaxonomyMap,
        private readonly AtlasOrganismActuationGate $actuationGate = new AtlasOrganismActuationGate,
    ) {}

    /**
     * Run the propose-only loop for one domain.
     *
     * @param  array<string,mixed>  $opts  {workspace?, cwd?, payload?, prior_limit?, ...}
     * @return array<string,mixed> {
     *                             domain, intent, sensitive,
     *                             proposal: array,            // provider-safe projection
     *                             validation: array,          // honest-metric verdict
     *                             brain: {anchored:bool, brain_refs:list<string>, prior_proposals_seen:int},
     *                             recorded: {recorded:bool, node_ref:string, reason?:string},
     *                             actuation_gate: 'requires_operator',
     *                             ceiling: string
     *                             }
     */
    public function propose(string $domain, string $intent, array $opts = []): array
    {
        $intent = trim($intent);
        if ($intent === '') {
            throw new InvalidArgumentException('intent is required');
        }

        $canonical = $this->taxonomy->canonical($domain);
        if ($canonical === null) {
            throw new InvalidArgumentException('unknown domain "'.$domain.'"');
        }
        if (! $this->registry->has($canonical)) {
            throw new InvalidArgumentException('no domain registered for "'.$canonical.'"');
        }

        $sensitive = $this->taxonomy->isSensitive($canonical);
        ['proposer' => $proposer, 'validator' => $validator] = $this->registry->resolve($canonical);

        // 1) BRAIN-ANCHOR + cross-domain COMPOUNDING. A sensitive domain stays on-machine:
        //    we still anchor (the brain is local), and fold in prior proposals as labels.
        $priorLimit = max(0, (int) ($opts['prior_limit'] ?? 10));
        $prior = $priorLimit > 0 ? $this->recorder->priorProposals($canonical, $priorLimit) : [];
        $context = $this->brain->anchor($intent, $opts + ['domain' => $canonical]);
        $context['prior_proposals'] = $prior;

        // 2) PROPOSE (stub/provider behind the seam). Resolve to canonical + enforce the
        //    sensitivity ceiling on the produced proposal (a proposer can RAISE, never lower).
        $raw = $proposer->propose($intent, $context, $opts + ['domain' => $canonical]);
        $proposal = DomainProposal::fromArray($raw->toProviderSafeArray() + [
            'domain' => $canonical,
            'intent' => $intent,
            'sensitive' => $sensitive || $raw->sensitive,
            'payload' => $raw->payload,
            'brain_refs' => $raw->brainRefs !== [] ? $raw->brainRefs : ($context['brain_refs'] ?? []),
        ], $this->taxonomy);

        // SENSITIVE GUARD (structural): a sensitive proposal is NEVER provider-serialized
        // downstream. The proposal object drops `payload` from its provider-safe view, and
        // we assert here that a sensitive proposal carries the sensitive flag so the
        // recorder + any presenter keep it on-machine.
        if ($sensitive && ! $proposal->sensitive) {
            throw new \RuntimeException('sensitivity invariant violated for domain "'.$canonical.'"');
        }

        // 3) VALIDATE on the domain's REAL honest metric (no win-rate; honest-empty allowed).
        $validation = $validator->validate($proposal);

        // 4) RECORD into the brain (cross-domain compounding). Provider-safe; sensitive
        //    proposals are recorded sensitive => true and stay on-machine.
        $recorded = $this->recorder->record($proposal, $validation);

        // 5) RETURN — the actuation gate is ALWAYS requires_operator (propose-only ceiling).
        return [
            'domain' => $canonical,
            'intent' => $intent,
            'sensitive' => $proposal->sensitive,
            'proposal' => $proposal->toProviderSafeArray(),
            'validation' => $validation,
            'brain' => [
                'anchored' => ($context['honest_empty'] ?? false) !== true,
                'brain_refs' => $proposal->brainRefs,
                'prior_proposals_seen' => count($prior),
                'proposer' => $proposer->label(),
            ],
            'recorded' => $recorded,
            'actuation_gate' => 'requires_operator',
            'ceiling' => AbstractDomainActuator::CEILING,
        ];
    }

    /**
     * The PROPOSE-ONLY actuation, N4.F4-HARDENED: every actuation flows through the single
     * {@see AtlasOrganismActuationGate}. The gate re-admits the actuator (proves it inherits the
     * sealed, final, I/O-free act path — never re-declared), invokes it (the only outcome is
     * requires_operator), strips any executed-action artifact, and writes an append-only audit
     * receipt. ZERO real-world side effect is reachable. The operator executes any real action.
     *
     * @return array<string,mixed> {status:'requires_operator', recorded, proposal_ref, domain,
     *                             instructions, ceiling, audit:{recorded, receipt_ref, reason?}}
     */
    public function actuate(DomainProposal $proposal): array
    {
        if (! $this->registry->has($proposal->domain)) {
            throw new InvalidArgumentException('no domain registered for "'.$proposal->domain.'"');
        }

        $actuator = $this->registry->resolve($proposal->domain)['actuator'];

        return $this->actuationGate->actuate($actuator, $proposal);
    }
}
