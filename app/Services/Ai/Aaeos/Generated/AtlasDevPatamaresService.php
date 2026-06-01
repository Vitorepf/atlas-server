<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Runtime for the "Atlas Dev Patamares" maturity-ladder specification.
 *
 * Turns the doc's conceptual ladder (A0..A7) into deterministic, pure decision
 * logic. The doc is explicit that Atlas Dev evolves through *patamares with their
 * own identity*, never through sequential numeric versions, and that working on a
 * future patamar before the current one is green is the primary anti-pattern. This
 * service enforces each load-bearing rule as a method returning a typed, blocking
 * verdict (no IO, no clock, no DB):
 *
 *   - The ordered ladder A0..A7, each with its capability phrase, lifecycle status
 *       and prerequisite (the previous patamar's cert green). -> levels()
 *   - The current-patamar invariant: exactly one patamar is "em construcao" and it
 *       defines the work scope. -> currentPatamar()
 *   - The anti-pattern guard (doc failure mode #1): a requested feature may target
 *       the current patamar or the very next "planejado" one, but NEVER a patamar
 *       above that — implementing a future patamar before the foundation is green is
 *       rejected. -> evaluateWorkScope()
 *   - The "Mudanca De Patamar" process: a promotion is admissible ONLY when it is a
 *       single monotonic step AND all five documented gates hold (AP approved, new
 *       capability phrase defined, components implemented+tested, previous cert
 *       green, status bump) AND the new name is not a "v2/v3" technical version.
 *       -> evaluatePromotion()
 *
 * Same input -> same output. The doc is the authoring boundary; this code never
 * mutates ladder state, never promotes on partial gates, and never lets a numeric
 * "vN" name pass as a patamar identity.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares.md
 */
final class AtlasDevPatamaresService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA = 'atlas.dev.patamares.v1';

    /** Closed verdict set. */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** Ordered conceptual ladder, low -> high (doc -> A0..A7). */
    public const LEVELS = ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7'];

    /** Closed set of lifecycle statuses a patamar may hold (doc -> maintenance rules). */
    public const STATUS_PASSADO = 'passado';
    public const STATUS_EM_CONSTRUCAO = 'em_construcao';
    public const STATUS_PLANEJADO = 'planejado';
    public const STATUS_FUTURO = 'futuro';

    public const LIFECYCLE_STATUSES = [
        self::STATUS_PASSADO,
        self::STATUS_EM_CONSTRUCAO,
        self::STATUS_PLANEJADO,
        self::STATUS_FUTURO,
    ];

    /**
     * Per-patamar identity: capability phrase + lifecycle status as documented.
     * `capability` is the doc's "Capability achievement" line (final level
     * definitions). `status` is the doc's current status field for that patamar.
     *
     * @var array<string, array{capability: string, status: string}>
     */
    private const LEVEL_SPEC = [
        'A0' => [
            'capability' => 'operator uses raw engine with Atlas branding',
            'status' => self::STATUS_PASSADO,
        ],
        'A1' => [
            'capability' => 'Atlas Dev has a canonical foundation',
            'status' => self::STATUS_EM_CONSTRUCAO,
        ],
        'A2' => [
            'capability' => 'operator sees the plan before spending a token',
            'status' => self::STATUS_PLANEJADO,
        ],
        'A3' => [
            'capability' => 'Atlas Dev produces a governed patch with evidence',
            'status' => self::STATUS_PLANEJADO,
        ],
        'A4' => [
            'capability' => 'Atlas Dev self-heals without council nor fallback',
            'status' => self::STATUS_PLANEJADO,
        ],
        'A5' => [
            'capability' => 'Atlas Dev available on every surface with parity',
            'status' => self::STATUS_PLANEJADO,
        ],
        'A6' => [
            'capability' => 'Atlas Dev inherits any engine leap automatically',
            'status' => self::STATUS_FUTURO,
        ],
        'A7' => [
            'capability' => 'Atlas Dev evolves via human-in-the-loop',
            'status' => self::STATUS_FUTURO,
        ],
    ];

    /**
     * The five gates the doc's "Mudanca De Patamar (Processo)" enumerates. Each
     * must hold for a promotion to be admissible. Keyed by stable rule key -> the
     * documented requirement text.
     *
     * @var array<string, string>
     */
    private const PROMOTION_GATES = [
        'ap_approved' => 'a new Architectural Proposal (AP) is approved',
        'capability_phrase_defined' => 'the new capability phrase is defined in the AP',
        'components_implemented_and_tested' => 'the patamar components are implemented and tested',
        'previous_cert_green' => 'the local cert for the previous patamar is green',
        'status_bump' => 'this doc bumps the status (planejado -> em construcao -> passado)',
    ];

    /**
     * The ordered ladder with identity + status + prerequisite, as evidence.
     *
     * The prerequisite of every patamar above A0 is the previous patamar's cert
     * green (doc -> "Pre-requisito: <prev> cert verde").
     *
     * @return list<array{level: string, index: int, capability: string, status: string, prerequisite: ?string}>
     */
    public function levels(): array
    {
        $out = [];
        foreach (self::LEVELS as $i => $level) {
            $out[] = [
                'level' => $level,
                'index' => $i,
                'capability' => self::LEVEL_SPEC[$level]['capability'],
                'status' => self::LEVEL_SPEC[$level]['status'],
                'prerequisite' => $i === 0 ? null : (self::LEVELS[$i - 1] . '_cert_green'),
            ];
        }

        return $out;
    }

    /**
     * The single patamar currently "em construcao" — the one that defines scope.
     *
     * The doc states exactly one patamar is in construction (A1 today). This reads
     * the canonical spec, so it is the source of truth for "what may be built now".
     *
     * @return array{status: string, schema: string, level: ?string, capability: ?string, blocking_reasons: list<string>}
     */
    public function currentPatamar(): array
    {
        $current = [];
        foreach (self::LEVEL_SPEC as $level => $spec) {
            if ($spec['status'] === self::STATUS_EM_CONSTRUCAO) {
                $current[] = $level;
            }
        }

        $reasons = [];
        if ($current === []) {
            $reasons[] = 'no_patamar_em_construcao: ladder has no current build target';
        } elseif (count($current) > 1) {
            $reasons[] = 'multiple_patamares_em_construcao: ' . implode(',', $current)
                . ' (exactly one patamar may be em construcao)';
        }

        $level = count($current) === 1 ? $current[0] : null;

        return [
            'status' => $reasons === [] ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'level' => $level,
            'capability' => $level !== null ? self::LEVEL_SPEC[$level]['capability'] : null,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Anti-pattern guard (doc failure mode: "IA implementa feature de patamar A4
     * enquanto A1 ainda nao esta completo. Resultado: fundacao instavel.").
     *
     * A requested feature is allowed when its target patamar is the current
     * "em construcao" patamar OR exactly the next "planejado" patamar above it.
     * Targeting anything higher is rejected as a future-patamar anti-pattern.
     * Targeting an already-"passado" patamar is a no-op regression and is rejected.
     *
     * @return array{
     *     status: string, schema: string, requested: string, current: ?string,
     *     allowed: bool, classification: string, blocking_reasons: list<string>
     * }
     */
    public function evaluateWorkScope(string $requestedLevel): array
    {
        $reasons = [];
        $reqIdx = array_search($requestedLevel, self::LEVELS, true);

        $current = $this->currentPatamar();
        $currentLevel = $current['level'];
        $currentIdx = $currentLevel !== null ? array_search($currentLevel, self::LEVELS, true) : false;

        if ($reqIdx === false) {
            return [
                'status' => self::STATUS_FAIL,
                'schema' => self::SCHEMA,
                'requested' => $requestedLevel,
                'current' => $currentLevel,
                'allowed' => false,
                'classification' => 'unknown',
                'blocking_reasons' => ["unknown_patamar: {$requestedLevel}"],
            ];
        }

        if ($currentIdx === false) {
            return [
                'status' => self::STATUS_FAIL,
                'schema' => self::SCHEMA,
                'requested' => $requestedLevel,
                'current' => $currentLevel,
                'allowed' => false,
                'classification' => 'no_current_patamar',
                'blocking_reasons' => $current['blocking_reasons'],
            ];
        }

        if ($reqIdx < $currentIdx) {
            $classification = 'past';
            $reasons[] = "past_patamar_regression: {$requestedLevel} is already passado "
                . "(current is {$currentLevel})";
        } elseif ($reqIdx === $currentIdx) {
            $classification = 'current';
        } elseif ($reqIdx === $currentIdx + 1) {
            $classification = 'next_planned';
        } else {
            $classification = 'future';
            $reasons[] = "future_patamar_anti_pattern: {$requestedLevel} is above the next "
                . "planejado patamar; build {$currentLevel} (and its successor) first";
        }

        $allowed = $reasons === [];

        return [
            'status' => $allowed ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'requested' => $requestedLevel,
            'current' => $currentLevel,
            'allowed' => $allowed,
            'classification' => $classification,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * "Mudanca De Patamar" promotion gate.
     *
     * A promotion from `$from` to `$to` is admissible ONLY when:
     *   - both patamares are known and `$to` is exactly one step above `$from`
     *     (no skipping, no demotion — patamares are monotonic),
     *   - every one of the five documented process gates holds, and
     *   - the target name is not a numeric technical version ("v2"/"v3"), because
     *     the doc forbids "v2/v3" as patamar identities.
     *
     * @param array<string, bool> $gates keyed by PROMOTION_GATES keys
     * @return array{
     *     status: string, schema: string, from: string, to: string,
     *     allowed: bool, missing_gates: list<string>, blocking_reasons: list<string>
     * }
     */
    public function evaluatePromotion(string $from, string $to, array $gates): array
    {
        $reasons = [];
        $fromIdx = array_search($from, self::LEVELS, true);
        $toIdx = array_search($to, self::LEVELS, true);

        if ($fromIdx === false) {
            $reasons[] = "unknown_source_patamar: {$from}";
        }
        if ($toIdx === false) {
            $reasons[] = "unknown_target_patamar: {$to}";
        }

        if ($this->looksLikeNumericVersion($to)) {
            $reasons[] = "numeric_version_forbidden: '{$to}' uses a v2/v3 technical "
                . 'version; a patamar must have its own descriptive identity';
        }

        if ($fromIdx !== false && $toIdx !== false) {
            if ($toIdx === $fromIdx) {
                $reasons[] = 'no_op: source and target patamar are identical';
            } elseif ($toIdx < $fromIdx) {
                $reasons[] = "demotion_not_a_promotion: {$from} -> {$to}";
            } elseif ($toIdx - $fromIdx > 1) {
                $reasons[] = "patamar_skip_forbidden: promotion must be one step (got {$from} -> {$to})";
            }
        }

        $missing = [];
        foreach (self::PROMOTION_GATES as $key => $requirement) {
            if (($gates[$key] ?? false) !== true) {
                $missing[] = $key;
                $reasons[] = "promotion_gate_unmet: {$requirement}";
            }
        }

        $allowed = $reasons === [];

        return [
            'status' => $allowed ? self::STATUS_PASS : self::STATUS_FAIL,
            'schema' => self::SCHEMA,
            'from' => $from,
            'to' => $to,
            'allowed' => $allowed,
            'missing_gates' => $missing,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-ladder evidence document over a reference bundle. Aggregates every
     * surface into a single pass|fail verdict (fails if ANY sub-check fails).
     *
     * @param array{
     *     work_scope?: array{requested: string},
     *     promotion?: array{from: string, to: string, gates: array<string, bool>}
     * } $bundle
     * @return array{status: string, schema: string, levels: list<array<string, mixed>>, current: array<string, mixed>, checks: array<string, mixed>}
     */
    public function audit(array $bundle): array
    {
        $checks = [];
        $current = $this->currentPatamar();

        if (isset($bundle['work_scope'])) {
            $checks['work_scope'] = $this->evaluateWorkScope($bundle['work_scope']['requested']);
        }
        if (isset($bundle['promotion'])) {
            $p = $bundle['promotion'];
            $checks['promotion'] = $this->evaluatePromotion(
                $p['from'],
                $p['to'],
                $p['gates'] ?? [],
            );
        }

        $allPass = $current['status'] === self::STATUS_PASS;
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
            'current' => $current,
            'checks' => $checks,
        ];
    }

    /**
     * True when a candidate patamar name is really a numeric technical version
     * such as "v2", "V3", "atlas-dev-v2" — which the doc forbids as an identity.
     */
    private function looksLikeNumericVersion(string $name): bool
    {
        return preg_match('/(^|[^a-z])v\d+$/i', trim($name)) === 1;
    }
}
