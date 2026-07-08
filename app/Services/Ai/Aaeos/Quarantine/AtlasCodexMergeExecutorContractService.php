<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Codex Merge Executor Contract — pure, deterministic read-only gate logic.
 *
 * Governs the THREE read-only surfaces that prepare a FUTURE merge executor.
 * The whole point of these surfaces is to define how a narrow, receipt-bound,
 * reversible, observable and stoppable patch execution could *eventually*
 * happen — while themselves executing nothing. Every method here keeps the
 * hard boundary: it never accepts persisted receipt evidence, never persists,
 * never records, never releases an executor, never executes a patch, never
 * records execution, never merges and never dispatches.
 *
 * Contract (from the doc Boundary / Release Preflight / Executor Contract
 * Template / Execution Receipt sections):
 *
 *   Boundary (every surface, always):
 *     execution_allowed=false, patch_execution_allowed=false,
 *     patch_executed=false, execution_recorded=false, executor_allowed=false,
 *     merge_allowed=false, receipt_persisted=false, ledger_write_allowed=false,
 *     dispatch_allowed=false.
 *
 *   Release Preflight  -> releasePreflight()
 *     requires 10 externally-proven hashes; blocks on 10 named conditions;
 *     ready only when every required hash is present AND no blocker fires.
 *
 *   Executor Contract Template -> executorContractTemplate()
 *     may become ready ONLY after the release preflight is ready; defines the
 *     revalidation set, the allowed future executor actions and the forbidden
 *     future executor actions.
 *
 *   Execution Receipt Template -> executionReceiptTemplate()
 *     may become ready ONLY after the contract template is ready; defines the
 *     evidence the future receipt must carry and the 7 conditions that must
 *     block a future merge; keeps patch_executed/execution_recorded/
 *     receipt_persisted/merge_allowed false regardless.
 *
 * Documented invariants this code enforces (not just documents):
 *   - "must keep ... =false" boundary => boundary() returns all-false and every
 *     surface embeds it verbatim; assertBoundaryHeld() proves no surface ever
 *     flipped a key true.
 *   - "It blocks on: missing persisted signed final receipt ... " (10 items)
 *     => releasePreflight maps each documented blocker to a real predicate over
 *     the input; ready=false the moment any fires.
 *   - "It may become ready only after executor release preflight is ready."
 *     => executorContractTemplate is ready only if the preflight it is handed is
 *     itself ready.
 *   - "It may become ready only after the executor contract template is ready."
 *     => executionReceiptTemplate is ready only if the contract template is
 *     ready (which transitively requires the preflight ready).
 *   - "It must block future merge if: applied patch hash does not match ..."
 *     (7 items) => executionReceiptTemplate computes would_block_future_merge
 *     and its reasons from the post-execution evidence; merge stays disallowed.
 *
 * Non-goals honoured (read-only): it does NOT execute patches, does NOT record
 * execution, does NOT persist receipts, does NOT merge, does NOT dispatch work
 * and does NOT accept persisted receipt evidence as an execution trigger.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
 */
final class AtlasCodexMergeExecutorContractService
{
    /** Stable evidence schema ids these read-only surfaces emit. */
    public const SCHEMA_RELEASE_PREFLIGHT = 'atlas.self_construction_codex_review_merge_executor_release_preflight.v1';
    public const SCHEMA_CONTRACT_TEMPLATE = 'atlas.self_construction_codex_review_merge_executor_contract_template.v1';
    public const SCHEMA_EXECUTION_RECEIPT = 'atlas.self_construction_codex_review_merge_execution_receipt_template.v1';

    /** Surface labels (closed set). */
    public const SURFACE_RELEASE_PREFLIGHT = 'executor_release_preflight';
    public const SURFACE_CONTRACT_TEMPLATE = 'executor_contract_template';
    public const SURFACE_EXECUTION_RECEIPT = 'execution_receipt_template';

    /**
     * The nine boundary keys (doc "Boundary"): each surface must keep all false.
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'patch_execution_allowed',
        'patch_executed',
        'execution_recorded',
        'executor_allowed',
        'merge_allowed',
        'receipt_persisted',
        'ledger_write_allowed',
        'dispatch_allowed',
    ];

    /**
     * Release Preflight required hashes (doc "It requires:"). Order preserved.
     *
     * @var list<string>
     */
    public const PREFLIGHT_REQUIRED_HASHES = [
        'persisted_signed_final_receipt_id',
        'persisted_signed_final_receipt_hash',
        'append_only_receipt_event_hash',
        'executor_contract_hash',
        'final_diff_check_hash',
        'hot_scope_check_hash',
        'docs_health_hash',
        'architecture_validation_hash',
        'focused_test_matrix_hash',
        'human_executor_release_confirmation_hash',
    ];

    /**
     * Executor Contract Template revalidation set (doc "It must require the
     * future executor to revalidate:").
     *
     * @var list<string>
     */
    public const CONTRACT_REVALIDATION = [
        'persisted_signed_final_receipt_hash',
        'executor_release_authority_hash',
        'final_diff_check',
        'hot_scope_check',
        'docs_health',
        'architecture_validation',
        'focused_test_matrix',
        'rollback_plan_availability',
    ];

    /**
     * Allowed FUTURE executor actions (doc "It may define these future executor
     * actions:"). These describe a future executor — this surface performs none.
     *
     * @var list<string>
     */
    public const CONTRACT_ALLOWED_FUTURE_ACTIONS = [
        'read_persisted_signed_final_receipt',
        'read_current_diff',
        'read_scope_validator_report',
        'read_gate_reports',
        'apply_only_the_receipt_bound_patch_set',
        'emit_executor_evidence_receipt',
        'stop_on_any_mismatch',
    ];

    /**
     * Forbidden future executor actions (doc "It must forbid:").
     *
     * @var list<string>
     */
    public const CONTRACT_FORBIDDEN_ACTIONS = [
        'modify_voice_or_kernel_hot_scope_without_a_new_receipt',
        'expand_scope_beyond_the_signed_receipt',
        'skip_final_diff_hot_scope_docs_architecture_or_tests',
        'merge_without_post_execution_receipt',
        'dispatch_new_packets',
    ];

    /**
     * Execution Receipt required evidence fields (doc "It must require:").
     *
     * @var list<string>
     */
    public const RECEIPT_REQUIRED_FIELDS = [
        'pre_execution_diff_hash',
        'post_execution_diff_hash',
        'applied_patch_hash',
        'files_changed',
        'commands_run',
        'focused_test_matrix_hash',
        'docs_health_hash',
        'architecture_validation_hash',
        'hot_scope_check_hash',
        'rollback_plan_hash',
        'execution_start_timestamp',
        'execution_completion_timestamp',
    ];

    /**
     * The nine documented boundary keys, all forced false.
     *
     * @return array<string,false>
     */
    public function boundary(): array
    {
        $out = [];
        foreach (self::BOUNDARY_KEYS as $key) {
            $out[$key] = false;
        }

        return $out;
    }

    /**
     * Release Preflight surface.
     *
     * Defines what must be externally proven before an executor can even be
     * considered. Computes which required hashes are present, which documented
     * blockers fire, and a single `ready` flag = (all required hashes present
     * AND no blocker fires). Releases nothing.
     *
     * @param array<string,mixed> $input documented hashes + boolean gate results:
     *   present hashes under PREFLIGHT_REQUIRED_HASHES keys (non-empty string);
     *   plus optional gate signals consumed by the blockers:
     *     persisted_signed_final_receipt_present (bool),
     *     append_only_receipt_event_present (bool),
     *     receipt_source_hash_matches (bool),
     *     executor_contract_hash_matches (bool),
     *     final_diff_passed (bool),
     *     hot_voice_or_kernel_scope_touched (bool),
     *     docs_health_passed (bool),
     *     architecture_validation_passed (bool),
     *     focused_test_matrix_passed (bool),
     *     human_executor_release_confirmation_present (bool).
     * @return array{
     *   surface:string, schema:string, ready:bool,
     *   required_hashes:array<string,bool>, missing_hashes:list<string>,
     *   blockers:list<string>, boundary:array<string,false>,
     *   releases_executor:false
     * }
     */
    public function releasePreflight(array $input): array
    {
        $requiredHashes = [];
        $missing = [];
        foreach (self::PREFLIGHT_REQUIRED_HASHES as $key) {
            $present = $this->hashPresent($input, $key);
            $requiredHashes[$key] = $present;
            if (! $present) {
                $missing[] = $key;
            }
        }

        // Each documented blocker is a real predicate over the input. A blocker
        // that cannot be positively cleared is treated as firing (fail-closed),
        // which is exactly what a release gate must do.
        $blockers = [];

        if (! $this->signal($input, 'persisted_signed_final_receipt_present', $this->hashPresent($input, 'persisted_signed_final_receipt_id'))) {
            $blockers[] = 'missing_persisted_signed_final_receipt';
        }
        if (! $this->signal($input, 'append_only_receipt_event_present', $this->hashPresent($input, 'append_only_receipt_event_hash'))) {
            $blockers[] = 'missing_append_only_receipt_event';
        }
        if (! $this->signal($input, 'receipt_source_hash_matches', false)) {
            $blockers[] = 'receipt_source_hash_mismatch';
        }
        if (! $this->signal($input, 'executor_contract_hash_matches', false)) {
            $blockers[] = 'executor_contract_hash_mismatch';
        }
        if (! $this->signal($input, 'final_diff_passed', false)) {
            $blockers[] = 'final_diff_failure';
        }
        // This one is inverted: presence of a hot touch is itself the blocker.
        if ($this->signal($input, 'hot_voice_or_kernel_scope_touched', false)) {
            $blockers[] = 'hot_voice_or_kernel_scope_touch';
        }
        if (! $this->signal($input, 'docs_health_passed', false)) {
            $blockers[] = 'docs_health_failure';
        }
        if (! $this->signal($input, 'architecture_validation_passed', false)) {
            $blockers[] = 'architecture_validation_failure';
        }
        if (! $this->signal($input, 'focused_test_matrix_passed', false)) {
            $blockers[] = 'focused_test_matrix_failure';
        }
        if (! $this->signal($input, 'human_executor_release_confirmation_present', $this->hashPresent($input, 'human_executor_release_confirmation_hash'))) {
            $blockers[] = 'missing_human_executor_release_confirmation';
        }

        $ready = $missing === [] && $blockers === [];

        return [
            'surface' => self::SURFACE_RELEASE_PREFLIGHT,
            'schema' => self::SCHEMA_RELEASE_PREFLIGHT,
            'ready' => $ready,
            'required_hashes' => $requiredHashes,
            'missing_hashes' => $missing,
            'blockers' => $blockers,
            'boundary' => $this->boundary(),
            'releases_executor' => false,
        ];
    }

    /**
     * Executor Contract Template surface.
     *
     * Describes how a future executor must behave. May become ready ONLY after
     * the release preflight it is handed is itself ready. Defines the
     * revalidation set, the allowed future actions and the forbidden actions.
     * Executes nothing.
     *
     * @param array{ready?:bool,surface?:string} $releasePreflight the result of
     *        releasePreflight() (or anything carrying its `ready`).
     * @return array{
     *   surface:string, schema:string, ready:bool,
     *   depends_on:string, dependency_ready:bool, gated_reason:?string,
     *   revalidation_required:list<string>,
     *   allowed_future_executor_actions:list<string>,
     *   forbidden_actions:list<string>,
     *   boundary:array<string,false>, executes_patches:false
     * }
     */
    public function executorContractTemplate(array $releasePreflight): array
    {
        $dependencyReady = ($releasePreflight['ready'] ?? false) === true;
        $ready = $dependencyReady;

        return [
            'surface' => self::SURFACE_CONTRACT_TEMPLATE,
            'schema' => self::SCHEMA_CONTRACT_TEMPLATE,
            'ready' => $ready,
            'depends_on' => self::SURFACE_RELEASE_PREFLIGHT,
            'dependency_ready' => $dependencyReady,
            'gated_reason' => $dependencyReady ? null : 'executor_release_preflight_not_ready',
            'revalidation_required' => self::CONTRACT_REVALIDATION,
            'allowed_future_executor_actions' => self::CONTRACT_ALLOWED_FUTURE_ACTIONS,
            'forbidden_actions' => self::CONTRACT_FORBIDDEN_ACTIONS,
            'boundary' => $this->boundary(),
            'executes_patches' => false,
        ];
    }

    /**
     * Execution Receipt Template surface.
     *
     * Defines the evidence shape a future executor receipt must carry, BEFORE
     * that executor exists. May become ready ONLY after the contract template is
     * ready (which transitively requires the preflight ready). Computes which
     * required fields are present and whether the documented post-execution
     * conditions would block a future merge. Keeps patch_executed /
     * execution_recorded / receipt_persisted / merge_allowed false regardless,
     * and never executes, records, persists, merges or dispatches.
     *
     * @param array{ready?:bool} $contractTemplate result of executorContractTemplate().
     * @param array<string,mixed> $receipt post-execution evidence. Required field
     *   presence under RECEIPT_REQUIRED_FIELDS; plus the documented merge-block
     *   signals:
     *     applied_patch_hash_matches_receipt_bound_set (bool),
     *     changed_files_within_signed_receipt_scope (bool),
     *     hot_scope_check_passed_after_execution (bool),
     *     docs_health_passed_after_execution (bool),
     *     architecture_validation_passed_after_execution (bool),
     *     focused_tests_passed_after_execution (bool),
     *     rollback_plan_present_after_execution (bool).
     * @return array{
     *   surface:string, schema:string, ready:bool,
     *   depends_on:string, dependency_ready:bool, gated_reason:?string,
     *   required_fields:array<string,bool>, missing_fields:list<string>,
     *   would_block_future_merge:bool, merge_block_reasons:list<string>,
     *   boundary:array<string,false>,
     *   patch_executed:false, execution_recorded:false,
     *   receipt_persisted:false, merge_allowed:false
     * }
     */
    public function executionReceiptTemplate(array $contractTemplate, array $receipt = []): array
    {
        $dependencyReady = ($contractTemplate['ready'] ?? false) === true;
        $ready = $dependencyReady;

        $requiredFields = [];
        $missing = [];
        foreach (self::RECEIPT_REQUIRED_FIELDS as $field) {
            $present = $this->fieldPresent($receipt, $field);
            $requiredFields[$field] = $present;
            if (! $present) {
                $missing[] = $field;
            }
        }

        // Doc "It must block future merge if:" — seven conditions, fail-closed.
        $blockReasons = [];
        if (! $this->signal($receipt, 'applied_patch_hash_matches_receipt_bound_set', false)) {
            $blockReasons[] = 'applied_patch_hash_mismatch';
        }
        if (! $this->signal($receipt, 'changed_files_within_signed_receipt_scope', false)) {
            $blockReasons[] = 'changed_files_outside_signed_receipt_scope';
        }
        if (! $this->signal($receipt, 'hot_scope_check_passed_after_execution', false)) {
            $blockReasons[] = 'hot_scope_check_failed_after_execution';
        }
        if (! $this->signal($receipt, 'docs_health_passed_after_execution', false)) {
            $blockReasons[] = 'docs_health_failed_after_execution';
        }
        if (! $this->signal($receipt, 'architecture_validation_passed_after_execution', false)) {
            $blockReasons[] = 'architecture_validation_failed_after_execution';
        }
        if (! $this->signal($receipt, 'focused_tests_passed_after_execution', false)) {
            $blockReasons[] = 'focused_tests_failed_after_execution';
        }
        if (! $this->signal($receipt, 'rollback_plan_present_after_execution', false)) {
            $blockReasons[] = 'rollback_plan_missing_after_execution';
        }

        return [
            'surface' => self::SURFACE_EXECUTION_RECEIPT,
            'schema' => self::SCHEMA_EXECUTION_RECEIPT,
            'ready' => $ready,
            'depends_on' => self::SURFACE_CONTRACT_TEMPLATE,
            'dependency_ready' => $dependencyReady,
            'gated_reason' => $dependencyReady ? null : 'executor_contract_template_not_ready',
            'required_fields' => $requiredFields,
            'missing_fields' => $missing,
            'would_block_future_merge' => $blockReasons !== [],
            'merge_block_reasons' => $blockReasons,
            'boundary' => $this->boundary(),
            // Boundary keys restated explicitly per the doc's closing paragraph.
            'patch_executed' => false,
            'execution_recorded' => false,
            'receipt_persisted' => false,
            'merge_allowed' => false,
        ];
    }

    /**
     * Primary entrypoint: evaluate all three surfaces in their documented
     * dependency order and prove the boundary held across every one.
     *
     * @param array{
     *   release_preflight?:array<string,mixed>,
     *   execution_receipt?:array<string,mixed>
     * } $input
     * @return array{
     *   schema_set:array{release_preflight:string,contract_template:string,execution_receipt:string},
     *   release_preflight:array<string,mixed>,
     *   executor_contract_template:array<string,mixed>,
     *   execution_receipt_template:array<string,mixed>,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   chain_ready:bool
     * }
     */
    public function contract(array $input = []): array
    {
        $preflight = $this->releasePreflight($input['release_preflight'] ?? []);
        $contract = $this->executorContractTemplate($preflight);
        $receipt = $this->executionReceiptTemplate($contract, $input['execution_receipt'] ?? []);

        $violations = $this->assertBoundaryHeld([$preflight, $contract, $receipt]);

        return [
            'schema_set' => [
                'release_preflight' => self::SCHEMA_RELEASE_PREFLIGHT,
                'contract_template' => self::SCHEMA_CONTRACT_TEMPLATE,
                'execution_receipt' => self::SCHEMA_EXECUTION_RECEIPT,
            ],
            'release_preflight' => $preflight,
            'executor_contract_template' => $contract,
            'execution_receipt_template' => $receipt,
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            // The chain is "ready" only when each surface in turn is ready; the
            // gating in each method already enforces the documented ordering.
            'chain_ready' => ($preflight['ready'] ?? false)
                && ($contract['ready'] ?? false)
                && ($receipt['ready'] ?? false),
        ];
    }

    /**
     * Prove that no surface ever flipped a boundary key to a truthy value.
     * Returns the list of "surface.key" violations (empty = boundary intact).
     *
     * @param list<array<string,mixed>> $surfaces
     * @return list<string>
     */
    public function assertBoundaryHeld(array $surfaces): array
    {
        $violations = [];
        foreach ($surfaces as $surface) {
            $label = is_string($surface['surface'] ?? null) ? $surface['surface'] : 'unknown';
            $boundary = is_array($surface['boundary'] ?? null) ? $surface['boundary'] : [];
            foreach (self::BOUNDARY_KEYS as $key) {
                // Missing key OR truthy value both count as a breach.
                if (! array_key_exists($key, $boundary) || $boundary[$key] !== false) {
                    $violations[] = $label.'.'.$key;
                }
            }
            // Receipt surface restates four keys outside the boundary array.
            foreach (['patch_executed', 'execution_recorded', 'receipt_persisted', 'merge_allowed'] as $extra) {
                if (array_key_exists($extra, $surface) && $surface[$extra] !== false) {
                    $violations[] = $label.'.'.$extra;
                }
            }
        }

        return $violations;
    }

    /**
     * A hash field is "present" when it is a non-empty trimmed string.
     *
     * @param array<string,mixed> $input
     */
    private function hashPresent(array $input, string $key): bool
    {
        $value = $input[$key] ?? null;

        return is_string($value) && trim($value) !== '';
    }

    /**
     * A receipt evidence field is "present" when it is a non-empty string or a
     * non-empty array (files_changed / commands_run are lists).
     *
     * @param array<string,mixed> $receipt
     */
    private function fieldPresent(array $receipt, string $field): bool
    {
        $value = $receipt[$field] ?? null;
        if (is_string($value)) {
            return trim($value) !== '';
        }
        if (is_array($value)) {
            return $value !== [];
        }

        return false;
    }

    /**
     * Read a boolean gate signal, falling back to a derived default when the
     * caller did not supply it explicitly. Non-bool values are coerced strictly:
     * only an exact boolean true clears a gate.
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key, bool $default): bool
    {
        if (! array_key_exists($key, $input)) {
            return $default;
        }

        return $input[$key] === true;
    }
}
