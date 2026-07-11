<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Constitution;

use Illuminate\Support\Arr;
use Throwable;

/**
 * LOOP-OS · FASE 3 · SLICE 4 — config/atlas.php monotonicity resolver (§3.7), pétreo / FORBIDDEN under Constitution/.
 *
 * config/atlas.php is the back-door: a candidate could DISABLE its own safety gates by flipping a true-default
 * to false or raising a threshold so a gate stops firing — and ship it green. The data leg of the Constitution
 * gate is this MONOTONICITY check: it reads the candidate config by LITERAL require (no config()/bootstrap —
 * those read the LIVE config; .env is excluded from the clone so env() resolves DEFAULTS deterministically),
 * flattens both loop namespaces, and REJECTS any gate-shaped flag that moved toward disabled. Ungameable
 * because it is a pure DATA comparison over all gate-shaped keys (not a hand-maintained allow-list), so a
 * newly-added gate is protected by its shape automatically.
 *
 * HONEST BOUNDARY (§3.7): an env()-VALUE override via the operator's LIVE .env is not caught (operator-owned,
 * outside the loop's edit surface). env()-DEFAULT disables inside config/atlas.php ARE caught.
 */
final class AtlasLoopConstitutionResolveFlags
{
    /** A key is "gate-shaped" (a safety lever) if its name matches any of these — covers new gates by shape. */
    private const GATE_SHAPE = '/(gate|guard|_enabled|canary|boot_smoke|regression|auto_merge|self_improvement|value_gate|abstain|drain)/i';

    /** Threshold keys whose RAISE disables a gate (the §3.7 calibrated-confidence min_samples example). */
    private const RISING_THRESHOLD_SHAPE = '/(min_samples|threshold|floor|required)/i';

    /**
     * Resolve a candidate config/atlas.php to a flat map of the loop namespaces by LITERAL require — never
     * config()/base_path()/bootstrap (those read the live config). Returns [] on any failure (fail-closed:
     * an unreadable candidate config cannot be proven monotonic, so the gate rejects upstream).
     *
     * @return array<string,mixed>
     */
    public function resolve(string $candidateConfigPath): array
    {
        if (! is_file($candidateConfigPath)) {
            return [];
        }
        try {
            $cfg = require $candidateConfigPath;
        } catch (Throwable) {
            return [];
        }
        if (! is_array($cfg)) {
            return [];
        }

        return array_merge(
            Arr::dot(['atlas.ai.loop' => Arr::get($cfg, 'ai.loop', [])]),
            Arr::dot(['atlas.loop' => Arr::get($cfg, 'loop', [])]),
        );
    }

    /**
     * Monotonicity: REJECT a candidate that flips a gate-shaped boolean true→false, OR raises a
     * gate-disabling threshold. Pure data comparison (ungameable).
     *
     * @param  array<string,mixed>  $live
     * @param  array<string,mixed>  $candidate
     * @return array{ok:bool, violations:list<string>}
     */
    public function monotonicityCheck(array $live, array $candidate): array
    {
        $violations = [];
        foreach ($live as $key => $liveValue) {
            if (! array_key_exists($key, $candidate)) {
                continue;
            }
            $candidateValue = $candidate[$key];

            // A gate-shaped safety flag turned OFF.
            if ($liveValue === true && $candidateValue === false && preg_match(self::GATE_SHAPE, $key) === 1) {
                $violations[] = $key.': safety gate disabled (true→false)';

                continue;
            }
            // A gate-disabling threshold RAISED so the gate stops firing.
            if (preg_match(self::RISING_THRESHOLD_SHAPE, $key) === 1
                && is_numeric($liveValue) && is_numeric($candidateValue)
                && (float) $candidateValue > (float) $liveValue) {
                $violations[] = $key.': threshold raised ('.$liveValue.'→'.$candidateValue.') ⇒ gate may stop firing';
            }
        }

        return ['ok' => $violations === [], 'violations' => $violations];
    }
}
