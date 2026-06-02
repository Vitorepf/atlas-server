<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S145 — AaeosL9SovereignEngineeringCertificationService (block: L9 Sovereign
 * Engineering).
 *
 * Read-only certification of L9 ("operator-superlinear engineering under proven
 * invariants") by COMPOSING the six upstream artefacts produced across the L9
 * line, in the safety-first order the sovereign-engineering map fixes:
 *
 *     post-L8 admission (S126) -> sovereignty invariant (S127)
 *       -> Q2 proven invariants (S132) -> Q1 judgment amplification (S138)
 *       -> Q3 discipline evolution (S143) -> engineering scope guard (S144)
 *
 * The arrival checklist (atlas-aaeos-l9-sovereign-engineering-map.md, "Como saber
 * que o AAEOS chegou ao regime soberano superlinear") is exactly: invariante vivo
 * (sovereignty formally fixed), Q2 real (proven, not just tested), Q1 real
 * (measured operator leverage), Q3 real (a system-discovered method validated and
 * retained) and escopo intacto (nothing outside engineering) — "com evidencia,
 * merge honesto, invariante vivo e Q2 provado primeiro". The binding DoD adds the
 * post-L8 floor: certification is post-L8 only and certified=true requires S125
 * (L8 real, carried by the admission) together with Q2/Q1/Q3 passing.
 *
 * This certifier is honesty-first and fail-closed. It never promotes a level,
 * never starts L10, never mutates state and never hides a blocker (DoD: "no L10
 * promotion side effect"). It mirrors the composition shape and fail-closed
 * doctrine of the sibling S125 AaeosL8TranscendenceCertificationService and the
 * S132 L9Q2ProvenInvariantCertificationService, on the
 * `atlas.aaeos.l9.sovereign_engineering_certification.v1` schema.
 *
 * Verdict rules (ordered, safety-first — same doctrine as the L8/Q-phase certs):
 *   the six checks are evaluated in the order admission, sovereignty, Q2, Q1, Q3,
 *   scope_guard; each emits its dedicated `<check>_*` blocker when it fails;
 *   certified=true ONLY when every check is clean — i.e. post-L8 admission holds
 *   (S125/L8 real), sovereignty is locked (the invariant is alive), Q2/Q1/Q3 are
 *   all certified and the engineering scope is intact. The blockers list carries
 *   every failed check in canonical order, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l9-sovereign-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class AaeosL9SovereignEngineeringCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l9.sovereign_engineering_certification.v1';

    /** L9 phase this service certifies. */
    public const PHASE = 'L9';

    public const STATUS_CERTIFIED = 'l9_sovereign_engineering';

    public const STATUS_BLOCKED = 'blocked_not_l9';

    /** Blocker when the post-L8 admission (S126) does not hold — L9 has no floor. */
    public const BLOCKER_ADMISSION_MISSING = 'admission_not_granted';

    /** Blocker when operator value-sovereignty is not locked (the invariant is dead). */
    public const BLOCKER_SOVEREIGNTY_MISSING = 'sovereignty_not_locked';

    /** Blocker when the L9-Q2 proven-invariant pillar (S132) is not certified. */
    public const BLOCKER_Q2_MISSING = 'q2_not_certified';

    /** Blocker when the L9-Q1 judgment-amplification pillar (S138) is not certified. */
    public const BLOCKER_Q1_MISSING = 'q1_not_certified';

    /** Blocker when the L9-Q3 discipline-evolution pillar (S143) is not certified. */
    public const BLOCKER_Q3_MISSING = 'q3_not_certified';

    /** Blocker when the engineering scope guard (S144) reports scope is not intact. */
    public const BLOCKER_SCOPE_NOT_INTACT = 'scope_not_intact';

    /**
     * The six composed L9 checks, ordered safety-first (post-L8 admission, then the
     * sovereignty invariant, then Q2 before the Q1/Q3 pillars, then the scope
     * guard). Each binds the input key it reads, the blocker emitted when the check
     * fails and a deterministic evidence-ref prefix.
     *
     * @var list<array{key:string,slice:string,blocker:string,evidence_prefix:string}>
     */
    private const CHECKS = [
        ['key' => 'admission', 'slice' => 'S126', 'blocker' => self::BLOCKER_ADMISSION_MISSING, 'evidence_prefix' => 'evidence://l9/sovereign/admission/'],
        ['key' => 'sovereignty', 'slice' => 'S127', 'blocker' => self::BLOCKER_SOVEREIGNTY_MISSING, 'evidence_prefix' => 'evidence://l9/sovereign/sovereignty/'],
        ['key' => 'q2', 'slice' => 'S132', 'blocker' => self::BLOCKER_Q2_MISSING, 'evidence_prefix' => 'evidence://l9/sovereign/q2/'],
        ['key' => 'q1', 'slice' => 'S138', 'blocker' => self::BLOCKER_Q1_MISSING, 'evidence_prefix' => 'evidence://l9/sovereign/q1/'],
        ['key' => 'q3', 'slice' => 'S143', 'blocker' => self::BLOCKER_Q3_MISSING, 'evidence_prefix' => 'evidence://l9/sovereign/q3/'],
        ['key' => 'scope_guard', 'slice' => 'S144', 'blocker' => self::BLOCKER_SCOPE_NOT_INTACT, 'evidence_prefix' => 'evidence://l9/sovereign/scope_guard/'],
    ];

    /**
     * The pass flag each composed check asserts. The first key that is present on
     * an array input decides the verdict for that check; a bare bool is the fast
     * path. Walking these keys per check lets each upstream artefact be passed in
     * its own native shape (the S126 admission carries `admitted`, the S132/S138/
     * S143 certs carry `q2_certified`/`q1_certified`/`q3_certified`, the S127
     * sovereignty registry carries `sovereignty_locked`, the S144 guard carries
     * `in_scope`) without the certifier inventing evidence.
     *
     * @var array<string, list<string>>
     */
    private const PASS_KEYS = [
        'admission' => ['admitted', 'certified', 'passed'],
        'sovereignty' => ['sovereignty_locked', 'locked', 'operator_is_sole_source', 'certified', 'passed'],
        'q2' => ['q2_certified', 'certified', 'passed'],
        'q1' => ['q1_certified', 'certified', 'passed'],
        'q3' => ['q3_certified', 'certified', 'passed'],
        'scope_guard' => ['in_scope', 'scope_intact', 'certified', 'passed'],
    ];

    /**
     * Certify L9 sovereign engineering by composing post-L8 admission, the
     * sovereignty invariant, the Q2/Q1/Q3 pillars and the engineering scope guard.
     *
     * Recognised `$inputs` (each composed artefact under its key admission /
     * sovereignty / q2 / q1 / q3 / scope_guard):
     *   - a bool — the artefact's pass flag directly; or
     *   - an array carrying the artefact's native pass flag (see PASS_KEYS) — e.g.
     *     `admitted` for the S126 admission, `q2_certified`/`q1_certified`/
     *     `q3_certified` for the S132/S138/S143 certs, `sovereignty_locked` for the
     *     S127 registry, `in_scope` for the S144 guard — plus an optional
     *     `evidence_ref`/`ref` (non-empty string) proof ref.
     * An artefact with no recognised pass evidence is fail-closed (not certified).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     admitted:bool,
     *     sovereignty_locked:bool,
     *     q2:bool,
     *     q1:bool,
     *     q3:bool,
     *     scope_guard:bool,
     *     checks:list<array{key:string,slice:string,passed:bool,evidence_ref:string}>,
     *     check_total:int,
     *     checks_passed_count:int,
     *     certified:bool,
     *     status:string,
     *     blockers:list<string>,
     *     evidence_refs:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $flags = [];
        $checks = [];
        $evidenceRefs = [];
        $blockers = [];
        $passedCount = 0;

        foreach (self::CHECKS as $item) {
            $key = $item['key'];
            $raw = $inputs[$key] ?? null;

            $passed = $this->checkPassed($key, $raw);
            $evidenceRef = $this->checkEvidenceRef($raw, $item['evidence_prefix'], $passed);

            $checks[] = [
                'key' => $key,
                'slice' => $item['slice'],
                'passed' => $passed,
                'evidence_ref' => $evidenceRef,
            ];

            $flags[$key] = $passed;

            if ($passed) {
                $passedCount++;
                $evidenceRefs[] = $evidenceRef;

                continue;
            }

            // Walked admission-first then sovereignty then Q2 before Q1/Q3 then the
            // scope guard, so the post-L8 floor and the invariant always lead the
            // blocker list ahead of the capability pillars. No blocker is hidden.
            $blockers[] = $item['blocker'];
        }

        // certified=true ONLY when every composed check is clean: post-L8 admission
        // holds (S125/L8 real), sovereignty is locked, Q2/Q1/Q3 are certified and
        // scope is intact. Read-only — no L10 promotion side effect ever occurs.
        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'admitted' => $flags['admission'],
            'sovereignty_locked' => $flags['sovereignty'],
            'q2' => $flags['q2'],
            'q1' => $flags['q1'],
            'q3' => $flags['q3'],
            'scope_guard' => $flags['scope_guard'],
            'checks' => $checks,
            'check_total' => count(self::CHECKS),
            'checks_passed_count' => $passedCount,
            'certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * A composed check passes only when its evidence explicitly asserts it. A bare
     * bool is the fast path; an array is decided by the FIRST of the check's
     * recognised pass keys that is present (=== true). Unknown or absent evidence
     * never passes (fail-closed certification).
     */
    private function checkPassed(string $key, mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            foreach (self::PASS_KEYS[$key] as $flag) {
                if (array_key_exists($flag, $raw)) {
                    return $raw[$flag] === true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve a stable evidence ref for a composed check. An explicit non-empty
     * `evidence_ref`/`ref` wins; a passed check without one falls back to a
     * deterministic prefixed ref, and a failed check carries a blocked marker so the
     * certification never implies absent evidence.
     */
    private function checkEvidenceRef(mixed $raw, string $prefix, bool $passed): string
    {
        if (is_array($raw)) {
            foreach (['evidence_ref', 'ref'] as $refKey) {
                $candidate = $raw[$refKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $passed ? $prefix.'met' : $prefix.'blocked';
    }
}
