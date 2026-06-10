<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use InvalidArgumentException;

/**
 * AOBG N4.F1 — the DOMAIN ACTUATOR REGISTRY: which {proposer, validator, actuator}
 * triplet serves each canonical domain.
 *
 * A domain "plugs into the organism" by registering its triplet here. Resolution is by
 * canonical domain id (via {@see CrossDomainTaxonomyMap}, so a mesh id OR registry id
 * resolves to the same domain — finance==finance, cyber==security, etc.). This is the
 * single seam {@see AtlasOrganismService} consults; it stays domain-agnostic.
 *
 * Pure in-memory wiring — no DB, no IO. The application service provider registers the
 * shipped domains (finance first); tests register fakes.
 */
final class AtlasOrganismRegistry
{
    /** @var array<string, array{proposer:DomainProposer, validator:DomainValidator, actuator:DomainActuator}> */
    private array $domains = [];

    public function __construct(private readonly CrossDomainTaxonomyMap $taxonomy = new CrossDomainTaxonomyMap) {}

    /**
     * Register a domain's triplet. The domain id is resolved to canonical; an unknown
     * domain id is refused (never invent a domain — the canon). The three components
     * MUST agree on the canonical domain they each declare.
     */
    public function register(DomainProposer $proposer, DomainValidator $validator, DomainActuator $actuator): void
    {
        $canonical = $this->taxonomy->canonical($proposer->domain());
        if ($canonical === null) {
            throw new InvalidArgumentException(
                'unknown domain "'.$proposer->domain().'" — register only canonical domains'
            );
        }

        foreach (['validator' => $validator->domain(), 'actuator' => $actuator->domain()] as $role => $declared) {
            if ($this->taxonomy->canonical($declared) !== $canonical) {
                throw new InvalidArgumentException(
                    'domain mismatch: proposer="'.$canonical.'" but '.$role.'="'.$declared.'"'
                );
            }
        }

        $this->domains[$canonical] = [
            'proposer' => $proposer,
            'validator' => $validator,
            'actuator' => $actuator,
        ];
    }

    public function has(string $domain): bool
    {
        $canonical = $this->taxonomy->canonical($domain);

        return $canonical !== null && isset($this->domains[$canonical]);
    }

    /**
     * @return array{proposer:DomainProposer, validator:DomainValidator, actuator:DomainActuator}
     */
    public function resolve(string $domain): array
    {
        $canonical = $this->taxonomy->canonical($domain);
        if ($canonical === null || ! isset($this->domains[$canonical])) {
            throw new InvalidArgumentException('no domain registered for "'.$domain.'"');
        }

        return $this->domains[$canonical];
    }

    /** @return list<string> the canonical domains currently registered. */
    public function registered(): array
    {
        return array_keys($this->domains);
    }

    public function isSensitive(string $domain): bool
    {
        $canonical = $this->taxonomy->canonical($domain);

        return $canonical !== null && $this->taxonomy->isSensitive($canonical);
    }
}
