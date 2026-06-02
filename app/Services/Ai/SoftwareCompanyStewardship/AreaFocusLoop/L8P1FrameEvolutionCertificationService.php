<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * S112 — L8P1FrameEvolutionCertificationService (block: L8 Transcendence).
 *
 * Certifies the L8-P1 teto ("Frame auto-evolutivo") only after the system has
 * actually exercised governed frame evolution end-to-end: at least one
 * STRUCTURAL frame proposal (a new phase / department / governance dimension —
 * not a code refactor, not a doc vision) that was PROPOSED, REPLAYED, dual-SIGNED
 * (Operador + Architect) and then either KEPT as a measured composed improvement
 * OR REVERTED HONESTLY — without breaking any invariant.
 *
 * This composes the S107-S111 frame-evolution chain into the arrival verdict of
 * atlas-aaeos-l8-transcendence-map.md (P1 criterio de chegada, line 139, and the
 * transcendence checklist, line 244). It mirrors the proposal/dual-signature
 * vocabulary of ArchitectureEvolutionProposalAdmissionService (S91) — structural
 * detection and operator/architect signature shape — byte-for-byte, but it never
 * admits or applies anything: it is a read-only certification of an already
 * measured-or-reverted history.
 *
 * Per-proposal lifecycle (a proposal only counts as governed frame evolution when
 * every stage holds):
 *   1. structural   — declares itself a frame change (phase/department/dimension);
 *   2. replayed     — a replay plan ran (S110: broad real obra replay);
 *   3. dual signed  — BOTH operator and architect signatures present (S91/S109);
 *   4. honest outcome — terminal state is KEPT (measured composed lift) or a
 *                       HONEST revert (git revert, never reset --hard). A revert
 *                       counts as governed LEARNING, never as a kept improvement;
 *                       a dishonest/forced revert is a self-deception signal.
 *
 * Verdict rules (ordered, honesty-first — the same doctrine as the L7 cert):
 *   - zero structural proposals blocks (no_structural_proposals);
 *   - any structural proposal missing its dual signature blocks
 *     (dual_signature_missing);
 *   - any structural proposal that was never replayed blocks (replay_missing);
 *   - any dishonest revert (reset --hard / honest=false) blocks
 *     (dishonest_revert_present) — frame evolution is measured-or-reverted like
 *     code, and a faked revert is exactly the P5 self-deception risk;
 *   - any breached invariant blocks (invariant_breach_present) — L8 may change the
 *     frame, never a sovereignty/sacred gate;
 *   - with all of the above clean, P1 is certified only when at least one proposal
 *     reached an honest terminal outcome (kept OR honestly reverted)
 *     (no_completed_honest_lifecycle otherwise).
 *
 * Pure: every returned field is computed from the method inputs via the rules
 * above. No I/O, DB, Eloquent, facade, provider, git, filesystem, clock or
 * randomness. Identical inputs always yield an identical certification.
 *
 * @see docs/engineering-knowledge-base/atlas-aaeos-l8-transcendence-map.md
 * @see app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/ArchitectureEvolutionProposalAdmissionService.php
 */
final class L8P1FrameEvolutionCertificationService
{
    private const SCHEMA_VERSION = 'atlas.aaeos.l8.p1_frame_evolution_certification.v1';

    public const BLOCKER_NO_STRUCTURAL_PROPOSALS = 'no_structural_proposals';

    public const BLOCKER_DUAL_SIGNATURE_MISSING = 'dual_signature_missing';

    public const BLOCKER_REPLAY_MISSING = 'replay_missing';

    public const BLOCKER_DISHONEST_REVERT_PRESENT = 'dishonest_revert_present';

    public const BLOCKER_INVARIANT_BREACH_PRESENT = 'invariant_breach_present';

    public const BLOCKER_NO_COMPLETED_HONEST_LIFECYCLE = 'no_completed_honest_lifecycle';

    /**
     * Frame-change kinds mirrored from ArchitectureEvolutionProposalAdmissionService
     * (structural self-refactor lexicon) extended with the L8-P1 frame vocabulary
     * (phase / department / dimension) of atlas-aaeos-l8-transcendence-map.md.
     *
     * @var list<string>
     */
    private const STRUCTURAL_KINDS = [
        'structural',
        'structural_self_refactor',
        'self_refactor',
        'architecture',
        'structural_frame_change',
        'frame_change',
        'frame',
        'phase',
        'department',
        'dimension',
    ];

    /**
     * Certify L8-P1 from a history of frame-evolution proposals.
     *
     * Recognised `$inputs`:
     *   - proposals: list<array<string,mixed>> — each record may carry:
     *       - proposal_type | kind | change_class (string) and/or structural=true,
     *         plus self_refactor | targets_self | frame_change=true, or at least
     *         one affected frame element (affected_layers/layers/frame_targets);
     *       - replayed=true (or replay/replay_plan with obras_replayed_count>=1);
     *       - operator_signature | operator_signed and architect_signature |
     *         architect_signed (same shape as S91: bool, or {signed, signer});
     *       - outcome: 'kept' | 'reverted' | 'pending'; a kept proposal must show a
     *         positive composed lift (composed_lift / dm_dt > 0 or kept=true with
     *         measured=true); a reverted proposal carries revert metadata
     *         (revert_method/honest) used to tell an honest revert from a faked one;
     *       - invariant_breached=true (or invariants_intact=false) marks a breach.
     *
     * @param  array<string,mixed>  $inputs
     * @return array{
     *     schema_version:string,
     *     p1_certified:bool,
     *     structural_proposals_count:int,
     *     kept_count:int,
     *     honest_reverted_count:int,
     *     dishonest_revert_count:int,
     *     replayed_count:int,
     *     dual_signed_count:int,
     *     completed_honest_lifecycle_count:int,
     *     invariant_breach_count:int,
     *     blockers:list<string>
     * }
     */
    public function certify(array $inputs): array
    {
        $proposals = $this->proposalList($inputs['proposals'] ?? null);

        $structuralCount = 0;
        $keptCount = 0;
        $honestRevertedCount = 0;
        $dishonestRevertCount = 0;
        $replayedCount = 0;
        $dualSignedCount = 0;
        $completedHonestLifecycleCount = 0;
        $invariantBreachCount = 0;

        $anyDualSignatureMissing = false;
        $anyReplayMissing = false;

        foreach ($proposals as $proposal) {
            // Only structural frame proposals count as governed frame evolution.
            // A code refactor or a doc vision is not L8-P1 and is ignored here.
            if (! $this->isStructuralFrameProposal($proposal)) {
                continue;
            }

            $structuralCount++;

            $dualSigned = $this->hasDualSignature($proposal);
            $replayed = $this->wasReplayed($proposal);
            $breached = $this->invariantBreached($proposal);

            if ($dualSigned) {
                $dualSignedCount++;
            } else {
                $anyDualSignatureMissing = true;
            }

            if ($replayed) {
                $replayedCount++;
            } else {
                $anyReplayMissing = true;
            }

            if ($breached) {
                $invariantBreachCount++;
            }

            $outcome = $this->outcome($proposal);

            if ($outcome === 'kept' && $this->isMeasuredKeep($proposal)) {
                $keptCount++;
            } elseif ($outcome === 'reverted') {
                // A revert is governed LEARNING, never a kept improvement.
                if ($this->isHonestRevert($proposal)) {
                    $honestRevertedCount++;
                } else {
                    $dishonestRevertCount++;
                }
            }

            // A completed honest lifecycle proves the mechanism end-to-end: the
            // proposal was structural, replayed, dual-signed, did not breach an
            // invariant, and reached an honest terminal outcome (kept OR honestly
            // reverted). This is exactly the P1 criterio de chegada.
            $keptHonestly = $outcome === 'kept' && $this->isMeasuredKeep($proposal);
            $revertedHonestly = $outcome === 'reverted' && $this->isHonestRevert($proposal);

            if ($dualSigned && $replayed && ! $breached && ($keptHonestly || $revertedHonestly)) {
                $completedHonestLifecycleCount++;
            }
        }

        $blockers = $this->resolveBlockers(
            $structuralCount,
            $anyDualSignatureMissing,
            $anyReplayMissing,
            $dishonestRevertCount,
            $invariantBreachCount,
            $completedHonestLifecycleCount,
        );

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'p1_certified' => $blockers === [],
            'structural_proposals_count' => $structuralCount,
            'kept_count' => $keptCount,
            'honest_reverted_count' => $honestRevertedCount,
            'dishonest_revert_count' => $dishonestRevertCount,
            'replayed_count' => $replayedCount,
            'dual_signed_count' => $dualSignedCount,
            'completed_honest_lifecycle_count' => $completedHonestLifecycleCount,
            'invariant_breach_count' => $invariantBreachCount,
            'blockers' => $blockers,
        ];
    }

    /**
     * Ordered, honesty-first blocker resolution. Zero proposals blocks first; a
     * missing dual signature blocks before deeper lifecycle gaps; an unproven
     * (un-replayed) or dishonestly reverted or invariant-breaching history blocks;
     * finally a clean history with no completed honest lifecycle still blocks (the
     * mechanism was not proven end-to-end).
     *
     * @return list<string>
     */
    private function resolveBlockers(
        int $structuralCount,
        bool $anyDualSignatureMissing,
        bool $anyReplayMissing,
        int $dishonestRevertCount,
        int $invariantBreachCount,
        int $completedHonestLifecycleCount,
    ): array {
        if ($structuralCount === 0) {
            // Zero proposals blocks — and nothing else is meaningful yet.
            return [self::BLOCKER_NO_STRUCTURAL_PROPOSALS];
        }

        $blockers = [];

        if ($anyDualSignatureMissing) {
            $blockers[] = self::BLOCKER_DUAL_SIGNATURE_MISSING;
        }

        if ($anyReplayMissing) {
            $blockers[] = self::BLOCKER_REPLAY_MISSING;
        }

        if ($dishonestRevertCount > 0) {
            $blockers[] = self::BLOCKER_DISHONEST_REVERT_PRESENT;
        }

        if ($invariantBreachCount > 0) {
            $blockers[] = self::BLOCKER_INVARIANT_BREACH_PRESENT;
        }

        if ($completedHonestLifecycleCount === 0) {
            $blockers[] = self::BLOCKER_NO_COMPLETED_HONEST_LIFECYCLE;
        }

        return $blockers;
    }

    /**
     * @param  mixed  $value
     * @return list<array<string,mixed>>
     */
    private function proposalList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $list = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $list[] = $item;
            }
        }

        return $list;
    }

    /**
     * A proposal is a structural frame change when it declares a frame/structural
     * kind (or structural=true) AND targets the frame itself — via an explicit
     * self/frame flag or at least one named affected frame element. Mirrors
     * ArchitectureEvolutionProposalAdmissionService::isStructuralSelfRefactor.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function isStructuralFrameProposal(array $proposal): bool
    {
        $kind = strtolower(trim((string) (
            $proposal['proposal_type']
            ?? $proposal['kind']
            ?? $proposal['change_class']
            ?? ''
        )));

        $declaredStructural = in_array($kind, self::STRUCTURAL_KINDS, true)
            || ($proposal['structural'] ?? false) === true;

        $frameTargets = $this->frameTargets($proposal);

        $targetsFrame = ($proposal['self_refactor'] ?? false) === true
            || ($proposal['targets_self'] ?? false) === true
            || ($proposal['frame_change'] ?? false) === true
            || $frameTargets !== [];

        return $declaredStructural && $targetsFrame;
    }

    /**
     * @param  array<string,mixed>  $proposal
     * @return list<string>
     */
    private function frameTargets(array $proposal): array
    {
        return $this->stringList(
            $proposal['affected_layers']
            ?? $proposal['layers']
            ?? $proposal['frame_targets']
            ?? []
        );
    }

    /**
     * Dual signature: BOTH operator and architect must have signed. Same accepted
     * shapes as the S91 admission gate (bool true, or {signed:true, signer:'...'}).
     *
     * @param  array<string,mixed>  $proposal
     */
    private function hasDualSignature(array $proposal): bool
    {
        return $this->hasSignature($proposal, 'operator_signature', 'operator_signed')
            && $this->hasSignature($proposal, 'architect_signature', 'architect_signed');
    }

    /**
     * @param  array<string,mixed>  $proposal
     */
    private function hasSignature(array $proposal, string $objectKey, string $boolKey): bool
    {
        $object = $proposal[$objectKey] ?? null;
        if (is_array($object)) {
            return ($object['signed'] ?? false) === true
                && trim((string) ($object['signer'] ?? '')) !== '';
        }

        if ($object === true) {
            return true;
        }

        return ($proposal[$boolKey] ?? false) === true;
    }

    /**
     * The proposal was replayed when it carries an explicit replayed flag or a
     * replay plan that actually ran at least one obra (S110 grounds promotion in
     * broad real replay). A replay plan with a zero/absent count did not run.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function wasReplayed(array $proposal): bool
    {
        if (($proposal['replayed'] ?? false) === true) {
            return true;
        }

        $plan = $proposal['replay_plan'] ?? $proposal['replay'] ?? null;
        if (is_array($plan)) {
            $count = $plan['obras_replayed_count'] ?? $plan['replayed_count'] ?? 0;
            if ((is_int($count) || is_float($count)) && $count >= 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Terminal outcome of the proposal, normalised. Anything that is not an
     * explicit kept/reverted terminal state is 'pending' (not yet a completed
     * lifecycle).
     *
     * @param  array<string,mixed>  $proposal
     */
    private function outcome(array $proposal): string
    {
        $raw = strtolower(trim((string) ($proposal['outcome'] ?? $proposal['terminal_state'] ?? '')));

        if (in_array($raw, ['kept', 'keep', 'merged', 'retained'], true)) {
            return 'kept';
        }

        if (in_array($raw, ['reverted', 'revert', 'rolled_back', 'rollback'], true)) {
            return 'reverted';
        }

        // Flag-driven fallbacks for callers that pass booleans instead of a string.
        if (($proposal['kept'] ?? false) === true) {
            return 'kept';
        }
        if (($proposal['reverted'] ?? false) === true) {
            return 'reverted';
        }

        return 'pending';
    }

    /**
     * A kept proposal is a real composed improvement only when the keep is backed
     * by a measured positive composed lift — not a self-declared "kept" with no
     * measurement (that would be the Goodhart trap P5 guards against).
     *
     * @param  array<string,mixed>  $proposal
     */
    private function isMeasuredKeep(array $proposal): bool
    {
        $lift = $proposal['composed_lift'] ?? $proposal['dm_dt'] ?? $proposal['lift'] ?? null;
        if ((is_int($lift) || is_float($lift)) && $lift > 0) {
            return true;
        }

        return ($proposal['kept'] ?? false) === true
            && ($proposal['measured'] ?? false) === true;
    }

    /**
     * An honest revert is one performed by a real revert (git revert), explicitly
     * marked honest, and NOT a history-rewriting reset --hard. A forced/dishonest
     * revert is treated as a self-deception signal, never as governed learning.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function isHonestRevert(array $proposal): bool
    {
        $method = strtolower(trim((string) ($proposal['revert_method'] ?? '')));

        // A reset --hard style rewrite is never honest, regardless of any flag.
        if (in_array($method, ['reset_hard', 'reset --hard', 'force_push', 'rewrite_history'], true)) {
            return false;
        }

        if (array_key_exists('honest_revert', $proposal)) {
            return ($proposal['honest_revert'] === true);
        }

        if (array_key_exists('honest', $proposal)) {
            return ($proposal['honest'] === true);
        }

        return in_array($method, ['git_revert', 'revert'], true);
    }

    /**
     * An invariant breach is asserted by an explicit breach flag or by
     * invariants_intact being explicitly false. Absent/unknown evidence is NOT a
     * breach here (the dedicated invariant gate S109 owns that fail-closed call);
     * this certification only surfaces a breach that the history asserts.
     *
     * @param  array<string,mixed>  $proposal
     */
    private function invariantBreached(array $proposal): bool
    {
        if (($proposal['invariant_breached'] ?? false) === true) {
            return true;
        }

        return array_key_exists('invariants_intact', $proposal)
            && $proposal['invariants_intact'] === false;
    }

    /**
     * Coerce a value into a strict list<string>: drop non-strings and empty
     * strings, and re-index with array_values so no int keys leak into the list.
     *
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList($value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            array_map(
                static fn ($item): string => is_string($item) ? trim($item) : '',
                $value,
            ),
            static fn (string $item): bool => $item !== '',
        ));
    }
}
