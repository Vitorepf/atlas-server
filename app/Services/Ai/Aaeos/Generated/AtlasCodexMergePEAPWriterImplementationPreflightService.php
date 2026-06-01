<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Codex Merge Post-Execution Action Persistence (PEAP) Writer IMPLEMENTATION
 * Preflight — pure, deterministic, read-only blocker-reporting logic.
 *
 * This surface answers exactly one human question (doc "Human Meaning"):
 *
 *     "What would block implementing the writer safely?"
 *
 * and explicitly does NOT answer:
 *
 *     "Should the writer be implemented now?"
 *
 * It is distinct from the sibling writer PREFLIGHT (which asks "what still blocks
 * a real append-only writer?") and from the writer CONTRACT. The implementation
 * preflight may NAME the future writer file and its future test, and may list the
 * required tests and release conditions an implementation must satisfy — but it
 * must never CREATE those files. It implements no writer, creates no file, writes
 * no ledger event, persists no receipt, accepts or validates no signature,
 * records no decision, approves nothing, merges nothing and dispatches nothing.
 *
 * Documented contract this code enforces (not just documents):
 *
 *   Boundary (doc "Boundary") -> boundary(): EIGHT keys must stay false —
 *     execution_allowed, writer_file_creation_allowed, ledger_write_allowed,
 *     dispatch_allowed, approval_granted, merge_allowed, signature_valid,
 *     receipt_persisted. The preflight embeds them verbatim and
 *     assertBoundaryHeld() proves they never flipped. Note the implementation
 *     preflight adds `writer_file_creation_allowed` (the sibling preflight does
 *     not), because its specific hazard is creating writer files prematurely.
 *
 *   Future Files (doc "Future Files") -> futureFiles(): the implementation may
 *     DECLARE the two expected future paths (the writer service + its test) as
 *     names only; `creation_allowed` is forced false and `created` is forced
 *     false for each. Naming a file never creates it.
 *
 *   Required Tests (doc "Required Tests") -> requiredTests(): the eight tests a
 *     future implementation MUST have. Until a test is proven present it is a
 *     standing blocker. The preflight runs none of them.
 *
 *   Release Conditions (doc "Release Conditions") -> releaseConditions(): the six
 *     conditions a future implementation needs before it can even be CONSIDERED.
 *     Each unmet condition is a standing blocker. `implementation_may_be_considered`
 *     becomes true ONLY when every release condition is met AND every required
 *     test is proven present AND the boundary held — and even then it is
 *     consideration, never authorization.
 *
 *   Human Meaning (doc "Human Meaning") -> preflight(): even a fully-unblocked
 *     implementation preflight is NOT an implementation decision:
 *     `implementation_authorized` is ALWAYS false.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-implementation-preflight.md
 */
final class AtlasCodexMergePEAPWriterImplementationPreflightService
{
    /** Stable evidence schema id this read-only surface emits. */
    public const SCHEMA = 'atlas.self_construction_codex_merge_peap_writer_implementation_preflight.v1';

    /** Surface label (closed set of one). */
    public const SURFACE = 'peap_writer_implementation_preflight';

    /** Documented status when implementation blocker reporting is not yet complete. */
    public const STATUS_BLOCKED = 'codex_merge_peap_writer_implementation_preflight_blocked';

    /** Documented status when implementation blocker reporting is complete (NOT authorization). */
    public const STATUS_READY = 'codex_merge_peap_writer_implementation_preflight_ready';

    /**
     * The eight boundary keys (doc "Boundary"): the implementation preflight must
     * keep all false. Distinct from the sibling preflight by including
     * `writer_file_creation_allowed` (and excluding `receipt_signed`).
     *
     * @var list<string>
     */
    public const BOUNDARY_KEYS = [
        'execution_allowed',
        'writer_file_creation_allowed',
        'ledger_write_allowed',
        'dispatch_allowed',
        'approval_granted',
        'merge_allowed',
        'signature_valid',
        'receipt_persisted',
    ];

    /**
     * The two future files (doc "Future Files") the implementation is expected to
     * introduce. Declared as names ONLY — this preflight must not create them.
     *
     * @var list<string>
     */
    public const FUTURE_FILES = [
        'app/Services/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriter.php',
        'tests/Unit/Ai/SelfConstruction/CodexReviewMergePostExecutionActionSignedReceiptPersistenceWriterTest.php',
    ];

    /**
     * The eight tests a FUTURE implementation must have (doc "Required Tests").
     * Order preserved. Until proven present, each is a standing blocker; the
     * preflight runs none of them.
     *
     * @var list<string>
     */
    public const REQUIRED_TESTS = [
        'writer_rejects_null_payload_fields',
        'writer_recomputes_payload_hash',
        'writer_enforces_source_hash_match',
        'writer_enforces_hot_scope_recheck',
        'writer_requires_human_confirmation_hash',
        'writer_has_no_merge_authority',
        'writer_has_no_dispatch_authority',
        'writer_is_append_only_write_only',
    ];

    /**
     * Release conditions (doc "Release Conditions"). A future implementation can
     * only be CONSIDERED when every one of these is met. Order preserved; each
     * maps to a boolean signal (missing => unmet, fail-closed).
     *
     * @var list<string>
     */
    public const RELEASE_CONDITIONS = [
        'implementation_files_exist',
        'implementation_tests_pass',
        'contract_hash_bound_to_implementation',
        'all_required_capabilities_verified',
        'all_forbidden_authorities_absent',
        'separate_writer_release_authorization_present',
    ];

    /**
     * The eight documented boundary keys, all forced false.
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
     * Future-files surface. Declares the two expected future paths as NAMES only.
     * `creation_allowed` and `created` are forced false for every entry: naming a
     * future file never creates it (doc "Future Files").
     *
     * @return array{
     *   surface:string, schema:string,
     *   future_files:list<array{path:string, creation_allowed:false, created:false}>,
     *   count:int, any_created:false, writer_file_creation_allowed:false,
     *   boundary:array<string,false>
     * }
     */
    public function futureFiles(): array
    {
        $files = [];
        foreach (self::FUTURE_FILES as $path) {
            $files[] = [
                'path' => $path,
                'creation_allowed' => false,
                'created' => false,
            ];
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'future_files' => $files,
            'count' => count($files),
            'any_created' => false,
            'writer_file_creation_allowed' => false,
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Required-tests surface. Declares the eight tests a future implementation
     * must have and reports which are still missing (standing blockers). Runs
     * none of them (doc "Required Tests").
     *
     * @param array<string,mixed> $present boolean "test present" signals keyed by
     *   REQUIRED_TESTS entries. Missing/non-true => missing => blocker.
     * @return array{
     *   surface:string, schema:string,
     *   required_tests:list<string>, count:int,
     *   present:array<string,bool>, missing_tests:list<string>,
     *   all_tests_present:bool, boundary:array<string,false>, runs_tests:false
     * }
     */
    public function requiredTests(array $present = []): array
    {
        $state = [];
        $missing = [];
        foreach (self::REQUIRED_TESTS as $test) {
            $ok = $this->signal($present, $test);
            $state[$test] = $ok;
            if (! $ok) {
                $missing[] = $test;
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'required_tests' => self::REQUIRED_TESTS,
            'count' => count(self::REQUIRED_TESTS),
            'present' => $state,
            'missing_tests' => $missing,
            'all_tests_present' => $missing === [],
            'boundary' => $this->boundary(),
            'runs_tests' => false,
        ];
    }

    /**
     * Release-conditions surface. Computes, fail-closed, which of the six
     * documented conditions are met and which still block. `all_conditions_met`
     * is true ONLY when every documented condition is met (doc "Release
     * Conditions").
     *
     * @param array<string,mixed> $input boolean condition signals keyed by
     *   RELEASE_CONDITIONS entries. Missing/non-true => unmet.
     * @return array{
     *   surface:string, schema:string,
     *   release_conditions:list<string>,
     *   conditions:array<string,bool>, unmet_conditions:list<string>,
     *   all_conditions_met:bool, boundary:array<string,false>
     * }
     */
    public function releaseConditions(array $input = []): array
    {
        $conditions = [];
        $unmet = [];
        foreach (self::RELEASE_CONDITIONS as $condition) {
            $met = $this->signal($input, $condition);
            $conditions[$condition] = $met;
            if (! $met) {
                $unmet[] = $condition;
            }
        }

        return [
            'surface' => self::SURFACE,
            'schema' => self::SCHEMA,
            'release_conditions' => self::RELEASE_CONDITIONS,
            'conditions' => $conditions,
            'unmet_conditions' => $unmet,
            'all_conditions_met' => $unmet === [],
            'boundary' => $this->boundary(),
        ];
    }

    /**
     * Primary entrypoint: run the read-only IMPLEMENTATION preflight.
     *
     * Enumerates EVERY standing blocker to implementing the writer safely — each
     * missing required test and each unmet release condition — and proves the
     * boundary held across every sub-surface (including future-files, which can
     * never flip `writer_file_creation_allowed`).
     *
     * Status is READY only when blocker reporting is complete, which here means
     * there are no missing tests and no unmet conditions and the boundary held;
     * otherwise BLOCKED. `implementation_may_be_considered` mirrors that clean
     * state. Per the doc's "Human Meaning", even a fully-unblocked implementation
     * preflight is NOT an implementation decision: `implementation_authorized`
     * stays false unconditionally.
     *
     * @param array{
     *   tests_present?:array<string,mixed>,
     *   release_conditions?:array<string,mixed>
     * } $input
     * @return array{
     *   schema:string, surface:string, status:string,
     *   future_files:array<string,mixed>,
     *   required_tests:array<string,mixed>,
     *   release_conditions:array<string,mixed>,
     *   standing_blockers:list<string>, blocker_count:int,
     *   boundary:array<string,false>,
     *   boundary_held:bool, boundary_violations:list<string>,
     *   ready:bool,
     *   implementation_may_be_considered:bool, implementation_authorized:false,
     *   writer_file_creation_allowed:false,
     *   human_question:string, does_not_answer:string
     * }
     */
    public function preflight(array $input = []): array
    {
        $futureFiles = $this->futureFiles();
        $tests = $this->requiredTests($input['tests_present'] ?? []);
        $conditions = $this->releaseConditions($input['release_conditions'] ?? []);

        $violations = $this->assertBoundaryHeld([$futureFiles, $tests, $conditions]);

        $blockers = [];
        foreach ($tests['missing_tests'] as $test) {
            $blockers[] = 'required_test_missing:'.$test;
        }
        foreach ($conditions['unmet_conditions'] as $condition) {
            $blockers[] = 'release_condition_unmet:'.$condition;
        }
        foreach ($violations as $violation) {
            $blockers[] = 'boundary_violation:'.$violation;
        }

        // Consideration (never authorization) needs the full picture clean.
        $mayBeConsidered = $tests['missing_tests'] === []
            && $conditions['unmet_conditions'] === []
            && $violations === [];

        // "Ready" means implementation blocker reporting is complete and clean.
        $ready = $mayBeConsidered;

        return [
            'schema' => self::SCHEMA,
            'surface' => self::SURFACE,
            'status' => $ready ? self::STATUS_READY : self::STATUS_BLOCKED,
            'future_files' => $futureFiles,
            'required_tests' => $tests,
            'release_conditions' => $conditions,
            'standing_blockers' => $blockers,
            'blocker_count' => count($blockers),
            'boundary' => $this->boundary(),
            'boundary_held' => $violations === [],
            'boundary_violations' => $violations,
            'ready' => $ready,
            'implementation_may_be_considered' => $mayBeConsidered,
            // Doc "Human Meaning": the preflight never answers "implement now".
            'implementation_authorized' => false,
            // Hard invariant of this surface: it never creates writer files.
            'writer_file_creation_allowed' => false,
            'human_question' => 'What would block implementing the writer safely?',
            'does_not_answer' => 'Should the writer be implemented now?',
        ];
    }

    /**
     * Standing-blockers convenience: just the flat list a future implementation
     * must clear, derived from the same preflight logic.
     *
     * @param array{
     *   tests_present?:array<string,mixed>,
     *   release_conditions?:array<string,mixed>
     * } $input
     * @return list<string>
     */
    public function blockers(array $input = []): array
    {
        return $this->preflight($input)['standing_blockers'];
    }

    /**
     * Prove that no sub-surface ever flipped a boundary key to a truthy value.
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
        }

        return $violations;
    }

    /**
     * Read a boolean signal. Strict: only an exact boolean true clears it;
     * missing keys and any non-true value count as unset (fail-closed).
     *
     * @param array<string,mixed> $input
     */
    private function signal(array $input, string $key): bool
    {
        return ($input[$key] ?? null) === true;
    }
}
