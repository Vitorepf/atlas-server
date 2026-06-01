<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Self-Construction Command Evidence Index v1 doc.
 *
 * The doc is a curated INDEX of the read-only self-construction verification
 * commands, mapping each command to: purpose, parallel safety, whether it
 * writes storage/ledger (always no), the critical JSON key_fields it emits,
 * and — the headline operational gain — what a FAILURE of that command MEANS.
 * It is a projection, never a writer: nothing here dispatches a provider,
 * writes the ledger, persists a claim or advances a pointer.
 *
 * This service makes the index's load-bearing contract executable and PURE
 * (no DB, no I/O, no provider). The hard invariants enforced (from "Tabela
 * canonica", "Comandos certificadores", "Regras para IA"):
 *
 *   - EVERY catalogued command is read-only: writes_storage=false AND
 *     writes_ledger=false. catalogIntegrity() rejects any entry that claims to
 *     write either ("nunca incluir comandos mutating"), and rejects any entry
 *     missing its failure_meaning ("IA nao pode remover/silenciar a coluna
 *     failure_meaning — ela e o ganho operacional principal").
 *   - runtimeSafetyRegressed(): for a certifier projection, ANY of the
 *     *_allowed flags being true (or runtime_safety_all_false=false) is a
 *     CRITICAL regression — the projection stopped being read-only.
 *   - projectionStatusVerdict(): status="available" means a read-only
 *     certification is AVAILABLE, NOT that runtime is executing. The verdict is
 *     never "runtime" / "runtime_capable" on the strength of status alone
 *     ("Projection available = certificacao read-only disponivel, nao runtime").
 *   - pointerAligned(): current_next_required_slice != expected (or
 *     current_pointer != expected_pointer) => pointer misaligned, which blocks
 *     advancing a macro-sprint.
 *   - replayDeterminismVerdict(): if any replay hash changes between two runs
 *     with no code change => non-determinism, a critical regression.
 *
 * This service NEVER runs a command and NEVER promotes anything to writer or
 * runtime — it only indexes, classifies and reads evidence shape.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-command-evidence-index-v1.md
 */
final class AtlasSelfConstructionCommandEvidenceIndexService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_command_evidence_index.v1';

    public const MODE = 'read_only_command_evidence_index';

    /** Stable command keys for the catalogued read-only commands. */
    public const CMD_PWD = 'pwd';

    public const CMD_GIT_STATUS = 'git_status_short';

    public const CMD_LIST = 'php_artisan_list_rg';

    public const CMD_PROJECTION = 'agent_control_plane';

    public const CMD_CHAIN_INTEGRITY = 'agent_control_plane_chain_integrity_certification_status';

    public const CMD_REPLAY = 'agent_control_plane_deterministic_chain_replay_status';

    public const CMD_DOCS_HEALTH = 'docs_health';

    public const CMD_ARCHITECTURE_VALIDATE = 'architecture_validate';

    /** Parallel-safety classes, from the safe_parallel column. */
    public const PARALLEL_FREE = 'free';

    public const PARALLEL_FREE_BUT_COSTLY = 'free_but_costly';

    public const PARALLEL_FREE_BUT_SNAPSHOT_SENSITIVE = 'free_but_snapshot_sensitive';

    /**
     * Certifier roles, from "Comandos certificadores". A command can carry more
     * than one role (e.g. chain-integrity is both runtime-safety and pointer).
     */
    public const ROLE_RUNTIME_SAFETY = 'runtime_safety';

    public const ROLE_POINTER = 'pointer';

    public const ROLE_ARCHITECTURE_DOCS = 'architecture_docs';

    /**
     * The *_allowed-style flags whose value MUST stay false for any read-only
     * certifier projection. If any is true, the projection has regressed out of
     * read-only mode. ("Qualquer *_allowed true -> regressao critica".)
     *
     * @var array<int,string>
     */
    public const RUNTIME_SAFETY_FLAGS = [
        'execution_allowed',
        'completion_allowed',
        'dispatch_allowed',
        'claim_persisted',
        'ledger_write_allowed',
        'runtime_write_allowed',
    ];

    /**
     * The canonical command evidence index — one row per read-only command.
     * Mirrors the doc's "Tabela canonica de comandos". EVERY row has
     * writes_storage=false, writes_ledger=false and a non-empty failure_meaning.
     *
     * @var array<string,array{
     *   purpose:string,
     *   safe_parallel:string,
     *   writes_storage:bool,
     *   writes_ledger:bool,
     *   roles:array<int,string>,
     *   key_fields:array<int,string>,
     *   failure_meaning:string
     * }>
     */
    public const INDEX = [
        self::CMD_PWD => [
            'purpose' => 'Confirm the atlas-server base directory before any artisan command.',
            'safe_parallel' => self::PARALLEL_FREE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [],
            'key_fields' => [],
            'failure_meaning' => 'wrong_directory_all_artisan_commands_fail_silently',
        ],
        self::CMD_GIT_STATUS => [
            'purpose' => 'List modified/untracked files to detect other Claudes touching shared files.',
            'safe_parallel' => self::PARALLEL_FREE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [],
            'key_fields' => [],
            'failure_meaning' => 'unexpectedly_clean_or_mass_deletion_suspect_reset_or_conflict_between_claudes',
        ],
        self::CMD_LIST => [
            'purpose' => 'Discover which canonical self-construction commands this kernel build registers.',
            'safe_parallel' => self::PARALLEL_FREE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [],
            'key_fields' => [],
            'failure_meaning' => 'expected_command_missing_console_registration_did_not_load_inspect_bootstrap_not_command_bug',
        ],
        self::CMD_PROJECTION => [
            'purpose' => 'Full Agent Control Plane projection (slices, capacity, blind spots, invariants).',
            'safe_parallel' => self::PARALLEL_FREE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [self::ROLE_RUNTIME_SAFETY],
            'key_fields' => [
                'schema_version',
                'status',
                'mode',
                'execution_allowed',
                'completion_allowed',
                'dispatch_allowed',
                'claim_persisted',
                'ledger_write_allowed',
                'control_plane.maturity',
                'control_plane.next_build_slices',
                'control_plane.not_yet_runtime_capable',
                'control_plane.runtime_contracts_available',
                'control_plane.invariants',
                'control_plane_hash',
                'non_execution_guarantees',
            ],
            'failure_meaning' => 'any_allowed_flag_true_projection_no_longer_read_only_critical_regression_or_schema_version_drift_contract_changed_without_docs',
        ],
        self::CMD_CHAIN_INTEGRITY => [
            'purpose' => 'Certify slice chain integrity: current vs expected pointer, invariants, violation count.',
            'safe_parallel' => self::PARALLEL_FREE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [self::ROLE_RUNTIME_SAFETY, self::ROLE_POINTER],
            'key_fields' => [
                'status',
                'mode',
                'execution_allowed',
                'ledger_write_allowed',
                'runtime_write_allowed',
                'agent_control_plane_chain_integrity_certification_status.chain_length',
                'agent_control_plane_chain_integrity_certification_status.checked_slice_count',
                'agent_control_plane_chain_integrity_certification_status.invariants_all_true',
                'agent_control_plane_chain_integrity_certification_status.runtime_safety_all_false',
                'agent_control_plane_chain_integrity_certification_status.violation_count',
                'agent_control_plane_chain_integrity_certification_status.warning_count',
                'agent_control_plane_chain_integrity_certification_status.current_next_required_slice',
                'agent_control_plane_chain_integrity_certification_status.expected_next_required_slice',
                'agent_control_plane_chain_integrity_certification_status.audit_hash',
                'agent_control_plane_chain_integrity_certification_status.next_action',
            ],
            'failure_meaning' => 'invariants_all_true_false_or_runtime_safety_all_false_false_or_violation_count_gt_0_blocks_macro_sprint_advance_or_pointer_mismatch_misaligned',
        ],
        self::CMD_REPLAY => [
            'purpose' => 'Deterministic chain replay; emits proof bundle and replay hashes.',
            'safe_parallel' => self::PARALLEL_FREE_BUT_COSTLY,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [self::ROLE_RUNTIME_SAFETY, self::ROLE_POINTER],
            'key_fields' => [
                'status',
                'agent_control_plane_deterministic_chain_replay_status.replay_id',
                'agent_control_plane_deterministic_chain_replay_status.replay_schema_version',
                'agent_control_plane_deterministic_chain_replay_status.replayed_slice_count',
                'agent_control_plane_deterministic_chain_replay_status.replayed_edge_count',
                'agent_control_plane_deterministic_chain_replay_status.invariants_all_true',
                'agent_control_plane_deterministic_chain_replay_status.runtime_safety_all_false',
                'agent_control_plane_deterministic_chain_replay_status.violation_count',
                'agent_control_plane_deterministic_chain_replay_status.warning_count',
                'agent_control_plane_deterministic_chain_replay_status.replay_hash',
                'agent_control_plane_deterministic_chain_replay_status.deterministic_replay_hash',
                'agent_control_plane_deterministic_chain_replay_status.proof_bundle_hash',
                'agent_control_plane_deterministic_chain_replay_status.current_pointer',
                'agent_control_plane_deterministic_chain_replay_status.expected_pointer',
                'agent_control_plane_deterministic_chain_replay_status.next_safe_macro_batch',
            ],
            'failure_meaning' => 'any_hash_changes_between_two_runs_without_code_change_non_determinism_critical_regression_or_runtime_safety_all_false_false_projection_allows_runtime',
        ],
        self::CMD_DOCS_HEALTH => [
            'purpose' => 'Audit the canonical doc set: required docs, oversize, frontmatter, canonical modules.',
            'safe_parallel' => self::PARALLEL_FREE_BUT_SNAPSHOT_SENSITIVE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [self::ROLE_ARCHITECTURE_DOCS],
            'key_fields' => [
                'status',
                'summary.docs_root',
                'summary.doc_count',
                'summary.required_doc_count',
                'summary.required_missing_count',
                'summary.oversized_count',
                'summary.frontmatter_violation_count',
                'summary.canonical_module_coverage_violation_count',
                'summary.canonical_module_violation_count',
                'violations',
            ],
            'failure_meaning' => 'status_failed_acceptable_mid_sprint_only_if_violations_known_unexpected_growth_means_auditable_docs_compromised',
        ],
        self::CMD_ARCHITECTURE_VALIDATE => [
            'purpose' => 'Validate the mother-architecture: kernel, domains, capabilities, orchestrators, docs.',
            'safe_parallel' => self::PARALLEL_FREE_BUT_SNAPSHOT_SENSITIVE,
            'writes_storage' => false,
            'writes_ledger' => false,
            'roles' => [self::ROLE_ARCHITECTURE_DOCS],
            'key_fields' => [
                'status',
                'schema_version',
                'validated_at',
                'kernel.valid',
                'documentation.status',
                'documentation.summary',
                'documentation.violations',
                'domains.valid',
                'capabilities.valid',
                'orchestrators.valid',
            ],
            'failure_meaning' => 'status_failed_only_from_documentation_is_less_critical_than_kernel_or_domains_read_documentation_violations_first_to_rule_out_docs_health_drag',
        ],
    ];

    /**
     * All catalogued command keys, in table order.
     *
     * @return array<int,string>
     */
    public function commands(): array
    {
        return array_keys(self::INDEX);
    }

    /**
     * The evidence row for one command. Unknown command keys are flagged rather
     * than throwing, and crucially are NOT treated as catalogued — the index is
     * a closed read-only set.
     *
     * @return array{
     *   command:string,
     *   known:bool,
     *   purpose:string,
     *   safe_parallel:string,
     *   writes_storage:bool,
     *   writes_ledger:bool,
     *   read_only:bool,
     *   roles:array<int,string>,
     *   key_fields:array<int,string>,
     *   failure_meaning:string
     * }
     */
    public function evidenceFor(string $command): array
    {
        $known = array_key_exists($command, self::INDEX);

        if (! $known) {
            return [
                'command' => $command,
                'known' => false,
                'purpose' => '',
                'safe_parallel' => '',
                'writes_storage' => false,
                'writes_ledger' => false,
                'read_only' => false,
                'roles' => [],
                'key_fields' => [],
                'failure_meaning' => 'command_not_in_read_only_evidence_index',
            ];
        }

        $row = self::INDEX[$command];

        return [
            'command' => $command,
            'known' => true,
            'purpose' => $row['purpose'],
            'safe_parallel' => $row['safe_parallel'],
            'writes_storage' => $row['writes_storage'],
            'writes_ledger' => $row['writes_ledger'],
            // read_only is derived, not declared: it is true iff the command
            // writes neither storage nor ledger.
            'read_only' => ! $row['writes_storage'] && ! $row['writes_ledger'],
            'roles' => $row['roles'],
            'key_fields' => $row['key_fields'],
            'failure_meaning' => $row['failure_meaning'],
        ];
    }

    /**
     * Validate the integrity of the whole index against the doc's "Regras para
     * IA" and "forbidden_changes". A candidate row set defaults to the canonical
     * INDEX, but a caller may pass a proposed set to test a would-be edit.
     *
     * Rejects (hard violations):
     *   - any row claiming writes_storage=true or writes_ledger=true (a mutating
     *     command must NEVER be catalogued here, "nem que seja para documentar");
     *   - any row with an empty failure_meaning (the column may never be removed
     *     or silenced — it is the catalog's main operational gain).
     *
     * @param  array<string,array<string,mixed>>|null  $rows
     * @return array{
     *   valid:bool,
     *   violations:array<int,string>,
     *   mutating_rows:array<int,string>,
     *   rows_missing_failure_meaning:array<int,string>,
     *   row_count:int,
     *   human_label:string
     * }
     */
    public function catalogIntegrity(?array $rows = null): array
    {
        $rows = $rows ?? self::INDEX;

        $violations = [];
        $mutatingRows = [];
        $missingFailureMeaning = [];

        foreach ($rows as $key => $row) {
            $writesStorage = (bool) ($row['writes_storage'] ?? false);
            $writesLedger = (bool) ($row['writes_ledger'] ?? false);

            if ($writesStorage || $writesLedger) {
                $mutatingRows[] = $key;
                $violations[] = 'mutating_command_in_read_only_index:'.$key;
            }

            $failureMeaning = trim((string) ($row['failure_meaning'] ?? ''));
            if ($failureMeaning === '') {
                $missingFailureMeaning[] = $key;
                $violations[] = 'missing_failure_meaning:'.$key;
            }
        }

        $valid = $violations === [];

        return [
            'valid' => $valid,
            'violations' => $violations,
            'mutating_rows' => $mutatingRows,
            'rows_missing_failure_meaning' => $missingFailureMeaning,
            'row_count' => count($rows),
            'human_label' => $valid
                ? 'read_only_evidence_index_intact'
                : 'evidence_index_rejected_mutating_or_missing_failure_meaning',
        ];
    }

    /**
     * Runtime-safety verdict for a certifier projection's JSON. ANY of the
     * *_allowed-style flags being true is a CRITICAL regression: the projection
     * stopped being read-only. A caller may also pass an explicit
     * runtime_safety_all_false flag (chain-integrity / replay expose it).
     *
     * @param  array<string,mixed>  $flags  e.g. ['execution_allowed'=>false,...]
     * @param  bool|null  $runtimeSafetyAllFalse  optional explicit aggregate flag
     * @return array{
     *   read_only_safe:bool,
     *   regressed:bool,
     *   offending_flags:array<int,string>,
     *   runtime_safety_all_false:bool,
     *   reason:string
     * }
     */
    public function runtimeSafetyVerdict(array $flags, ?bool $runtimeSafetyAllFalse = null): array
    {
        $offending = [];
        foreach (self::RUNTIME_SAFETY_FLAGS as $flag) {
            if (array_key_exists($flag, $flags) && (bool) $flags[$flag] === true) {
                $offending[] = $flag;
            }
        }

        // An explicit aggregate of false (runtime_safety_all_false=false) is, on
        // its own, a regression even if no per-flag was supplied.
        $aggregateRegressed = $runtimeSafetyAllFalse === false;

        $regressed = $offending !== [] || $aggregateRegressed;

        if ($offending !== []) {
            $reason = 'allowed_flag_true_projection_no_longer_read_only';
        } elseif ($aggregateRegressed) {
            $reason = 'runtime_safety_all_false_is_false_projection_allows_runtime';
        } else {
            $reason = 'all_runtime_safety_flags_false_read_only_preserved';
        }

        return [
            'read_only_safe' => ! $regressed,
            'regressed' => $regressed,
            'offending_flags' => $offending,
            // If not supplied, derive: safe iff no offending flags.
            'runtime_safety_all_false' => $runtimeSafetyAllFalse ?? ($offending === []),
            'reason' => $reason,
        ];
    }

    /**
     * Convenience predicate around runtimeSafetyVerdict: did the projection
     * regress out of read-only?
     *
     * @param  array<string,mixed>  $flags
     */
    public function runtimeSafetyRegressed(array $flags, ?bool $runtimeSafetyAllFalse = null): bool
    {
        return $this->runtimeSafetyVerdict($flags, $runtimeSafetyAllFalse)['regressed'];
    }

    /**
     * Interpret a projection's `status` field. The doc's central operator-safety
     * rule: status="available" means a read-only certification is AVAILABLE, it
     * does NOT mean runtime is executing or that any capability is runtime. This
     * method NEVER returns a "runtime" verdict on the strength of status alone.
     *
     * @return array{
     *   status:string,
     *   certification_available:bool,
     *   runtime_executing:bool,
     *   capability_class:string,
     *   reason:string
     * }
     */
    public function projectionStatusVerdict(string $status): array
    {
        $available = in_array($status, [
            'available',
            'agent_control_plane_ready',
        ], true);

        return [
            'status' => $status,
            'certification_available' => $available,
            // Hard rule: projection status NEVER implies runtime execution.
            'runtime_executing' => false,
            'capability_class' => $available
                ? 'read_only_certification_available'
                : 'certification_unavailable',
            'reason' => $available
                ? 'status_available_means_read_only_certification_not_runtime_executing'
                : 'status_not_available_certification_cannot_be_relied_on',
        ];
    }

    /**
     * Pointer-alignment verdict. current != expected => misaligned, which blocks
     * advancing a macro-sprint (applies to both
     * current_next_required_slice/expected_next_required_slice and
     * current_pointer/expected_pointer).
     *
     * @return array{
     *   aligned:bool,
     *   current:string,
     *   expected:string,
     *   blocks_macro_sprint_advance:bool,
     *   reason:string
     * }
     */
    public function pointerAligned(string $current, string $expected): array
    {
        $aligned = $current === $expected;

        return [
            'aligned' => $aligned,
            'current' => $current,
            'expected' => $expected,
            'blocks_macro_sprint_advance' => ! $aligned,
            'reason' => $aligned
                ? 'pointer_aligned_safe_to_proceed'
                : 'pointer_misaligned_blocks_macro_sprint_advance',
        ];
    }

    /**
     * Determinism verdict for the deterministic chain replay. The doc: ANY hash
     * changing between two runs WITHOUT a code change is non-determinism, a
     * critical regression. Compares the hash maps of two replay runs.
     *
     * @param  array<string,string>  $firstHashes  e.g. ['replay_hash'=>'a',...]
     * @param  array<string,string>  $secondHashes
     * @return array{
     *   deterministic:bool,
     *   regressed:bool,
     *   changed_hashes:array<int,string>,
     *   reason:string
     * }
     */
    public function replayDeterminismVerdict(array $firstHashes, array $secondHashes): array
    {
        $changed = [];
        $keys = array_unique(array_merge(array_keys($firstHashes), array_keys($secondHashes)));
        foreach ($keys as $key) {
            $a = $firstHashes[$key] ?? null;
            $b = $secondHashes[$key] ?? null;
            if ($a !== $b) {
                $changed[] = $key;
            }
        }

        $deterministic = $changed === [];

        return [
            'deterministic' => $deterministic,
            'regressed' => ! $deterministic,
            'changed_hashes' => $changed,
            'reason' => $deterministic
                ? 'replay_hashes_stable_deterministic'
                : 'replay_hash_changed_without_code_change_non_determinism_critical_regression',
        ];
    }

    /**
     * All catalogued commands carrying a given certifier role (from "Comandos
     * certificadores"). Unknown roles return an empty list.
     *
     * @return array<int,string>
     */
    public function commandsWithRole(string $role): array
    {
        $out = [];
        foreach (self::INDEX as $key => $row) {
            if (in_array($role, $row['roles'], true)) {
                $out[] = $key;
            }
        }

        return $out;
    }

    /**
     * Self-describe the whole evidence index and run worked evaluations that
     * prove the rules are live: the canonical index passes integrity, a smuggled
     * mutating row is rejected, a regressed projection is caught, status
     * "available" is NOT runtime, a misaligned pointer blocks advance, and a
     * changed replay hash is flagged non-deterministic.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        // Worked rejection: a would-be edit that smuggles a mutating ledger
        // writer AND a row with no failure_meaning into the index.
        $badRows = self::INDEX + [
            'ledger_write' => [
                'purpose' => 'persist a decision receipt',
                'safe_parallel' => self::PARALLEL_FREE,
                'writes_storage' => true,
                'writes_ledger' => true,
                'roles' => [],
                'key_fields' => [],
                'failure_meaning' => 'should_never_be_catalogued',
            ],
            'unsilenced' => [
                'purpose' => 'a read-only probe missing its failure meaning',
                'safe_parallel' => self::PARALLEL_FREE,
                'writes_storage' => false,
                'writes_ledger' => false,
                'roles' => [],
                'key_fields' => [],
                'failure_meaning' => '',
            ],
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'command_count' => count(self::INDEX),
            'commands' => $this->commands(),
            'index' => self::INDEX,
            'runtime_safety_flags' => self::RUNTIME_SAFETY_FLAGS,
            'runtime_safety_certifiers' => $this->commandsWithRole(self::ROLE_RUNTIME_SAFETY),
            'pointer_certifiers' => $this->commandsWithRole(self::ROLE_POINTER),
            'architecture_docs_certifiers' => $this->commandsWithRole(self::ROLE_ARCHITECTURE_DOCS),
            // Worked samples proving the rules at runtime.
            'sample_index_intact' => $this->catalogIntegrity(),
            'sample_mutating_row_rejected' => $this->catalogIntegrity($badRows),
            'sample_projection_regressed' => $this->runtimeSafetyVerdict(
                ['execution_allowed' => true],
                null,
            ),
            'sample_projection_read_only' => $this->runtimeSafetyVerdict([
                'execution_allowed' => false,
                'completion_allowed' => false,
                'dispatch_allowed' => false,
                'claim_persisted' => false,
                'ledger_write_allowed' => false,
            ], true),
            'sample_status_available_not_runtime' => $this->projectionStatusVerdict('available'),
            'sample_pointer_misaligned' => $this->pointerAligned('slice_a', 'slice_b'),
            'sample_replay_non_deterministic' => $this->replayDeterminismVerdict(
                ['replay_hash' => 'h1', 'proof_bundle_hash' => 'p1'],
                ['replay_hash' => 'h2', 'proof_bundle_hash' => 'p1'],
            ),
            // Doc invariant headlines.
            'every_command_read_only' => $this->catalogIntegrity()['valid'],
            'projection_status_ever_implies_runtime' => false,
        ];
    }
}
