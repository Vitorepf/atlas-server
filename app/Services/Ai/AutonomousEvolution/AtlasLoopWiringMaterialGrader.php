<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * THE WIRING-MATERIAL GRADER — the operator-authored external RULER for ONE debt class:
 * "wire a parked primitive into a consumer so the loop does something NEW."
 *
 * Operator decision (2026-06-22): the loop ORIGINATES and BUILDS the work autonomously, but it may NEVER
 * author its own acceptance bar (that is the forbidden Goodhart — grade-your-own-homework, the wall the
 * IntentVerifierFactory canon enforces: atoms are frozen, never inferred by the candidate). So the engineer
 * authors a fixed proof-template PER debt class, once; the loop fills a CONSTRAINED slot, and this grader is
 * the immovable bar. I give the ruler, not the work.
 *
 * INVIOLABLE correction: this grades ONLY a real BEHAVIOUR CHANGE (red→green on NEW behaviour). A
 * behaviour-PRESERVING change (dedup, dep-cycle break, god-class split) is PROXY = ZERO and has no template
 * here. Anti-trivial-wiring is enforced by THREE deterministic gates here + a runtime RED-check + the diff
 * having to actually USE the primitive (so an unused `new X()` cannot pass):
 *   1. the primitive + consumer must be real files;
 *   2. the primitive must NOT already be wired into the consumer (else it is not a NEW wiring);
 *   3. the acceptance atom must be an OBSERVABLE-BEHAVIOUR atom (method_return / command_output /
 *      http_response) — never a structural "references X" atom, which a trivial unused reference would satisfy.
 * The runtime RED-check (the atom must FAIL on the current code) is enforced when the packet is compiled by
 * {@see AtlasLoopIntentVerifierFactory}; this class is its pure, deterministic pre-gate.
 */
final class AtlasLoopWiringMaterialGrader
{
    public const SCHEMA = 'atlas.loop.wiring_material_grader.v1';

    /** The only atom types that assert OBSERVABLE new behaviour (never a structural "references" atom). */
    public const BEHAVIOURAL_ATOM_TYPES = ['method_return', 'command_output', 'http_response'];

    /**
     * Pure, deterministic pre-gate. Returns whether the wiring claim is admissible as MATERIAL, and why not.
     *
     * @param  array<string,mixed>  $claim  { primitive_path, consumer_path, behaviour_atom:{type,...} }
     * @return array{material:bool, reason:?string, primitive_class:?string, consumer_path:?string}
     */
    public function validateClaim(array $claim, string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $primitiveRel = ltrim(trim((string) ($claim['primitive_path'] ?? '')), '/');
        $consumerRel = ltrim(trim((string) ($claim['consumer_path'] ?? '')), '/');
        $atom = is_array($claim['behaviour_atom'] ?? null) ? $claim['behaviour_atom'] : (is_array($claim['behavior_atom'] ?? null) ? $claim['behavior_atom'] : []);

        if ($primitiveRel === '' || ! is_file($repoRoot.'/'.$primitiveRel)) {
            return $this->reject('primitive_not_a_real_file');
        }
        if ($consumerRel === '' || ! is_file($repoRoot.'/'.$consumerRel)) {
            return $this->reject('consumer_not_a_real_file');
        }
        if ($primitiveRel === $consumerRel) {
            return $this->reject('primitive_and_consumer_are_the_same_file');
        }

        $primitiveClass = pathinfo($primitiveRel, PATHINFO_FILENAME);
        $consumerSrc = (string) @file_get_contents($repoRoot.'/'.$consumerRel);

        // GATE 2 — must be a NEW wiring. If the consumer already names the primitive, there is nothing new to
        // wire; certifying it would be a no-op dressed as material.
        if ($primitiveClass !== '' && $this->referencesSymbol($consumerSrc, $primitiveClass)) {
            return $this->reject('primitive_already_wired_into_consumer');
        }

        // GATE 3 — the bar must assert OBSERVABLE behaviour, never a structural "references X" claim (a trivial
        // unused `new X()` would satisfy structural; it must not satisfy material).
        $type = trim((string) ($atom['type'] ?? ''));
        if (! in_array($type, self::BEHAVIOURAL_ATOM_TYPES, true)) {
            return $this->reject('acceptance_atom_is_not_an_observable_behaviour_atom:'.($type === '' ? 'none' : $type));
        }

        return [
            'material' => true,
            'reason' => null,
            'primitive_class' => $primitiveClass,
            'consumer_path' => $consumerRel,
        ];
    }

    /**
     * The structural requirement carried into the grind: the diff MUST use the primitive (so an unused
     * reference cannot pass). Returns the symbol the materialized diff must reference, or null if inadmissible.
     *
     * @param  array<string,mixed>  $claim
     */
    public function requiredPrimitiveUsage(array $claim, string $repoRoot): ?string
    {
        $v = $this->validateClaim($claim, $repoRoot);

        return $v['material'] ? $v['primitive_class'] : null;
    }

    /** Does $src reference the class symbol as code (use/new/::/typehint), not merely inside a comment or string. */
    private function referencesSymbol(string $src, string $class): bool
    {
        // Strip line + block comments so a `// mentions AtlasLoopFoo` note never counts as wiring.
        $code = (string) preg_replace(['~//[^\n]*~', '~/\*.*?\*/~s', '~\#[^\n]*~'], '', $src);

        return preg_match('/\b'.preg_quote($class, '/').'\b/', $code) === 1;
    }

    /**
     * @return array{material:false, reason:string, primitive_class:null, consumer_path:null}
     */
    private function reject(string $reason): array
    {
        return ['material' => false, 'reason' => $reason, 'primitive_class' => null, 'consumer_path' => null];
    }
}
