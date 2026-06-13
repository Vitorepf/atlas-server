<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S152 — L10R3BoundedRecursionCertificationService (block: L10 Generative
 * Engineering Guard).
 *
 * Read-only certification of L10-R3 ("bounded recursion") by COMPOSING the three
 * load-bearing R3 artefacts in the safety-first order the generative-engineering
 * map fixes — the bound is proven and gated BEFORE any divergence verdict is
 * trusted:
 *
 *     convergence proof verified (S149) -> recursive depth limit gate (S150)
 *       -> recursive divergence / gaming detector (S151)
 *
 * The arrival line (atlas-aaeos-l10-generative-engineering-map.md, R3 checklist)
 * is exactly: "recursao de auto-melhoria operando sob bound provado, com 0
 * divergencia e 0 gaming detectado". So R3 is certified ONLY when there is a
 * verified convergence proof, the depth gate holds the request inside that proven
 * bound (no hard stop) AND the divergence detector reports neither divergence nor
 * gaming. Missing proof blocks; detected divergence blocks; detected gaming blocks.
 *
 * R3 is bounded recursion CERTIFICATION, not recursion execution: this service is
 * honesty-first and fail-closed. It never runs recursion, never promotes a level,
 * never mutates state and — even when an input advertises a requested runtime
 * mutation — that mutation is never performed (`runtime_mutation_performed` stays
 * false, always). It mirrors the composition shape and fail-closed doctrine of the
 * sibling S145 AaeosL9SovereignEngineeringCertificationService, on the
 * `atlas.aaeos.l10.r3_bounded_recursion_certification.v1` schema.
 *
 * Verdict rules (ordered, safety-first):
 *   the three checks are evaluated in the order proof, depth_gate, divergence;
 *   each emits its dedicated blocker when it fails; r3_certified=true ONLY when
 *   every check is clean. The proven recursion depth (`max_proven_depth`) is the
 *   bound covered by BOTH the verified proof and the depth gate — it can never
 *   exceed the proof's own proven depth, and with no verified proof it collapses to
 *   zero (a verified proof is the only thing that lifts the bound above 0). The
 *   blockers list carries every failed check in canonical order, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class L10R3BoundedRecursionCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.r3_bounded_recursion_certification.v1';

    /** L10 phase this service certifies. */
    public const PHASE = 'R3';

    public const STATUS_CERTIFIED = 'r3_bounded_recursion';

    public const STATUS_BLOCKED = 'blocked_not_r3';

    /** Blocker when the convergence proof (S149) is not verified — R3 has no bound. */
    public const BLOCKER_PROOF_MISSING = 'convergence_proof_missing';

    /** Blocker when the recursive depth gate (S150) does not hold the proven bound. */
    public const BLOCKER_DEPTH_GATE_OPEN = 'depth_gate_not_held';

    /** Blocker when the divergence / gaming detector (S151) flags divergence or gaming. */
    public const BLOCKER_DIVERGENCE_DETECTED = 'divergence_or_gaming_detected';

    /** Key of the composed convergence-proof artefact in $inputs. */
    private const CHECK_PROOF = 'proof';

    /** Key of the composed recursive-depth-gate artefact in $inputs. */
    private const CHECK_DEPTH_GATE = 'depth_gate';

    /** Key of the composed divergence / gaming detector artefact in $inputs. */
    private const CHECK_DIVERGENCE = 'divergence';

    /**
     * The three composed R3 checks, ordered safety-first (proof, then the depth
     * gate that holds the proven bound, then the divergence detector). Each binds
     * the input key it reads, the blocker emitted when the check fails and a
     * deterministic evidence-ref prefix.
     *
     * @var list<array{key:string,slice:string,blocker:string,evidence_prefix:string}>
     */
    private const CHECKS = [
        ['key' => self::CHECK_PROOF, 'slice' => 'S149', 'blocker' => self::BLOCKER_PROOF_MISSING, 'evidence_prefix' => 'evidence://l10/r3/proof/'],
        ['key' => self::CHECK_DEPTH_GATE, 'slice' => 'S150', 'blocker' => self::BLOCKER_DEPTH_GATE_OPEN, 'evidence_prefix' => 'evidence://l10/r3/depth_gate/'],
        ['key' => self::CHECK_DIVERGENCE, 'slice' => 'S151', 'blocker' => self::BLOCKER_DIVERGENCE_DETECTED, 'evidence_prefix' => 'evidence://l10/r3/divergence/'],
    ];

    /**
     * Certify L10-R3 bounded recursion by composing the verified convergence proof,
     * the recursive depth-limit gate and the divergence / gaming detector.
     *
     * Recognised `$inputs` (each composed artefact under its key proof /
     * depth_gate / divergence):
     *   - proof (S149 verifier): a bool, or an array carrying `verified` (the S149
     *     pass flag) and an optional non-negative integer `max_proven_depth` (the
     *     proven recursion depth) plus an optional `evidence_ref`/`ref`.
     *   - depth_gate (S150 gate): a bool, or an array carrying `allowed` (the S150
     *     pass flag); a truthy `hard_stop` forces the gate closed; an optional
     *     non-negative integer `max_allowed_depth` (the depth the gate permits)
     *     plus an optional `evidence_ref`/`ref`.
     *   - divergence (S151 detector): a bool — interpreted as "is this evidence
     *     divergence-free?" — or an array carrying the detector's native
     *     `divergence_detected` / `gaming_detected` / `hard_stop_required` flags
     *     plus an optional `evidence_ref`/`ref`. Any of those three set true blocks.
     *
     * An artefact with no recognised pass evidence is fail-closed (not certified).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     proof:bool,
     *     depth_gate:bool,
     *     divergence:bool,
     *     r3_certified:bool,
     *     max_proven_depth:int,
     *     divergence_free:bool,
     *     gaming_free:bool,
     *     runtime_mutation_performed:bool,
     *     checks:list<array{key:string,slice:string,passed:bool,evidence_ref:string}>,
     *     check_total:int,
     *     checks_passed_count:int,
     *     status:string,
     *     blockers:list<string>,
     *     evidence_refs:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $proofRaw = $inputs[self::CHECK_PROOF] ?? null;
        $depthRaw = $inputs[self::CHECK_DEPTH_GATE] ?? null;
        $divergenceRaw = $inputs[self::CHECK_DIVERGENCE] ?? null;

        $proofPassed = $this->proofPassed($proofRaw);
        $depthPassed = $this->depthGatePassed($depthRaw);
        $divergencePassed = $this->divergencePassed($divergenceRaw);

        $passedByKey = [
            self::CHECK_PROOF => $proofPassed,
            self::CHECK_DEPTH_GATE => $depthPassed,
            self::CHECK_DIVERGENCE => $divergencePassed,
        ];

        $checks = [];
        $evidenceRefs = [];
        $blockers = [];
        $passedCount = 0;

        foreach (self::CHECKS as $item) {
            $key = $item['key'];
            $passed = $passedByKey[$key];
            $evidenceRef = AreaFocusEvidenceRefNormalizer::gateEvidenceRef($inputs[$key] ?? null, $item['evidence_prefix'], $passed);

            $checks[] = [
                'key' => $key,
                'slice' => $item['slice'],
                'passed' => $passed,
                'evidence_ref' => $evidenceRef,
            ];

            if ($passed) {
                $passedCount++;
                $evidenceRefs[] = $evidenceRef;

                continue;
            }

            // Walked proof-first, then the depth gate, then divergence, so the bound
            // always leads the blocker list ahead of the divergence verdict. No
            // blocker is hidden.
            $blockers[] = $item['blocker'];
        }

        // r3_certified=true ONLY when every composed check is clean: a verified
        // convergence proof, a depth gate that holds the proven bound and a
        // divergence detector reporting neither divergence nor gaming.
        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'proof' => $proofPassed,
            'depth_gate' => $depthPassed,
            'divergence' => $divergencePassed,
            'r3_certified' => $certified,
            // The proven recursion depth is the bound covered by BOTH the verified
            // proof and the held depth gate, and is exactly 0 whenever either the
            // proof is not verified or the gate does not hold — only a verified proof
            // under a held gate can lift the bound above zero.
            'max_proven_depth' => $this->provenDepth($proofRaw, $depthRaw, $proofPassed, $depthPassed),
            'divergence_free' => $this->isDivergenceFree($divergenceRaw),
            'gaming_free' => $this->isGamingFree($divergenceRaw),
            // R3 is certification, NOT execution: a requested runtime mutation is
            // never performed by this read-only certifier.
            'runtime_mutation_performed' => false,
            'checks' => $checks,
            'check_total' => count(self::CHECKS),
            'checks_passed_count' => $passedCount,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * The convergence proof (S149) passes only when it explicitly verifies. A bare
     * bool is the fast path; an array passes when `verified` (or the generic
     * `certified`/`passed`) is === true. Unknown or absent evidence never passes.
     */
    private function proofPassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            foreach (['verified', 'certified', 'passed'] as $flag) {
                if (array_key_exists($flag, $raw)) {
                    return $raw[$flag] === true;
                }
            }
        }

        return false;
    }

    /**
     * The recursive depth gate (S150) passes only when it allows the request AND
     * does not raise a hard stop. A bare bool is the fast path; an array passes when
     * `allowed` (or `certified`/`passed`) is === true and `hard_stop` is not raised.
     * A raised hard stop closes the gate regardless of the allow flag. The hard stop
     * is a safety VETO, not a pass flag, so it is read truthy / fail-closed: any
     * truthy `hard_stop` (a real bool true from S150, or a `1` / `"true"` arriving
     * from a serialized envelope) closes the gate, while an explicit falsey value
     * leaves the gate governed by the allow flag.
     */
    private function depthGatePassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_array($raw)) {
            return false;
        }

        if (($raw['hard_stop'] ?? false)) {
            return false;
        }

        foreach (['allowed', 'certified', 'passed'] as $flag) {
            if (array_key_exists($flag, $raw)) {
                return $raw[$flag] === true;
            }
        }

        return false;
    }

    /**
     * The divergence detector (S151) passes only when the evidence is BOTH
     * divergence-free and gaming-free and raises no hard stop. A bare bool is the
     * fast path (true == "this evidence is divergence-free"); an array is read in the
     * detector's native shape — any of `divergence_detected`, `gaming_detected` or
     * `hard_stop_required` raised closes the check. Each of those three is a safety
     * VETO, not a pass flag, so it is read truthy / fail-closed exactly like the
     * depth gate's `hard_stop`: any truthy value (a real bool true from S151, or a
     * `1` / `"true"` arriving from a serialized envelope) closes the check, so a
     * divergence / gaming / hard-stop signal can never fail OPEN into a certification.
     * An array that carries none of the recognised S151 verdict flags also fails
     * closed: absence of detector verdict is not clean detector evidence.
     */
    private function divergencePassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (! is_array($raw)) {
            return false;
        }

        if (! $this->hasDivergenceVerdict($raw)) {
            return false;
        }

        foreach (['divergence_detected', 'gaming_detected', 'hard_stop_required'] as $flag) {
            if ($this->flagRaised($raw[$flag] ?? false)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the divergence evidence is divergence-free. A bare bool is taken
     * directly; an array with at least one recognised S151 verdict flag is
     * divergence-free unless `divergence_detected` is raised (read truthy /
     * fail-closed, so a `1` from a serialized envelope still reports a divergence);
     * absent evidence and verdict-less arrays are fail-closed (not divergence-free).
     */
    private function isDivergenceFree(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw) && $this->hasDivergenceVerdict($raw)) {
            return ! $this->flagRaised($raw['divergence_detected'] ?? false);
        }

        return false;
    }

    /**
     * Whether the divergence evidence is gaming-free. A bare bool is taken directly;
     * an array with at least one recognised S151 verdict flag is gaming-free unless
     * `gaming_detected` is raised (read truthy / fail-closed, so a `1` from a
     * serialized envelope still reports gaming); absent evidence and verdict-less
     * arrays are fail-closed (not gaming-free).
     */
    private function isGamingFree(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw) && $this->hasDivergenceVerdict($raw)) {
            return ! $this->flagRaised($raw['gaming_detected'] ?? false);
        }

        return false;
    }

    /**
     * Read a safety-veto flag truthy / fail-closed, exactly as the depth gate reads
     * its `hard_stop`: a real bool true raises it, and so does a truthy non-bool
     * (`1` / `"true"`) arriving from a serialized envelope, while every falsey value
     * (false, 0, "0", "", [], null) leaves it unraised. A veto can never fail OPEN.
     */
    private function flagRaised(mixed $value): bool
    {
        return (bool) $value;
    }

    /**
     * S151 detector arrays must carry at least one recognised verdict flag. An
     * artefact with none of these flags is missing the detector verdict and must
     * fail closed instead of being inferred clean from absent vetoes.
     *
     * @param  array<string,mixed>  $raw
     */
    private function hasDivergenceVerdict(array $raw): bool
    {
        foreach (['divergence_detected', 'gaming_detected', 'hard_stop_required'] as $flag) {
            if (array_key_exists($flag, $raw)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve the proven recursion depth: the bound covered by BOTH the verified
     * proof and the held depth gate. With no verified proof, or a depth gate that
     * does not hold, the bound is 0 (only a verified proof under a held gate lifts it
     * above zero). Otherwise it is the minimum of the proof's proven depth and the
     * gate's allowed depth, never negative and never above the proof's own proven
     * depth.
     */
    private function provenDepth(mixed $proofRaw, mixed $depthRaw, bool $proofPassed, bool $depthPassed): int
    {
        if (! $proofPassed || ! $depthPassed) {
            return 0;
        }

        $proofDepth = $this->nonNegativeInt($proofRaw, 'max_proven_depth');
        $gateDepth = $this->nonNegativeInt($depthRaw, 'max_allowed_depth');

        if ($gateDepth === null) {
            // No explicit gate ceiling: the proven bound is the proof's depth.
            return $proofDepth ?? 0;
        }

        if ($proofDepth === null) {
            // Verified proof without an explicit depth: hold to the gate's ceiling.
            return $gateDepth;
        }

        return min($proofDepth, $gateDepth);
    }

    /**
     * Read a non-negative integer depth field from an array artefact. Returns the
     * clamped integer (floored at 0) when the key holds an int- or numeric-string
     * value, or null when the artefact is not an array or the key is absent /
     * non-numeric.
     */
    private function nonNegativeInt(mixed $raw, string $key): ?int
    {
        if (! is_array($raw) || ! array_key_exists($key, $raw)) {
            return null;
        }

        $value = $raw[$key];

        if (is_int($value)) {
            return max(0, $value);
        }

        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return max(0, (int) $value);
        }

        return null;
    }
}
