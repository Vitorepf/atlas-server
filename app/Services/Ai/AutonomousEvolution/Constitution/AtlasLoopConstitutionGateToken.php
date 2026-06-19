<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

/**
 * LOOP-OS · FASE 3 · SLICE 4 — the merge-time PASS-TOKEN (§3.5), pétreo / FORBIDDEN under Constitution/.
 *
 * The grind-time ConstitutionGate runs the candidate's bytes through the battery and, on PASS, MINTS a token
 * that binds the verdict to four facts: the candidate commit tree, the battery version it was judged against,
 * the literal verdict, and a SINGLE-USE nonce. At merge time the pétreo actuator, under the exclusive lock,
 * RE-VERIFIES the token against the POST-APPLY tree + the CURRENT battery root before committing. Any
 * divergence invalidates it ⇒ no commit:
 *   - the tree moved between gate and commit (a re-presented diff applied to a different base);
 *   - the battery was bumped since the gate ran (a stale PASS no longer reflects the current must-catch set);
 *   - the verdict was not PASS;
 *   - the nonce was already consumed (replay of a stale post-apply bind).
 *
 * The token is unforgeable without the four inputs (sha256) and is consumed once — so "the gate said PASS for
 * THIS exact tree against THIS exact battery, once" is the only thing that lets a property_gated edit commit.
 */
final class AtlasLoopConstitutionGateToken
{
    public const VERDICT_PASS = 'PASS';

    /**
     * Mint a token binding (tree, battery version, verdict, nonce). A non-PASS verdict mints a token that can
     * never satisfy {@see verify} (which only accepts PASS) — so a REJECT can never masquerade as a PASS.
     */
    public function mint(string $candidateTreeSha, string $batteryRootHash, string $verdict, string $nonce): string
    {
        return hash('sha256', implode("\0", [
            'atlas-loop-constitution-token.v1',
            trim($candidateTreeSha),
            trim($batteryRootHash),
            trim($verdict),
            trim($nonce),
        ]));
    }

    /**
     * Re-verify a token UNDER LOCK against the post-apply tree + current battery root. Valid iff the token
     * re-binds to (postApplyTreeSha, currentBatteryRootHash, PASS, nonce) AND the nonce was not already
     * consumed. The caller consumes the nonce on success (single-use).
     *
     * @param  list<string>  $consumedNonces  nonces already spent (replay defense)
     * @return array{valid:bool, reason:string}
     */
    public function verify(string $token, string $postApplyTreeSha, string $currentBatteryRootHash, string $nonce, array $consumedNonces): array
    {
        if ($nonce === '' || in_array($nonce, $consumedNonces, true)) {
            return ['valid' => false, 'reason' => 'nonce_replayed_or_empty'];
        }
        $expected = $this->mint($postApplyTreeSha, $currentBatteryRootHash, self::VERDICT_PASS, $nonce);
        if (! hash_equals($expected, trim($token))) {
            return ['valid' => false, 'reason' => 'token_mismatch (tree moved / battery bumped / verdict != PASS)'];
        }

        return ['valid' => true, 'reason' => 'bound_to_post_apply_tree_and_current_battery'];
    }
}
