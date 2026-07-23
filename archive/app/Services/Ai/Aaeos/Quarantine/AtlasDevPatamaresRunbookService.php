<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Runtime for the "Atlas Dev Patamares Runbook" specification.
 *
 * Turns the runbook's "8 patamares com fatias canonicas" table into deterministic,
 * pure decision logic. The doc defines HOW to promote between the eight Atlas Dev
 * patamares A0..A7; this service enforces that promotion contract as a typed,
 * blocking verdict (no IO, no clock, no DB):
 *
 *   - The ordered patamar ladder A0..A7, each carrying its documented atomic
 *       slices, principal DTO and promotion gate. -> patamares(), patamar()
 *   - The promotion gate (doc -> "Regras para IA"): promoting A<n> -> A<n+1> is
 *       allowed ONLY when (a) it is monotonic and exactly one step up, (b) ALL
 *       atomic slices of the SOURCE patamar are green, and (c) the source
 *       patamar's documented numeric gate threshold is met (e.g. A1 -> "50 runs
 *       verdes", A3 -> "20 scope_violation=0"). -> evaluatePromotion()
 *   - The A3 hard rule (doc -> Fluxo "scope violation -> bloqueia promocao"):
 *       any scope violation observed at A3 blocks promotion regardless of the
 *       green count. -> evaluatePromotion() (A3 branch)
 *   - DTO schema binding per patamar, so callers validate the right envelope
 *       against the right patamar. -> dtoFor()
 *
 * Same input -> same output. The doc is the authoring boundary; this code never
 * promotes on UI presence, never skips a patamar and never relaxes a gate.
 *
 * @see docs/engineering-knowledge-base/atlas-dev-patamares-runbook.md
 */
final class AtlasDevPatamaresRunbookService
{
    /** Stable evidence schema id this runtime emits. */
    public const SCHEMA = 'atlas.dev.patamares_runbook.v1';

    /** Closed verdict set. */
    public const STATUS_PASS = 'pass';
    public const STATUS_FAIL = 'fail';

    /** Ordered patamar ladder A0..A7, low -> high (doc -> "8 patamares"). */
    public const PATAMARES = ['A0', 'A1', 'A2', 'A3', 'A4', 'A5', 'A6', 'A7'];

    /**
     * Per-patamar contract — verbatim intent from the runbook's table:
     * name, atomic slices, principal DTO, gate label and the numeric gate
     * threshold the SOURCE patamar must reach before promotion.
     *
     * `gate_count` is the documented count that must be reached (green runs /
     * sessions / recoveries / etc). A0 has no DTO and a structural gate only.
     *
     * @var array<string, array{
     *     name: string,
     *     slices: list<string>,
     *     dto: ?string,
     *     gate_label: string,
     *     gate_count: int
     * }>
     */
    public const TABLE = [
        'A0' => [
            'name' => 'Pre-Foundation',
            'slices' => ['esquema dos schemas'],
            'dto' => null,
            'gate_label' => 'schemas declarados',
            'gate_count' => 1,
        ],
        'A1' => [
            'name' => 'Foundation',
            'slices' => ['discovery', 'prompt projection', 'telemetry', 'persistence'],
            'dto' => 'atlas.dev.run.v1',
            'gate_label' => '50 runs verdes A1',
            'gate_count' => 50,
        ],
        'A2' => [
            'name' => 'Plan-Visible',
            'slices' => ['desktop SSE', 'plan stream', 'receipt visible'],
            'dto' => 'atlas.dev.plan_stream.v1',
            'gate_label' => '30 sessions plan-visible',
            'gate_count' => 30,
        ],
        'A3' => [
            'name' => 'Provider-Verified',
            'slices' => ['scope guard runtime', 'verification receipt', 'provider attestation'],
            'dto' => 'atlas.dev.scope_guard.v1',
            'gate_label' => '20 scope_violation=0',
            'gate_count' => 20,
        ],
        'A4' => [
            'name' => 'Self-Healing',
            'slices' => ['repair loop runtime', 'retry policy', 'drift detection'],
            'dto' => 'atlas.dev.repair_loop.v1',
            'gate_label' => '15 self-healing recoveries',
            'gate_count' => 15,
        ],
        'A5' => [
            'name' => 'Surface-Parity',
            'slices' => ['desktop', 'cli', 'app', 'api parity'],
            'dto' => 'atlas.dev.surface.v1',
            'gate_label' => '4 surfaces feature-equal',
            'gate_count' => 4,
        ],
        'A6' => [
            'name' => 'Multi-Slice Coordination',
            'slices' => ['parallel slices durable', 'collision guard local'],
            'dto' => 'atlas.dev.parallel_slice.v1',
            'gate_label' => '10 multi-slice obras',
            'gate_count' => 10,
        ],
        'A7' => [
            'name' => 'Self-Improving Dev',
            'slices' => ['propoe own optimization', 'gates aprovam'],
            'dto' => 'atlas.dev.self_improvement.v1',
            'gate_label' => '5 self-improvements aprovados',
            'gate_count' => 5,
        ],
    ];

    /**
     * The whole ladder as a list, each row enriched with its rank.
     *
     * @return list<array{
     *     patamar: string, rank: int, name: string,
     *     slices: list<string>, dto: ?string,
     *     gate_label: string, gate_count: int
     * }>
     */
    public function patamares(): array
    {
        $out = [];
        foreach (self::PATAMARES as $rank => $code) {
            $out[] = ['patamar' => $code, 'rank' => $rank] + self::TABLE[$code];
        }

        return $out;
    }

    /**
     * One patamar's contract row, or null if the code is unknown.
     *
     * @return array{
     *     patamar: string, rank: int, name: string,
     *     slices: list<string>, dto: ?string,
     *     gate_label: string, gate_count: int
     * }|null
     */
    public function patamar(string $code): ?array
    {
        $rank = array_search($code, self::PATAMARES, true);
        if ($rank === false) {
            return null;
        }

        return ['patamar' => $code, 'rank' => $rank] + self::TABLE[$code];
    }

    /** Principal DTO schema for a patamar, or null (A0 / unknown). */
    public function dtoFor(string $code): ?string
    {
        return self::TABLE[$code]['dto'] ?? null;
    }

    /**
     * Promotion gate (doc -> "Regras para IA": "Promover patamar exige TODAS as
     * fatias verdes do anterior").
     *
     * Promotion from `$from` to `$to` is admissible ONLY when:
     *   - both codes are valid patamares, and
     *   - `$to` is exactly one step above `$from` (monotonic, no skip, no demote),
     *   - EVERY atomic slice of the SOURCE patamar is green, and
     *   - the SOURCE patamar's documented numeric gate threshold is met by
     *     `$greenCount` (green runs / sessions / recoveries observed), and
     *   - (A3 only, doc -> Fluxo) no scope violation is present.
     *
     * @param array<string, bool> $sliceStatus map slice-name -> green? (missing = not green)
     * @param int $greenCount observed count of green source-patamar runs/sessions/recoveries
     * @param int $scopeViolations observed scope violations (only constrains A3)
     *
     * @return array{
     *     status: self::STATUS_*,
     *     promote: bool,
     *     from: string,
     *     to: string,
     *     required_slices: list<string>,
     *     green_slices: list<string>,
     *     missing_slices: list<string>,
     *     gate_label: string,
     *     gate_required: int,
     *     gate_observed: int,
     *     gate_met: bool,
     *     blocking_reasons: list<string>
     * }
     */
    public function evaluatePromotion(
        string $from,
        string $to,
        array $sliceStatus,
        int $greenCount,
        int $scopeViolations = 0,
    ): array {
        $reasons = [];

        $fromRank = array_search($from, self::PATAMARES, true);
        $toRank = array_search($to, self::PATAMARES, true);

        if ($fromRank === false) {
            $reasons[] = "unknown_source_patamar: {$from}";
        }
        if ($toRank === false) {
            $reasons[] = "unknown_target_patamar: {$to}";
        }

        $required = $fromRank !== false ? self::TABLE[$from]['slices'] : [];
        $green = [];
        $missing = [];
        foreach ($required as $slice) {
            if (($sliceStatus[$slice] ?? false) === true) {
                $green[] = $slice;
            } else {
                $missing[] = $slice;
            }
        }

        $gateRequired = $fromRank !== false ? self::TABLE[$from]['gate_count'] : 0;
        $gateLabel = $fromRank !== false ? self::TABLE[$from]['gate_label'] : '';
        $gateMet = $greenCount >= $gateRequired;

        // Ladder shape: exactly one step up, never a skip or a demotion.
        if ($fromRank !== false && $toRank !== false) {
            if ($toRank <= $fromRank) {
                $reasons[] = "demotion_not_a_promotion: {$from} -> {$to}";
            } elseif ($toRank !== $fromRank + 1) {
                $reasons[] = "patamar_skip_forbidden: promotion must be one step (got {$from} -> {$to})";
            }
        }

        // All atomic slices of the source patamar must be green.
        if ($missing !== []) {
            $reasons[] = 'source_slices_not_green: ' . implode(', ', $missing);
        }

        // Source patamar's numeric gate threshold must be reached.
        if (! $gateMet) {
            $reasons[] = "gate_threshold_not_met: need {$gateRequired} ({$gateLabel}), have {$greenCount}";
        }

        // A3 hard rule (doc -> Fluxo): a scope violation blocks promotion.
        if ($from === 'A3' && $scopeViolations > 0) {
            $reasons[] = "scope_violation_blocks_promotion: {$scopeViolations} violation(s) at A3";
        }

        $promote = $reasons === [];

        return [
            'status' => $promote ? self::STATUS_PASS : self::STATUS_FAIL,
            'promote' => $promote,
            'from' => $from,
            'to' => $to,
            'required_slices' => array_values($required),
            'green_slices' => $green,
            'missing_slices' => $missing,
            'gate_label' => $gateLabel,
            'gate_required' => $gateRequired,
            'gate_observed' => $greenCount,
            'gate_met' => $gateMet,
            'blocking_reasons' => $reasons,
        ];
    }

    /**
     * Whole-ladder evidence document over a reference bundle. Aggregates the
     * ladder shape and, when a `promotion` request is supplied, the gated
     * verdict for it. Pure: no IO, deterministic.
     *
     * @param array{
     *     promotion?: array{
     *         from: string, to: string,
     *         slice_status?: array<string,bool>,
     *         green_count?: int,
     *         scope_violations?: int
     *     }
     * } $bundle
     *
     * @return array{
     *     schema: string,
     *     patamares: list<array<string,mixed>>,
     *     ladder_size: int,
     *     promotion?: array<string,mixed>
     * }
     */
    public function audit(array $bundle): array
    {
        $doc = [
            'schema' => self::SCHEMA,
            'patamares' => $this->patamares(),
            'ladder_size' => count(self::PATAMARES),
        ];

        if (isset($bundle['promotion'])) {
            $p = $bundle['promotion'];
            $doc['promotion'] = $this->evaluatePromotion(
                (string) ($p['from'] ?? ''),
                (string) ($p['to'] ?? ''),
                (array) ($p['slice_status'] ?? []),
                (int) ($p['green_count'] ?? 0),
                (int) ($p['scope_violations'] ?? 0),
            );
        }

        return $doc;
    }
}
