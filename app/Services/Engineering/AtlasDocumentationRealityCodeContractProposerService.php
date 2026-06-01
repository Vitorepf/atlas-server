<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Semantic\FrontmatterParser;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * L1-P2 — Generative / Self-Healing, THIRD increment: the doc-AHEAD-of-code
 * direction — the SAFE form of the documented "code-from-spec" P2 increment.
 *
 * The first two P2 increments handle the two DOC-SIDE directions:
 *   - OVER-claim (AtlasDocumentationRealityRepairProposerService): the doc claims a
 *     HIGHER state than the index proves -> downgrade the doc / supply evidence.
 *   - UNDER-claim (AtlasDocumentationRealityBidirectionalReconciliationService): the
 *     code resolves MORE than the doc claims -> upgrade the doc to match reality.
 *
 * This service adds the MISSING third direction and COMPLETES the P2 reconciliation
 * triangle: doc-AHEAD-of-code. A doc is LAW. When a doc DECLARES evidence_refs that
 * NAME specific code — a symbol/command/test/route/receipt — which do NOT resolve in
 * the code-intelligence index, the doc has named code that does not exist yet. The
 * inverse of the under-claim direction (which raises the DOC) is to propose building
 * the missing CODE so a human makes the CODE catch up to the LAW. This service emits
 * the CONTRACT to build it: a skeleton signature DESCRIPTION + a test OUTLINE.
 *
 * ABSOLUTE SAFETY ("auto-reparo cego"/blind code-generation is named as THE risk of
 * the code-from-spec direction in atlas-documentation-reality-generative-leap.md):
 * this is strictly READ-ONLY and PROPOSAL-ONLY. It NEVER generates or writes runnable
 * code, NEVER writes any file, NEVER executes any command, NEVER auto-applies. Every
 * contract item is a DESCRIPTION (a signature string + intent), explicitly
 * is_code=false and must_be_implemented_by_human=true. The verifying test is an
 * OUTLINE (given/when/then), NOT real test code — exactly like the P3 antibody
 * proposer's reproducing-test outline. The actual code-from-spec GENERATION onto disk
 * is the deliberately UNBUILT, risky form and is out of scope here, forever in this
 * service: a structural guard makes an unsafe emission impossible (see below).
 *
 * It NEVER invents a ref the doc did not declare: every contract item is keyed to a
 * ref the doc itself authored in its evidence_refs and that the index could not
 * resolve. The set of refs is bounded by the doc; this service only describes what it
 * would take to make each named-but-missing ref resolve.
 *
 * Degrade-SAFE: an empty code-intelligence index makes EVERY declared ref look
 * unresolved (mass false-gap), which would fabricate a corpus-wide "build all this
 * code" plan. The over-claim proposer already withholds on a present-but-empty index;
 * this service REUSES the same index-health verdict and withholds (degraded=true,
 * proposals=[], degraded_reason=code_intelligence_index_empty_or_absent_contracts_withheld).
 *
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-code-contract-proposals.md
 * @see docs/engineering-knowledge-base/atlas-documentation-reality-generative-leap.md
 */
class AtlasDocumentationRealityCodeContractProposerService
{
    public const SCHEMA = 'atlas.documentation_reality.code_contract.v1';

    /**
     * Resolved status for every contract proposal. There is no other status: a
     * contract is a proposal awaiting a human, never self-built code.
     */
    public const STATUS = 'proposed_requires_human_implementation';

    /**
     * Status frontmatter values that mark a doc as NON-runtime-claiming, i.e. a doc
     * that labeled ITSELF as not-yet-runtime (a target/spec/source artifact). We
     * RESPECT a doc that self-labels non-runtime exactly as the multi-estate service
     * respects a sovereignty data-class: a non-runtime doc that names unresolved code
     * is describing a FUTURE, not over-claiming a present gap, so it is SKIPPED.
     *
     * @var array<int,string>
     */
    private const NON_RUNTIME_STATUSES = ['template', 'source_material', 'future', 'source'];

    /**
     * The implementation_state tiers (normalized) that are runtime-CLAIMING with a
     * gap worth a contract: spec and partial. `verified` is excluded — a verified doc
     * has NO gap (every tier already resolves), so a stray unresolved ref there is not
     * a doc-ahead-of-code build target.
     *
     * @var array<int,string>
     */
    private const CLAIMING_STATES = ['spec', 'partial'];

    /**
     * Recommended order in which a human should make the code catch up to the doc:
     * the symbol first (the thing exists), then how it is reached (command/route),
     * then the test that proves it, then the receipt a real run PRODUCES.
     *
     * @var array<int,string>
     */
    private const RECOMMENDED_ORDER = ['symbol', 'command', 'route', 'test', 'receipt'];

    public function __construct(
        private readonly AtlasAaeosImplementationTruthService $truth,
        private readonly FrontmatterParser $frontmatter,
    ) {}

    /**
     * Scan the whole capability truth ledger and emit a code-CONTRACT proposal for
     * every runtime-claiming doc that NAMED code (evidence_refs) which the index
     * cannot resolve.
     *
     * @return array<string,mixed>
     */
    public function proposeAll(): array
    {
        return $this->propose(null);
    }

    /**
     * Same as proposeAll() but restricted to a single owner doc (id/slug or path
     * substring, exactly like the maturity ledger filter).
     *
     * @return array<string,mixed>
     */
    public function proposeForDoc(string $ownerDoc): array
    {
        return $this->propose($ownerDoc);
    }

    /**
     * @return array<string,mixed>
     */
    private function propose(?string $capability): array
    {
        // Degrade-SAFE against a BLIND index: every evidence_ref is resolved against
        // the code-intelligence index. If that index is present but EMPTY, EVERY
        // declared ref would falsely look unresolved and we would hand a human a
        // corpus-wide "build all this code" plan. Withhold and degrade instead.
        if (! $this->indexHealthy()) {
            return $this->degradedEnvelope($capability);
        }

        $ledger = $this->truth->ledger($capability);
        $rows = (array) ($ledger['capabilities'] ?? []);

        $proposals = [];
        $totalUnresolved = 0;
        $byKind = ['symbol' => 0, 'command' => 0, 'route' => 0, 'test' => 0, 'receipt' => 0];

        foreach ($rows as $row) {
            if (! $this->rowQualifies($row)) {
                continue;
            }

            $unresolvedRefs = $this->unresolvedDeclaredRefs($row);
            // Target population rule (iii): only a doc with >=1 DECLARED-but-unresolved
            // ref qualifies. A claiming doc whose every declared ref resolves has no
            // doc-ahead-of-code gap and is skipped (no false gap).
            if ($unresolvedRefs === []) {
                continue;
            }

            $proposal = $this->proposalFor($row, $unresolvedRefs);
            foreach ($proposal['contract_items'] as $item) {
                $totalUnresolved++;
                $kind = (string) $item['kind'];
                if (array_key_exists($kind, $byKind)) {
                    $byKind[$kind]++;
                }
            }
            $proposals[] = $proposal;
        }

        $envelope = [
            'schema_version' => self::SCHEMA,
            'mode' => 'doc_ahead_of_code_contract_proposer',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'code_from_spec_safe_contract_proposer_no_code_generation',
            'direction' => 'doc_ahead_of_code',
            'capability_filter' => $capability,
            'summary' => [
                'docs_with_gaps' => count($proposals),
                'total_unresolved_refs' => $totalUnresolved,
                'by_kind' => $byKind,
                'ledger_evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
            ],
            'recommended_order' => self::RECOMMENDED_ORDER,
            'degraded' => false,
            'proposals' => $proposals,
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ];

        return $this->finalize($envelope);
    }

    /**
     * The code-intelligence index is "healthy enough to trust ref resolution" when its
     * symbol table holds at least one active row. An ABSENT table is a non-production
     * /test context (a real checkout always has it migrated), so resolution is trusted
     * there; a PRESENT-but-EMPTY table is the dangerous blind index and must degrade.
     * Identical verdict to the over-claim proposer, kept consistent on purpose.
     */
    private function indexHealthy(): bool
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            return true;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->limit(1)
            ->exists();
    }

    /**
     * A ledger row qualifies for a code contract ONLY when ALL hold:
     *   (i)  it is runtime-CLAIMING: normalized implementation_state in {spec, partial}
     *        (NEVER verified — verified has no gap) AND the doc status is NOT a
     *        non-runtime self-label (template/source_material/future/source);
     *   (ii) it DECLARED >=1 evidence_ref (the ledger only carries docs with evidence,
     *        but we re-assert it so the rule is explicit and producer-independent).
     * Rule (iii) — >=1 declared ref unresolved — is checked by the caller via
     * unresolvedDeclaredRefs(), so a doc whose refs all resolve never reaches here as
     * a gap.
     *
     * @param  array<string,mixed>  $row
     */
    private function rowQualifies(array $row): bool
    {
        // (i-a) implementation_state gate. Use the ledger's NORMALIZED claimed_state
        // (spec/partial/verified) — the same 3-tier map the truth layer uses — so the
        // heterogeneous raw implementation_state vocabulary collapses correctly.
        $claimedState = (string) ($row['claimed_state'] ?? 'spec');
        if (! in_array($claimedState, self::CLAIMING_STATES, true)) {
            return false;
        }

        // (i-b) self-label gate. RESPECT a doc that labeled ITSELF as non-runtime via
        // ANY self-label field (status / graph_kind / type): a non-runtime doc that
        // names unresolved code is describing a FUTURE, not over-claiming a present gap.
        // Skip it.
        if ($this->selfLabelsNonRuntime($row)) {
            return false;
        }

        // (ii) declared >=1 evidence_ref.
        return $this->declaredRefs($row) !== [];
    }

    /**
     * Whether the doc labeled ITSELF as non-runtime through ANY self-label frontmatter
     * field — `status`, `graph_kind`, or `type`. A doc may declare its non-runtime
     * nature through any of them (e.g. `graph_kind: template`, `type: source_material`)
     * while still carrying `status: active`; we respect the self-label wherever it is
     * declared, exactly as the multi-estate service respects a sovereignty data-class.
     *
     * @param  array<string,mixed>  $row
     */
    private function selfLabelsNonRuntime(array $row): bool
    {
        foreach ($this->selfLabelValues($row) as $value) {
            if (in_array($value, self::NON_RUNTIME_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The doc's self-label frontmatter values (status, graph_kind, type), lower-cased
     * and trimmed. Prefers in-row values (test determinism / future ledger enrichment);
     * otherwise reads the owner doc's frontmatter once from disk via the canonical
     * parser. An unreadable/absent doc yields empty labels — NOT non-runtime — so an
     * unreadable doc is never silently skipped; the other two gates (claiming state +
     * an actual unresolved declared ref) still bound the population tightly.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,string>
     */
    private function selfLabelValues(array $row): array
    {
        $fields = ['status', 'graph_kind', 'type'];

        $haveInRow = false;
        foreach ($fields as $field) {
            if (array_key_exists($field, $row) && is_scalar($row[$field])) {
                $haveInRow = true;
                break;
            }
        }

        $fm = $haveInRow ? $row : $this->ownerFrontmatter($row);

        $values = [];
        foreach ($fields as $field) {
            $raw = $fm[$field] ?? '';
            if (is_scalar($raw)) {
                $values[] = strtolower(trim((string) $raw));
            }
        }

        return $values;
    }

    /**
     * The owner doc's parsed frontmatter, or [] when the doc path is empty/unreadable.
     *
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private function ownerFrontmatter(array $row): array
    {
        $ownerDoc = (string) ($row['owner_doc'] ?? '');
        if ($ownerDoc === '') {
            return [];
        }

        $absolute = base_path($ownerDoc);
        if (! File::exists($absolute)) {
            return [];
        }

        $parsed = $this->frontmatter->parse(File::get($absolute));

        return is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
    }

    /**
     * Every evidence_ref the doc DECLARED, as {kind, ref}, from the ledger row's own
     * per-ref evidence list. Never invents a ref: this is exactly what the doc
     * authored. Empty/junk entries are dropped.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function declaredRefs(array $row): array
    {
        $refs = [];
        foreach ((array) ($row['evidence'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $kind = strtolower(trim((string) ($entry['kind'] ?? '')));
            $ref = trim((string) ($entry['ref'] ?? ''));
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * The subset of DECLARED refs that did NOT resolve in the index — the named code
     * that does not exist yet. Straight from the ledger row's per-ref resolved flag;
     * we never re-resolve and never invent. These are the exact build targets.
     *
     * @param  array<string,mixed>  $row
     * @return array<int,array{kind:string, ref:string}>
     */
    private function unresolvedDeclaredRefs(array $row): array
    {
        $refs = [];
        foreach ((array) ($row['evidence'] ?? []) as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            // Only DECLARED-but-UNRESOLVED refs. A resolved ref is code that already
            // exists — no contract needed. resolved must be strictly true to count as
            // "exists"; anything else (false/absent/non-bool) is treated as unresolved.
            if (($entry['resolved'] ?? false) === true) {
                continue;
            }
            $kind = strtolower(trim((string) ($entry['kind'] ?? '')));
            $ref = trim((string) ($entry['ref'] ?? ''));
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * Build the code-contract proposal for one qualifying doc: one contract item per
     * declared-but-unresolved ref, plus the recommended build order. Every item is a
     * DESCRIPTION; none generates or writes code (the per-item structural guard below
     * makes an unsafe item impossible to emit).
     *
     * @param  array<string,mixed>  $row
     * @param  array<int,array{kind:string, ref:string}>  $unresolvedRefs
     * @return array<string,mixed>
     */
    private function proposalFor(array $row, array $unresolvedRefs): array
    {
        $items = [];
        foreach ($unresolvedRefs as $ref) {
            $items[] = $this->guardContractItem($this->contractItemFor($ref));
        }

        return [
            'owner_doc' => (string) ($row['owner_doc'] ?? ''),
            'capability_id' => (string) ($row['capability_id'] ?? ''),
            'claimed_state' => (string) ($row['claimed_state'] ?? 'spec'),
            'computed_state' => (string) ($row['computed_state'] ?? 'spec'),
            'direction' => 'doc_ahead_of_code',
            // The doc named this code as LAW; the code must catch up to the doc.
            'intent' => 'the doc DECLARED this code as law but the index cannot resolve it; propose the contract so a human makes the CODE catch up to the doc',
            'contract_items' => $items,
            'recommended_order' => self::RECOMMENDED_ORDER,
            'status' => self::STATUS,
            'safety' => [
                'read_only' => true,
                'generates_code' => false,
                'writes' => false,
                'auto_applies' => false,
                'proposes_contract_only' => true,
                'only_doc_declared_refs' => true,
            ],
        ];
    }

    /**
     * Map one declared-but-unresolved ref to its contract item. Each item is a
     * DESCRIPTION keyed to the kind:
     *   - symbol  -> a proposed class/method signature DESCRIPTION;
     *   - command -> a proposed artisan command signature DESCRIPTION;
     *   - route   -> a route DESCRIPTION;
     *   - test    -> a given/when/then test OUTLINE (must_fail_before_implemented:true);
     *   - receipt -> a note that a GENUINE receipt must be PRODUCED by a real run,
     *                never hand-written.
     * Every item carries is_code=false and must_be_implemented_by_human=true. None
     * carries runnable code or a path to write.
     *
     * @param  array{kind:string, ref:string}  $ref
     * @return array<string,mixed>
     */
    protected function contractItemFor(array $ref): array
    {
        $kind = $ref['kind'];
        $value = $ref['ref'];

        $base = [
            'kind' => $kind,
            'ref' => $value,
            'is_code' => false,
            'must_be_implemented_by_human' => true,
        ];

        return match ($kind) {
            'symbol' => $base + [
                'contract' => "Propose a class/method named `{$value}` so the symbol the doc declared resolves in the index. CONTRACT (description, not code): declare the signature `{$value}(/* typed params */): /* return type */` with a docblock stating its single responsibility as the doc describes it. The human writes the real implementation through the existing gates + Evidence Ledger; this is a signature DESCRIPTION only.",
                'proposed_signature' => "{$value}(/* typed params */): /* return type */",
            ],
            'command' => $base + [
                'contract' => "Propose an artisan command whose signature contains `{$value}` so the command the doc declared resolves. CONTRACT (description, not code): an auto-discovered Command with signature `{$value} {--json}` that is read-only unless the doc says otherwise, delegating to its service. The human writes the real command; this is a signature DESCRIPTION only.",
                'proposed_signature' => "{$value} {--json}",
            ],
            'route' => $base + [
                'contract' => "Propose a route matching `{$value}` so the route the doc declared resolves. CONTRACT (description, not code): a route entry binding `{$value}` to its controller/action with the method and middleware the doc implies. The human wires the real route; this is a route DESCRIPTION only.",
                'proposed_route' => $value,
            ],
            'test' => $base + [
                'contract' => "Propose a test named `{$value}` that PROVES the declared capability, written by a human BEFORE the capability is called done. This is a test OUTLINE, never runnable test code.",
                'test_outline' => $this->testOutline($value),
                'must_fail_before_implemented' => true,
            ],
            'receipt' => $base + [
                'contract' => "The doc declared a receipt at `{$value}` that does not exist. A GENUINE receipt MUST be PRODUCED by a real run of the capability — it is evidence of an actual execution and MUST NOT be hand-written or fabricated to satisfy the index. CONTRACT (description, not code): run the real capability through the existing gates + Evidence Ledger so the run emits `{$value}`; only a produced artifact counts.",
                'must_be_produced_by_real_run' => true,
                'never_hand_written' => true,
            ],
            // Any other declared kind (e.g. migration): a neutral, code-free contract
            // description. Still is_code=false, still human-implemented.
            default => $base + [
                'contract' => "The doc declared a `{$kind}` ref `{$value}` that does not resolve. CONTRACT (description, not code): make `{$value}` exist as the doc describes, through the existing gates + Evidence Ledger. This is a DESCRIPTION only; no code is generated here.",
            ],
        };
    }

    /**
     * A given/when/then OUTLINE for the proposed verifying test — the SAME outline
     * shape the P3 antibody proposer uses for its reproducing-test outline. It is a
     * DESCRIPTION of the test a human must write, never runnable test code. The test
     * must FAIL before the capability is implemented (red-before-green), proving it
     * actually exercises the missing code.
     *
     * @return array<string,mixed>
     */
    private function testOutline(string $testRef): array
    {
        return [
            'description' => "Outline (not code) for `{$testRef}`: a test that exercises the capability the doc declares and FAILS until the code the doc named exists, then passes once it does.",
            'given_when_then' => [
                'given' => 'the capability the doc declares as law, with the symbol/command/route it names',
                'when' => 'that capability is exercised exactly as the doc specifies',
                'then' => 'the test asserts the documented behavior — RED before the code exists, GREEN once the human implements it',
            ],
            'steps' => [
                'arrange the inputs/fixtures the documented capability requires',
                'act: exercise the capability as the doc specifies',
                'assert the documented outcome (this fails until the named code exists)',
            ],
            'must_fail_before_implemented' => true,
        ];
    }

    /**
     * STRUCTURAL GUARD (mirrors the P3 antibody proposer's LogicException invariant):
     * a contract item that is missing its is_code=false marker, or carries any
     * code-ish / write-ish field, is structurally UNEMITTABLE. If any item would be
     * emitted with is_code!==false, or with a populated runnable-code / file / path-to
     * -write field, we throw — making an unsafe emission impossible rather than a
     * matter of discipline. This is the in-code proof that the risky code-from-spec
     * GENERATION form cannot leak through this service.
     *
     * @param  array<string,mixed>  $item
     * @return array<string,mixed>
     */
    protected function guardContractItem(array $item): array
    {
        // is_code MUST be present AND strictly boolean false.
        if (($item['is_code'] ?? null) !== false) {
            throw new LogicException('Invariant violation: every contract item must be is_code=false; this service never emits code.');
        }

        // must_be_implemented_by_human MUST be present AND strictly true.
        if (($item['must_be_implemented_by_human'] ?? null) !== true) {
            throw new LogicException('Invariant violation: every contract item must be must_be_implemented_by_human=true.');
        }

        // No field may carry runnable code, a file, or a path to write. Presence of
        // ANY of these (with a non-empty value) means the item crossed from
        // DESCRIPTION into generation/writing — structurally forbidden.
        foreach (self::forbiddenItemFields() as $forbidden) {
            if (array_key_exists($forbidden, $item) && ! $this->isEmptyValue($item[$forbidden])) {
                throw new LogicException(
                    "Invariant violation: a contract item carried a forbidden field '{$forbidden}'; this service never generates or writes code.",
                );
            }
        }

        return $item;
    }

    /**
     * Fields whose presence (non-empty) would mean an item generates or writes code,
     * names a file to create, or a path to write. None of these may ever appear with a
     * value on an emitted contract item.
     *
     * @return array<int,string>
     */
    private static function forbiddenItemFields(): array
    {
        return [
            'code', 'source_code', 'generated_code', 'runnable_code', 'body',
            'file', 'file_path', 'path_to_write', 'write_path', 'target_path',
            'create_file', 'created_file', 'generated_file', 'file_written',
            'apply', 'auto_apply', 'install', 'execute', 'exec', 'command_to_run',
        ];
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null || $value === false) {
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

    /**
     * @return array<string,bool>
     */
    private function claimPolicy(): array
    {
        return [
            'read_only' => true,
            'generates_code' => false,
            'writes' => false,
            'auto_applies' => false,
            'proposes_contract_only' => true,
            'only_doc_declared_refs' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function degradedEnvelope(?string $capability): array
    {
        return $this->finalize([
            'schema_version' => self::SCHEMA,
            'mode' => 'doc_ahead_of_code_contract_proposer',
            'pillar' => 'P2_generative_self_healing',
            'increment' => 'code_from_spec_safe_contract_proposer_no_code_generation',
            'direction' => 'doc_ahead_of_code',
            'capability_filter' => $capability,
            'degraded' => true,
            'degraded_reason' => 'code_intelligence_index_empty_or_absent_contracts_withheld',
            'summary' => [
                'docs_with_gaps' => 0,
                'total_unresolved_refs' => 0,
                'by_kind' => ['symbol' => 0, 'command' => 0, 'route' => 0, 'test' => 0, 'receipt' => 0],
                'ledger_evaluated' => 0,
            ],
            'recommended_order' => self::RECOMMENDED_ORDER,
            'proposals' => [],
            'writes' => false,
            'claim_policy' => $this->claimPolicy(),
        ]);
    }

    /**
     * @param  array<string,mixed>  $envelope
     * @return array<string,mixed>
     */
    private function finalize(array $envelope): array
    {
        $hashPayload = $envelope;
        unset($hashPayload['generated_at'], $hashPayload['contract_hash']);
        $envelope['contract_hash'] = hash(
            'sha256',
            json_encode($hashPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return $envelope;
    }
}
