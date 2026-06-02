<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S141 — L9ParallelEngineeringLineageSandboxPlanner (block: L9 Sovereign
 * Engineering, phase Q3).
 *
 * Plans sandboxed parallel AAEOS engineering lineages. L9-Q3 lets the AAEOS
 * explore MULTIPLE lineages of its OWN engineering frame in parallel
 * (sandboxes evolving differently), then select and transfer the best
 * engineering evolutions between lineages — all WITHIN engineering, touching
 * no other domain. The canonical safety gate (L9 map, "Gate de seguranca") is
 * unconditional: parallel lineages are sandboxed and NEVER merge without the
 * gate, and the operator curates which discipline advances enter the canon.
 *
 * This planner therefore grants NO merge authority. For EVERY lineage it emits
 * `merge_allowed = false` and `transfer_gate_required = true`, no matter the
 * input. A lineage that asks for a direct main merge is rejected; a lineage
 * whose scope is not AAEOS software engineering is rejected; and if the Q2
 * proof boundary is missing the whole plan blocks (Q3 depends on Q2 — the
 * proven sandbox — to be safe), so no lineage is admitted for exploration.
 *
 * Pure planning function: no I/O, DB, Eloquent, facades, HTTP/provider calls,
 * git/Process, filesystem, clock/now() or randomness. The `lineage_id` of each
 * lineage is a DETERMINISTIC sha256 content digest of its normalized name and
 * declared scope, so identical input always yields an identical plan. Every
 * returned field is computed from the `$lineages` / `$q2Boundary` arguments via
 * real rules.
 *
 * Ordered per-lineage rejection rules (canonical fail-closed order):
 *   1. non-AAEOS scope — a lineage outside AAEOS software engineering rejects.
 *   2. direct main merge — a lineage requesting a direct merge to main rejects
 *      (it would bypass merge governance).
 * A lineage that violates neither is `admitted_for_sandbox` — it may explore in
 * its sandbox, but still carries no merge authority and a required transfer
 * gate.
 *
 * Top-level rule:
 *   - missing Q2 boundary — when the Q2 proof boundary is absent/not certified
 *     the plan blocks: `planned = false`, every lineage is forced to
 *     `blocked_q2_missing`, none is admitted for sandbox exploration.
 */
final class L9ParallelEngineeringLineageSandboxPlanner
{
    public const SCHEMA_VERSION = 'atlas.aaeos.l9.parallel_engineering_lineage_sandbox_plan.v1';

    public const PHASE = 'L9-Q3';

    /**
     * The only scope L9 may operate in: AAEOS software engineering. Q3 evolves
     * the engineering DISCIPLINE, never external domains. Mirrors the L9 map
     * invariant "tudo dentro de engenharia, sem tocar outros dominios".
     */
    public const ALLOWED_SCOPE = 'aaeos_engineering';

    public const STATUS_ADMITTED = 'admitted_for_sandbox';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_BLOCKED = 'blocked';

    /**
     * Per-lineage rejection blockers.
     */
    public const BLOCKER_NON_AAEOS_SCOPE = 'non_aaeos_lineage_rejected';

    public const BLOCKER_DIRECT_MAIN_MERGE = 'direct_main_merge_forbidden';

    /**
     * Top-level (whole-plan) blocker: the Q2 proof boundary is missing, so no
     * sandbox is proven safe.
     */
    public const BLOCKER_Q2_BOUNDARY_MISSING = 'q2_boundary_missing';

    /**
     * Per-lineage marker carried when the whole plan is blocked because Q2 is
     * missing (the lineage itself may be well-formed, but cannot be admitted).
     */
    public const BLOCKER_BLOCKED_Q2_MISSING = 'blocked_q2_missing';

    /**
     * Scope tokens that unambiguously identify AAEOS software engineering. A
     * lineage whose declared scope matches one of these is in-scope; anything
     * else (marketing, finance, cyber, trading, an external company, a domain
     * generator, ...) is a non-AAEOS lineage and rejects.
     *
     * @var list<string>
     */
    private const AAEOS_SCOPE_TOKENS = [
        'aaeos_engineering',
        'aaeos',
        'software_engineering',
        'engineering',
    ];

    /**
     * Plan sandboxed parallel AAEOS engineering lineages with no merge authority
     * and a Q2-gated transfer.
     *
     * @param  array<int|string, mixed>  $lineages
     * @param  array<string, mixed>  $q2Boundary
     * @return array{
     *     schema_version: string,
     *     phase: string,
     *     planned: bool,
     *     q2_boundary_present: bool,
     *     lineage_count: int,
     *     admitted_count: int,
     *     rejected_count: int,
     *     lineages: list<array{
     *         lineage_id: string,
     *         name: string,
     *         sandbox_scope: string,
     *         scope: string,
     *         status: string,
     *         merge_allowed: bool,
     *         transfer_gate_required: bool,
     *         requests_direct_main_merge: bool,
     *         is_aaeos_engineering: bool,
     *         blockers: list<string>
     *     }>,
     *     blockers: list<string>
     * }
     */
    public function plan(array $lineages, array $q2Boundary): array
    {
        $q2Present = $this->q2BoundaryPresent($q2Boundary);

        $normalized = $this->normalizeLineages($lineages);

        $planned = [];
        $admittedCount = 0;
        $rejectedCount = 0;
        $sawDirectMainMerge = false;
        $sawNonAaeos = false;

        foreach ($normalized as $lineage) {
            $name = $lineage['name'];
            $scope = $lineage['scope'];
            $isAaeos = $this->isAaeosScope($scope);
            $requestsDirectMainMerge = $lineage['requests_direct_main_merge'];

            $blockers = [];

            // Top-level guard wins: without a proven Q2 boundary, no sandbox is
            // safe, so the lineage cannot be admitted regardless of its shape.
            if (! $q2Present) {
                $blockers[] = self::BLOCKER_BLOCKED_Q2_MISSING;
            } else {
                // Rule 1 — non-AAEOS scope rejects (engineering only).
                if (! $isAaeos) {
                    $blockers[] = self::BLOCKER_NON_AAEOS_SCOPE;
                }

                // Rule 2 — a direct main merge request bypasses merge governance.
                if ($requestsDirectMainMerge) {
                    $blockers[] = self::BLOCKER_DIRECT_MAIN_MERGE;
                }
            }

            // Top-level roll-up mirrors the per-lineage fail-closed precedence:
            // when Q2 is missing every lineage is `blocked` (not rejected) and
            // its scope/merge rules are never evaluated, so those rejection
            // categories must NOT leak into the top-level blockers — the only
            // top-level blocker in that case is `q2_boundary_missing`.
            if ($q2Present) {
                if (! $isAaeos) {
                    $sawNonAaeos = true;
                }
                if ($requestsDirectMainMerge) {
                    $sawDirectMainMerge = true;
                }
            }

            $status = $this->lineageStatus($q2Present, $blockers);
            if ($status === self::STATUS_ADMITTED) {
                $admittedCount++;
            } elseif ($status === self::STATUS_REJECTED) {
                $rejectedCount++;
            }

            $planned[] = [
                'lineage_id' => $this->lineageId($name, $scope),
                'name' => $name,
                // The sandbox scope is always the lineage's own isolated sandbox:
                // it may read engineering context but owns only its sandbox.
                'sandbox_scope' => $this->sandboxScope($name),
                'scope' => $scope,
                'status' => $status,
                // No merge authority is EVER granted by this planner.
                'merge_allowed' => false,
                'transfer_gate_required' => true,
                'requests_direct_main_merge' => $requestsDirectMainMerge,
                'is_aaeos_engineering' => $isAaeos,
                'blockers' => $blockers,
            ];
        }

        $topBlockers = [];
        if (! $q2Present) {
            $topBlockers[] = self::BLOCKER_Q2_BOUNDARY_MISSING;
        }
        if ($sawNonAaeos) {
            $topBlockers[] = self::BLOCKER_NON_AAEOS_SCOPE;
        }
        if ($sawDirectMainMerge) {
            $topBlockers[] = self::BLOCKER_DIRECT_MAIN_MERGE;
        }

        // The plan is planned only when Q2 is proven, at least one lineage was
        // supplied, and EVERY supplied lineage is admissible (in-scope and not
        // asking to bypass merge governance).
        $planExecutes = $q2Present
            && $normalized !== []
            && $rejectedCount === 0;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'planned' => $planExecutes,
            'q2_boundary_present' => $q2Present,
            'lineage_count' => count($normalized),
            'admitted_count' => $admittedCount,
            'rejected_count' => $rejectedCount,
            'lineages' => $planned,
            'blockers' => $topBlockers,
        ];
    }

    private function lineageStatus(bool $q2Present, array $blockers): string
    {
        if (! $q2Present) {
            return self::STATUS_BLOCKED;
        }

        return $blockers === [] ? self::STATUS_ADMITTED : self::STATUS_REJECTED;
    }

    /**
     * @param  array<int|string, mixed>  $lineages
     * @return list<array{name: string, scope: string, requests_direct_main_merge: bool}>
     */
    private function normalizeLineages(array $lineages): array
    {
        $normalized = [];
        foreach ($lineages as $lineage) {
            if (! is_array($lineage)) {
                continue;
            }

            $name = $this->stringValue($lineage, ['lineage_id', 'name', 'id'], '');
            if ($name === '') {
                // A nameless lineage is not a real lineage; drop it.
                continue;
            }

            $normalized[] = [
                'name' => $name,
                'scope' => $this->scopeOf($lineage),
                'requests_direct_main_merge' => $this->requestsDirectMainMerge($lineage),
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $lineage
     */
    private function scopeOf(array $lineage): string
    {
        return $this->stringValue($lineage, ['scope', 'domain', 'sandbox_scope'], '');
    }

    private function isAaeosScope(string $scope): bool
    {
        return in_array($this->normalizeToken($scope), self::AAEOS_SCOPE_TOKENS, true);
    }

    /**
     * A lineage requests a direct main merge when it explicitly flags it, or
     * when it declares a merge target that resolves to the protected main line.
     *
     * @param  array<string, mixed>  $lineage
     */
    private function requestsDirectMainMerge(array $lineage): bool
    {
        if (($lineage['requests_direct_main_merge'] ?? false) === true
            || ($lineage['direct_main_merge'] ?? false) === true
            || ($lineage['bypass_merge_gate'] ?? false) === true) {
            return true;
        }

        $target = $this->stringValue($lineage, ['merge_target', 'target_branch', 'target'], '');

        return in_array($this->normalizeToken($target), ['main', 'master', 'trunk'], true);
    }

    /**
     * @param  array<string, mixed>  $q2Boundary
     */
    private function q2BoundaryPresent(array $q2Boundary): bool
    {
        if ($q2Boundary === []) {
            return false;
        }

        // An explicitly un-certified boundary does not count as present.
        if (($q2Boundary['q2_certified'] ?? null) === false
            || ($q2Boundary['certified'] ?? null) === false
            || ($q2Boundary['present'] ?? null) === false) {
            return false;
        }

        if (($q2Boundary['q2_certified'] ?? false) === true
            || ($q2Boundary['certified'] ?? false) === true
            || ($q2Boundary['present'] ?? false) === true) {
            return true;
        }

        // A boundary that carries a concrete proof anchor is present.
        $anchor = $this->stringValue(
            $q2Boundary,
            ['boundary_id', 'proof_boundary_id', 'delegation_boundary', 'evidence_ref'],
            '',
        );

        return $anchor !== '';
    }

    /**
     * Deterministic content-addressed lineage identifier. Pure: sha256 over the
     * normalized lineage name and scope — no uuid, no clock, no randomness.
     * Identical input yields an identical id.
     */
    private function lineageId(string $name, string $scope): string
    {
        $digest = implode('|', [
            'l9_lineage',
            $this->normalizeToken($name),
            $this->normalizeToken($scope),
        ]);

        return 'lineage_'.substr(hash('sha256', $digest), 0, 16);
    }

    /**
     * Deterministic isolated sandbox scope name for a lineage. The lineage owns
     * only this sandbox; it has no authority over the main line.
     */
    private function sandboxScope(string $name): string
    {
        return 'sandbox/'.$this->normalizeToken($name);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  list<string>  $keys
     */
    private function stringValue(array $payload, array $keys, string $default): string
    {
        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return $default;
    }

    private function normalizeToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[\s\-]+/', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }
}
