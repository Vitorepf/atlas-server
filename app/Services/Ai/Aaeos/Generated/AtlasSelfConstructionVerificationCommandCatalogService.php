<?php

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the Self-Construction Verification Command Catalog v1 doc.
 *
 * The doc is a CATALOG of read-only verification command sequences for the
 * Self-Construction OS, designed for an operator coordinating multiple Claudes
 * simultaneously. Its load-bearing contract is the ordered phase sequences,
 * the safe-vs-isolated classification, and the "Regras para IA". This service
 * makes those rules executable and PURE (no DB, no I/O, no provider, no ledger).
 *
 * The hard invariants enforced here (from "Fluxo" + "Regras para IA"):
 *   - The recommended sequence is READ-ONLY ONLY. A mutating command may NEVER
 *     enter a phase sequence, "mesmo que so para documentar". validateSequence()
 *     rejects any command not in the read-only allow-list.
 *   - chain-integrity certification MUST precede deterministic replay. The doc
 *     forbids reordering replay before integrity ("integrity primeiro, replay
 *     depois"). validateOrdering() flags this exact inversion.
 *   - promotion / completion claim is FORBIDDEN while not_yet_runtime_capable is
 *     non-empty. promotionDecision() returns forbidden whenever that list has
 *     items, regardless of how "green" everything else looks.
 *   - "tudo verde" never authorizes promotion (the headline operator risk).
 *   - During a macro-sprint, the expensive replay and the canonical-edit-fragile
 *     docs-health are on an avoid-list (prefer begin/end of sprint).
 *
 * This service NEVER runs a command and NEVER promotes anything to writer or
 * runtime — it only sequences, classifies and gates read-only verification.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-self-construction-verification-command-catalog-v1.md
 */
final class AtlasSelfConstructionVerificationCommandCatalogService
{
    public const SCHEMA_VERSION = 'atlas.aaeos.self_construction_verification_command_catalog.v1';

    public const MODE = 'read_only_verification_command_catalog';

    /** Canonical phase keys (the four "Fluxo" stages, in lifecycle order). */
    public const PHASE_BASELINE = 'before_macro_sprint';

    public const PHASE_DURING = 'during_macro_sprint';

    public const PHASE_CLOSE = 'after_macro_sprint';

    public const PHASE_PROMOTION = 'before_promotion_or_completion';

    public const PHASES = [
        self::PHASE_BASELINE,
        self::PHASE_DURING,
        self::PHASE_CLOSE,
        self::PHASE_PROMOTION,
    ];

    /** Stable command keys for the read-only verification commands in the doc. */
    public const CMD_PWD = 'pwd';

    public const CMD_GIT_STATUS = 'git_status_short';

    public const CMD_LIST = 'php_artisan_list_rg';

    public const CMD_GIT_DIFF_CHECK = 'git_diff_check';

    public const CMD_PROJECTION = 'agent_control_plane';

    public const CMD_CHAIN_INTEGRITY = 'agent_control_plane_chain_integrity_certification_status';

    public const CMD_REPLAY = 'agent_control_plane_deterministic_chain_replay_status';

    public const CMD_DOCS_HEALTH = 'docs_health';

    public const CMD_ARCHITECTURE_VALIDATE = 'architecture_validate';

    /**
     * The complete set of commands the doc permits this Claude to run. ALL are
     * read-only. Anything outside this set is treated as mutating / forbidden by
     * validateSequence(), enforcing "nunca incluir comando mutating".
     *
     * @var array<int,string>
     */
    public const READ_ONLY_ALLOWED = [
        self::CMD_PWD,
        self::CMD_GIT_STATUS,
        self::CMD_LIST,
        self::CMD_GIT_DIFF_CHECK,
        self::CMD_PROJECTION,
        self::CMD_CHAIN_INTEGRITY,
        self::CMD_REPLAY,
        self::CMD_DOCS_HEALTH,
        self::CMD_ARCHITECTURE_VALIDATE,
    ];

    /**
     * Commands that need REAL isolation and are therefore forbidden during this
     * read-only operation (provider start, dispatch tick, ledger writers,
     * snapshot persist). Used as the negative reference for "mutating".
     *
     * @var array<int,string>
     */
    public const FORBIDDEN_MUTATING = [
        'provider_start',
        'dispatch_tick',
        'ledger_write',
        'snapshot_store_persist',
        'promotion_gate',
        'completion_claim',
    ];

    /**
     * Recommended ordered sequence per phase, taken literally from the "Fluxo"
     * bash blocks. Order is contractual: chain-integrity ALWAYS before replay.
     *
     * @var array<string,array<int,string>>
     */
    public const PHASE_SEQUENCES = [
        self::PHASE_BASELINE => [
            self::CMD_PWD,
            self::CMD_GIT_STATUS,
            self::CMD_LIST,
            self::CMD_PROJECTION,
            self::CMD_CHAIN_INTEGRITY,
            self::CMD_REPLAY,
            self::CMD_DOCS_HEALTH,
            self::CMD_ARCHITECTURE_VALIDATE,
        ],
        self::PHASE_DURING => [
            self::CMD_PWD,
            self::CMD_GIT_STATUS,
            self::CMD_LIST,
            self::CMD_PROJECTION,
        ],
        self::PHASE_CLOSE => [
            self::CMD_GIT_STATUS,
            self::CMD_PROJECTION,
            self::CMD_CHAIN_INTEGRITY,
            self::CMD_REPLAY,
            self::CMD_DOCS_HEALTH,
            self::CMD_ARCHITECTURE_VALIDATE,
            self::CMD_GIT_DIFF_CHECK,
        ],
        // The future, currently-forbidden promotion sequence. Lists the read-only
        // pre-checks only; the mutating gate itself lives OUTSIDE this doc.
        self::PHASE_PROMOTION => [
            self::CMD_CHAIN_INTEGRITY,
            self::CMD_REPLAY,
            self::CMD_DOCS_HEALTH,
            self::CMD_ARCHITECTURE_VALIDATE,
        ],
    ];

    /**
     * Commands to AVOID *during* a macro-sprint (prefer begin/end). Replay is
     * expensive and can mask real progress; docs-health can snapshot an
     * inconsistent canonical doc while another Claude is rewriting it.
     *
     * @var array<int,string>
     */
    public const DURING_SPRINT_AVOID = [
        self::CMD_REPLAY,
        self::CMD_DOCS_HEALTH,
    ];

    /**
     * Per-command parallel-safety recommendation, from the "Comandos seguros para
     * multiplos Claudes simultaneos" table. 'free' = run freely in parallel;
     * 'free_but_costly' = read-only but avoid simultaneous bursts;
     * 'free_but_snapshot_sensitive' = read-only but may snapshot inconsistently
     * during canonical doc rewrites.
     *
     * @var array<string,string>
     */
    public const PARALLEL_SAFETY = [
        self::CMD_PWD => 'free',
        self::CMD_GIT_STATUS => 'free',
        self::CMD_LIST => 'free',
        self::CMD_GIT_DIFF_CHECK => 'free',
        self::CMD_PROJECTION => 'free',
        self::CMD_CHAIN_INTEGRITY => 'free',
        self::CMD_REPLAY => 'free_but_costly',
        self::CMD_DOCS_HEALTH => 'free_but_snapshot_sensitive',
        self::CMD_ARCHITECTURE_VALIDATE => 'free_but_snapshot_sensitive',
    ];

    /**
     * Validate that an ordered list of command keys is a legal read-only
     * sequence. Enforces two hard doc rules at once:
     *   - every command is in READ_ONLY_ALLOWED (no mutating command, ever);
     *   - chain-integrity precedes replay wherever both appear.
     * PURE. Returns the verdict plus every concrete violation.
     *
     * @param  array<int,string>  $sequence
     * @return array{
     *   valid:bool,
     *   violations:array<int,string>,
     *   mutating_commands:array<int,string>,
     *   ordering_ok:bool,
     *   human_label:string
     * }
     */
    public function validateSequence(array $sequence): array
    {
        $violations = [];

        $mutating = $this->mutatingCommands($sequence);
        foreach ($mutating as $cmd) {
            $violations[] = 'mutating_command_forbidden:'.$cmd;
        }

        $ordering = $this->validateOrdering($sequence);
        if (! $ordering['ordering_ok']) {
            $violations[] = $ordering['violation'];
        }

        $valid = $violations === [];

        return [
            'valid' => $valid,
            'violations' => $violations,
            'mutating_commands' => array_values($mutating),
            'ordering_ok' => $ordering['ordering_ok'],
            'human_label' => $valid
                ? 'read_only_sequence_valid'
                : 'sequence_rejected_diagnose_before_running',
        ];
    }

    /**
     * The commands in a sequence that are NOT in the read-only allow-list, i.e.
     * the mutating ones the doc forbids from any phase sequence.
     *
     * @param  array<int,string>  $sequence
     * @return array<int,string>
     */
    public function mutatingCommands(array $sequence): array
    {
        $out = [];
        foreach ($sequence as $cmd) {
            if (! in_array($cmd, self::READ_ONLY_ALLOWED, true)) {
                $out[] = $cmd;
            }
        }

        return $out;
    }

    /**
     * Enforce the ordering invariant: chain-integrity certification MUST come
     * before deterministic replay. The doc forbids placing replay first. If only
     * one (or neither) appears, ordering is trivially OK.
     *
     * @param  array<int,string>  $sequence
     * @return array{ordering_ok:bool, violation:string}
     */
    public function validateOrdering(array $sequence): array
    {
        $integrityIdx = array_search(self::CMD_CHAIN_INTEGRITY, $sequence, true);
        $replayIdx = array_search(self::CMD_REPLAY, $sequence, true);

        // Both present and replay appears before integrity -> the forbidden order.
        if ($integrityIdx !== false && $replayIdx !== false && $replayIdx < $integrityIdx) {
            return [
                'ordering_ok' => false,
                'violation' => 'replay_before_chain_integrity_forbidden',
            ];
        }

        return [
            'ordering_ok' => true,
            'violation' => '',
        ];
    }

    /**
     * The canonical recommended sequence for a given phase. Throws nothing —
     * unknown phases return an empty sequence with a marker.
     *
     * @return array{phase:string, known:bool, sequence:array<int,string>, sequence_valid:bool}
     */
    public function sequenceFor(string $phase): array
    {
        $known = array_key_exists($phase, self::PHASE_SEQUENCES);
        $sequence = $known ? self::PHASE_SEQUENCES[$phase] : [];

        return [
            'phase' => $phase,
            'known' => $known,
            'sequence' => $sequence,
            // Every canonical phase sequence must itself satisfy validateSequence.
            'sequence_valid' => $this->validateSequence($sequence)['valid'],
        ];
    }

    /**
     * Decide whether a command should be run DURING a macro-sprint. Commands on
     * the avoid-list are discouraged mid-sprint (prefer begin/end); unknown
     * read-only commands default to allowed; mutating commands are forbidden.
     *
     * @return array{command:string, run_during_sprint:bool, reason:string}
     */
    public function duringSprintAdvice(string $command): array
    {
        if (! in_array($command, self::READ_ONLY_ALLOWED, true)) {
            return [
                'command' => $command,
                'run_during_sprint' => false,
                'reason' => 'mutating_command_forbidden',
            ];
        }

        if (in_array($command, self::DURING_SPRINT_AVOID, true)) {
            return [
                'command' => $command,
                'run_during_sprint' => false,
                'reason' => $command === self::CMD_REPLAY
                    ? 'expensive_replay_prefer_begin_or_end_of_sprint'
                    : 'docs_health_snapshot_may_be_inconsistent_during_canonical_rewrite',
            ];
        }

        return [
            'command' => $command,
            'run_during_sprint' => true,
            'reason' => 'read_only_no_lock_contention',
        ];
    }

    /**
     * Promotion / completion gate. Per the doc, promotion is ABSOLUTELY
     * forbidden while not_yet_runtime_capable is non-empty, and "tudo verde"
     * NEVER authorizes promotion on its own. PURE.
     *
     * @param  array<int,string>  $notYetRuntimeCapable  the explicit negative list
     * @param  bool  $allReadOnlyGreen  whether the read-only checks are all green
     * @return array{
     *   promotion_allowed:bool,
     *   reason:string,
     *   pending_capabilities:array<int,string>,
     *   os_under_construction:bool,
     *   human_label:string
     * }
     */
    public function promotionDecision(array $notYetRuntimeCapable, bool $allReadOnlyGreen = true): array
    {
        $pending = array_values($notYetRuntimeCapable);
        $underConstruction = $pending !== [];

        // Hard rule: any pending capability => promotion forbidden, full stop.
        // Even all-green read-only does NOT lift this.
        $allowed = ! $underConstruction;

        if ($underConstruction) {
            $reason = 'not_yet_runtime_capable_non_empty_promotion_forbidden';
        } elseif (! $allReadOnlyGreen) {
            // Negative list empty but read-only not green -> still not promotable.
            $allowed = false;
            $reason = 'read_only_checks_not_all_green';
        } else {
            $reason = 'read_only_prechecks_clear_mutating_gate_lives_outside_this_doc';
        }

        return [
            'promotion_allowed' => $allowed,
            'reason' => $reason,
            'pending_capabilities' => $pending,
            'os_under_construction' => $underConstruction,
            'human_label' => $allowed
                ? 'promotion_prechecks_clear_invoke_mutating_gate_elsewhere'
                : 'promotion_forbidden_os_not_runtime_complete',
        ];
    }

    /**
     * Self-describe the whole catalog and run worked evaluations that prove the
     * rules are live: a valid baseline sequence, the forbidden replay-first
     * inversion, a mutating-command rejection, the during-sprint avoid advice,
     * and the promotion gate under a non-empty negative list.
     *
     * @return array<string,mixed>
     */
    public function describe(): array
    {
        $baseline = $this->sequenceFor(self::PHASE_BASELINE);

        // Worked rejection 1: replay placed before chain-integrity.
        $invertedSequence = [
            self::CMD_PROJECTION,
            self::CMD_REPLAY,
            self::CMD_CHAIN_INTEGRITY,
        ];

        // Worked rejection 2: a mutating command smuggled in.
        $mutatingSequence = [
            self::CMD_PROJECTION,
            self::CMD_CHAIN_INTEGRITY,
            'ledger_write',
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'phases' => self::PHASES,
            'read_only_allowed' => self::READ_ONLY_ALLOWED,
            'forbidden_mutating' => self::FORBIDDEN_MUTATING,
            'phase_sequences' => self::PHASE_SEQUENCES,
            'during_sprint_avoid' => self::DURING_SPRINT_AVOID,
            'parallel_safety' => self::PARALLEL_SAFETY,
            'baseline_sequence' => $baseline,
            'sample_valid_sequence' => $this->validateSequence($baseline['sequence']),
            'sample_replay_before_integrity' => $this->validateSequence($invertedSequence),
            'sample_mutating_rejected' => $this->validateSequence($mutatingSequence),
            'sample_during_sprint_replay' => $this->duringSprintAdvice(self::CMD_REPLAY),
            'sample_promotion_blocked' => $this->promotionDecision(
                ['automatic_dispatch_scheduler_runtime'],
                true,
            ),
            // Doc invariant headline: promotion is never authorized by this catalog.
            'promotion_authorized' => false,
        ];
    }
}
