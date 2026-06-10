<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S165 — AaeosL10GenerativeEngineeringCertificationService (block: L10 Generative
 * Engineering Guard).
 *
 * Read-only certification of the L10 asymptote ("open, generative, recursive
 * engineering intelligence that is still irreducibly the operator's") by
 * COMPOSING the seven upstream artefacts produced across the L10 line, in the
 * safety-first order the generative-engineering map fixes:
 *
 *     post-L9 admission (S145) -> sovereignty + convergence bound preconditions
 *       -> R3 bounded recursion (S152) -> R2 long-horizon strategy (S156)
 *       -> R1 generative engineering (S160) -> R4 fusion latency (S163)
 *       -> engineering scope + runaway guard (S164)
 *
 * The canonical precedence is inviolable: "o bound e a soberania precedem
 * R1/R2/R4" (atlas-aaeos-l10-generative-engineering-map.md, "Pre-condicoes
 * inegociaveis"). The arrival checklist ("Como saber que o AAEOS chegou a
 * assintota") is exactly: invariante + bound vivos, R1, R2, R3, R4 and escopo
 * intacto — and only "com soberania e bound inviolaveis". The binding DoD adds
 * the post-L9 floor: certified=true requires S145 (L9 real, carried by the
 * admission) together with every R-pillar and the runaway guard passing.
 *
 * This certifier is honesty-first and fail-closed. It never promotes a level,
 * never starts L11, never mutates state, never invents a paradigm and never hides
 * a blocker (DoD: "no L11 promotion side effect"). It mirrors the composition
 * shape and fail-closed doctrine of the sibling S145
 * AaeosL9SovereignEngineeringCertificationService, on the
 * `atlas.aaeos.l10.generative_engineering_certification.v1` schema.
 *
 * Verdict rules (ordered, safety-first — same doctrine as the L8/L9 capstones):
 *   the seven checks are evaluated in the order admission, preconditions, r3, r2,
 *   r1, r4, scope_guard; each emits its dedicated `<check>_*` blocker when it
 *   fails; certified=true ONLY when every check is clean — i.e. post-L9 admission
 *   holds (S145/L9 real), the sovereignty + convergence-bound preconditions are
 *   locked, R3/R2/R1/R4 are all certified and the engineering scope guard reports
 *   no runaway. The blockers list carries every failed check in canonical order,
 *   never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l10-generative-engineering-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md
 */
final class AaeosL10GenerativeEngineeringCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l10.generative_engineering_certification.v1';

    /** L10 phase this service certifies. */
    public const PHASE = 'L10';

    public const STATUS_CERTIFIED = 'l10_generative_engineering';

    public const STATUS_BLOCKED = 'blocked_not_l10';

    /** Blocker when the post-L9 admission (S145) does not hold — L10 has no floor. */
    public const BLOCKER_ADMISSION_MISSING = 'admission_not_granted';

    /** Blocker when sovereignty + convergence-bound preconditions are not locked. */
    public const BLOCKER_PRECONDITIONS_MISSING = 'preconditions_not_locked';

    /** Blocker when the R3 bounded-recursion pillar (S152) is not certified. */
    public const BLOCKER_R3_MISSING = 'r3_not_certified';

    /** Blocker when the R2 long-horizon-strategy pillar (S156) is not certified. */
    public const BLOCKER_R2_MISSING = 'r2_not_certified';

    /** Blocker when the R1 generative-engineering pillar (S160) is not certified. */
    public const BLOCKER_R1_MISSING = 'r1_not_certified';

    /** Blocker when the R4 fusion-latency pillar (S163) is not certified. */
    public const BLOCKER_R4_MISSING = 'r4_not_certified';

    /** Blocker when the engineering scope + runaway guard (S164) reports a runaway. */
    public const BLOCKER_SCOPE_NOT_INTACT = 'scope_not_intact';

    /**
     * The seven composed L10 checks, ordered safety-first (post-L9 admission, then
     * the sovereignty + convergence-bound preconditions, then R3 before the
     * R2/R1/R4 capability pillars, then the scope guard). Each binds the input key
     * it reads, the originating slice, the blocker emitted when the check fails and
     * a deterministic evidence-ref prefix.
     *
     * @var list<array{key:string,slice:string,blocker:string,evidence_prefix:string}>
     */
    private const CHECKS = [
        ['key' => 'admission', 'slice' => 'S145', 'blocker' => self::BLOCKER_ADMISSION_MISSING, 'evidence_prefix' => 'evidence://l10/generative/admission/'],
        ['key' => 'preconditions', 'slice' => 'S152', 'blocker' => self::BLOCKER_PRECONDITIONS_MISSING, 'evidence_prefix' => 'evidence://l10/generative/preconditions/'],
        ['key' => 'r3', 'slice' => 'S152', 'blocker' => self::BLOCKER_R3_MISSING, 'evidence_prefix' => 'evidence://l10/generative/r3/'],
        ['key' => 'r2', 'slice' => 'S156', 'blocker' => self::BLOCKER_R2_MISSING, 'evidence_prefix' => 'evidence://l10/generative/r2/'],
        ['key' => 'r1', 'slice' => 'S160', 'blocker' => self::BLOCKER_R1_MISSING, 'evidence_prefix' => 'evidence://l10/generative/r1/'],
        ['key' => 'r4', 'slice' => 'S163', 'blocker' => self::BLOCKER_R4_MISSING, 'evidence_prefix' => 'evidence://l10/generative/r4/'],
        ['key' => 'scope_guard', 'slice' => 'S164', 'blocker' => self::BLOCKER_SCOPE_NOT_INTACT, 'evidence_prefix' => 'evidence://l10/generative/scope_guard/'],
    ];

    /**
     * The pass flag each composed check asserts. The first key that is present on
     * an array input decides the verdict for that check; a bare bool is the fast
     * path. Walking these keys per check lets each upstream artefact be passed in
     * its own native shape (the S145 admission carries `admitted`/`certified`, the
     * preconditions carry `preconditions_locked`/`bound_proven`+`sovereignty_locked`,
     * the S152/S156/S160 certs carry `r3_certified`/`r2_certified`/`r1_certified`,
     * the S163 latency scorer carries `r4_certified`/`near_zero_threshold_met`, the
     * S164 guard carries `in_scope`) without the certifier inventing evidence.
     *
     * @var array<string, list<string>>
     */
    private const PASS_KEYS = [
        'admission' => ['admitted', 'certified', 'passed'],
        'preconditions' => ['preconditions_locked', 'bound_and_sovereignty_locked', 'certified', 'passed'],
        'r3' => ['r3_certified', 'certified', 'passed'],
        'r2' => ['r2_certified', 'certified', 'passed'],
        'r1' => ['r1_certified', 'certified', 'passed'],
        'r4' => ['r4_certified', 'near_zero_threshold_met', 'certified', 'passed'],
        'scope_guard' => ['in_scope', 'scope_intact', 'certified', 'passed'],
    ];

    /**
     * Certify the L10 generative-engineering asymptote by composing post-L9
     * admission, the sovereignty + convergence-bound preconditions, the R3/R2/R1/R4
     * pillars and the engineering scope + runaway guard.
     *
     * Recognised `$inputs` (each composed artefact under its key admission /
     * preconditions / r3 / r2 / r1 / r4 / scope_guard):
     *   - a bool — the artefact's pass flag directly; or
     *   - an array carrying the artefact's native pass flag (see PASS_KEYS) — e.g.
     *     `admitted` for the S145 admission, `preconditions_locked` for the
     *     bound+sovereignty floor, `r3_certified`/`r2_certified`/`r1_certified` for
     *     the S152/S156/S160 certs, `r4_certified` for the S163 latency scorer,
     *     `in_scope` for the S164 guard — plus an optional `evidence_ref`/`ref`
     *     (non-empty string) proof ref.
     * An artefact with no recognised pass evidence is fail-closed (not certified).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     admitted:bool,
     *     preconditions:bool,
     *     r3:bool,
     *     r2:bool,
     *     r1:bool,
     *     r4:bool,
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
            $evidenceRef = AreaFocusEvidenceRefNormalizer::gateEvidenceRef($raw, $item['evidence_prefix'], $passed);

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

            // Walked admission-first then the bound+sovereignty preconditions then
            // R3 before R2/R1/R4 then the scope guard, so the post-L9 floor and the
            // inviolable preconditions always lead the blocker list ahead of the
            // capability pillars. No blocker is hidden.
            $blockers[] = $item['blocker'];
        }

        // certified=true ONLY when every composed check is clean: post-L9 admission
        // holds (S145/L9 real), the bound + sovereignty preconditions are locked,
        // R3/R2/R1/R4 are certified and scope is intact. Read-only — no L11
        // promotion side effect ever occurs.
        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'admitted' => $flags['admission'],
            'preconditions' => $flags['preconditions'],
            'r3' => $flags['r3'],
            'r2' => $flags['r2'],
            'r1' => $flags['r1'],
            'r4' => $flags['r4'],
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
}
