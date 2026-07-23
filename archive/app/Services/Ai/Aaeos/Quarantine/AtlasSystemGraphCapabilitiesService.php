<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;
use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * System-Graph `capabilities` module gear.
 *
 * Pure, deterministic implementation of the system-graph `capabilities` node.
 * The doc's one-line law is "Capability e ferramenta/harness; dominio e perfil
 * cognitivo. Eles nao sao a mesma coisa" and the invariant "capability nao
 * decide sozinha". This service is the authorization boundary that sits between
 * `policy-profile` (upstream `depends_on`) and `runtime-executor` (downstream
 * `flows_to`): given a catalog of capabilities and a policy, it emits the
 * authorized set the executor is allowed to trigger, and refuses everything the
 * policy did not sanction.
 *
 * Contract (from the doc "Contratos" / "Fluxo" / "Regras para IA" / "Riscos" /
 * "Escopo de Implementacao" / "Proximas Acoes"):
 *   Entrada:    "catalogo de capacidades e policy".
 *   Saida:      "capabilities autorizadas".
 *   Invariante: "capability nao decide sozinha".
 *   Fluxo:      "Policy autoriza capacidades. Runtime Executor aciona apenas o
 *                que foi permitido pelo receipt."
 *   Regra IA:   "IA deve tratar capability como meio de execucao, nao como
 *                autoridade operacional."
 *   Escopo:     Permitido = "catalogo, limits e harnesses"; Proibido = "executar
 *                fora de policy".
 *   Proximas:   "Classificar capabilities por risco e evidence obrigatoria."
 *
 * Decidable rules this gear enforces:
 *
 *  1. Classify by risk + required evidence (doc "Proximas Acoes" + frontmatter
 *     risk_level + requires_evidence). classify() maps a capability to its risk
 *     tier (low|medium|high|critical) and resolves whether evidence is mandatory:
 *     high and critical ALWAYS require evidence (the doc's headline risk is "uma
 *     ferramenta poderosa sem gate"), regardless of what the catalog declares.
 *
 *  2. Capability is never an authority (invariant "capability nao decide
 *     sozinha"). authorize() refuses any capability that arrives with no policy
 *     grant — `deny:not_in_policy`. A capability that tries to set a kernel-owned
 *     decision field (provider/policy/scope/domain) is refused as
 *     `deny:capability_overstep` — it is acting as operational authority, which
 *     the "Regras para IA" forbids.
 *
 *  3. Harness is not a domain (decision "Capability e ferramenta/harness;
 *     dominio e perfil cognitivo"). A catalog entry mislabeled with `kind:domain`
 *     (or carrying a `domain` field as its identity) is refused as
 *     `deny:harness_is_not_domain` — the doc's second risk, "confundir harness
 *     com dominio".
 *
 *  4. Powerful tool without a gate (risk "ferramenta poderosa sem gate"). A
 *     high/critical capability authorized by policy but presented WITHOUT the
 *     required evidence is refused as `deny:evidence_required` — the gate is the
 *     evidence, and the doc makes evidence mandatory for risky capabilities.
 *
 *  5. Execute outside policy is forbidden (Escopo "Proibido: executar fora de
 *     policy"). authorizeCatalog() runs every capability through the same gate and
 *     returns ONLY the authorized subset plus the per-capability denial reasons,
 *     so the downstream Runtime Executor receives exactly "o que foi permitido".
 *
 * Stateless and DB-free: every method is a pure function of its arguments. The
 * service never executes a capability, calls a provider, runs a harness or
 * touches the database — it only decides which capabilities are authorized.
 *
 * @see docs/engineering-knowledge-base/system-graph/capabilities.md
 */
final class AtlasSystemGraphCapabilitiesService
{
    /** Stable schema id for the authorization verdict this gear emits. */
    public const SCHEMA_VERSION = 'atlas.system_graph.capabilities.v1';

    /** Canonical verdicts (closed set). */
    public const VERDICT_AUTHORIZE = 'authorize';
    public const VERDICT_DENY = 'deny';

    /**
     * Risk tiers the doc's "Classificar capabilities por risco" implies, weakest
     * first. Index 0 is the safest; higher index == more dangerous. The doc's own
     * frontmatter declares this module `risk_level: high`.
     *
     * @var list<string>
     */
    public const RISK_TIERS = ['low', 'medium', 'high', 'critical'];

    /**
     * Risk tiers for which evidence is ALWAYS mandatory, no matter what the
     * catalog says. The doc's headline risk is "ferramenta poderosa sem gate", so
     * a powerful (high/critical) capability without evidence has no gate.
     *
     * @var list<string>
     */
    private const EVIDENCE_MANDATORY_TIERS = ['high', 'critical'];

    /**
     * Kernel-owned decision fields. The "Regras para IA" forbids a capability from
     * being "autoridade operacional": if a capability tries to decide any of
     * these, it has overstepped from execution-means into authority.
     *
     * @var list<string>
     */
    private const KERNEL_OWNED_FIELDS = [
        'provider',
        'policy',
        'scope',
        'domain',
        'autonomy',
    ];

    /**
     * Classify one capability by risk and resolve whether evidence is mandatory.
     *
     * Implements the doc's "Proximas Acoes": "Classificar capabilities por risco e
     * evidence obrigatoria." High and critical capabilities ALWAYS require
     * evidence (the gate), overriding any softer catalog flag.
     *
     * @param array<string,mixed> $capability Recognized keys:
     *        id                : string  capability identifier (e.g. "programming_harness").
     *        risk              : string  one of RISK_TIERS (default "low" / unknown -> "high").
     *        requires_evidence : bool    catalog hint; ignored when risk is high/critical.
     *
     * @return array{
     *     schema:string,
     *     id:string,
     *     risk:string,
     *     risk_rank:int,
     *     requires_evidence:bool,
     *     evidence_mandatory_by_risk:bool
     * }
     */
    public function classify(array $capability): array
    {
        $id = $this->stringOrEmpty($capability['id'] ?? '');
        $risk = $this->normalizeRisk($capability['risk'] ?? null);
        $mandatoryByRisk = in_array($risk, self::EVIDENCE_MANDATORY_TIERS, true);

        // Catalog may opt a low/medium capability INTO evidence, but it can never
        // opt a high/critical one OUT of it.
        $catalogHint = (bool) ($capability['requires_evidence'] ?? false);
        $requiresEvidence = $mandatoryByRisk || $catalogHint;

        return [
            'schema' => self::SCHEMA_VERSION,
            'id' => $id,
            'risk' => $risk,
            'risk_rank' => (int) array_search($risk, self::RISK_TIERS, true),
            'requires_evidence' => $requiresEvidence,
            'evidence_mandatory_by_risk' => $mandatoryByRisk,
        ];
    }

    /**
     * Decide whether ONE capability may be triggered under ONE policy, enforcing
     * every documented law in priority order. A capability is "meio de execucao",
     * never authority: it is authorized only when the policy granted it, it does
     * not overstep, it is not a mislabeled domain, and (when risky) it carries the
     * required evidence.
     *
     * @param array<string,mixed> $capability Recognized keys:
     *        id                : string  capability identifier.
     *        kind              : string  "harness"|"tool"|... ; "domain" is rejected.
     *        domain            : string  if present as identity -> harness/domain confusion.
     *        risk              : string  one of RISK_TIERS.
     *        requires_evidence : bool    catalog hint (see classify()).
     *        has_evidence      : bool    whether an evidence reference is attached.
     *        decided_fields    : array<string,mixed> kernel-owned fields the capability
     *                            is (illegitimately) trying to set itself.
     * @param array<string,mixed> $policy Recognized keys:
     *        allowed_capabilities : list<string> ids the policy sanctions. Empty list
     *                               means the policy granted NOTHING -> deny all (the
     *                               doc: "executar fora de policy" is forbidden, so the
     *                               default is closed, never open).
     *
     * @return array{
     *     schema:string,
     *     id:string,
     *     verdict:string,
     *     authorized:bool,
     *     reason:string,
     *     classification:array<string,mixed>
     * }
     */
    public function authorize(array $capability, array $policy): array
    {
        $id = $this->stringOrEmpty($capability['id'] ?? '');
        $classification = $this->classify($capability);

        // Rule 1 — harness is not a domain ("confundir harness com dominio").
        // A capability mislabeled as a domain, or carrying a domain as its
        // identity, is rejected before anything else: it is a category error.
        $kind = strtolower($this->stringOrEmpty($capability['kind'] ?? 'harness'));
        $domainIdentity = $this->stringOrEmpty($capability['domain'] ?? '');
        if ($kind === 'domain' || $domainIdentity !== '') {
            return $this->deny('harness_is_not_domain', $id, $classification);
        }

        // Rule 2 — capability is never authority ("capability nao decide sozinha"
        // / "nao como autoridade operacional"). A capability trying to set a
        // kernel-owned decision field has overstepped.
        $overstep = $this->kernelOverstep($capability['decided_fields'] ?? []);
        if ($overstep !== []) {
            $result = $this->deny('capability_overstep', $id, $classification);
            $result['overstep_fields'] = $overstep;

            return $result;
        }

        // Rule 3 — execute outside policy is forbidden ("Proibido: executar fora
        // de policy"). Default-closed: a capability the policy did not grant is
        // never authorized. An empty allow-list grants nothing.
        $granted = AtlasAaeosStringListNormalizer::trimmedStrings($policy['allowed_capabilities'] ?? []);
        if (! in_array($id, $granted, true)) {
            return $this->deny('not_in_policy', $id, $classification);
        }

        // Rule 4 — powerful tool without a gate ("ferramenta poderosa sem gate").
        // A risky capability the policy granted but that arrives without evidence
        // has no gate; evidence IS the gate for high/critical capabilities.
        $hasEvidence = (bool) ($capability['has_evidence'] ?? false);
        if ($classification['requires_evidence'] && ! $hasEvidence) {
            return $this->deny('evidence_required', $id, $classification);
        }

        // All capability laws satisfied: authorized strictly as a means of
        // execution, to be triggered by the Runtime Executor under its receipt.
        return [
            'schema' => self::SCHEMA_VERSION,
            'id' => $id,
            'verdict' => self::VERDICT_AUTHORIZE,
            'authorized' => true,
            'reason' => 'authorized_by_policy',
            'classification' => $classification,
        ];
    }

    /**
     * Run a whole catalog through the gate under one policy and return ONLY the
     * authorized subset plus every denial reason, so the Runtime Executor receives
     * exactly "o que foi permitido pelo receipt" — the doc's "Saida: capabilities
     * autorizadas".
     *
     * @param list<array<string,mixed>> $catalog
     * @param array<string,mixed> $policy
     *
     * @return array{
     *     schema:string,
     *     total:int,
     *     authorized:list<string>,
     *     denied:list<array{id:string,reason:string,risk:string}>,
     *     decisions:list<array<string,mixed>>
     * }
     */
    public function authorizeCatalog(array $catalog, array $policy): array
    {
        $authorized = [];
        $denied = [];
        $decisions = [];

        foreach ($catalog as $capability) {
            if (! is_array($capability)) {
                continue;
            }
            $decision = $this->authorize($capability, $policy);
            $decisions[] = $decision;

            if ($decision['authorized'] === true) {
                $authorized[] = $decision['id'];

                continue;
            }
            $denied[] = [
                'id' => $decision['id'],
                'reason' => $decision['reason'],
                'risk' => $decision['classification']['risk'],
            ];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'total' => count($decisions),
            'authorized' => array_values(array_unique($authorized)),
            'denied' => $denied,
            'decisions' => $decisions,
        ];
    }

    /**
     * Convenience predicate: may this capability be triggered under this policy?
     *
     * @param array<string,mixed> $capability
     * @param array<string,mixed> $policy
     */
    public function isAuthorized(array $capability, array $policy): bool
    {
        return $this->authorize($capability, $policy)['verdict'] === self::VERDICT_AUTHORIZE;
    }

    /**
     * Build a uniform denial verdict.
     *
     * @param array<string,mixed> $classification
     *
     * @return array{
     *     schema:string,
     *     id:string,
     *     verdict:string,
     *     authorized:bool,
     *     reason:string,
     *     classification:array<string,mixed>
     * }
     */
    private function deny(string $reason, string $id, array $classification): array
    {
        return [
            'schema' => self::SCHEMA_VERSION,
            'id' => $id,
            'verdict' => self::VERDICT_DENY,
            'authorized' => false,
            'reason' => $reason,
            'classification' => $classification,
        ];
    }

    /**
     * Which kernel-owned decision fields is the capability illegitimately trying
     * to set? A non-empty result means the capability is acting as authority.
     *
     * @param mixed $decidedFields
     * @return list<string>
     */
    private function kernelOverstep(mixed $decidedFields): array
    {
        if (! is_array($decidedFields)) {
            return [];
        }

        $hit = [];
        foreach (self::KERNEL_OWNED_FIELDS as $field) {
            if (! array_key_exists($field, $decidedFields)) {
                continue;
            }
            if (! $this->isEmptyValue($decidedFields[$field])) {
                $hit[] = $field;
            }
        }

        return $hit;
    }

    /**
     * Normalize a risk label to the closed tier set. Unknown / missing risk is
     * treated as `high` (fail-safe): an unclassified capability must be gated, not
     * waved through — "ferramenta poderosa sem gate" is the doc's headline risk.
     */
    private function normalizeRisk(mixed $risk): string
    {
        return AtlasAaeosValueNormalizer::lowercaseAllowed($risk, self::RISK_TIERS, 'high');
    }

    private function isEmptyValue(mixed $value): bool
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

    private function stringOrEmpty(mixed $value): string
    {
        return is_string($value) ? trim($value) : '';
    }

}
