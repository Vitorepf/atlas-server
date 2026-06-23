<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Closure;

/**
 * THE DELIVERY BRIDGE — turns a loop-originated WIRING CLAIM into a grindable RED verifier packet, gated by
 * the operator-authored {@see AtlasLoopWiringMaterialGrader} and compiled by {@see AtlasLoopIntentVerifierFactory}.
 *
 * This is the seam that lets the loop DELIVER material without authoring its own bar:
 *   loop originates a wiring claim  →  GRADER (external ruler) admits it ONLY if it is a genuinely new,
 *   behaviour-changing wiring  →  FACTORY compiles the loop's observable-behaviour atom into a RED, frozen,
 *   un-editable verifier on the consumer  →  the grind drives the implementation to green  →  cert/refute.
 *
 * Two anti-Goodhart guards are STACKED here: (1) the grader's deterministic pre-gate (real files, NEW wiring,
 * observable-behaviour atom); (2) a REQUIRED-USAGE refuter — the materialized diff MUST reference the primitive
 * symbol, so an implementation that makes the atom green WITHOUT actually wiring the primitive is refuted. The
 * factory's own RED-check (the atom must fail on current code) is the third. Fail-closed: a non-material claim,
 * or a factory packet that is not READY, yields null (no task) — never a silently-green delivery.
 */
final class AtlasLoopOriginationDeliveryBridge
{
    public const SCHEMA = 'atlas.loop.origination_delivery_bridge.v1';

    /**
     * @param  Closure(string,string,array):array|null  $compile  defaults to the real IntentVerifierFactory;
     *                                                             injectable so the gating logic is testable
     *                                                             without compiling/running a verifier.
     */
    public function __construct(
        private readonly ?AtlasLoopWiringMaterialGrader $grader = null,
        private readonly ?Closure $compile = null,
    ) {
    }

    /**
     * @param  array<string,mixed>  $claim  { primitive_path, consumer_path, behaviour_atom:{type,...} }
     * @return array{ready:bool, reason:?string, packet:?array<string,mixed>, required_symbol:?string}
     */
    public function buildGrindablePacket(array $claim, string $intent, string $repoRoot): array
    {
        $grader = $this->grader ?? new AtlasLoopWiringMaterialGrader;
        $verdict = $grader->validateClaim($claim, $repoRoot);
        if (($verdict['material'] ?? false) !== true) {
            // GATE 1 — the external ruler refused: not a new, behaviour-changing wiring. No task.
            return ['ready' => false, 'reason' => 'grader_rejected:'.(string) ($verdict['reason'] ?? 'unknown'), 'packet' => null, 'required_symbol' => null];
        }

        $consumer = (string) $verdict['consumer_path'];
        $primitive = (string) $verdict['primitive_class'];
        $atom = is_array($claim['behaviour_atom'] ?? null) ? $claim['behaviour_atom'] : (array) ($claim['behavior_atom'] ?? []);

        // GATE 2 — the diff MUST use the primitive (else a green atom achieved without wiring is fake). A
        // grep-style refuter run on the candidate: if the consumer does not reference the primitive symbol as
        // code after the change, the verifier refutes the candidate.
        $usageRefuter = sprintf(
            "php -r \"exit((bool)preg_match('/\\\\b%s\\\\b/', preg_replace(['~//[^\\n]*~','~/\\\\*.*?\\\\*/~s'],'',file_get_contents('%s')))?0:1);\"",
            preg_quote($primitive, '/'),
            $consumer,
        );

        $payload = [
            'target_relative_path' => $consumer,
            'verification_atoms' => [$atom],
            // the required-usage refuter rides as a verifier refuter the candidate must survive
            'verifier_refuter_commands' => [$usageRefuter],
        ];

        $compile = $this->compile ?? function (string $repo, string $intentText, array $p): array {
            return app(AtlasLoopIntentVerifierFactory::class)->compileFrameworkPacket($repo, $intentText, $p);
        };

        $packet = (array) $compile($repoRoot, $intent, $payload);
        if (($packet['ready'] ?? false) !== true) {
            // The factory blocked it — most importantly the RED-check (the atom is already green => not new
            // behaviour) or an unparseable target. Honest refusal, never a forced green.
            return [
                'ready' => false,
                'reason' => 'verifier_not_ready:'.implode(',', array_map(static fn ($b): string => is_array($b) ? (string) ($b['code'] ?? $b['reason'] ?? 'blocked') : (string) $b, (array) ($packet['blockers'] ?? ['blocked']))),
                'packet' => $packet,
                'required_symbol' => $primitive,
            ];
        }

        return ['ready' => true, 'reason' => null, 'packet' => $packet, 'required_symbol' => $primitive];
    }
}
