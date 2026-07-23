<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Atlas Universal Failure Mode Catalog (AUFC) — pure, deterministic recovery router.
 *
 * Each canonical doc declares its own `failure_modes`; this catalog consolidates
 * them into ONE governed authority. Where a per-doc failure-modes service decides
 * the recovery for its own surface, AUFC is the cross-AAEOS index: given a
 * detected failure mode it resolves the catalog entry (severity, blast radius,
 * detection signals, mitigation, recovery steps, learning), routes the severity
 * to its documented runtime action, and emits the worst-severity verdict across a
 * set of simultaneously-active modes. It is read-only: it never runs a provider,
 * never writes evidence and never relaxes a gate.
 *
 * Five documented surfaces are implemented faithfully:
 *
 *   1. Catalog ("Catalogo (snapshot 2026-05-26)"). Nineteen entries, each keyed
 *      by its documented id and carrying the exact `atlas.failure_mode.entry.v1`
 *      fields: doc_owner, severity, blast_radius, detection, recovery. `entry()`
 *      / `catalog()` expose them; an entry whose id is unknown is — per "Regras
 *      para IA" ("Failure mode sem entry aqui nao tem runbook governado") —
 *      reported as ungoverned, never silently allowed.
 *
 *   2. Severity policy ("Severity policy"). Four closed levels, each mapped to
 *      its documented runtime action: critical -> halt_runtime, high ->
 *      block_escalate, medium -> mitigate_monitor, low -> track. `actionFor()`
 *      returns the action for one severity; `classify()` walks active modes and
 *      returns the single worst severity (low < medium < high < critical) so a
 *      `critical` mode is never masked by a `low` notice.
 *
 *   3. Flow ("Fluxo"). detect -> classify severity -> route action -> recovery
 *      runbook -> register learning. `route()` returns the full chain for an
 *      active mode (the entry, its action, whether the runtime must halt, the
 *      recovery steps, and whether a learning entry is owed).
 *
 *   4. Blast-radius ladder ("Schema" enum). Four closed values, ordered widest
 *      last (single_intent < single_obra < department < aaeos_global). The
 *      catalog verdict surfaces the widest active blast radius so a global
 *      failure dominates a single-intent one.
 *
 *   5. Recurrence rule ("Regras para IA"). "Recurrence > 3 em 7 dias -> escalate
 *      Architect." `recurrenceEscalates()` flips to true the moment a 7-day
 *      window count exceeds 3 (i.e. at 4), and "Critical sem recovery testado ->
 *      bloqueia runtime" is enforced by `criticalNeedsTestedRecovery()`.
 *
 * Non-goals honoured ("O que este doc NAO e" / forbidden_changes):
 *   - does NOT replace per-doc `failure_modes` — it consolidates them;
 *   - does NOT remove a mode (the catalog is the closed governed set);
 *   - read-only: it reports the action the catalog demands; the operator acts.
 *
 * @see docs/engineering-knowledge-base/atlas-universal-failure-mode-catalog.md
 */
final class AtlasUniversalFailureModeCatalogService
{
    /** Canonical schema id for one catalog entry / the verdict envelope. */
    public const ENTRY_SCHEMA = 'atlas.failure_mode.entry.v1';

    // --- Severity policy (closed set; "Severity policy", weakest -> strongest). -
    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';
    public const SEVERITY_CRITICAL = 'critical';

    /**
     * The four severities in documented order, lowest impact first. The index is
     * the comparison rank used to pick the single worst active mode.
     *
     * @var list<string>
     */
    public const SEVERITY_ORDER = [
        self::SEVERITY_LOW,
        self::SEVERITY_MEDIUM,
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    /**
     * Runtime action per severity ("Severity policy"):
     *   - critical: corrupts canonical state -> halt runtime + forensics.
     *   - high    : blocks operation, state preserved -> block + escalate.
     *   - medium  : degrades quality -> mitigate + monitor.
     *   - low     : notice -> track.
     *
     * @var array<string,string>
     */
    public const SEVERITY_ACTION = [
        self::SEVERITY_LOW => 'track',
        self::SEVERITY_MEDIUM => 'mitigate_monitor',
        self::SEVERITY_HIGH => 'block_escalate',
        self::SEVERITY_CRITICAL => 'halt_runtime',
    ];

    /**
     * Severities for which the runtime must withhold execution / halt. A
     * `critical` halts (corrupts canonical state); `high` blocks (operation
     * stopped, state preserved). `medium`/`low` continue.
     *
     * @var list<string>
     */
    public const HALTING_SEVERITIES = [
        self::SEVERITY_HIGH,
        self::SEVERITY_CRITICAL,
    ];

    // --- Blast-radius ladder (closed set; "Schema" enum, narrowest -> widest). --
    public const BLAST_SINGLE_INTENT = 'single_intent';
    public const BLAST_SINGLE_OBRA = 'single_obra';
    public const BLAST_DEPARTMENT = 'department';
    public const BLAST_AAEOS_GLOBAL = 'aaeos_global';

    /** @var list<string> */
    public const BLAST_ORDER = [
        self::BLAST_SINGLE_INTENT,
        self::BLAST_SINGLE_OBRA,
        self::BLAST_DEPARTMENT,
        self::BLAST_AAEOS_GLOBAL,
    ];

    /**
     * Recurrence escalation threshold ("Regras para IA": "Recurrence > 3 em 7
     * dias -> escalate Architect"). Strictly greater than 3 -> escalate (4+).
     */
    public const RECURRENCE_ESCALATION_THRESHOLD = 3;

    // --- Failure-mode ids (closed governed set; "Catalogo", documented order). --
    public const MODE_PHASE_SKIPPED_NO_RECEIPT = 'phase_skipped_no_receipt';
    public const MODE_HANDOFF_WITHOUT_SCHEMA = 'handoff_without_schema';
    public const MODE_HTTP_PATH_LEGACY_FALLBACK = 'http_path_legacy_fallback';
    public const MODE_LAYER_VIOLATION_MULTI_AGENT = 'layer_violation_multi_agent';
    public const MODE_SPRAWL_COMMAND_NAME = 'sprawl_command_name';
    public const MODE_RESERVATION_LEASE_LEAK = 'reservation_lease_leak';
    public const MODE_COLLISION_SILENT = 'collision_silent';
    public const MODE_AUTONOMY_PROMOTE_PREMATURE = 'autonomy_promote_premature';
    public const MODE_DUAL_SIGNATURE_BYPASS = 'dual_signature_bypass';
    public const MODE_SCHEMA_BREAKING_NO_RECEIPT = 'schema_breaking_no_receipt';
    public const MODE_DEPT_BLOCKER_STALE = 'dept_blocker_stale';
    public const MODE_OBRA_REPLAY_DETERMINISM_VIOLATION = 'obra_replay_determinism_violation';
    public const MODE_REPAIR_LOOP_INFINITE = 'repair_loop_infinite';
    public const MODE_VETO_PROPAGATION_LOST = 'veto_propagation_lost';
    public const MODE_DOCS_HEALTH_DRIFT = 'docs_health_drift';
    public const MODE_TRUST_LEDGER_SCORE_DROP = 'trust_ledger_score_drop';
    public const MODE_ACOS_HEALTH_DEGRADED = 'acos_health_degraded';
    public const MODE_EVIDENCE_LEDGER_HASH_MISMATCH = 'evidence_ledger_hash_mismatch';
    public const MODE_REPEAT_WORK_LOOP = 'repeat_work_loop';

    /**
     * The full catalog ("Catalogo (snapshot 2026-05-26)"), one row per documented
     * mode, transcribing the table verbatim into `atlas.failure_mode.entry.v1`
     * fields. Detection and recovery are split into the documented signal/step
     * lists.
     *
     * @var array<string,array{doc_owner:string,severity:string,blast_radius:string,detection:list<string>,mitigation:list<string>,recovery:list<string>}>
     */
    public const CATALOG = [
        self::MODE_PHASE_SKIPPED_NO_RECEIPT => [
            'doc_owner' => 'aaeos-runbook',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_SINGLE_INTENT,
            'detection' => ['aaeos_phase_skipped_count > 0'],
            'mitigation' => ['block phase advance until receipt present'],
            'recovery' => ['rollback fase', 'emit receipt forensico'],
        ],
        self::MODE_HANDOFF_WITHOUT_SCHEMA => [
            'doc_owner' => 'aaeos-runbook',
            'severity' => self::SEVERITY_MEDIUM,
            'blast_radius' => self::BLAST_SINGLE_INTENT,
            'detection' => ['validator envelope'],
            'mitigation' => ['hold handoff at validator'],
            'recovery' => ['repair handoff', 'emit envelope'],
        ],
        self::MODE_HTTP_PATH_LEGACY_FALLBACK => [
            'doc_owner' => 'http-path-spec',
            'severity' => self::SEVERITY_MEDIUM,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['http_path_legacy_fallback_rate > 0.05'],
            'mitigation' => ['monitor fallback rate'],
            'recovery' => ['revert flag para fase anterior'],
        ],
        self::MODE_LAYER_VIOLATION_MULTI_AGENT => [
            'doc_owner' => 'multi-agent-unified',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['amua_layer_violation_count > 0'],
            'mitigation' => ['block PR'],
            'recovery' => ['block PR', 'Architect review'],
        ],
        self::MODE_SPRAWL_COMMAND_NAME => [
            'doc_owner' => 'self-construction-compaction',
            'severity' => self::SEVERITY_MEDIUM,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['scos_command_max_name_length > 80'],
            'mitigation' => ['block release'],
            'recovery' => ['block release', 'refactor'],
        ],
        self::MODE_RESERVATION_LEASE_LEAK => [
            'doc_owner' => 'parallel-multi-agent',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_SINGLE_OBRA,
            'detection' => ['parallel_lease_expired_count > N'],
            'mitigation' => ['monitor lease TTL'],
            'recovery' => ['force_release', 'GC worktree'],
        ],
        self::MODE_COLLISION_SILENT => [
            'doc_owner' => 'parallel-multi-agent',
            'severity' => self::SEVERITY_CRITICAL,
            'blast_radius' => self::BLAST_SINGLE_OBRA,
            'detection' => ['merge produces unmerged conflict'],
            'mitigation' => ['abort merge'],
            'recovery' => ['abort merge', 'force review'],
        ],
        self::MODE_AUTONOMY_PROMOTE_PREMATURE => [
            'doc_owner' => 'autonomy-ladder-runbook',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['autonomy_demote_count > 0 within 7d'],
            'mitigation' => ['freeze ladder'],
            'recovery' => ['demote', 'freeze ladder'],
        ],
        self::MODE_DUAL_SIGNATURE_BYPASS => [
            'doc_owner' => 'mission-control-cockpit',
            'severity' => self::SEVERITY_CRITICAL,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['gesture L4+ sem dual sig'],
            'mitigation' => ['revoke gesture'],
            'recovery' => ['revoke gesture', 'audit'],
        ],
        self::MODE_SCHEMA_BREAKING_NO_RECEIPT => [
            'doc_owner' => 'contract-registry',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['bump v1->v2 sem receipt'],
            'mitigation' => ['block PR'],
            'recovery' => ['block PR'],
        ],
        self::MODE_DEPT_BLOCKER_STALE => [
            'doc_owner' => 'dept-maturity-matrix',
            'severity' => self::SEVERITY_LOW,
            'blast_radius' => self::BLAST_DEPARTMENT,
            'detection' => ['last_evaluation > 30d'],
            'mitigation' => ['track staleness'],
            'recovery' => ['force re-eval'],
        ],
        self::MODE_OBRA_REPLAY_DETERMINISM_VIOLATION => [
            'doc_owner' => 'obra-replay',
            'severity' => self::SEVERITY_CRITICAL,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['replay_determinism_violation_count > 0'],
            'mitigation' => ['freeze replay'],
            'recovery' => ['freeze replay', 'audit hash chain'],
        ],
        self::MODE_REPAIR_LOOP_INFINITE => [
            'doc_owner' => 'cross-dept-choreography',
            'severity' => self::SEVERITY_CRITICAL,
            'blast_radius' => self::BLAST_SINGLE_INTENT,
            'detection' => ['repair_loop_iterations > 3'],
            'mitigation' => ['freeze intent'],
            'recovery' => ['escalate operator', 'freeze intent'],
        ],
        self::MODE_VETO_PROPAGATION_LOST => [
            'doc_owner' => 'cross-dept-choreography',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['downstream nao pausa em veto'],
            'mitigation' => ['block runtime'],
            'recovery' => ['block runtime', 'audit'],
        ],
        self::MODE_DOCS_HEALTH_DRIFT => [
            'doc_owner' => 'docs-health-v2',
            'severity' => self::SEVERITY_MEDIUM,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['docs-health fail'],
            'mitigation' => ['block sync'],
            'recovery' => ['block sync', 'manual review'],
        ],
        self::MODE_TRUST_LEDGER_SCORE_DROP => [
            'doc_owner' => 'trust-ledger',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['score < 0.7 sustained'],
            'mitigation' => ['freeze L4+ promotions'],
            'recovery' => ['freeze L4+ promotions'],
        ],
        self::MODE_ACOS_HEALTH_DEGRADED => [
            'doc_owner' => 'acos',
            'severity' => self::SEVERITY_HIGH,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['acos_health=degraded'],
            'mitigation' => ['alert'],
            'recovery' => ['fail-safe to L0', 'alert'],
        ],
        self::MODE_EVIDENCE_LEDGER_HASH_MISMATCH => [
            'doc_owner' => 'evidence-cert-runtime',
            'severity' => self::SEVERITY_CRITICAL,
            'blast_radius' => self::BLAST_AAEOS_GLOBAL,
            'detection' => ['hash chain inconsistency'],
            'mitigation' => ['freeze writes'],
            'recovery' => ['freeze writes', 'forensics'],
        ],
        self::MODE_REPEAT_WORK_LOOP => [
            'doc_owner' => 'cross-dept-choreography',
            'severity' => self::SEVERITY_MEDIUM,
            'blast_radius' => self::BLAST_SINGLE_INTENT,
            'detection' => ['same step repeats with no new evidence'],
            'mitigation' => ['watch recurrence count'],
            'recovery' => ['narrow scope', 'register learning'],
        ],
    ];

    /**
     * Whole catalog, one normalized entry per mode (schema-shaped).
     *
     * @return array<string,array{schema:string,id:string,doc_owner:string,severity:string,blast_radius:string,detection:list<string>,mitigation:list<string>,recovery:list<string>}>
     */
    public function catalog(): array
    {
        $out = [];
        foreach (array_keys(self::CATALOG) as $id) {
            $out[$id] = $this->entry($id);
        }

        return $out;
    }

    /** Ordered list of all governed mode ids. @return list<string> */
    public function modeIds(): array
    {
        return array_keys(self::CATALOG);
    }

    /**
     * Resolve one catalog entry as a full `atlas.failure_mode.entry.v1` record.
     * An unknown id is reported as ungoverned ("Failure mode sem entry aqui nao
     * tem runbook governado") rather than throwing — the caller must escalate.
     *
     * @return array{schema:string,id:string,governed:bool,doc_owner:string,severity:string,blast_radius:string,detection:list<string>,mitigation:list<string>,recovery:list<string>}
     */
    public function entry(string $modeId): array
    {
        if (! isset(self::CATALOG[$modeId])) {
            return [
                'schema' => self::ENTRY_SCHEMA,
                'id' => $modeId,
                'governed' => false,
                'doc_owner' => '',
                'severity' => '',
                'blast_radius' => '',
                'detection' => [],
                'mitigation' => [],
                'recovery' => [],
            ];
        }

        $row = self::CATALOG[$modeId];

        return [
            'schema' => self::ENTRY_SCHEMA,
            'id' => $modeId,
            'governed' => true,
            'doc_owner' => $row['doc_owner'],
            'severity' => $row['severity'],
            'blast_radius' => $row['blast_radius'],
            'detection' => $row['detection'],
            'mitigation' => $row['mitigation'],
            'recovery' => $row['recovery'],
        ];
    }

    /** True when the mode id has a governed catalog entry. */
    public function isGoverned(string $modeId): bool
    {
        return isset(self::CATALOG[$modeId]);
    }

    /**
     * Runtime action for a severity ("Severity policy"). An unknown severity is
     * conservatively treated as halting (never silently continue).
     */
    public function actionFor(string $severity): string
    {
        return self::SEVERITY_ACTION[$severity] ?? 'halt_runtime';
    }

    /** True when a severity requires the runtime to stop/halt (high|critical). */
    public function isHalting(string $severity): bool
    {
        return in_array($severity, self::HALTING_SEVERITIES, true);
    }

    /** Numeric rank of a severity (0=low .. 3=critical; -1 if unknown). */
    public function severityRank(string $severity): int
    {
        $rank = array_search($severity, self::SEVERITY_ORDER, true);

        return $rank === false ? -1 : (int) $rank;
    }

    /** Numeric rank of a blast radius (0=single_intent .. 3=aaeos_global; -1 if unknown). */
    public function blastRank(string $blast): int
    {
        $rank = array_search($blast, self::BLAST_ORDER, true);

        return $rank === false ? -1 : (int) $rank;
    }

    /**
     * The full documented flow for ONE active mode ("Fluxo"):
     * detect -> classify -> route action -> recovery -> register learning.
     *
     * @return array{schema:string,id:string,governed:bool,severity:string,blast_radius:string,action:string,halt_runtime:bool,detection:list<string>,recovery:list<string>,register_learning:bool}
     */
    public function route(string $modeId): array
    {
        $entry = $this->entry($modeId);

        if (! $entry['governed']) {
            // Ungoverned mode: no runbook -> escalate. Treated as halting.
            return [
                'schema' => self::ENTRY_SCHEMA,
                'id' => $modeId,
                'governed' => false,
                'severity' => '',
                'blast_radius' => '',
                'action' => 'escalate_ungoverned',
                'halt_runtime' => true,
                'detection' => [],
                'recovery' => ['add catalog entry', 'escalate operator'],
                'register_learning' => true,
            ];
        }

        $severity = $entry['severity'];

        return [
            'schema' => self::ENTRY_SCHEMA,
            'id' => $modeId,
            'governed' => true,
            'severity' => $severity,
            'blast_radius' => $entry['blast_radius'],
            'action' => $this->actionFor($severity),
            'halt_runtime' => $this->isHalting($severity),
            'detection' => $entry['detection'],
            'recovery' => $entry['recovery'],
            // The flow always ends in "register learning".
            'register_learning' => true,
        ];
    }

    /**
     * Classify a set of simultaneously-active modes and emit the single worst
     * verdict: the worst severity (a critical is never masked by a low), the
     * widest blast radius, the resulting runtime action, whether execution must
     * be withheld, and whether any ungoverned mode was present.
     *
     * @param  list<string>  $activeModes
     * @return array{schema:string,active_count:int,worst_severity:string,widest_blast_radius:string,action:string,withhold_execution:bool,has_ungoverned:bool,governed_modes:list<string>,ungoverned_modes:list<string>}
     */
    public function classify(array $activeModes): array
    {
        $worstRank = -1;
        $worstSeverity = self::SEVERITY_LOW;
        $widestBlastRank = -1;
        $widestBlast = '';
        $governed = [];
        $ungoverned = [];

        foreach ($activeModes as $modeId) {
            if (! $this->isGoverned($modeId)) {
                $ungoverned[] = $modeId;

                continue;
            }

            $governed[] = $modeId;
            $row = self::CATALOG[$modeId];

            $sRank = $this->severityRank($row['severity']);
            if ($sRank > $worstRank) {
                $worstRank = $sRank;
                $worstSeverity = $row['severity'];
            }

            $bRank = $this->blastRank($row['blast_radius']);
            if ($bRank > $widestBlastRank) {
                $widestBlastRank = $bRank;
                $widestBlast = $row['blast_radius'];
            }
        }

        $hasUngoverned = $ungoverned !== [];

        // An ungoverned mode has no governed runbook -> escalate. If the governed
        // verdict is softer than "halt", an ungoverned presence still forces a
        // stop (no silent continue on an unknown failure).
        if ($hasUngoverned && $worstRank < $this->severityRank(self::SEVERITY_HIGH)) {
            $action = 'escalate_ungoverned';
            $withhold = true;
        } else {
            $action = $worstRank < 0 ? 'track' : $this->actionFor($worstSeverity);
            $withhold = $this->isHalting($worstSeverity) || $hasUngoverned;
        }

        return [
            'schema' => self::ENTRY_SCHEMA,
            'active_count' => count($activeModes),
            'worst_severity' => $worstRank < 0 ? '' : $worstSeverity,
            'widest_blast_radius' => $widestBlast,
            'action' => $action,
            'withhold_execution' => $withhold,
            'has_ungoverned' => $hasUngoverned,
            'governed_modes' => $governed,
            'ungoverned_modes' => $ungoverned,
        ];
    }

    /**
     * "Recurrence > 3 em 7 dias -> escalate Architect" ("Regras para IA"). The
     * count is the number of occurrences inside the trailing 7-day window;
     * strictly greater than 3 escalates (so 0..3 = no, 4+ = yes).
     */
    public function recurrenceEscalates(int $recurrenceCountWithin7d): bool
    {
        return $recurrenceCountWithin7d > self::RECURRENCE_ESCALATION_THRESHOLD;
    }

    /**
     * "Critical sem recovery testado -> bloqueia runtime" ("Regras para IA"). A
     * critical mode whose recovery has NOT been tested blocks the runtime; any
     * other combination does not trip this specific rule.
     */
    public function criticalNeedsTestedRecovery(string $severity, bool $recoveryTested): bool
    {
        return $severity === self::SEVERITY_CRITICAL && $recoveryTested === false;
    }
}
