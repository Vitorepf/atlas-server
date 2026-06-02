<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S106 — L8P5SelfDeceptionImmunityCertificationService (block: L8 Transcendence).
 *
 * Read-only certification of L8-P5 (self-deception immunity, anti-Goodhart). It
 * COMPOSES the five S101-S105 gates into a deterministic verdict: it never
 * promotes a level, never mutates state and never hides a blocker. P5 is the
 * safety-precondition of the whole L8 ladder — "no L8 capability before P5 is
 * alive" — so this certifier is fail-closed: every pillar must explicitly assert
 * its pass, every canonical ground-truth anchor must be present, and a simulated
 * gaming attempt must have been detected and blocked before p5 can certify.
 *
 * The five composed P5 pillars (S101-S105), in order:
 *   - admission        (S101 L8PostL7AdmissionGate)            — L7 real + P5 candidate admitted;
 *   - anchors          (S102 L8GroundTruthAnchorRegistry)      — the five immutable, independent metric anchors;
 *   - divergence       (S103 L8MetricRealityDivergenceDetector)— metric-vs-anchor Goodhart detector wired;
 *   - adversarial      (S104 L8SelfDeceptionAdversarialProbe)  — adversarial self-refutation cases exist;
 *   - trust_penalty    (S105 L8TrustPenaltyAndDemotePolicy)    — divergence lowers Trust + demotes.
 *
 * The five canonical ground-truth anchors are the metrics the loop optimizes
 * (mirrored byte-for-byte from S102's spec): useful_cycle_rate, trust_ledger_score,
 * dm_dt, retained_evolution_rate, provider_honesty_rate. A registry missing any
 * one of them blocks P5 — the system cannot anchor a metric it cannot independently
 * verify.
 *
 * Verdict rules (ordered, safety-first):
 *   - each S101-S105 pillar passes only when its evidence explicitly asserts the
 *     pillar passed; an unmet pillar emits its named blocker;
 *   - every one of the five canonical anchors must be present (independent +
 *     immutable + not self-reported); a missing anchor emits its named blocker;
 *   - at least one supplied adversarial case must report a blocked simulated
 *     gaming attempt; if none do, the system has not proven it can refute its own
 *     gain and the `simulated_gaming_not_blocked` blocker is emitted;
 *   - p5_certified=true ONLY when every pillar passes AND all five anchors are
 *     present AND simulated gaming is blocked; otherwise p5_certified=false and
 *     the verdict carries every blocker in canonical order, never hidden.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-l10-implementation-detailing.md
 */
final class L8P5SelfDeceptionImmunityCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.p5_self_deception_immunity_certification.v1';

    /** L8 phase this service certifies. */
    public const PHASE = 'L8-P5';

    public const STATUS_CERTIFIED = 'p5_certified';

    public const STATUS_BLOCKED = 'blocked_not_p5';

    /**
     * The five S101-S105 pillars that compose P5, ordered.
     *
     * Each binds the `$inputs['gates']` key carrying that pillar's pass evidence,
     * the originating slice, the blocker emitted when the pillar is not met and a
     * deterministic evidence-ref prefix for the proof list.
     *
     * @var list<array{key:string,slice:string,blocker:string,evidence_prefix:string}>
     */
    private const PILLARS = [
        ['key' => 'admission', 'slice' => 'S101', 'blocker' => 'l8_admission_not_granted', 'evidence_prefix' => 'evidence://l8/p5/admission/'],
        ['key' => 'anchors', 'slice' => 'S102', 'blocker' => 'ground_truth_anchors_not_registered', 'evidence_prefix' => 'evidence://l8/p5/anchors/'],
        ['key' => 'divergence', 'slice' => 'S103', 'blocker' => 'divergence_detector_not_wired', 'evidence_prefix' => 'evidence://l8/p5/divergence/'],
        ['key' => 'adversarial', 'slice' => 'S104', 'blocker' => 'adversarial_probe_not_wired', 'evidence_prefix' => 'evidence://l8/p5/adversarial/'],
        ['key' => 'trust_penalty', 'slice' => 'S105', 'blocker' => 'trust_penalty_policy_not_wired', 'evidence_prefix' => 'evidence://l8/p5/trust_penalty/'],
    ];

    /**
     * The five canonical metrics the loop optimizes, each needing an independent
     * ground-truth anchor (mirrors S102 L8GroundTruthAnchorRegistry).
     *
     * @var list<string>
     */
    private const CANONICAL_ANCHORS = [
        'useful_cycle_rate',
        'trust_ledger_score',
        'dm_dt',
        'retained_evolution_rate',
        'provider_honesty_rate',
    ];

    /** Blocker for any canonical anchor absent from the registry. */
    private const BLOCKER_ANCHOR_MISSING = 'ground_truth_anchor_missing';

    /** Blocker when no supplied adversarial case proves simulated gaming was blocked. */
    private const BLOCKER_GAMING_NOT_BLOCKED = 'simulated_gaming_not_blocked';

    /** Evidence prefix for the simulated-gaming-blocked proof. */
    private const ADVERSARIAL_EVIDENCE_PREFIX = 'evidence://l8/p5/simulated_gaming_blocked/';

    /**
     * Certify L8-P5 by composing the S101-S105 gates.
     *
     * Recognised `$inputs`:
     *   - gates: array<string,mixed> keyed by the PILLARS keys; each entry may be
     *     a bool, or an array carrying `passed`/`met`/`pass` (bool) and an
     *     optional `evidence_ref`/`ref` (non-empty string). Fail-closed.
     *   - anchors: list<array|string>|array<string,mixed> — the registered
     *     ground-truth anchors. A canonical anchor counts as present only when it
     *     is named AND marked independent + immutable + not self-reported (mirrors
     *     S102 semantics). `anchor_count` reflects how many canonical anchors are
     *     present (0..5).
     *   - adversarial_cases: list<array<string,mixed>> — simulated gaming cases
     *     (S104). A case counts as a blocked gaming attempt when it carries
     *     `gaming===true`/`metric_up_anchor_flat===true` AND `blocked===true`
     *     (or `detected===true`). `adversarial_case_count` is the supplied count.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     p5_certified:bool,
     *     status:string,
     *     pillars:list<array{key:string,slice:string,passed:bool,evidence_ref:string}>,
     *     pillar_total:int,
     *     pillars_passed_count:int,
     *     anchor_count:int,
     *     anchor_required_count:int,
     *     missing_anchors:list<string>,
     *     adversarial_case_count:int,
     *     simulated_gaming_blocked:bool,
     *     blockers:list<string>,
     *     evidence_refs:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $gateInput = is_array($inputs['gates'] ?? null) ? $inputs['gates'] : [];
        $presentAnchors = $this->presentCanonicalAnchors($inputs['anchors'] ?? null);
        $adversarialCases = is_array($inputs['adversarial_cases'] ?? null) ? $inputs['adversarial_cases'] : [];

        $pillars = [];
        $evidenceRefs = [];
        $pillarBlockers = [];
        $passedCount = 0;

        foreach (self::PILLARS as $item) {
            $raw = $gateInput[$item['key']] ?? null;
            $passed = $this->gatePassed($raw);
            $evidenceRef = $this->gateEvidenceRef($raw, $item['evidence_prefix'], $passed);

            $pillars[] = [
                'key' => $item['key'],
                'slice' => $item['slice'],
                'passed' => $passed,
                'evidence_ref' => $evidenceRef,
            ];

            if ($passed) {
                $passedCount++;
                $evidenceRefs[] = $evidenceRef;

                continue;
            }

            $pillarBlockers[] = $item['blocker'];
        }

        // Ground-truth anchors: a missing canonical anchor blocks P5. The system
        // cannot anchor a metric it cannot independently verify, so an incomplete
        // registry surfaces one blocker (with the missing names listed).
        $missingAnchors = $this->missingCanonicalAnchors($presentAnchors);
        $anchorCount = count($presentAnchors);

        // Adversarial self-refutation: at least one supplied case must prove a
        // simulated gaming attempt was detected and blocked. No proof => the
        // system has not shown it can refute its own gain.
        $gamingBlocked = $this->simulatedGamingBlocked($adversarialCases);
        $adversarialCaseCount = count($adversarialCases);

        // Blocker order (canonical, safety-first): pillar gaps in S101-S105 order,
        // then a missing-anchor gap, then an unblocked-gaming gap. No blocker is
        // ever hidden, so the operator reads the same ordered story every time.
        $blockers = $pillarBlockers;
        if ($missingAnchors !== []) {
            $blockers[] = self::BLOCKER_ANCHOR_MISSING;
        }
        if (! $gamingBlocked) {
            $blockers[] = self::BLOCKER_GAMING_NOT_BLOCKED;
        }

        $certified = $blockers === [];

        // Only a certified verdict surfaces the simulated-gaming-blocked proof.
        if ($certified) {
            $evidenceRefs[] = self::ADVERSARIAL_EVIDENCE_PREFIX.'met';
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'p5_certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'pillars' => $pillars,
            'pillar_total' => count(self::PILLARS),
            'pillars_passed_count' => $passedCount,
            'anchor_count' => $anchorCount,
            'anchor_required_count' => count(self::CANONICAL_ANCHORS),
            'missing_anchors' => $missingAnchors,
            'adversarial_case_count' => $adversarialCaseCount,
            'simulated_gaming_blocked' => $gamingBlocked,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * A pillar passes only when its evidence explicitly asserts it. Unknown or
     * absent evidence never passes (fail-closed certification).
     */
    private function gatePassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            foreach (['passed', 'met', 'pass'] as $flag) {
                if (array_key_exists($flag, $raw)) {
                    return $raw[$flag] === true;
                }
            }
        }

        return false;
    }

    /**
     * Resolve a stable evidence ref for a pillar. A passed pillar without an
     * explicit ref falls back to a deterministic prefixed ref; an unmet pillar
     * carries a blocked marker so the certification never implies absent evidence.
     */
    private function gateEvidenceRef(mixed $raw, string $prefix, bool $passed): string
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

    /**
     * The set of canonical anchors present in the supplied registry. An anchor
     * counts as present only when it is named for a canonical metric AND marked
     * independent + immutable + not self-reported (mirrors S102). Anchors may be
     * supplied as a list of names, a list of descriptor arrays, or a map keyed by
     * metric. A bare string name is taken as a fully-qualified anchor.
     *
     * @return list<string> canonical metric names present, in canonical order
     */
    private function presentCanonicalAnchors(mixed $anchors): array
    {
        if (! is_array($anchors)) {
            return [];
        }

        $present = [];

        foreach ($anchors as $mapKey => $entry) {
            $metric = $this->anchorMetricName($mapKey, $entry);

            if ($metric === null || ! in_array($metric, self::CANONICAL_ANCHORS, true)) {
                continue;
            }

            if (! $this->anchorIsIndependentImmutable($entry)) {
                continue;
            }

            if (! in_array($metric, $present, true)) {
                $present[] = $metric;
            }
        }

        // Return in canonical order regardless of input order.
        return array_values(array_filter(
            self::CANONICAL_ANCHORS,
            static fn (string $metric): bool => in_array($metric, $present, true),
        ));
    }

    /**
     * Resolve the metric name an anchor entry refers to. A descriptor array names
     * it via `optimized_metric`/`metric`/`name`; otherwise a string map key or a
     * bare string value is used.
     */
    private function anchorMetricName(int|string $mapKey, mixed $entry): ?string
    {
        if (is_array($entry)) {
            foreach (['optimized_metric', 'metric', 'name'] as $nameKey) {
                $candidate = $entry[$nameKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }

            // Descriptor without an explicit name: fall back to a string map key.
            return is_string($mapKey) && $mapKey !== '' ? $mapKey : null;
        }

        if (is_string($entry) && $entry !== '') {
            return $entry;
        }

        // Bare descriptor under a string metric key (e.g. ['useful_cycle_rate' => true]).
        return is_string($mapKey) && $mapKey !== '' ? $mapKey : null;
    }

    /**
     * An anchor is valid only when it is independent + immutable + not
     * self-reported. A bare string anchor (no descriptor) is trusted as a
     * fully-qualified registry entry; a descriptor array must assert the three
     * properties (defaulting them to true when the key is absent but never
     * accepting an explicit false).
     */
    private function anchorIsIndependentImmutable(mixed $entry): bool
    {
        if (! is_array($entry)) {
            return true;
        }

        $independent = $this->flagAllows($entry, ['independent', 'independent_source']);
        $immutable = $this->flagAllows($entry, ['immutable']);
        $notSelfReported = $this->notSelfReported($entry);

        return $independent && $immutable && $notSelfReported;
    }

    /**
     * A property flag allows the anchor unless it is explicitly present and not
     * truthy. `independent_source` may carry a non-empty string source name.
     *
     * @param  array<string,mixed>  $entry
     * @param  list<string>  $keys
     */
    private function flagAllows(array $entry, array $keys): bool
    {
        foreach ($keys as $key) {
            if (! array_key_exists($key, $entry)) {
                continue;
            }

            $value = $entry[$key];

            if ($value === true) {
                return true;
            }

            if (is_string($value) && $value !== '') {
                return true;
            }

            return false;
        }

        return true;
    }

    /**
     * The anchor must not be self-reported. Accepts either an explicit
     * `cannot_be_self_reported===true` or the absence of a `self_reported===true`
     * flag; an explicit `self_reported===true` disqualifies it.
     *
     * @param  array<string,mixed>  $entry
     */
    private function notSelfReported(array $entry): bool
    {
        if (array_key_exists('cannot_be_self_reported', $entry)) {
            return $entry['cannot_be_self_reported'] === true;
        }

        if (array_key_exists('self_reported', $entry)) {
            return $entry['self_reported'] !== true;
        }

        return true;
    }

    /**
     * Canonical anchors absent from the registry, in canonical order.
     *
     * @param  list<string>  $present
     * @return list<string>
     */
    private function missingCanonicalAnchors(array $present): array
    {
        return array_values(array_filter(
            self::CANONICAL_ANCHORS,
            static fn (string $metric): bool => ! in_array($metric, $present, true),
        ));
    }

    /**
     * Simulated gaming is blocked when at least one supplied adversarial case
     * reports a gaming attempt (metric up, anchor flat) that was detected AND
     * blocked. A case that merely exists, or one that was detected but NOT
     * blocked, does not prove immunity: detecting one's own gaming without
     * stopping it is exactly the self-deception P5 exists to refute, so the
     * certifier is fail-closed against an explicit `blocked === false`.
     *
     * @param  list<mixed>  $cases
     */
    private function simulatedGamingBlocked(array $cases): bool
    {
        foreach ($cases as $case) {
            if (! is_array($case)) {
                continue;
            }

            $isGaming = ($case['gaming'] ?? null) === true
                || ($case['metric_up_anchor_flat'] ?? null) === true
                || ($case['simulated_gaming'] ?? null) === true;

            if (! $isGaming) {
                continue;
            }

            // An explicit `blocked === false` is decisive and is never overridden
            // by a `detected` flag: a detected-but-unblocked gaming attempt proves
            // the opposite of immunity.
            if (($case['blocked'] ?? null) === false) {
                continue;
            }

            $wasBlocked = ($case['blocked'] ?? null) === true
                || ($case['detected'] ?? null) === true;

            if ($wasBlocked) {
                return true;
            }
        }

        return false;
    }
}
