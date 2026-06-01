<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas World Model runtime decider.
 *
 * Pure, deterministic implementation of the digital-world model contract: it
 * turns external/internal observations about the *digital world* (markets,
 * companies, products, platforms, APIs, repos, costs, risks, opportunities)
 * into validated world entities, edges and a freshness/confidence-checked
 * snapshot a mission may (or may not) be allowed to use. Nothing here touches a
 * database, a clock, a provider or the filesystem — every method returns a
 * typed decision array. This is explicitly the digital-world model, NOT the
 * Codebase World Model (see the "Codebase Variant" section of the doc, served
 * by WorldModelGraphRanker / TimeAwareWorldModelService).
 *
 * Load-bearing contracts pinned here (from the doc):
 *
 *   1. Entidades. Closed taxonomy of 16 entity types (mercado, empresa,
 *      produto, plataforma, api, repo, ferramenta, pessoa_publica, canal,
 *      audiencia, fornecedor, regulacao, custo, risco, oportunidade, metrica).
 *      -> validateEntity()
 *
 *   2. Edges. Closed taxonomy of 11 edge types (competes_with, uses, offers,
 *      requires, blocks, costs, integrates_with, substitutes, depends_on,
 *      creates_opportunity, creates_risk). -> validateEdge()
 *
 *   3. Regras para IA. "Sempre registrar fonte e data." -> an entity with no
 *      source/date is INVALID. "Diferenciar fato, estimativa e inferencia." ->
 *      claim_kind is a closed set {fact, estimate, inference}. "Nao usar
 *      contexto expirado para decisao sensivel." -> freshness() + canUseForDecision()
 *      refuse expired context for sensitive decisions. "Nao transformar
 *      correlacao em causalidade." -> a causal edge backed only by inference is
 *      flagged and may NOT assert causality. -> validateEntity()/validateEdge()/
 *      freshness()/canUseForDecision()
 *
 *   4. Quality Gates. {entities-mapped, sources-linked, freshness-known,
 *      confidence-set} must all hold before the snapshot is usable. ->
 *      buildSnapshot() / qualityGates()
 *
 *   5. Failure Modes. "Contexto desatualizado", "Fonte fraca vira fato",
 *      "Relacao causal falsa" — each is an explicit refusal path, not a silent
 *      pass. -> validateEntity()/validateEdge()/canUseForDecision()
 *
 *   6. Evidencias. Required evidence fields: source, date, excerpt, confidence,
 *      hash, affected entity, edge. -> REQUIRED_EVIDENCE_FIELDS / evidenceComplete()
 *
 * @see docs/engineering-knowledge-base/atlas-world-model.md
 */
final class AtlasWorldModelRtService
{
    /** Stable receipt schema id for the decisions this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.ai.world_model.v1';

    /**
     * The closed entity taxonomy from the doc's "Entidades" section.
     *
     * @var list<string>
     */
    public const ENTITY_TYPES = [
        'mercado',
        'empresa',
        'produto',
        'plataforma',
        'api',
        'repo',
        'ferramenta',
        'pessoa_publica',
        'canal',
        'audiencia',
        'fornecedor',
        'regulacao',
        'custo',
        'risco',
        'oportunidade',
        'metrica',
    ];

    /**
     * The closed edge taxonomy from the doc's "Edges" section.
     *
     * @var list<string>
     */
    public const EDGE_TYPES = [
        'competes_with',
        'uses',
        'offers',
        'requires',
        'blocks',
        'costs',
        'integrates_with',
        'substitutes',
        'depends_on',
        'creates_opportunity',
        'creates_risk',
    ];

    /**
     * Edges whose meaning is causal: asserting them claims that one thing
     * *produces* another. The doc's rule "Nao transformar correlacao em
     * causalidade" applies here — these may not be asserted from an inference
     * alone; they need observed/fact grounding.
     *
     * @var list<string>
     */
    public const CAUSAL_EDGE_TYPES = [
        'creates_opportunity',
        'creates_risk',
        'blocks',
        'requires',
    ];

    /**
     * Closed claim-kind set (Regras para IA: "Diferenciar fato, estimativa e
     * inferencia"). Ordered strongest -> weakest grounding.
     *
     * @var list<string>
     */
    public const CLAIM_KINDS = ['fact', 'estimate', 'inference'];

    /**
     * Evidence fields the doc's "Evidencias" section requires for every
     * recorded world fact.
     *
     * @var list<string>
     */
    public const REQUIRED_EVIDENCE_FIELDS = [
        'source',
        'date',
        'excerpt',
        'confidence',
        'hash',
        'entity',
        'edge',
    ];

    /** Freshness statuses produced by freshness(). */
    public const FRESHNESS_FRESH = 'fresh';
    public const FRESHNESS_AGING = 'aging';
    public const FRESHNESS_EXPIRED = 'expired';
    public const FRESHNESS_UNKNOWN = 'unknown';

    /**
     * Default freshness policy in days. Market/digital context "muda rapido"
     * (Riscos), so the windows are deliberately short. Caller may override
     * per-call. Beyond `expired_after` the context is expired.
     */
    public const DEFAULT_FRESH_WITHIN_DAYS = 30;
    public const DEFAULT_EXPIRED_AFTER_DAYS = 90;

    /** The four documented quality gates (quality_gates frontmatter). */
    public const QUALITY_GATES = [
        'entities-mapped',
        'sources-linked',
        'freshness-known',
        'confidence-set',
    ];

    /**
     * Validate one world entity against the doc's Entidades + Regras para IA.
     *
     * Refusals (each maps to a documented failure mode / rule):
     *   - type not in the closed taxonomy            -> unknown_entity_type
     *   - missing source OR date (Regras para IA:    -> missing_source_or_date
     *     "Sempre registrar fonte e data")             (failure mode "Fonte fraca vira fato")
     *   - claim_kind not in {fact,estimate,inference}-> unknown_claim_kind
     *   - confidence missing/out of [0,1]            -> confidence_not_set
     *
     * @param array<string,mixed> $entity
     *        type        : string  one of ENTITY_TYPES
     *        name        : string  human label (optional but recommended)
     *        source      : mixed   non-empty source reference (required)
     *        date        : mixed   non-empty observation date (required)
     *        claim_kind  : string  one of CLAIM_KINDS (default 'inference')
     *        confidence  : float   in [0,1]
     *
     * @return array<string,mixed>
     */
    public function validateEntity(array $entity): array
    {
        $type = $this->stringOrNull($entity['type'] ?? null);
        $claimKind = $this->stringOrNull($entity['claim_kind'] ?? null) ?? 'inference';
        $errors = [];

        if ($type === null || ! in_array($type, self::ENTITY_TYPES, true)) {
            $errors[] = 'unknown_entity_type';
        }

        if ($this->isBlank($entity['source'] ?? null) || $this->isBlank($entity['date'] ?? null)) {
            // Regras para IA: "Sempre registrar fonte e data." A sourceless,
            // dateless entity is exactly the "Fonte fraca vira fato" failure.
            $errors[] = 'missing_source_or_date';
        }

        if (! in_array($claimKind, self::CLAIM_KINDS, true)) {
            $errors[] = 'unknown_claim_kind';
        }

        $confidence = $this->confidenceOrNull($entity['confidence'] ?? null);
        if ($confidence === null) {
            // quality gate "confidence-set".
            $errors[] = 'confidence_not_set';
        }

        $valid = $errors === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'entity',
            'type' => $type,
            'claim_kind' => in_array($claimKind, self::CLAIM_KINDS, true) ? $claimKind : null,
            'confidence' => $confidence,
            'valid' => $valid,
            'errors' => array_values($errors),
            // A non-fact claim used as if it were truth is the "Fonte fraca
            // vira fato" failure; surface the distinction for callers.
            'is_fact' => $valid && $claimKind === 'fact',
        ];
    }

    /**
     * Validate one world edge against the doc's Edges + Regras para IA.
     *
     * The "Nao transformar correlacao em causalidade" rule is enforced here:
     * a causal edge (CAUSAL_EDGE_TYPES) claimed only from an inference is
     * refused as an asserted causal relation — it may be kept as a hypothesis
     * but `asserts_causality` is false and the error `causal_from_inference`
     * (failure mode "Relacao causal falsa") is raised.
     *
     * @param array<string,mixed> $edge
     *        type       : string  one of EDGE_TYPES
     *        from       : mixed   source entity ref (required)
     *        to         : mixed   target entity ref (required)
     *        claim_kind : string  one of CLAIM_KINDS (default 'inference')
     *        source     : mixed   non-empty source reference (required)
     *        date       : mixed   non-empty observation date (required)
     *
     * @return array<string,mixed>
     */
    public function validateEdge(array $edge): array
    {
        $type = $this->stringOrNull($edge['type'] ?? null);
        $claimKind = $this->stringOrNull($edge['claim_kind'] ?? null) ?? 'inference';
        $errors = [];

        if ($type === null || ! in_array($type, self::EDGE_TYPES, true)) {
            $errors[] = 'unknown_edge_type';
        }

        if ($this->isBlank($edge['from'] ?? null) || $this->isBlank($edge['to'] ?? null)) {
            $errors[] = 'missing_endpoints';
        }

        if ($this->isBlank($edge['source'] ?? null) || $this->isBlank($edge['date'] ?? null)) {
            $errors[] = 'missing_source_or_date';
        }

        if (! in_array($claimKind, self::CLAIM_KINDS, true)) {
            $errors[] = 'unknown_claim_kind';
        }

        $isCausal = $type !== null && in_array($type, self::CAUSAL_EDGE_TYPES, true);

        // Regras para IA: "Nao transformar correlacao em causalidade."
        // A causal edge grounded only in inference cannot assert causality.
        $causalFromInference = $isCausal && $claimKind === 'inference';
        if ($causalFromInference) {
            $errors[] = 'causal_from_inference';
        }

        $valid = $errors === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'edge',
            'type' => $type,
            'claim_kind' => in_array($claimKind, self::CLAIM_KINDS, true) ? $claimKind : null,
            'is_causal' => $isCausal,
            // Only a valid causal edge with fact/estimate grounding asserts
            // causality. Inference-only causal edges are hypotheses at best.
            'asserts_causality' => $valid && $isCausal,
            'valid' => $valid,
            'errors' => array_values($errors),
        ];
    }

    /**
     * Classify the freshness of a world fact given its observation age.
     *
     * @param int|null $ageInDays         days since the fact was observed; null = unknown
     * @param array<string,mixed> $policy fresh_within_days / expired_after_days overrides
     *
     * @return array<string,mixed>
     */
    public function freshness(?int $ageInDays, array $policy = []): array
    {
        $freshWithin = (int) ($policy['fresh_within_days'] ?? self::DEFAULT_FRESH_WITHIN_DAYS);
        $expiredAfter = (int) ($policy['expired_after_days'] ?? self::DEFAULT_EXPIRED_AFTER_DAYS);

        if ($ageInDays === null || $ageInDays < 0) {
            $status = self::FRESHNESS_UNKNOWN;
        } elseif ($ageInDays <= $freshWithin) {
            $status = self::FRESHNESS_FRESH;
        } elseif ($ageInDays <= $expiredAfter) {
            $status = self::FRESHNESS_AGING;
        } else {
            $status = self::FRESHNESS_EXPIRED;
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'freshness',
            'age_days' => $ageInDays,
            'status' => $status,
            'fresh_within_days' => $freshWithin,
            'expired_after_days' => $expiredAfter,
            // "freshness-known" quality gate: status must not be unknown.
            'freshness_known' => $status !== self::FRESHNESS_UNKNOWN,
            'is_expired' => $status === self::FRESHNESS_EXPIRED,
        ];
    }

    /**
     * Decide whether a world fact may be used to drive a decision.
     *
     * Enforces "Nao usar contexto expirado para decisao sensivel": expired
     * context is refused for a sensitive decision (and also refused whenever
     * freshness is unknown for a sensitive decision — an unknown-age fact is
     * not safe to lean on). For non-sensitive decisions expired context is
     * downgraded to advisory rather than hard-refused.
     *
     * @param array<string,mixed> $fact
     *        age_days   : int|null
     *        valid      : bool   (from validateEntity/validateEdge)
     *        claim_kind : string
     * @param bool $sensitive whether the decision is sensitive
     * @param array<string,mixed> $policy freshness policy overrides
     *
     * @return array<string,mixed>
     */
    public function canUseForDecision(array $fact, bool $sensitive, array $policy = []): array
    {
        $fresh = $this->freshness($this->intOrNull($fact['age_days'] ?? null), $policy);
        $factValid = (bool) ($fact['valid'] ?? false);
        $reasons = [];

        if (! $factValid) {
            $reasons[] = 'fact_invalid';
        }

        if ($sensitive && $fresh['status'] === self::FRESHNESS_EXPIRED) {
            // The headline rule: expired context cannot drive a sensitive decision.
            $reasons[] = 'expired_context_sensitive_decision';
        }

        if ($sensitive && $fresh['status'] === self::FRESHNESS_UNKNOWN) {
            $reasons[] = 'unknown_freshness_sensitive_decision';
        }

        $allowed = $reasons === [];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'decision_use',
            'sensitive' => $sensitive,
            'freshness' => $fresh['status'],
            'allowed' => $allowed,
            // Even when allowed for a non-sensitive decision, an expired or
            // unknown fact is advisory only.
            'advisory_only' => $allowed
                && in_array($fresh['status'], [self::FRESHNESS_AGING, self::FRESHNESS_EXPIRED, self::FRESHNESS_UNKNOWN], true),
            'refusal_reasons' => array_values($reasons),
        ];
    }

    /**
     * Check the doc's required evidence fields (Evidencias) are all present.
     *
     * @param array<string,mixed> $evidence
     *
     * @return array<string,mixed>
     */
    public function evidenceComplete(array $evidence): array
    {
        $missing = [];
        foreach (self::REQUIRED_EVIDENCE_FIELDS as $field) {
            // `edge` may legitimately be null for a pure entity fact; treat a
            // present-but-null edge key as satisfied, only a wholly absent key
            // (or blank value on a required-value field) counts as missing.
            if ($field === 'edge') {
                if (! array_key_exists('edge', $evidence)) {
                    $missing[] = 'edge';
                }

                continue;
            }
            if ($this->isBlank($evidence[$field] ?? null)) {
                $missing[] = $field;
            }
        }

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'evidence',
            'required' => self::REQUIRED_EVIDENCE_FIELDS,
            'missing' => array_values($missing),
            'complete' => $missing === [],
        ];
    }

    /**
     * Evaluate the four documented quality gates over a candidate snapshot
     * before it is allowed to serve planning.
     *
     * @param array<string,mixed> $snapshot
     *        entity_count : int   mapped entities (entities-mapped)
     *        sources_count: int   distinct sources linked (sources-linked)
     *        freshness_known_count / total : freshness-known coverage
     *        confidence_set_count  / total : confidence-set coverage
     *
     * @return array<string,mixed>
     */
    public function qualityGates(array $snapshot): array
    {
        $entityCount = max(0, $this->intOrNull($snapshot['entity_count'] ?? null) ?? 0);
        $sourceCount = max(0, $this->intOrNull($snapshot['sources_count'] ?? null) ?? 0);
        $total = max(0, $this->intOrNull($snapshot['total'] ?? null) ?? $entityCount);
        $freshKnown = max(0, $this->intOrNull($snapshot['freshness_known_count'] ?? null) ?? 0);
        $confSet = max(0, $this->intOrNull($snapshot['confidence_set_count'] ?? null) ?? 0);

        $gates = [
            'entities-mapped' => $entityCount > 0,
            'sources-linked' => $sourceCount > 0,
            // Every counted fact must have a known freshness / a set confidence.
            'freshness-known' => $total > 0 && $freshKnown >= $total,
            'confidence-set' => $total > 0 && $confSet >= $total,
        ];

        $failed = array_keys(array_filter($gates, static fn (bool $ok): bool => ! $ok));

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'quality_gates',
            'gates' => $gates,
            'failed' => array_values($failed),
            'passed' => $failed === [],
        ];
    }

    /**
     * Assemble a world snapshot decision from validated entities + edges and
     * gate it. The snapshot is only `usable` when every quality gate passes;
     * otherwise it is blocked with the failing gates surfaced. This is the
     * Definition of Done in code: "missoes consultam contexto estruturado com
     * fontes, freshness, confidence e relacoes antes de decisoes relevantes".
     *
     * @param array<string,mixed> $input
     *        entities : list<array<string,mixed>>  candidate entities
     *        edges    : list<array<string,mixed>>  candidate edges
     *        policy   : array<string,mixed>        freshness policy overrides
     *
     * @return array<string,mixed>
     */
    public function buildSnapshot(array $input = []): array
    {
        /** @var list<array<string,mixed>> $entities */
        $entities = array_values(array_filter(
            (array) ($input['entities'] ?? []),
            'is_array',
        ));
        /** @var list<array<string,mixed>> $edges */
        $edges = array_values(array_filter(
            (array) ($input['edges'] ?? []),
            'is_array',
        ));
        $policy = (array) ($input['policy'] ?? []);

        $entityResults = array_map(fn (array $e): array => $this->validateEntity($e), $entities);
        $edgeResults = array_map(fn (array $e): array => $this->validateEdge($e), $edges);

        $validEntities = array_values(array_filter($entityResults, static fn (array $r): bool => (bool) $r['valid']));
        $validEdges = array_values(array_filter($edgeResults, static fn (array $r): bool => (bool) $r['valid']));

        $sources = [];
        foreach ($entities as $e) {
            $src = $this->stringOrNull($e['source'] ?? null);
            if ($src !== null && $src !== '') {
                $sources[$src] = true;
            }
        }

        // Freshness/confidence coverage is measured over the candidate entities:
        // a fact with no known age or unset confidence fails the matching gate.
        $freshnessKnown = 0;
        $confidenceSet = 0;
        foreach ($entities as $e) {
            $age = $this->intOrNull($e['age_days'] ?? null);
            if ($age !== null && $age >= 0) {
                $freshnessKnown++;
            }
            if ($this->confidenceOrNull($e['confidence'] ?? null) !== null) {
                $confidenceSet++;
            }
        }

        $gates = $this->qualityGates([
            'entity_count' => count($validEntities),
            'sources_count' => count($sources),
            'total' => count($entities),
            'freshness_known_count' => $freshnessKnown,
            'confidence_set_count' => $confidenceSet,
        ]);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'snapshot',
            'entity_types' => self::ENTITY_TYPES,
            'edge_types' => self::EDGE_TYPES,
            'entity_count' => count($entities),
            'valid_entity_count' => count($validEntities),
            'edge_count' => count($edges),
            'valid_edge_count' => count($validEdges),
            'source_count' => count($sources),
            'quality_gates' => $gates,
            // The snapshot may serve planning only when all gates pass. This is
            // the refusal that protects against "Contexto desatualizado" and
            // "Fonte fraca vira fato" reaching a decision.
            'usable' => (bool) $gates['passed'],
            'blocked_reason' => $gates['passed'] ? null : 'quality_gates_failed',
        ];
    }

    /**
     * The 7-step build-and-consult flow from the doc's "Fluxo" section, exposed
     * as ordered runtime steps.
     *
     * @return array<string,mixed>
     */
    public function flow(): array
    {
        $steps = [
            ['order' => 1, 'step' => 'identify_entities'],
            ['order' => 2, 'step' => 'collect_sources'],
            ['order' => 3, 'step' => 'create_nodes_and_edges'],
            ['order' => 4, 'step' => 'classify_confidence_and_freshness'],
            ['order' => 5, 'step' => 'relate_opportunities_risks_tools_platforms'],
            ['order' => 6, 'step' => 'consult_during_planning'],
            ['order' => 7, 'step' => 'update_with_outcomes'],
        ];

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'kind' => 'flow',
            'count' => count($steps),
            'steps' => $steps,
        ];
    }

    // --- helpers ---------------------------------------------------------

    private function stringOrNull(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }

    private function intOrNull(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '' && ctype_digit(ltrim($value, '-'))) {
            return (int) $value;
        }

        return null;
    }

    private function confidenceOrNull(mixed $value): ?float
    {
        if (is_int($value) || is_float($value)) {
            $f = (float) $value;

            return ($f >= 0.0 && $f <= 1.0) ? $f : null;
        }

        return null;
    }
}
