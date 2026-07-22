<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * ARBOR-GRAFT DD1 (gate) — deterministic compliance check for the idea_drafting rubric. Gates DRAFT
 * ADMISSION only, never certification, never an honor-gate (Arbor §12).
 *
 * HARD rejections are PURELY STRUCTURAL / evidence-resolvable (floor-check mandate):
 *   - a PROBE BLOCK is present,
 *   - at least TWO cited path tokens resolve to real files (anti probe-disconnected; a candidate that just
 *     pastes rubric headers without resolvable evidence is rejected).
 * The semantic kill-filter criteria (single-knob / reword / more-X / goal-not-mechanism / re-treads-pruned)
 * CANNOT be reduced to a deterministic structural test without becoming a gameable honor-gate, so they are
 * emitted as ADVISORY WARNINGS, never hard rejections — this also protects the loop's #1 risk (work supply).
 */
final class AtlasLoopRubricComplianceGate
{
    /**
     * @param  callable(string):bool  $pathExists
     * @param  list<string>           $prunedTokens  tokens from pruned lessons (advisory re-tread check)
     * @return array{ok: bool, reasons: list<string>, warnings: list<string>}
     */
    public static function validate(string $text, callable $pathExists, array $prunedTokens = []): array
    {
        $reasons = [];

        // HARD 1 — PROBE BLOCK present.
        if (stripos($text, 'PROBE BLOCK') === false) {
            $reasons[] = 'missing_probe_block';
        }

        // HARD 2 — at least two cited path tokens resolve to real files.
        $resolved = 0;
        foreach (AtlasLoopHypothesisContractGate::evidenceTokens($text) as $tok) {
            if ($pathExists($tok)) {
                $resolved++;
            }
        }
        if ($resolved < 2) {
            $reasons[] = 'insufficient_resolved_evidence';
        }

        return [
            'ok' => $reasons === [],
            'reasons' => array_values(array_unique($reasons)),
            'warnings' => self::killFilterWarnings($text, $prunedTokens),
        ];
    }

    /**
     * ADVISORY kill-filter heuristics — surfaced, never rejected (downgraded per floor-check).
     *
     * @param  list<string>  $prunedTokens
     * @return list<string>
     */
    public static function killFilterWarnings(string $text, array $prunedTokens = []): array
    {
        $warnings = [];
        if (preg_match('/\b(increase|decrease|set|bump|tune|adjust|raise|lower)\b[^.\n]{0,40}\b(threshold|timeout|limit|retries|count|size|delay)\b/i', $text) === 1) {
            $warnings[] = 'maybe_single_knob';
        }
        if (preg_match('/\bmore\s+(retries|context|results|tokens|attempts|data|examples)\b/i', $text) === 1) {
            $warnings[] = 'maybe_more_x';
        }
        if (preg_match('/\bmechanism\s*:\s*(be\s+more|improve|make\s+better|more\s+robust)\b/i', $text) === 1) {
            $warnings[] = 'maybe_goal_not_mechanism';
        }
        if ($prunedTokens !== []) {
            $lower = strtolower($text);
            $hits = 0;
            foreach (array_unique($prunedTokens) as $tok) {
                if ($tok !== '' && str_contains($lower, strtolower($tok))) {
                    $hits++;
                }
            }
            if ($hits >= 3 && stripos($text, 'conflicts') === false && stripos($text, 'counter') === false) {
                $warnings[] = 'maybe_re_treads_pruned';
            }
        }

        return array_values(array_unique($warnings));
    }

    /**
     * Thin FS wrapper. Zero provider calls.
     *
     * @param  list<string>  $prunedTokens
     * @return array{ok: bool, reasons: list<string>, warnings: list<string>}
     */
    public function admit(string $text, string $repoRoot, array $prunedTokens = []): array
    {
        $root = rtrim($repoRoot, '/');
        $resolver = static fn (string $tok): bool => is_file($root.'/'.ltrim($tok, '/'));

        return self::validate($text, $resolver, $prunedTokens);
    }
}
