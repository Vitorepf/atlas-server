<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;

/**
 * AOBG N4.F1 — the cross-domain ORGANISM's unit of output: a DOMAIN PROPOSAL.
 *
 * N4 generalizes the N3 closed loop (intent → plan-DAG → per-node delivery) to ANY
 * of the 21 canonical domains: the per-node "delivery" becomes a domain PROPOSAL —
 * a trade idea (finance), a campaign draft (marketing), an audit plan (cyber) —
 * generated BRAIN-ANCHORED, validated by the domain's HONEST metric, recorded into
 * the brain (cross-domain compounding), and PRESENTED to the operator.
 *
 * HONEST CEILING (declared, non-negotiable): a proposal is NEVER an executed action.
 * It carries only `content` (the provider-safe description of what the operator could
 * do) + `rationale` + `brain_refs`. The framework's actuate boundary RECORDS this and
 * returns `requires_operator`; it NEVER places a trade, spends ad budget, makes a
 * purchase, or publishes. See {@see DomainActuator} / {@see AbstractDomainActuator}.
 *
 * PRIVACY: every field is a provider-safe LABEL — a domain idea + its citations,
 * never a secret, never raw market keys, never PII. A `sensitive` domain (finance,
 * health, cyber, trading, legal, personal — per {@see CrossDomainTaxonomyMap}) marks
 * the proposal so the organism keeps it ON-MACHINE and never crosses it to a provider.
 */
final class DomainProposal
{
    /**
     * @param  string  $domain  canonical domain id (resolved via CrossDomainTaxonomyMap)
     * @param  string  $intent  the operator intent / cross-domain node this answers
     * @param  string  $content  provider-safe description of the PROPOSED action (NOT executed)
     * @param  string  $rationale  why the brain/domain proposes it (provider-safe)
     * @param  list<string>  $brainRefs  the AURG/context-pack refs that anchored it (cite-or-omit)
     * @param  bool  $sensitive  true ⇒ keep on-machine, never cross to a provider
     * @param  array<string,mixed>  $payload  on-machine-only structured detail the
     *         VALIDATOR needs (e.g. candidate daily returns for the honest metric).
     *         NEVER serialized to a provider; sensitive proposals keep it local.
     * @param  string  $ts  ISO-8601 creation timestamp
     */
    public function __construct(
        public readonly string $domain,
        public readonly string $intent,
        public readonly string $content,
        public readonly string $rationale,
        public readonly array $brainRefs = [],
        public readonly bool $sensitive = false,
        public readonly array $payload = [],
        public readonly string $ts = '',
    ) {}

    /**
     * Build a proposal, resolving the domain to canonical + defaulting `sensitive`
     * from the taxonomy (over-tag is safe). A proposer may force sensitive=true but
     * can NEVER downgrade a taxonomy-sensitive domain to non-sensitive.
     *
     * @param  array<string,mixed>  $row {domain, intent, content, rationale,
     *         brain_refs?, sensitive?, payload?}
     */
    public static function fromArray(array $row, ?CrossDomainTaxonomyMap $taxonomy = null): self
    {
        $taxonomy ??= new CrossDomainTaxonomyMap;

        $rawDomain = trim((string) ($row['domain'] ?? ''));
        $canonical = $taxonomy->canonical($rawDomain) ?? $rawDomain;

        $brainRefs = [];
        foreach ((array) ($row['brain_refs'] ?? []) as $ref) {
            $ref = trim((string) $ref);
            if ($ref !== '') {
                $brainRefs[] = $ref;
            }
        }

        // Sensitivity is a CEILING, not a toggle: a taxonomy-sensitive domain stays
        // sensitive even if the row tries to set false. A row may only RAISE it.
        $taxonomySensitive = $canonical !== '' && $taxonomy->isSensitive($canonical);
        $rowSensitive = (bool) ($row['sensitive'] ?? false);
        $sensitive = $taxonomySensitive || $rowSensitive;

        return new self(
            domain: $canonical,
            intent: trim((string) ($row['intent'] ?? '')),
            content: trim((string) ($row['content'] ?? '')),
            rationale: trim((string) ($row['rationale'] ?? '')),
            brainRefs: array_values(array_unique($brainRefs)),
            sensitive: $sensitive,
            payload: is_array($row['payload'] ?? null) ? $row['payload'] : [],
            ts: trim((string) ($row['ts'] ?? '')) !== '' ? (string) $row['ts'] : now()->toJSON(),
        );
    }

    /**
     * PROVIDER-SAFE projection: the description a provider may see for a NON-sensitive
     * proposal. Sensitive proposals NEVER reach this method's output on a provider path
     * (the organism gates that), and the structured `payload` is ALWAYS dropped here —
     * it is the on-machine validator input, never a provider field.
     *
     * @return array<string,mixed>
     */
    public function toProviderSafeArray(): array
    {
        return [
            'domain' => $this->domain,
            'intent' => $this->intent,
            'content' => $this->content,
            'rationale' => $this->rationale,
            'brain_refs' => $this->brainRefs,
            'sensitive' => $this->sensitive,
            'ts' => $this->ts,
            // NB: payload intentionally absent — on-machine validator input only.
        ];
    }

    /** A deterministic, stable reference for this proposal (used as the brain node key). */
    public function ref(): string
    {
        return 'proposal:'.$this->domain.':'.substr(
            hash('sha256', $this->domain.'|'.$this->intent.'|'.$this->content.'|'.$this->ts),
            0,
            32,
        );
    }
}
