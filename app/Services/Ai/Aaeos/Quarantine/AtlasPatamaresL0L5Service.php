<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the Obras "Patamares L0 to L5" maturity-ladder specification.
 *
 * Turns the doc's compact authority map into deterministic, pure decision logic.
 * The doc defines four load-bearing contract surfaces; this service enforces each
 * one as a method that returns a typed, blocking verdict (no IO, no clock, no DB):
 *
 *   - The ordered maturity ladder L0..L5, each with its documented purpose and
 *       its "Ready when ..." gate. Promotion is monotonic: exactly one step up at
 *       a time, and only when the target level's readiness gate is satisfied.
 *       -> evaluatePromotion(), levels()
 *   - The L0 minimum contract: the required fields, the 12 valid Obra types and
 *       the 7 valid statuses. Anything outside the closed sets is rejected.
 *       -> validateL0Obra()
 *   - The Autonomy ladder A0..A5 with the documented operating rules:
 *       "Initial Atlas target is A2/A3" and "Enterprise A4+ requires logs,
 *       permissions and reversibility". A requested autonomy level above the
 *       personal target is only permitted in enterprise mode AND only when logs,
 *       permissions and reversibility are all present. -> evaluateAutonomy()
 *   - The L5 non-negotiable gates: six "no strategy may ..." rules. A strategy is
 *       admissible only when it violates NONE of them. -> evaluateSovereignGates()
 *
 * Same input -> same output. The doc is the authoring boundary; this code never
 * mutates Obra state, never promotes on UI presence, and never relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/obras/patamares-l0-l5.md
 */
final class AtlasPatamaresL0L5Service
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA = 'atlas.obras.patamares_l0_l5.v1';

    /** Closed verdict set. */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** Ordered maturity ladder (the documented patamares), low -> high. */
    public const LEVELS = ['L0', 'L1', 'L2', 'L3', 'L4', 'L5'];

    /** L0 minimum-contract required fields (doc -> "L0 - Tela Obras"). */
    public const L0_REQUIRED_FIELDS = [
        'id', 'name', 'type', 'domain', 'objective', 'status',
        'deadline', 'priority', 'next_step', 'description',
    ];

    /** Valid Obra types — the 12 documented values (closed set). */
    public const OBRA_TYPES = [
        'academic', 'technical', 'product', 'strategic', 'creative',
        'educational', 'cognitive', 'financial', 'health', 'operational',
        'research', 'documentation',
    ];

    /** Valid Obra statuses — the 7 documented values (closed set). */
    public const OBRA_STATUSES = [
        'Idea', 'Planned', 'In Construction', 'In Review',
        'Blocked', 'Finished', 'Archived',
    ];

    /** Ordered autonomy ladder A0..A5 (doc -> "Autonomy ladder", L3). */
    public const AUTONOMY_LEVELS = ['A0', 'A1', 'A2', 'A3', 'A4', 'A5'];

    /**
     * The highest autonomy level Atlas targets by default for personal mode.
     * Doc: "Initial Atlas target is A2/A3." -> the ceiling without enterprise gates.
     */
    public const PERSONAL_AUTONOMY_CEILING = 'A3';

    /**
     * Per-level purpose + readiness gate, verbatim intent from the doc body.
     * `ready_when` is the documented "Ready when ..." condition for each level.
     *
     * @var array<string, array{purpose: string, ready_when: string}>
     */
    private const LEVEL_SPEC = [
        'L0' => [
            'purpose' => 'make Obra a first-class Atlas entity',
            'ready_when' => 'Atlas can create, list, open, edit status, update next step and archive Obras with required-field gates',
        ],
        'L1' => [
            'purpose' => 'turn an Obra from a card into a living production workspace',
            'ready_when' => 'Atlas preserves context per Obra, associates work artifacts and uses AI inside the Obra context without losing traceability',
        ],
        'L2' => [
            'purpose' => 'make Obras reliable, auditable and governable',
            'ready_when' => 'Atlas controls permissions, versions, decisions, evidence, gate runs and traceable outputs',
        ],
        'L3' => [
            'purpose' => 'conduct an Obra from intention to validated delivery',
            'ready_when' => 'Atlas receives an intention and conducts a simple Obra to validated delivery with spec, plan, gates, repairs, decisions and final version',
        ],
        'L4' => [
            'purpose' => 'manage a strategic portfolio of Obras that become assets',
            'ready_when' => 'Atlas maps the portfolio, prioritizes Obras, detects dependencies and dispersion, suggests spin-offs and recommends pause/kill/scale',
        ],
        'L5' => [
            'purpose' => "govern Vitor's autonomy ecosystem through Obras",
            'ready_when' => 'Atlas connects Obras to life strategy, increases autonomy, protects human constraints, reviews portfolio/operator state and turns deliveries into assets',
        ],
    ];

    /**
     * Autonomy ladder semantics (doc -> "Autonomy ladder", L3).
     * `runs` = true when the level actually executes work (A2+).
     *
     * @var array<string, array{label: string, runs: bool}>
     */
    private const AUTONOMY_SPEC = [
        'A0' => ['label' => 'suggests only', 'runs' => false],
        'A1' => ['label' => 'suggests and organizes', 'runs' => false],
        'A2' => ['label' => 'executes with confirmation', 'runs' => true],
        'A3' => ['label' => 'executes safe parts alone', 'runs' => true],
        'A4' => ['label' => 'executes full flows with checkpoints', 'runs' => true],
        'A5' => ['label' => 'supervised autonomy with strong policy', 'runs' => true],
    ];

    /**
     * L5 non-negotiable gates (doc -> "L5 - Atlas Sovereign OS").
     * Each entry maps a stable rule key to the boolean violation flag a caller
     * supplies. If the flag is true, the rule is VIOLATED and the strategy fails.
     *
     * @var array<string, string>
     */
    private const SOVEREIGN_GATES = [
        'increases_money_destroying_health' => 'no strategy may increase money while destroying health',
        'increases_productivity_destroying_relationships' => 'no strategy may increase productivity while destroying primary relationships',
        'increases_speed_reducing_integrity' => 'no strategy may increase speed while reducing integrity',
        'creates_many_obras_without_focus' => 'no strategy may create many Obras without focus',
        'depends_on_untested_assumptions' => 'no strategy may depend on untested assumptions',
        'lacks_success_metric_or_reversal_plan' => 'no strategy may lack success metric or reversal plan',
    ];

    /**
     * The ordered ladder with purpose + readiness, as an evidence list.
     *
     * @return list<array{level: string, index: int, purpose: string, ready_when: string}>
     */
    public function levels(): array
    {
        $out = [];
        foreach (self::LEVELS as $i => $level) {
            $out[] = [
                'level' => $level,
                'index' => $i,
                'purpose' => self::LEVEL_SPEC[$level]['purpose'],
                'ready_when' => self::LEVEL_SPEC[$level]['ready_when'],
            ];
        }

        return $out;
    }

    /**
     * Promotion rule: monotonic, one step at a time, gated by readiness.
     *
     * The doc states Obras "must be implemented in layers while preserving the
     * final-state ontology" and gives a "Ready when ..." condition per level. A
     * promotion from `$from` to `$to` is allowed ONLY when:
     *   - both levels are known,
     *   - `$to` is exactly one step above `$from` (no skipping, no demotion here),
     *   - the source level's readiness gate is met (`from_ready` = true).
     *
     * @return array{
     *     status: string, schema: string, from: string, to: string,
     *     allowed: bool, blocking_reasons: list<string>,
     *     target_ready_when: ?string
     * }
     */
    public function evaluatePromotion(string $from, string $to, bool $fromReady): array
    {
        $reasons = [];
        $fromIdx = array_search($from, self::LEVELS, true);
        $toIdx = array_search($to, self::LEVELS, true);

        if ($fromIdx === false) {
            $reasons[] = "unknown_source_level: {$from}";
        }
        if ($toIdx === false) {
            $reasons[] = "unknown_target_level: {$to}";
        }

        if ($fromIdx !== false && $toIdx !== false) {
            if ($toIdx === $fromIdx) {
                $reasons[] = 'no_op: source and target level are identical';
            } elseif ($toIdx < $fromIdx) {
                $reasons[] = "demotion_not_a_promotion: {$from} -> {$to}";
            } elseif ($toIdx - $fromIdx > 1) {
                $reasons[] = "level_skip_forbidden: promotion must be one step (got {$from} -> {$to})";
            } elseif (! $fromReady) {
                $reasons[] = "source_level_not_ready: {$from} readiness gate is not satisfied";
            }
        }

        $allowed = $reasons === [];

        return [
            'status' => $allowed ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'from' => $from,
            'to' => $to,
            'allowed' => $allowed,
            'blocking_reasons' => $reasons,
            'target_ready_when' => $toIdx !== false ? self::LEVEL_SPEC[$to]['ready_when'] : null,
        ];
    }

    /**
     * L0 minimum contract: required fields present + type/status in closed sets.
     *
     * @param array<string, mixed> $obra
     * @return array{
     *     status: string, schema: string, valid: bool,
     *     missing_fields: list<string>, invalid_type: ?string,
     *     invalid_status: ?string, blocking_reasons: list<string>
     * }
     */
    public function validateL0Obra(array $obra): array
    {
        $reasons = [];

        $missing = [];
        foreach (self::L0_REQUIRED_FIELDS as $field) {
            $value = $obra[$field] ?? null;
            if ($value === null || (is_string($value) && trim($value) === '')) {
                $missing[] = $field;
            }
        }
        foreach ($missing as $field) {
            $reasons[] = "missing_required_field: {$field}";
        }

        $type = is_string($obra['type'] ?? null) ? $obra['type'] : null;
        $invalidType = ($type !== null && ! in_array($type, self::OBRA_TYPES, true)) ? $type : null;
        if ($invalidType !== null) {
            $reasons[] = "invalid_type: {$invalidType} is not one of the 12 documented types";
        }

        $status = is_string($obra['status'] ?? null) ? $obra['status'] : null;
        $invalidStatus = ($status !== null && ! in_array($status, self::OBRA_STATUSES, true)) ? $status : null;
        if ($invalidStatus !== null) {
            $reasons[] = "invalid_status: {$invalidStatus} is not one of the 7 documented statuses";
        }

        $valid = $reasons === [];

        return [
            'status' => $valid ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'valid' => $valid,
            'missing_fields' => $missing,
            'invalid_type' => $invalidType,
            'invalid_status' => $invalidStatus,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Autonomy gate (doc -> L3 "Autonomy ladder").
     *
     * Decides whether Atlas may operate at `$requestedLevel`.
     *   - Unknown level -> blocked.
     *   - At or below the personal ceiling (A2/A3) -> permitted in any mode.
     *   - Above the ceiling (A4, A5) -> permitted ONLY when `$enterprise` is true
     *     AND logs, permissions and reversibility are ALL present, because the
     *     doc states "Enterprise A4+ requires logs, permissions and reversibility".
     *
     * @param array{logs?: bool, permissions?: bool, reversibility?: bool} $controls
     * @return array{
     *     status: string, schema: string, requested: string, runs: bool,
     *     permitted: bool, requires_enterprise_controls: bool,
     *     missing_controls: list<string>, blocking_reasons: list<string>
     * }
     */
    public function evaluateAutonomy(string $requestedLevel, bool $enterprise = false, array $controls = []): array
    {
        $reasons = [];
        $missing = [];
        $idx = array_search($requestedLevel, self::AUTONOMY_LEVELS, true);
        $ceilingIdx = array_search(self::PERSONAL_AUTONOMY_CEILING, self::AUTONOMY_LEVELS, true);

        if ($idx === false) {
            return [
                'status' => self::STATUS_FAIL,
                'schema' => self::SCHEMA,
                'requested' => $requestedLevel,
                'runs' => false,
                'permitted' => false,
                'requires_enterprise_controls' => false,
                'missing_controls' => [],
                'blocking_reasons' => ["unknown_autonomy_level: {$requestedLevel}"],
            ];
        }

        $requiresEnterprise = $idx > $ceilingIdx;

        if ($requiresEnterprise) {
            if (! $enterprise) {
                $reasons[] = "above_personal_ceiling: {$requestedLevel} exceeds initial target "
                    . self::PERSONAL_AUTONOMY_CEILING . ' and requires enterprise mode';
            }
            foreach (['logs', 'permissions', 'reversibility'] as $control) {
                if (($controls[$control] ?? false) !== true) {
                    $missing[] = $control;
                }
            }
            foreach ($missing as $control) {
                $reasons[] = "missing_enterprise_control: {$control} required for A4+";
            }
        }

        $permitted = $reasons === [];

        return [
            'status' => $permitted ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'requested' => $requestedLevel,
            'runs' => self::AUTONOMY_SPEC[$requestedLevel]['runs'],
            'permitted' => $permitted,
            'requires_enterprise_controls' => $requiresEnterprise,
            'missing_controls' => $missing,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * L5 non-negotiable gates (doc -> "L5 - Atlas Sovereign OS").
     *
     * A strategy is admissible only when it violates NONE of the six gates.
     * Callers supply a map of violation flags; a true flag means that gate is
     * breached. Unsupplied flags default to false (gate not breached).
     *
     * @param array<string, bool> $violations keyed by SOVEREIGN_GATES keys
     * @return array{
     *     status: string, schema: string, admissible: bool,
     *     violated_gates: list<string>, blocking_reasons: list<string>
     * }
     */
    public function evaluateSovereignGates(array $violations): array
    {
        $violated = [];
        $reasons = [];

        foreach (self::SOVEREIGN_GATES as $key => $rule) {
            if (($violations[$key] ?? false) === true) {
                $violated[] = $key;
                $reasons[] = "sovereign_gate_violated: {$rule}";
            }
        }

        $admissible = $violated === [];

        return [
            'status' => $admissible ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'admissible' => $admissible,
            'violated_gates' => $violated,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-ladder evidence document over a reference bundle. Aggregates every
     * surface into a single pass|fail verdict (fails if ANY sub-check fails).
     *
     * @param array{
     *     promotion?: array{from: string, to: string, from_ready: bool},
     *     l0?: array<string, mixed>,
     *     autonomy?: array{level: string, enterprise?: bool, controls?: array<string, bool>},
     *     sovereign?: array<string, bool>
     * } $bundle
     * @return array{status: string, schema: string, levels: list<array<string, mixed>>, checks: array<string, mixed>}
     */
    public function audit(array $bundle): array
    {
        $checks = [];

        if (isset($bundle['promotion'])) {
            $p = $bundle['promotion'];
            $checks['promotion'] = $this->evaluatePromotion(
                $p['from'],
                $p['to'],
                $p['from_ready'] ?? false,
            );
        }
        if (isset($bundle['l0'])) {
            $checks['l0'] = $this->validateL0Obra($bundle['l0']);
        }
        if (isset($bundle['autonomy'])) {
            $a = $bundle['autonomy'];
            $checks['autonomy'] = $this->evaluateAutonomy(
                $a['level'],
                $a['enterprise'] ?? false,
                $a['controls'] ?? [],
            );
        }
        if (isset($bundle['sovereign'])) {
            $checks['sovereign'] = $this->evaluateSovereignGates($bundle['sovereign']);
        }

        $allPass = true;
        foreach ($checks as $check) {
            if (($check['status'] ?? self::STATUS_FAIL) !== self::STATUS_PASS) {
                $allPass = false;
                break;
            }
        }

        return [
            'status' => $allPass ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'levels' => $this->levels(),
            'checks' => $checks,
        ];
    }
}
