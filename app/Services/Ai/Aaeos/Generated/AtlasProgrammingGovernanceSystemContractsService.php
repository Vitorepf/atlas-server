<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Atlas Programming Governance System Contracts — pure, deterministic
 * field-completeness validator for the seven governed-programming contracts.
 *
 * The INDEX doc (flow, scope ceremony, Atlas Dev fast-lane mapping,
 * non-relaxation law) is decided by
 * {@see AtlasProgrammingGovernanceSystemService}. This service encodes the
 * distinct, decidable content of the CONTRACTS doc that the index decider does
 * NOT cover: the per-contract required-field schemas and the doc's explicit
 * rejection rules.
 *
 * The contracts doc is a set of minimum boundaries, each with a named list of
 * required fields ("Contratos") plus hard rules ("Regras para IA",
 * "failure_modes"):
 *
 *   - Contract 1 Feature Placement: must declare domain, module/service,
 *     canonical docs, likely files, forbidden/dangerous files and which system
 *     owns the change.
 *   - Contract 2 Spec Before Code: must declare objective, canonical context,
 *     expected behavior, likely files/modules, inputs, outputs, risks, tests,
 *     evidence required, rollback/containment and completion criteria. A
 *     retroactive spec is a process FAILURE ("Spec retroativa e falha de
 *     processo." / "Nao usar spec retroativa.").
 *   - Contract 3 Task Contract: must declare allowed_files, forbidden_files,
 *     expected_files, owner, dependencies, risk_level, validation_commands,
 *     acceptance_criteria, rollback, evidence_required, docs_required and
 *     cartography_required. A diff outside the contract is blocked, justified
 *     or escalated ("Diff fora do contrato e bloqueado, justificado ou
 *     escalado.").
 *   - Contract 4 Code Intelligence: must consult likely files, related
 *     symbols, routes/commands/jobs/migrations, existing tests, canonical docs,
 *     dependencies, history/evidence, risks and forbidden zones. "Nao programar
 *     por memoria quando existe contexto indexado."
 *   - Contract 5 Evidence: must carry spec id, task id, agent/runner, changed
 *     files, executed commands, test result, updated docs, updated cartography,
 *     errors, residual risk and completion decision. Evidence may NOT be
 *     reduced to a textual summary ("Nao reduzir evidence para resumo
 *     textual."): a textual-only payload missing the mechanical proof fields is
 *     rejected.
 *   - Contract 6 Learning: a failure / repair / missing context / weak gate /
 *     absent test / drift becomes a learning proposal that goes through review;
 *     learning never auto-applies governance ("Nao promover learning sem
 *     review.").
 *   - Contract 7 Cartography: a structural change must declare cartographic
 *     impact relating feature, spec, module, file, symbol, test, evidence and
 *     decision.
 *
 * Everything here is in-memory and side-effect free. The service judges a
 * candidate payload against a contract's documented schema/rules; it never
 * executes, never touches a database, never calls a provider.
 *
 * @see docs/engineering-knowledge-base/atlas-programming-governance-system-contracts.md
 */
final class AtlasProgrammingGovernanceSystemContractsService
{
    /** Stable verdict kind this decider emits. */
    public const VERDICT_KIND = 'atlas.programming.governance.contracts';

    /** The seven governed-programming contracts, in doc order. */
    public const CONTRACT_FEATURE_PLACEMENT = 'feature_placement';
    public const CONTRACT_SPEC_BEFORE_CODE = 'spec_before_code';
    public const CONTRACT_TASK_CONTRACT = 'task_contract';
    public const CONTRACT_CODE_INTELLIGENCE = 'code_intelligence';
    public const CONTRACT_EVIDENCE = 'evidence';
    public const CONTRACT_LEARNING = 'learning';
    public const CONTRACT_CARTOGRAPHY = 'cartography';

    /**
     * Required fields per contract (doc "Contratos"). Each list is the minimum
     * boundary; a payload missing ANY listed field fails the contract.
     *
     * @var array<string,list<string>>
     */
    private const REQUIRED_FIELDS = [
        self::CONTRACT_FEATURE_PLACEMENT => [
            'domain',
            'module',
            'canonical_docs',
            'likely_files',
            'forbidden_files',
            'owning_system',
        ],
        self::CONTRACT_SPEC_BEFORE_CODE => [
            'objective',
            'canonical_context',
            'expected_behavior',
            'likely_files',
            'inputs',
            'outputs',
            'risks',
            'tests',
            'evidence_required',
            'rollback',
            'completion_criteria',
        ],
        self::CONTRACT_TASK_CONTRACT => [
            'allowed_files',
            'forbidden_files',
            'expected_files',
            'owner',
            'dependencies',
            'risk_level',
            'validation_commands',
            'acceptance_criteria',
            'rollback',
            'evidence_required',
            'docs_required',
            'cartography_required',
        ],
        self::CONTRACT_CODE_INTELLIGENCE => [
            'likely_files',
            'related_symbols',
            'routes_commands_jobs_migrations',
            'existing_tests',
            'canonical_docs',
            'dependencies',
            'history_evidence',
            'risks',
            'forbidden_zones',
        ],
        self::CONTRACT_EVIDENCE => [
            'spec_id',
            'task_id',
            'agent_runner',
            'changed_files',
            'executed_commands',
            'test_result',
            'updated_docs',
            'updated_cartography',
            'errors',
            'residual_risk',
            'completion_decision',
        ],
        self::CONTRACT_LEARNING => [
            'trigger',
            'observation',
            'proposal',
            'review_state',
        ],
        self::CONTRACT_CARTOGRAPHY => [
            'feature',
            'spec',
            'module',
            'file',
            'symbol',
            'test',
            'evidence',
            'decision',
        ],
    ];

    /**
     * The mechanical-proof fields of an Evidence payload (doc "Contrato 5").
     * If a payload supplies only a textual summary and none of these proof
     * fields carry real values, it is rejected ("Nao reduzir evidence para
     * resumo textual.").
     *
     * @var list<string>
     */
    private const EVIDENCE_PROOF_FIELDS = [
        'executed_commands',
        'test_result',
        'changed_files',
    ];

    /** Disposition of a diff that falls outside its task contract. */
    public const DIFF_BLOCKED = 'blocked';
    public const DIFF_JUSTIFIED = 'justified';
    public const DIFF_ESCALATED = 'escalated';

    /** Disposition of a learning proposal (never auto-applies governance). */
    public const LEARNING_PENDING_REVIEW = 'pending_review';
    public const LEARNING_ACCEPTED = 'accepted';
    public const LEARNING_REJECTED = 'rejected';

    /**
     * Names of the seven contracts, in doc order.
     *
     * @return list<string>
     */
    public function contracts(): array
    {
        return array_keys(self::REQUIRED_FIELDS);
    }

    /**
     * The documented required fields for one contract.
     *
     * @return list<string>
     */
    public function requiredFields(string $contract): array
    {
        return self::REQUIRED_FIELDS[$this->normalize($contract)] ?? [];
    }

    /**
     * Validate a candidate payload against a contract's documented schema.
     *
     * A field counts as PRESENT only when it is supplied AND non-empty: a key
     * mapped to null, '' or [] does not satisfy a required field (the doc
     * requires the boundary to be actually declared, not merely keyed). The
     * verdict is invalid when any required field is missing.
     *
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function validateContract(string $contract, array $payload): array
    {
        $key = $this->normalize($contract);
        $known = isset(self::REQUIRED_FIELDS[$key]);

        if (! $known) {
            return [
                'kind' => self::VERDICT_KIND,
                'contract' => $contract,
                'known_contract' => false,
                'valid' => false,
                'missing' => [],
                'reasons' => ['unknown_contract:'.$contract],
            ];
        }

        $missing = [];
        foreach (self::REQUIRED_FIELDS[$key] as $field) {
            if (! $this->present($payload, $field)) {
                $missing[] = $field;
            }
        }

        $reasons = array_map(static fn (string $f): string => 'missing_required_field:'.$f, $missing);

        return [
            'kind' => self::VERDICT_KIND,
            'contract' => $key,
            'known_contract' => true,
            'valid' => $missing === [],
            'missing' => array_values($missing),
            'reasons' => $reasons,
        ];
    }

    /**
     * Gate a Spec-Before-Code submission (doc "Contrato 2").
     *
     * Two independent failures: (a) the spec is retroactive — declared at or
     * after the code already exists — which is a process failure regardless of
     * field completeness ("Spec retroativa e falha de processo."); (b) the spec
     * omits a required field. A retroactive-but-complete spec STILL fails.
     *
     * @param  array<string,mixed>  $spec
     * @return array<string,mixed>
     */
    public function gateSpecBeforeCode(array $spec): array
    {
        $fieldCheck = $this->validateContract(self::CONTRACT_SPEC_BEFORE_CODE, $spec);

        $retroactive = ($spec['retroactive'] ?? false) === true
            || ($spec['code_already_exists'] ?? false) === true;

        $reasons = $fieldCheck['reasons'];
        if ($retroactive) {
            $reasons[] = 'retroactive_spec_is_process_failure';
        }

        return [
            'kind' => self::VERDICT_KIND,
            'contract' => self::CONTRACT_SPEC_BEFORE_CODE,
            'retroactive' => $retroactive,
            'missing' => $fieldCheck['missing'],
            'valid' => $fieldCheck['valid'] && ! $retroactive,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Gate an Evidence submission (doc "Contrato 5").
     *
     * Beyond field completeness, evidence may not collapse to prose: if every
     * mechanical-proof field (executed commands, test result, changed files) is
     * empty, the payload is a textual-only summary and is rejected even when a
     * narrative is present ("Nao reduzir evidence para resumo textual.").
     *
     * @param  array<string,mixed>  $evidence
     * @return array<string,mixed>
     */
    public function gateEvidence(array $evidence): array
    {
        $fieldCheck = $this->validateContract(self::CONTRACT_EVIDENCE, $evidence);

        $hasProof = false;
        foreach (self::EVIDENCE_PROOF_FIELDS as $field) {
            if ($this->present($evidence, $field)) {
                $hasProof = true;
                break;
            }
        }
        $textualOnly = ! $hasProof;

        $reasons = $fieldCheck['reasons'];
        if ($textualOnly) {
            $reasons[] = 'evidence_reduced_to_textual_summary';
        }

        return [
            'kind' => self::VERDICT_KIND,
            'contract' => self::CONTRACT_EVIDENCE,
            'textual_only' => $textualOnly,
            'missing' => $fieldCheck['missing'],
            'valid' => $fieldCheck['valid'] && ! $textualOnly,
            'reasons' => array_values($reasons),
        ];
    }

    /**
     * Decide the disposition of a code diff relative to its task contract (doc
     * "Contrato 3": "Diff fora do contrato e bloqueado, justificado ou
     * escalado.").
     *
     * A diff touching a path in the contract's forbidden_files is escalated.
     * A diff that stays within allowed_files is in-contract (no action). A diff
     * that strays into a path that is neither allowed nor forbidden is
     * out-of-contract: it is BLOCKED, unless an explicit justification is
     * attached, in which case it is JUSTIFIED. The disposition is never
     * "silently allowed".
     *
     * @param  array<string,mixed>  $contract  must carry allowed_files / forbidden_files
     * @param  list<string>  $diffFiles  paths the diff touches
     * @return array<string,mixed>
     */
    public function classifyDiff(array $contract, array $diffFiles, bool $justified = false): array
    {
        $allowed = array_values(array_filter((array) ($contract['allowed_files'] ?? []), 'is_string'));
        $forbidden = array_values(array_filter((array) ($contract['forbidden_files'] ?? []), 'is_string'));

        $forbiddenHits = array_values(array_intersect($diffFiles, $forbidden));
        $outside = array_values(array_diff($diffFiles, $allowed, $forbidden));

        if ($forbiddenHits !== []) {
            return [
                'kind' => self::VERDICT_KIND,
                'in_contract' => false,
                'disposition' => self::DIFF_ESCALATED,
                'forbidden_hits' => $forbiddenHits,
                'outside_files' => $outside,
                'reason' => 'Diff touches forbidden_files; must be escalated, never silently merged.',
            ];
        }

        if ($outside === []) {
            return [
                'kind' => self::VERDICT_KIND,
                'in_contract' => true,
                'disposition' => 'in_contract',
                'forbidden_hits' => [],
                'outside_files' => [],
                'reason' => 'Diff stays within allowed_files.',
            ];
        }

        return [
            'kind' => self::VERDICT_KIND,
            'in_contract' => false,
            'disposition' => $justified ? self::DIFF_JUSTIFIED : self::DIFF_BLOCKED,
            'forbidden_hits' => [],
            'outside_files' => $outside,
            'reason' => $justified
                ? 'Out-of-contract diff carries an explicit justification.'
                : 'Out-of-contract diff is blocked until justified or escalated.',
        ];
    }

    /**
     * Decide whether a learning proposal may apply governance (doc "Contrato 6":
     * "Learning nao autoaplica governanca; ele passa por review." /
     * "Nao promover learning sem review.").
     *
     * Learning never self-applies: a proposal may only take effect once a human
     * review has explicitly accepted it. Pending or rejected proposals — and any
     * attempt to apply without review — are not applied.
     *
     * @param  array<string,mixed>  $learning
     * @return array<string,mixed>
     */
    public function evaluateLearning(array $learning): array
    {
        $reviewState = is_string($learning['review_state'] ?? null)
            ? $learning['review_state']
            : self::LEARNING_PENDING_REVIEW;

        $reviewed = ($learning['reviewed'] ?? false) === true
            || in_array($reviewState, [self::LEARNING_ACCEPTED, self::LEARNING_REJECTED], true);

        $applies = $reviewed && $reviewState === self::LEARNING_ACCEPTED;

        $reasons = [];
        if (! $reviewed) {
            $reasons[] = 'learning_not_reviewed';
        }
        if ($reviewState !== self::LEARNING_ACCEPTED) {
            $reasons[] = 'learning_not_accepted:'.$reviewState;
        }

        return [
            'kind' => self::VERDICT_KIND,
            'contract' => self::CONTRACT_LEARNING,
            'review_state' => $reviewState,
            'reviewed' => $reviewed,
            'applies_governance' => $applies,
            'reasons' => $applies ? [] : array_values($reasons),
        ];
    }

    /**
     * Whether a required field is actually present (supplied and non-empty).
     *
     * @param  array<string,mixed>  $payload
     */
    private function present(array $payload, string $field): bool
    {
        if (! array_key_exists($field, $payload)) {
            return false;
        }

        $value = $payload[$field];

        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        // bool/int/float (e.g. risk_level=0, cartography_required=false) count as
        // declared: the boundary was stated.
        return true;
    }

    private function normalize(string $contract): string
    {
        return str_replace('-', '_', strtolower(trim($contract)));
    }
}
