<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\AtlasAaeosStringListNormalizer;

/**
 * Atlas Domain Expansion Spec decider.
 *
 * Pure, deterministic gate that turns the "how to create a new domain from
 * scratch" spec into an executable contract. The doc is explicit that it is the
 * runbook for *creating* a 16th domain ("Nao registra domains existentes; e
 * como criar novos"), so this service validates a *new-domain proposal* against
 * the canonical contract and answers one question without lying: may this
 * proposal become an active L0 domain, or must it be refined?
 *
 * It enforces three documented layers:
 *
 *  1. Schema `atlas.domain.v1` (doc "Contratos > Schema"): the required keys and
 *     the closed `sovereignty_class` set {ok_to_share, sensitive, secret, cyber}.
 *
 *  2. The 12-item mandatory checklist (doc "Checklist obrigatorio (12 items)").
 *     Each item is a real, checkable predicate, not a label. The load-bearing
 *     thresholds the doc states verbatim:
 *       - item 1  : canonical doc path under docs/.../domains/<id>.md present.
 *       - item 2  : schema header equals `atlas.domain.v1`.
 *       - item 3  : `scope` declared with no overlap vs the existing 15.
 *       - item 4  : at least one `intents_supported`.
 *       - item 5  : `departments_touched` minimo 3.
 *       - item 6  : `sovereignty_class` declared (closed set).
 *       - item 7  : every `data_sources` entry carries a sovereignty/kind class.
 *       - item 8  : `skills_required` reference the canonical `atlas.skill.*` pack.
 *       - item 9  : `evidence_required` minimo 3 evidence kinds.
 *       - item 10 : `gates` minimo 5 = 3 universais + 2 domain-specific.
 *       - item 11 : `maturity_level` inicial = L0 (a new domain may not self-promote).
 *       - item 12 : Architect review approval registrado.
 *
 *  3. Cross-dept dependencies (doc "Cross-dept dependencies obrigatorias"): the
 *     per-kind minimum departments table is enforced exactly, and "Regras para
 *     IA" adds two hard rules — a domain with no canonical doc gets NO routing,
 *     and sovereignty in {sensitive, secret, cyber} demands an extra Security
 *     department gate beyond the universal gates.
 *
 * The overall verdict follows the doc flow: a proposal is `approve` -> `l0_active`
 * only when the schema is valid AND all 12 checklist items pass AND the cross-dept
 * minimum is satisfied AND (when sovereignty is sensitive/secret/cyber) the extra
 * Security gate is present. Otherwise the flow routes to `refine` and names every
 * blocking reason. A new domain can NEVER be born above L0.
 *
 * The service is pure: it consumes an already-normalized proposal array and emits
 * a verdict. It never reads a doc, runs a harness, or touches a DB.
 *
 * @see docs/engineering-knowledge-base/atlas-domain-expansion-spec.md
 */
final class AtlasDomainExpansionSpecService
{
    /** Stable receipt schema id for the verdict this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.aaeos.domain_expansion_spec.v1';

    /** The schema header a new-domain proposal must carry (doc "Schema"). */
    public const DOMAIN_SCHEMA_ID = 'atlas.domain.v1';

    /** Closed `sovereignty_class` set (doc schema). */
    public const SOVEREIGNTY_CLASSES = ['ok_to_share', 'sensitive', 'secret', 'cyber'];

    /**
     * Sovereignty classes that, per "Regras para IA", require an additional
     * Security department gate beyond the universal gates.
     *
     * @var array<int,string>
     */
    public const SECURITY_GATED_SOVEREIGNTY = ['sensitive', 'secret', 'cyber'];

    /** A new domain is always born here (doc checklist item 11). */
    public const INITIAL_MATURITY = 'L0';

    /** Closed set of overall verdicts. */
    public const VERDICT_L0_ACTIVE = 'l0_active';
    public const VERDICT_REFINE = 'refine';

    /** Documented minimum cardinalities (doc checklist items 5, 9, 10). */
    public const MIN_DEPARTMENTS_TOUCHED = 3;
    public const MIN_EVIDENCE_KINDS = 3;
    public const MIN_GATES = 5;
    public const MIN_UNIVERSAL_GATES = 3;
    public const MIN_DOMAIN_SPECIFIC_GATES = 2;

    /**
     * The three universal gates every domain must carry. The doc says "5 gates
     * (3 universais + 2 domain-specific)"; these three are the universal floor
     * that also lets us count the domain-specific remainder.
     *
     * @var array<int,string>
     */
    public const UNIVERSAL_GATES = ['evidence_required', 'doc_canonical', 'architect_review'];

    /**
     * Cross-dept dependency matrix (doc "Cross-dept dependencies obrigatorias").
     * Each domain kind maps to the exact minimum departments declared in the doc
     * table. Unknown kinds fall back to the universal floor (>= 3 depts) only.
     *
     * @var array<string,array<int,string>>
     */
    public const CROSS_DEPT_MATRIX = [
        'programming' => ['product', 'architect', 'dev', 'forge', 'review', 'qa', 'security', 'delivery', 'memory'],
        'finance' => ['product', 'architect', 'security', 'review', 'qa', 'delivery', 'memory'],
        'marketing' => ['product', 'research', 'dev', 'review', 'qa', 'delivery', 'memory'],
        'trading' => ['architect', 'security', 'qa', 'delivery', 'memory'],
        'health' => ['architect', 'security', 'qa', 'delivery', 'memory'],
    ];

    /**
     * Validate a new-domain proposal against the canonical contract and emit a
     * verdict. The input is the proposal plus the existing-domain context used
     * for the no-overlap check.
     *
     * @param array{
     *   schema?:string,
     *   id?:string,
     *   human_name?:string,
     *   scope?:string,
     *   kind?:string,
     *   intents_supported?:array<int,string>,
     *   departments_touched?:array<int,string>,
     *   sovereignty_class?:string,
     *   data_sources?:array<int,array<string,mixed>>,
     *   skills_required?:array<int,string>,
     *   evidence_required?:array<int,string>,
     *   gates?:array<int,string>,
     *   maturity_level?:string,
     *   canonical_doc_path?:string,
     *   scope_overlaps_existing?:bool,
     *   architect_approved?:bool,
     *   existing_domain_ids?:array<int,string>
     * } $proposal
     * @return array{
     *   schema:string,
     *   verdict:string,
     *   routing_enabled:bool,
     *   schema_valid:bool,
     *   checklist:array<string,array{ok:bool,reason:string}>,
     *   checklist_passed:int,
     *   checklist_total:int,
     *   cross_dept:array{kind:string,required:array<int,string>,missing:array<int,string>,ok:bool},
     *   security_gate:array{required:bool,present:bool,ok:bool},
     *   blocking_reasons:array<int,string>
     * }
     */
    public function evaluateProposal(array $proposal): array
    {
        $checklist = $this->checklist($proposal);
        $schemaValid = $this->schemaValid($proposal);
        $crossDept = $this->crossDept($proposal);
        $securityGate = $this->securityGate($proposal);

        $blocking = [];

        if (! $schemaValid) {
            $blocking[] = 'schema_invalid';
        }

        foreach ($checklist as $item => $result) {
            if (! $result['ok']) {
                $blocking[] = 'checklist:'.$item;
            }
        }

        if (! $crossDept['ok']) {
            $blocking[] = 'cross_dept_minimum_unmet';
        }

        if ($securityGate['required'] && ! $securityGate['present']) {
            $blocking[] = 'security_gate_missing';
        }

        $passed = 0;
        foreach ($checklist as $result) {
            if ($result['ok']) {
                $passed++;
            }
        }

        $verdict = $blocking === [] ? self::VERDICT_L0_ACTIVE : self::VERDICT_REFINE;

        // Regras para IA: "Domain sem doc canonico nao recebe roteamento."
        $routingEnabled = $this->hasCanonicalDoc($proposal);

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'routing_enabled' => $routingEnabled,
            'schema_valid' => $schemaValid,
            'checklist' => $checklist,
            'checklist_passed' => $passed,
            'checklist_total' => count($checklist),
            'cross_dept' => $crossDept,
            'security_gate' => $securityGate,
            'blocking_reasons' => array_values($blocking),
        ];
    }

    /**
     * The 12 mandatory checklist items, each a real predicate.
     *
     * @param array<string,mixed> $proposal
     * @return array<string,array{ok:bool,reason:string}>
     */
    public function checklist(array $proposal): array
    {
        $intents = AtlasAaeosStringListNormalizer::trimmedStrings($proposal['intents_supported'] ?? []);
        $depts = AtlasAaeosStringListNormalizer::trimmedStrings($proposal['departments_touched'] ?? []);
        $skills = AtlasAaeosStringListNormalizer::trimmedStrings($proposal['skills_required'] ?? []);
        $evidence = AtlasAaeosStringListNormalizer::trimmedStrings($proposal['evidence_required'] ?? []);
        $gates = AtlasAaeosStringListNormalizer::trimmedStrings($proposal['gates'] ?? []);
        $dataSources = is_array($proposal['data_sources'] ?? null) ? $proposal['data_sources'] : [];
        $scope = trim((string) ($proposal['scope'] ?? ''));
        $overlap = (bool) ($proposal['scope_overlaps_existing'] ?? false);
        $maturity = (string) ($proposal['maturity_level'] ?? '');

        $gateSplit = $this->gateSplit($gates);

        return [
            'item_1_canonical_doc' => $this->check(
                $this->hasCanonicalDoc($proposal),
                'canonical doc must exist at docs/engineering-knowledge-base/domains/<id>.md',
            ),
            'item_2_schema_filled' => $this->check(
                (string) ($proposal['schema'] ?? '') === self::DOMAIN_SCHEMA_ID,
                'schema header must equal '.self::DOMAIN_SCHEMA_ID,
            ),
            'item_3_scope_no_overlap' => $this->check(
                $scope !== '' && ! $overlap,
                'scope must be declared and must not overlap the existing 15 domains',
            ),
            'item_4_intents_declared' => $this->check(
                $intents !== [],
                'at least one intents_supported must be declared',
            ),
            'item_5_departments_min_3' => $this->check(
                count($depts) >= self::MIN_DEPARTMENTS_TOUCHED,
                'departments_touched requires a minimum of '.self::MIN_DEPARTMENTS_TOUCHED,
            ),
            'item_6_sovereignty_declared' => $this->check(
                in_array((string) ($proposal['sovereignty_class'] ?? ''), self::SOVEREIGNTY_CLASSES, true),
                'sovereignty_class must be one of: '.implode('|', self::SOVEREIGNTY_CLASSES),
            ),
            'item_7_data_sources_classed' => $this->check(
                $this->dataSourcesClassed($dataSources),
                'every data_sources entry must carry a sovereignty/kind class',
            ),
            'item_8_skills_canonical' => $this->check(
                $skills !== [] && $this->allCanonicalSkills($skills),
                'skills_required must reference the canonical atlas.skill.* pack',
            ),
            'item_9_evidence_min_3' => $this->check(
                count($evidence) >= self::MIN_EVIDENCE_KINDS,
                'evidence_required needs a minimum of '.self::MIN_EVIDENCE_KINDS.' evidence kinds',
            ),
            'item_10_gates_min_5_split' => $this->check(
                count($gates) >= self::MIN_GATES
                    && $gateSplit['universal'] >= self::MIN_UNIVERSAL_GATES
                    && $gateSplit['domain_specific'] >= self::MIN_DOMAIN_SPECIFIC_GATES,
                'gates need a minimum of '.self::MIN_GATES.' = '
                    .self::MIN_UNIVERSAL_GATES.' universal + '.self::MIN_DOMAIN_SPECIFIC_GATES.' domain-specific',
            ),
            'item_11_maturity_l0' => $this->check(
                $maturity === self::INITIAL_MATURITY,
                'maturity_level must start at '.self::INITIAL_MATURITY.' (a new domain may not self-promote)',
            ),
            'item_12_architect_approved' => $this->check(
                (bool) ($proposal['architect_approved'] ?? false),
                'an Architect review approval must be registered',
            ),
        ];
    }

    /**
     * Schema-level validity (doc "Schema atlas.domain.v1"): the required keys are
     * present and non-empty, and the sovereignty class is in the closed set.
     *
     * @param array<string,mixed> $proposal
     */
    public function schemaValid(array $proposal): bool
    {
        $requiredNonEmpty = [
            'schema', 'id', 'human_name', 'scope', 'sovereignty_class', 'maturity_level',
        ];
        foreach ($requiredNonEmpty as $key) {
            if (trim((string) ($proposal[$key] ?? '')) === '') {
                return false;
            }
        }

        if ((string) $proposal['schema'] !== self::DOMAIN_SCHEMA_ID) {
            return false;
        }

        if (! in_array((string) $proposal['sovereignty_class'], self::SOVEREIGNTY_CLASSES, true)) {
            return false;
        }

        $listKeys = ['intents_supported', 'departments_touched', 'skills_required', 'evidence_required', 'gates'];
        foreach ($listKeys as $key) {
            if (! is_array($proposal[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Cross-dept dependency check (doc table). Returns the kind's required
     * departments and which are missing from the proposal.
     *
     * @param array<string,mixed> $proposal
     * @return array{kind:string,required:array<int,string>,missing:array<int,string>,ok:bool}
     */
    public function crossDept(array $proposal): array
    {
        $kind = strtolower(trim((string) ($proposal['kind'] ?? '')));
        $declared = array_map('strtolower', AtlasAaeosStringListNormalizer::trimmedStrings($proposal['departments_touched'] ?? []));

        $required = self::CROSS_DEPT_MATRIX[$kind] ?? [];
        $missing = array_values(array_diff($required, $declared));

        // Universal floor: even an unknown kind must satisfy >= 3 depts (item 5).
        $floorOk = count($declared) >= self::MIN_DEPARTMENTS_TOUCHED;

        return [
            'kind' => $kind,
            'required' => $required,
            'missing' => $missing,
            'ok' => $missing === [] && $floorOk,
        ];
    }

    /**
     * Security-gate rule (doc "Regras para IA"): sovereignty in
     * {sensitive, secret, cyber} requires an additional Security dept gate.
     *
     * @param array<string,mixed> $proposal
     * @return array{required:bool,present:bool,ok:bool}
     */
    public function securityGate(array $proposal): array
    {
        $sovereignty = (string) ($proposal['sovereignty_class'] ?? '');
        $required = in_array($sovereignty, self::SECURITY_GATED_SOVEREIGNTY, true);

        $depts = array_map('strtolower', AtlasAaeosStringListNormalizer::trimmedStrings($proposal['departments_touched'] ?? []));
        $gates = array_map('strtolower', AtlasAaeosStringListNormalizer::trimmedStrings($proposal['gates'] ?? []));

        // The extra gate is satisfied when Security is an explicit department AND
        // a security-flavoured gate is declared (e.g. secret_scan, sandbox_only).
        $securityDept = in_array('security', $depts, true);
        $securityGateDeclared = $this->hasSecurityFlavouredGate($gates);
        $present = $securityDept && $securityGateDeclared;

        return [
            'required' => $required,
            'present' => $present,
            'ok' => ! $required || $present,
        ];
    }

    /**
     * Split gates into the universal floor vs. the domain-specific remainder
     * (doc item 10). A gate counts as universal if its name maps to a known
     * universal concern; everything else is domain-specific.
     *
     * @param array<int,string> $gates
     * @return array{universal:int,domain_specific:int}
     */
    public function gateSplit(array $gates): array
    {
        $universal = 0;
        $domainSpecific = 0;
        foreach ($this->unique($gates) as $gate) {
            if ($this->isUniversalGate($gate)) {
                $universal++;
            } else {
                $domainSpecific++;
            }
        }

        return ['universal' => $universal, 'domain_specific' => $domainSpecific];
    }

    /**
     * @param array<string,mixed> $proposal
     */
    private function hasCanonicalDoc(array $proposal): bool
    {
        $path = trim((string) ($proposal['canonical_doc_path'] ?? ''));
        if ($path === '') {
            return false;
        }

        // Doc item 1: path must live under the canonical domains folder.
        return str_contains($path, 'docs/engineering-knowledge-base/domains/')
            && str_ends_with($path, '.md');
    }

    /**
     * @param array<int,array<string,mixed>> $dataSources
     */
    private function dataSourcesClassed(array $dataSources): bool
    {
        foreach ($dataSources as $source) {
            if (! is_array($source)) {
                return false;
            }
            $kind = trim((string) ($source['kind'] ?? ''));
            $class = trim((string) ($source['sovereignty_class'] ?? ''));
            if ($kind === '' && $class === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,string> $skills
     */
    private function allCanonicalSkills(array $skills): bool
    {
        foreach ($skills as $skill) {
            if (! str_starts_with(strtolower(trim($skill)), 'atlas.skill.')) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int,string> $gates
     */
    private function hasSecurityFlavouredGate(array $gates): bool
    {
        $needles = ['secret_scan', 'sandbox_only', 'no_external_call', 'security', 'secret'];
        foreach ($gates as $gate) {
            $g = strtolower(trim($gate));
            foreach ($needles as $needle) {
                if (str_contains($g, $needle)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isUniversalGate(string $gate): bool
    {
        $g = strtolower(trim($gate));
        foreach (self::UNIVERSAL_GATES as $universal) {
            if (str_contains($g, $universal) || str_contains($g, str_replace('_', '', $universal))) {
                return true;
            }
        }

        // Common universal aliases used across the canon.
        foreach (['evidence', 'canonical_doc', 'doc_exists', 'review_gate'] as $alias) {
            if (str_contains($g, $alias)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param bool $ok
     */
    private function check(bool $ok, string $reason): array
    {
        return ['ok' => $ok, 'reason' => $reason];
    }

    /**
     * @param array<int,string> $values
     * @return array<int,string>
     */
    private function unique(array $values): array
    {
        return array_values(array_unique(array_map(static fn (string $v): string => strtolower(trim($v)), $values)));
    }
}
