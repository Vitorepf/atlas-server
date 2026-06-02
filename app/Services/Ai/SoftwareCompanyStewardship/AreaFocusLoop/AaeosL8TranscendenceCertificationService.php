<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S125 — AaeosL8TranscendenceCertificationService (block: L8 Transcendence).
 *
 * Read-only certification of L8 (frame transcendence) by COMPOSING the five
 * pillar certifications produced after S100: P5 (self-deception immunity, S106),
 * P1 (frame evolution, S112), P2 (meta-compounding, S117), P3 (predictive twin,
 * S121) and P4 (local distilled engine, S124). It never promotes a level, never
 * mutates state and never hides a blocker.
 *
 * L8 means "frame evolution with safety, not future docs": the system stopped
 * evolving code inside a fixed frame and began evolving the frame itself WITH
 * evidence. So this certifier is honesty-first and fail-closed on two axes:
 *   - each pillar passes ONLY when its evidence explicitly asserts it passed;
 *   - each pillar additionally requires an HONEST MERGE — the pillar's evidence
 *     was admitted under double signature with no faked/auto merge (mirrors the
 *     transcendence-map checklist "com evidencia, merge honesto e P5 vivo").
 *
 * P5 is the hard precondition of the whole ladder ("nenhuma capacidade antes da
 * imunidade"): a missing or dishonest P5 blocks the certification even when
 * P1-P4 all pass, and surfaces a dedicated blocker so the gap is never masked by
 * the capability pillars.
 *
 * Verdict rules (ordered, safety-first — P5 first):
 *   - the five pillars are evaluated in the order P5, P1, P2, P3, P4;
 *   - a pillar passes only when its evidence asserts passed AND the merge is
 *     honest; an unpassed pillar emits its `<pillar>_not_certified` blocker and a
 *     dishonest-merge pillar emits its `<pillar>_merge_not_honest` blocker;
 *   - certified=true ONLY when every pillar passes with an honest merge;
 *   - if P5 is the only gap (P1-P4 green), the blockers still lead with the P5
 *     blocker — P5 is never traded away for capability.
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-l10-implementation-detailing.md
 */
final class AaeosL8TranscendenceCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.transcendence_certification.v1';

    /** L8 phase this service certifies. */
    public const PHASE = 'L8';

    public const STATUS_CERTIFIED = 'l8_transcended';

    public const STATUS_BLOCKED = 'blocked_not_l8';

    /**
     * The five L8 pillar certifications, ordered safety-first (P5 before the
     * capability pillars P1-P4).
     *
     * Each binds the pillar key carried in `$inputs`, the originating slice, the
     * blocker emitted when the pillar is not certified, the blocker emitted when
     * the pillar's merge is not honest and a deterministic evidence-ref prefix.
     *
     * @var list<array{key:string,slice:string,blocker:string,merge_blocker:string,evidence_prefix:string}>
     */
    private const PILLARS = [
        ['key' => 'p5', 'slice' => 'S106', 'blocker' => 'p5_not_certified', 'merge_blocker' => 'p5_merge_not_honest', 'evidence_prefix' => 'evidence://l8/transcendence/p5/'],
        ['key' => 'p1', 'slice' => 'S112', 'blocker' => 'p1_not_certified', 'merge_blocker' => 'p1_merge_not_honest', 'evidence_prefix' => 'evidence://l8/transcendence/p1/'],
        ['key' => 'p2', 'slice' => 'S117', 'blocker' => 'p2_not_certified', 'merge_blocker' => 'p2_merge_not_honest', 'evidence_prefix' => 'evidence://l8/transcendence/p2/'],
        ['key' => 'p3', 'slice' => 'S121', 'blocker' => 'p3_not_certified', 'merge_blocker' => 'p3_merge_not_honest', 'evidence_prefix' => 'evidence://l8/transcendence/p3/'],
        ['key' => 'p4', 'slice' => 'S124', 'blocker' => 'p4_not_certified', 'merge_blocker' => 'p4_merge_not_honest', 'evidence_prefix' => 'evidence://l8/transcendence/p4/'],
    ];

    /** The pillar key whose absence blocks even when every other pillar passes. */
    private const PRECONDITION_PILLAR = 'p5';

    /**
     * Certify L8 transcendence by composing the five pillar certifications.
     *
     * Recognised `$inputs` (each pillar under its key p5/p1/p2/p3/p4):
     *   - a bool — the pillar's pass flag (an honest merge is then assumed); or
     *   - an array carrying:
     *       - `certified`/`passed`/`met`/`pass` (bool) — the pillar pass flag;
     *       - `honest_merge`/`merge_honest`/`double_signed` (bool) — whether the
     *         pillar's evidence was admitted under honest double-signature merge.
     *         Absent => assumed honest (a passing pillar is honest unless the
     *         evidence explicitly marks the merge dishonest or `faked_merge`);
     *       - `evidence_ref`/`ref` (non-empty string) — the pillar's proof ref.
     * A pillar with no recognised pass evidence is fail-closed (not certified).
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     phase:string,
     *     p5:bool,
     *     p1:bool,
     *     p2:bool,
     *     p3:bool,
     *     p4:bool,
     *     pillars:list<array{key:string,slice:string,certified:bool,honest_merge:bool,evidence_ref:string}>,
     *     pillar_total:int,
     *     pillars_certified_count:int,
     *     certified:bool,
     *     status:string,
     *     blockers:list<string>,
     *     evidence_refs:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $pillars = [];
        $pillarFlags = [];
        $evidenceRefs = [];
        $blockers = [];
        $certifiedCount = 0;

        foreach (self::PILLARS as $item) {
            $key = $item['key'];
            $raw = $inputs[$key] ?? null;

            $passed = $this->pillarPassed($raw);
            $honestMerge = $this->mergeHonest($raw);
            // A pillar only counts as certified when it both passed AND merged
            // honestly: L8 forbids capability bought with a faked merge.
            $pillarCertified = $passed && $honestMerge;
            $evidenceRef = $this->pillarEvidenceRef($raw, $item['evidence_prefix'], $pillarCertified);

            $pillars[] = [
                'key' => $key,
                'slice' => $item['slice'],
                'certified' => $pillarCertified,
                'honest_merge' => $honestMerge,
                'evidence_ref' => $evidenceRef,
            ];

            $pillarFlags[$key] = $pillarCertified;

            if ($pillarCertified) {
                $certifiedCount++;
                $evidenceRefs[] = $evidenceRef;

                continue;
            }

            // Blocker precedence within a pillar: a failed pass is the primary
            // gap; a passed-but-dishonest merge surfaces the merge blocker. The
            // pillars are walked P5-first, so P5 gaps always lead the list.
            if (! $passed) {
                $blockers[] = $item['blocker'];
            } else {
                $blockers[] = $item['merge_blocker'];
            }
        }

        $certified = $blockers === [];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'phase' => self::PHASE,
            'p5' => $pillarFlags['p5'],
            'p1' => $pillarFlags['p1'],
            'p2' => $pillarFlags['p2'],
            'p3' => $pillarFlags['p3'],
            'p4' => $pillarFlags['p4'],
            'pillars' => $pillars,
            'pillar_total' => count(self::PILLARS),
            'pillars_certified_count' => $certifiedCount,
            'certified' => $certified,
            'status' => $certified ? self::STATUS_CERTIFIED : self::STATUS_BLOCKED,
            'blockers' => $blockers,
            'evidence_refs' => $evidenceRefs,
        ];
    }

    /**
     * A pillar passes only when its evidence explicitly asserts it. Unknown or
     * absent evidence never passes (fail-closed certification).
     */
    private function pillarPassed(mixed $raw): bool
    {
        if (is_bool($raw)) {
            return $raw;
        }

        if (is_array($raw)) {
            foreach (['certified', 'passed', 'met', 'pass'] as $flag) {
                if (array_key_exists($flag, $raw)) {
                    return $raw[$flag] === true;
                }
            }
        }

        return false;
    }

    /**
     * A pillar's merge is honest unless the evidence explicitly marks it
     * dishonest. A bool pillar (or a pillar without merge metadata) is assumed
     * honest; an explicit `honest_merge`/`merge_honest`/`double_signed === false`,
     * or an explicit `faked_merge`/`merge_faked === true`, marks it dishonest.
     */
    private function mergeHonest(mixed $raw): bool
    {
        if (! is_array($raw)) {
            return true;
        }

        foreach (['faked_merge', 'merge_faked', 'auto_merged'] as $dishonestFlag) {
            if (($raw[$dishonestFlag] ?? null) === true) {
                return false;
            }
        }

        foreach (['honest_merge', 'merge_honest', 'double_signed'] as $honestFlag) {
            if (array_key_exists($honestFlag, $raw)) {
                return $raw[$honestFlag] === true;
            }
        }

        return true;
    }

    /**
     * Resolve a stable evidence ref for a pillar. A certified pillar without an
     * explicit ref falls back to a deterministic prefixed ref; an uncertified
     * pillar carries a blocked marker so the certification never implies absent
     * evidence.
     */
    private function pillarEvidenceRef(mixed $raw, string $prefix, bool $certified): string
    {
        if (is_array($raw)) {
            foreach (['evidence_ref', 'ref'] as $refKey) {
                $candidate = $raw[$refKey] ?? null;
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            }
        }

        return $certified ? $prefix.'met' : $prefix.'blocked';
    }
}
