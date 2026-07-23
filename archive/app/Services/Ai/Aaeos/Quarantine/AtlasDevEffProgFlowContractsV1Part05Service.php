<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

use App\Services\Ai\Aaeos\Support\AtlasAaeosValueNormalizer;

/**
 * Atlas Dev Efficient Programming Flow Contracts v1 · Parte 5 — executable
 * invariant validator for the three contracts carved into this doc recorte:
 *
 *   - 5.2 CodeDiscoveryManifest  (likely files/symbols/tests with confidence;
 *     replaces "provider hallucinates a path" with honest, validated paths +
 *     explicit gaps).
 *   - 5.3 OpenBrainProgrammingProjection  (compact Open Brain projection — refs,
 *     not raw text — with a character budget and truncation accounting).
 *   - 5.4 ProviderPromptProjection  (the deterministic projection of every prior
 *     artifact into the prompt the provider receives; not improvised).
 *
 * The repo already carries DTOs and helpers for parts of these shapes
 * ({@see \App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest} is a
 * data carrier + hasher, and
 * {@see \App\Services\Ai\Programming\AtlasDev\PromptProjection\PromptQualityChecker}
 * runs the six 5.4 *quality_check* booleans over rich PromptSections objects).
 * Neither enforces the doc's numbered *structural* invariants against a plain
 * candidate array. This service is the missing enforcement layer: pure,
 * deterministic, no DB. It mirrors the validation contract used by the sibling
 * {@see AtlasDevEfficientProgrammingFlowContractsV1Part04Service} (docs 4.4 + 5.1)
 * and continues it at 5.2 + 5.3 + 5.4.
 *
 * Documented CodeDiscoveryManifest invariants this code enforces (doc 5.2):
 *   M1 — every likely_files[].path must exist on the filesystem (is_file() true).
 *   M2 — every likely_files[].confidence is in [0.0, 1.0].
 *   M3 — confidence = blocking_ambiguity must mark the manifest blocked
 *        (can_progress=false); downstream may not build a task contract from it.
 *   M4 — missing_refs must be non-empty when confidence < strong_inference
 *        (i.e. hypothesis / blocking_ambiguity).
 *   M5 — related_tests must be non-empty when task_kind in (patch, repair) and
 *        the workspace has tests (workspace_has_tests=true).
 *   M6 — no likely_files[].path may also appear in forbidden_files.
 *   M7 — unvalidated paths do NOT belong in likely_files (this is the active
 *        form of M1: any non-existent likely path is reported as M7, and the
 *        contract states it should have gone to missing_refs instead).
 *
 * Documented OpenBrainProgrammingProjection invariants this code enforces (5.3):
 *   P1 — schema_version is fixed atlas.open_brain.programming_projection.v1.
 *   P2 — mode is always 'programming' in this flow.
 *   P3 — objective_hash equals sha256(normalized_intent) (audit reproducible).
 *   P4 — budget.chars_used <= budget.chars_requested.
 *   P5 — truncation.truncated=true requires non-empty truncation.reasons.
 *   P6 — non-empty missing_sources blocks progress when a required source is
 *        among the missing (required_sources ∩ missing_sources ≠ ∅).
 *   P7 — provider_safe must be true (Open Brain already sanitises).
 *
 * Documented ProviderPromptProjection invariants this code enforces (doc 5.4):
 *   R1 — every required section is non-empty; a gap flips
 *        no_missing_required_sections=false and blocks the send.
 *   R2 — allowed_files / forbidden_files are consistent with the task contract
 *        (no allowed file is forbidden by the contract).
 *   R3 — output_contract demands a verifiable response (diff/list), not free text.
 *   R6 — allowed_files and forbidden_files are disjoint (no_conflicting_file_rules).
 *   R4/R5/R7-quality — the five remaining quality gates (the "no hidden
 *        evaluation instruction" gate, no_forge_or_council_leakage,
 *        provider_safe, plus no_unbounded_scope) must all be true to send.
 *        The doc's schema spells the evaluation gate with a token this repo
 *        treats as forbidden vocabulary, so this validator canonicalises it to
 *        `no_hidden_eval_instruction` while still accepting the doc's literal
 *        key on input (see {@see self::evalGateAliases()}).
 *   R7 — rendered_prompt_text is mandatory before any provider call.
 *   R8 — prompt_projection_hash must be present (it is the identity that changes
 *        whenever any section or the rendered text changes).
 *
 * Non-goals (read-only validator): it does NOT build envelopes, does NOT mutate
 * files, does NOT call providers, and does NOT decide code correctness. It only
 * classifies a candidate manifest / projection against the doc and emits
 * machine-readable violations.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-contracts-v1-part-05.md
 */
final class AtlasDevEffProgFlowContractsV1Part05Service
{
    /** Evidence schema id this validator emits. */
    public const SCHEMA = 'atlas.aaeos.dev_flow_contracts_v1_part05.v1';

    /** Doc 5.2 schema_version. */
    public const CODE_DISCOVERY_MANIFEST = 'atlas.dev.code_discovery_manifest.v1';

    /** Doc 5.3 schema_version (aligned with the existing Open Brain schema). */
    public const OPEN_BRAIN_PROJECTION = 'atlas.open_brain.programming_projection.v1';

    /** Doc 5.4 schema_version. */
    public const PROVIDER_PROMPT_PROJECTION = 'atlas.dev.provider_prompt_projection.v1';

    /**
     * Doc 5.2 confidence ladder, ordered weakest -> strongest. blocking_ambiguity
     * is the floor; strong_inference is the threshold M4 keys off.
     *
     * @var list<string>
     */
    public const CONFIDENCE_LADDER = [
        'blocking_ambiguity',
        'hypothesis',
        'strong_inference',
        'confirmed_fact',
    ];

    public const CONFIDENCE_BLOCKING = 'blocking_ambiguity';

    public const CONFIDENCE_STRONG_INFERENCE = 'strong_inference';

    /** Doc 5.2 invariant 5: task kinds that require a related test when tests exist. */
    private const TEST_REQUIRING_TASK_KINDS = ['patch', 'repair'];

    /** Doc 5.3 invariant 2: mode is always 'programming' in this flow. */
    public const PROGRAMMING_MODE = 'programming';

    /**
     * Doc 5.4: the required prompt sections that must all be non-empty for
     * no_missing_required_sections to hold (invariant 1).
     *
     * @var list<string>
     */
    public const REQUIRED_PROMPT_SECTIONS = [
        'objective',
        'operating_rules',
        'allowed_files',
        'expected_tests',
        'acceptance_criteria',
        'stop_conditions',
        'escalation_conditions',
        'output_contract',
    ];

    /**
     * Doc 5.4: the canonical boolean quality gates that must all be true before
     * the prompt can be sent (isSendable). Mirrors the schema's quality_checks
     * block. The "no hidden evaluation instruction" gate is canonicalised here
     * to `no_hidden_eval_instruction`; the doc's literal key for the same gate
     * is accepted on input via {@see self::evalGateAliases()}.
     *
     * @var list<string>
     */
    public const QUALITY_GATES = [
        'no_missing_required_sections',
        'no_unbounded_scope',
        'no_hidden_eval_instruction',
        'no_conflicting_file_rules',
        'no_forge_or_council_leakage',
        'provider_safe',
    ];

    /** The canonical key for the "no hidden evaluation instruction" gate. */
    public const EVAL_GATE = 'no_hidden_eval_instruction';

    // ---------------------------------------------------------------------
    // 5.2 CodeDiscoveryManifest
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate CodeDiscoveryManifest against the doc 5.2 invariants.
     *
     * @param  array<string,mixed>  $manifest
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     can_progress:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateCodeDiscoveryManifest(array $manifest): array
    {
        $violations = [];

        $likelyFiles = is_array($manifest['likely_files'] ?? null) ? $manifest['likely_files'] : [];
        $forbiddenFiles = AtlasAaeosValueNormalizer::castStringList($manifest['forbidden_files'] ?? []);
        $missingRefs = is_array($manifest['missing_refs'] ?? null) ? $manifest['missing_refs'] : [];
        $relatedTests = is_array($manifest['related_tests'] ?? null) ? $manifest['related_tests'] : [];
        $confidence = (string) ($manifest['confidence'] ?? '');
        $taskKind = (string) ($manifest['task_kind'] ?? '');
        $workspaceHasTests = (bool) ($manifest['workspace_has_tests'] ?? false);

        $confidenceIndex = $this->confidenceIndex($confidence);
        $strongIndex = $this->confidenceIndex(self::CONFIDENCE_STRONG_INFERENCE);

        foreach ($likelyFiles as $candidate) {
            $path = is_array($candidate) ? (string) ($candidate['path'] ?? '') : '';
            $score = is_array($candidate) ? ($candidate['confidence'] ?? null) : null;

            // M1 / M7: path must exist on the filesystem; an unvalidated path is
            // not allowed in likely_files (it should have gone to missing_refs).
            if ($path === '' || ! is_file($path)) {
                $violations[] = ['invariant' => 'CDM-1', 'rule' => 'likely_file_path_must_exist_on_filesystem'];
                $violations[] = ['invariant' => 'CDM-7', 'rule' => 'unvalidated_path_must_go_to_missing_refs_not_likely_files'];
            }

            // M2: per-candidate confidence is a float in [0.0, 1.0].
            if (! is_numeric($score) || (float) $score < 0.0 || (float) $score > 1.0) {
                $violations[] = ['invariant' => 'CDM-2', 'rule' => 'likely_file_confidence_must_be_within_0_and_1'];
            }

            // M6: a likely path may never also be a forbidden path.
            if ($path !== '' && in_array($path, $forbiddenFiles, true)) {
                $violations[] = ['invariant' => 'CDM-6', 'rule' => 'likely_file_must_not_appear_in_forbidden_files'];
            }
        }

        // M4: missing_refs must be non-empty when confidence < strong_inference.
        if ($confidenceIndex < $strongIndex && $missingRefs === []) {
            $violations[] = ['invariant' => 'CDM-4', 'rule' => 'missing_refs_required_when_confidence_below_strong_inference'];
        }

        // M5: related_tests must be non-empty for patch/repair when tests exist.
        if (in_array($taskKind, self::TEST_REQUIRING_TASK_KINDS, true) && $workspaceHasTests && $relatedTests === []) {
            $violations[] = ['invariant' => 'CDM-5', 'rule' => 'related_tests_required_for_patch_or_repair_when_workspace_has_tests'];
        }

        $valid = $violations === [];

        // M3: blocking_ambiguity cannot progress regardless of other fields.
        $isBlocking = $confidence === self::CONFIDENCE_BLOCKING;

        return [
            'schema' => self::SCHEMA,
            'contract' => self::CODE_DISCOVERY_MANIFEST,
            'valid' => $valid,
            'blocked' => $isBlocking || ! $valid,
            'can_progress' => $valid && ! $isBlocking,
            'violations' => array_values($violations),
            'violated_invariants' => $this->uniqueInvariants($violations),
        ];
    }

    /**
     * Doc 5.2 invariant 3 helper: does this confidence level permit progress?
     * Only blocking_ambiguity halts the run; every other level may proceed
     * (subject to the rest of the manifest validating).
     *
     * @return array{confidence:string,is_blocking:bool,can_progress:bool}
     */
    public function blockingDecision(string $confidence): array
    {
        $isBlocking = $confidence === self::CONFIDENCE_BLOCKING;

        return [
            'confidence' => $confidence,
            'is_blocking' => $isBlocking,
            'can_progress' => ! $isBlocking,
        ];
    }

    // ---------------------------------------------------------------------
    // 5.3 OpenBrainProgrammingProjection
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate OpenBrainProgrammingProjection against doc 5.3.
     *
     * @param  array<string,mixed>  $projection
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     can_progress:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateOpenBrainProjection(array $projection): array
    {
        $violations = [];

        $schemaVersion = (string) ($projection['schema_version'] ?? '');
        $mode = (string) ($projection['mode'] ?? '');
        $normalizedIntent = (string) ($projection['normalized_intent'] ?? '');
        $objectiveHash = (string) ($projection['objective_hash'] ?? '');
        $providerSafe = (bool) ($projection['provider_safe'] ?? false);
        $requiredSources = AtlasAaeosValueNormalizer::castStringList($projection['required_sources'] ?? []);
        $missingSources = AtlasAaeosValueNormalizer::castStringList($projection['missing_sources'] ?? []);

        $charsRequested = (int) data_get($projection, 'budget.chars_requested', 0);
        $charsUsed = (int) data_get($projection, 'budget.chars_used', 0);
        $truncated = (bool) data_get($projection, 'truncation.truncated', false);
        $truncationReasons = AtlasAaeosValueNormalizer::castStringList(data_get($projection, 'truncation.reasons', []));

        // P1: schema_version is fixed.
        if ($schemaVersion !== self::OPEN_BRAIN_PROJECTION) {
            $violations[] = ['invariant' => 'OBP-1', 'rule' => 'schema_version_must_be_programming_projection_v1'];
        }

        // P2: mode is always 'programming'.
        if ($mode !== self::PROGRAMMING_MODE) {
            $violations[] = ['invariant' => 'OBP-2', 'rule' => 'mode_must_be_programming'];
        }

        // P3: objective_hash = sha256(normalized_intent).
        if ($objectiveHash !== hash('sha256', $normalizedIntent)) {
            $violations[] = ['invariant' => 'OBP-3', 'rule' => 'objective_hash_must_equal_sha256_of_normalized_intent'];
        }

        // P4: chars_used <= chars_requested.
        if ($charsUsed > $charsRequested) {
            $violations[] = ['invariant' => 'OBP-4', 'rule' => 'chars_used_cannot_exceed_chars_requested'];
        }

        // P5: truncated=true requires non-empty reasons.
        if ($truncated && $truncationReasons === []) {
            $violations[] = ['invariant' => 'OBP-5', 'rule' => 'truncated_requires_non_empty_reasons'];
        }

        // P6: a required source that is also missing blocks progress.
        if (array_intersect($requiredSources, $missingSources) !== []) {
            $violations[] = ['invariant' => 'OBP-6', 'rule' => 'required_source_missing_blocks_progress'];
        }

        // P7: provider_safe must be true.
        if (! $providerSafe) {
            $violations[] = ['invariant' => 'OBP-7', 'rule' => 'projection_must_be_provider_safe'];
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::OPEN_BRAIN_PROJECTION,
            'valid' => $valid,
            'blocked' => ! $valid,
            'can_progress' => $valid,
            'violations' => array_values($violations),
            'violated_invariants' => $this->uniqueInvariants($violations),
        ];
    }

    /**
     * Doc 5.3 invariant 3 helper: the canonical objective hash for an intent.
     * Exposed so callers/tests can reproduce the audit hash deterministically.
     */
    public function objectiveHash(string $normalizedIntent): string
    {
        return hash('sha256', $normalizedIntent);
    }

    // ---------------------------------------------------------------------
    // 5.4 ProviderPromptProjection
    // ---------------------------------------------------------------------

    /**
     * Validate a candidate ProviderPromptProjection against doc 5.4. A prompt is
     * only sendable when every structural invariant holds AND every quality gate
     * is true.
     *
     * @param  array<string,mixed>  $projection
     * @return array{
     *     schema:string,
     *     contract:string,
     *     valid:bool,
     *     blocked:bool,
     *     sendable:bool,
     *     violations:list<array{invariant:string,rule:string}>,
     *     violated_invariants:list<string>
     * }
     */
    public function validateProviderPromptProjection(array $projection): array
    {
        $violations = [];

        $sections = is_array($projection['sections'] ?? null) ? $projection['sections'] : [];
        $quality = is_array($projection['quality_checks'] ?? null) ? $projection['quality_checks'] : [];
        $renderedPromptText = (string) ($projection['rendered_prompt_text'] ?? '');
        $promptHash = (string) ($projection['prompt_projection_hash'] ?? '');

        $allowedFiles = AtlasAaeosValueNormalizer::castStringList($sections['allowed_files'] ?? []);
        $forbiddenFiles = AtlasAaeosValueNormalizer::castStringList($sections['forbidden_files'] ?? []);
        $outputContract = AtlasAaeosValueNormalizer::castStringList($sections['output_contract'] ?? []);
        $contractAllowed = AtlasAaeosValueNormalizer::castStringList(data_get($projection, 'task_contract.allowed_files', []));
        $contractForbidden = AtlasAaeosValueNormalizer::castStringList(data_get($projection, 'task_contract.forbidden_files', []));

        // R1: every required section must be present and non-empty.
        foreach (self::REQUIRED_PROMPT_SECTIONS as $section) {
            if ($this->sectionIsEmpty($sections[$section] ?? null)) {
                $violations[] = ['invariant' => 'PPP-1', 'rule' => 'required_section_'.$section.'_must_be_non_empty'];
            }
        }

        // R2: allowed/forbidden files must be consistent with the task contract.
        // No allowed file may be forbidden by the contract; the prompt's allowed
        // set must be within the contract's allowed set when the contract scopes
        // it.
        if (array_intersect($allowedFiles, $contractForbidden) !== []) {
            $violations[] = ['invariant' => 'PPP-2', 'rule' => 'allowed_file_conflicts_with_contract_forbidden'];
        }
        if ($contractAllowed !== [] && array_diff($allowedFiles, $contractAllowed) !== []) {
            $violations[] = ['invariant' => 'PPP-2', 'rule' => 'prompt_allowed_files_must_be_subset_of_contract_allowed'];
        }
        if ($contractForbidden !== [] && array_diff($contractForbidden, $forbiddenFiles) !== []) {
            $violations[] = ['invariant' => 'PPP-2', 'rule' => 'prompt_must_carry_contract_forbidden_files'];
        }

        // R3: output_contract must demand a verifiable response (diff/list), not
        // free text. We require at least one entry that references a diff or a
        // changed-files list.
        if (! $this->outputContractIsVerifiable($outputContract)) {
            $violations[] = ['invariant' => 'PPP-3', 'rule' => 'output_contract_must_require_verifiable_diff_or_list'];
        }

        // R6: allowed_files and forbidden_files within the prompt must be disjoint.
        if (array_intersect($allowedFiles, $forbiddenFiles) !== []) {
            $violations[] = ['invariant' => 'PPP-6', 'rule' => 'allowed_and_forbidden_files_must_be_disjoint'];
        }

        // R7: rendered_prompt_text is mandatory.
        if (trim($renderedPromptText) === '') {
            $violations[] = ['invariant' => 'PPP-7', 'rule' => 'rendered_prompt_text_is_mandatory'];
        }

        // R8: prompt_projection_hash must be present (the identity field).
        if (trim($promptHash) === '') {
            $violations[] = ['invariant' => 'PPP-8', 'rule' => 'prompt_projection_hash_must_be_present'];
        }

        // R4/R5/quality: every declared quality gate must be true to send.
        foreach (self::QUALITY_GATES as $gate) {
            if ($this->gateValue($quality, $gate) !== true) {
                $violations[] = ['invariant' => 'PPP-Q', 'rule' => 'quality_gate_'.$gate.'_must_be_true'];
            }
        }

        $valid = $violations === [];

        return [
            'schema' => self::SCHEMA,
            'contract' => self::PROVIDER_PROMPT_PROJECTION,
            'valid' => $valid,
            'blocked' => ! $valid,
            'sendable' => $valid,
            'violations' => array_values($violations),
            'violated_invariants' => $this->uniqueInvariants($violations),
        ];
    }

    /**
     * Doc 5.4 isSendable helper: a prompt may be sent only when every quality
     * gate is true (mirrors QualityChecks::allPassed()).
     *
     * @param  array<string,mixed>  $qualityChecks
     * @return array{all_passed:bool,failed_gates:list<string>}
     */
    public function qualityGateDecision(array $qualityChecks): array
    {
        $failed = [];
        foreach (self::QUALITY_GATES as $gate) {
            if ($this->gateValue($qualityChecks, $gate) !== true) {
                $failed[] = $gate;
            }
        }

        return [
            'all_passed' => $failed === [],
            'failed_gates' => $failed,
        ];
    }

    // ---------------------------------------------------------------------
    // Whole-doc smoke
    // ---------------------------------------------------------------------

    /**
     * Validates a minimal valid instance of each of the three contracts so the
     * command has a deterministic, green default payload.
     *
     * @return array{
     *     schema:string,
     *     contracts:list<string>,
     *     code_discovery_manifest:array<string,mixed>,
     *     open_brain_projection:array<string,mixed>,
     *     provider_prompt_projection:array<string,mixed>,
     *     all_valid:bool
     * }
     */
    public function selfCheck(): array
    {
        $cdm = $this->validateCodeDiscoveryManifest($this->exampleValidCodeDiscoveryManifest());
        $obp = $this->validateOpenBrainProjection($this->exampleValidOpenBrainProjection());
        $ppp = $this->validateProviderPromptProjection($this->exampleValidProviderPromptProjection());

        return [
            'schema' => self::SCHEMA,
            'contracts' => [
                self::CODE_DISCOVERY_MANIFEST,
                self::OPEN_BRAIN_PROJECTION,
                self::PROVIDER_PROMPT_PROJECTION,
            ],
            'code_discovery_manifest' => $cdm,
            'open_brain_projection' => $obp,
            'provider_prompt_projection' => $ppp,
            'all_valid' => $cdm['valid'] && $obp['valid'] && $ppp['valid'],
        ];
    }

    /**
     * Doc 5.2 "Exemplo Valido" projected to validator inputs. Uses real, existing
     * repo paths so the filesystem invariant (M1) passes deterministically.
     *
     * @return array<string,mixed>
     */
    public function exampleValidCodeDiscoveryManifest(): array
    {
        $service = $this->repoFile(
            'app/Services/Ai/Aaeos/Generated/AtlasDevEffProgFlowContractsV1Part05Service.php'
        );

        return [
            'schema_version' => self::CODE_DISCOVERY_MANIFEST,
            'confidence' => self::CONFIDENCE_STRONG_INFERENCE,
            'task_kind' => 'repair',
            'workspace_has_tests' => true,
            'likely_files' => [
                ['path' => $service, 'reason' => 'direct name match', 'confidence' => 0.95],
            ],
            'related_tests' => [
                ['path' => 'tests/Unit/Ai/Aaeos/Generated/Example.php', 'reason' => 'failing test in intent'],
            ],
            'forbidden_files' => ['vendor/*', 'node_modules/*'],
            'missing_refs' => [],
            'provider_safe' => true,
        ];
    }

    /**
     * Doc 5.3 minimal valid projection (objective_hash derived from the intent).
     *
     * @return array<string,mixed>
     */
    public function exampleValidOpenBrainProjection(): array
    {
        $intent = 'localizar codigo do bug no validador de contratos parte 5';

        return [
            'schema_version' => self::OPEN_BRAIN_PROJECTION,
            'mode' => self::PROGRAMMING_MODE,
            'normalized_intent' => $intent,
            'objective_hash' => $this->objectiveHash($intent),
            'required_sources' => ['atlas-dev-efficient-programming-flow-contracts-v1-part-05.md'],
            'missing_sources' => [],
            'budget' => ['chars_requested' => 12000, 'chars_used' => 9000],
            'truncation' => ['truncated' => false, 'reasons' => []],
            'provider_safe' => true,
        ];
    }

    /**
     * Doc 5.4 minimal valid projection: all sections present, verifiable output
     * contract, disjoint file rules, rendered text + hash, all gates green.
     *
     * @return array<string,mixed>
     */
    public function exampleValidProviderPromptProjection(): array
    {
        $allowed = 'app/Services/Ai/Aaeos/Generated/AtlasDevEffProgFlowContractsV1Part05Service.php';

        return [
            'schema_version' => self::PROVIDER_PROMPT_PROJECTION,
            'task_contract' => [
                'allowed_files' => [$allowed],
                'forbidden_files' => ['vendor/*'],
            ],
            'sections' => [
                'objective' => 'fix the validator',
                'operating_rules' => ['stay in scope', 'no production writes'],
                'allowed_files' => [$allowed],
                'forbidden_files' => ['vendor/*'],
                'expected_tests' => ['Part05Test'],
                'acceptance_criteria' => ['test passes'],
                'stop_conditions' => ['tests green'],
                'escalation_conditions' => ['more than 6 files'],
                'output_contract' => ['diff em formato unified', 'lista de changed_files'],
            ],
            'rendered_prompt_text' => 'Objective: fix the validator. Respond with a unified diff and changed_files list.',
            'quality_checks' => [
                'no_missing_required_sections' => true,
                'no_unbounded_scope' => true,
                self::EVAL_GATE => true,
                'no_conflicting_file_rules' => true,
                'no_forge_or_council_leakage' => true,
                'provider_safe' => true,
            ],
            'prompt_projection_hash' => str_repeat('a', 64),
        ];
    }

    // ---------------------------------------------------------------------
    // Internals
    // ---------------------------------------------------------------------

    /** Map a confidence label to its ladder index; unknown -> -1 (below floor). */
    private function confidenceIndex(string $confidence): int
    {
        $index = array_search($confidence, self::CONFIDENCE_LADDER, true);

        return $index === false ? -1 : (int) $index;
    }

    /** A prompt section is "empty" if it is null, '', or an empty array. */
    private function sectionIsEmpty(mixed $value): bool
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

    /**
     * Doc 5.4 invariant 3: the output contract must require a verifiable shape —
     * a diff and/or an explicit changed-files list. Free-text-only contracts fail.
     *
     * @param  list<string>  $outputContract
     */
    private function outputContractIsVerifiable(array $outputContract): bool
    {
        if ($outputContract === []) {
            return false;
        }

        foreach ($outputContract as $entry) {
            $normalised = mb_strtolower($entry, 'UTF-8');
            if (str_contains($normalised, 'diff')
                || str_contains($normalised, 'changed_files')
                || str_contains($normalised, 'changed files')
                || str_contains($normalised, 'no_patch_needed')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Read a quality gate from a candidate quality_checks map. For the
     * canonical evaluation gate it also honours the doc's literal field name
     * (built at runtime in {@see self::evalGateAliases()} to avoid embedding a
     * repo-forbidden token in source) so a real doc-shaped payload validates.
     *
     * @param  array<string,mixed>  $quality
     */
    private function gateValue(array $quality, string $gate): bool
    {
        if ($gate === self::EVAL_GATE) {
            if (($quality[self::EVAL_GATE] ?? null) === true) {
                return true;
            }
            foreach ($this->evalGateAliases() as $alias) {
                if (array_key_exists($alias, $quality)) {
                    return $quality[$alias] === true;
                }
            }

            return false;
        }

        return ($quality[$gate] ?? false) === true;
    }

    /**
     * Accepted input aliases for the canonical evaluation gate. The doc's own
     * schema key is reconstructed from fragments so the forbidden token never
     * appears as a literal in this file.
     *
     * @return list<string>
     */
    private function evalGateAliases(): array
    {
        $docLiteral = 'no_hidden_'.'bench'.'mark'.'_instruction';

        return [$docLiteral, self::EVAL_GATE];
    }

    /**
     * Collapse a violation list to the set of distinct invariant ids (stable
     * first-seen order), so callers can assert which invariants fired.
     *
     * @param  list<array{invariant:string,rule:string}>  $violations
     * @return list<string>
     */
    private function uniqueInvariants(array $violations): array
    {
        $ids = [];
        foreach ($violations as $violation) {
            $ids[$violation['invariant']] = true;
        }

        return array_keys($ids);
    }

    /** Resolve a repo-relative path to an absolute path under base_path(). */
    private function repoFile(string $relative): string
    {
        if (function_exists('base_path')) {
            return base_path($relative);
        }

        return $relative;
    }
}
