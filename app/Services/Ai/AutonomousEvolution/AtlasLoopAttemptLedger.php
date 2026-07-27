<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * NEXT-LEVER 5 — PER-OBRA WORKING MEMORY (anti-thrash / anti-context-rot).
 *
 * A single agent (Claude/Codex) loses context over a long task and repeats failed approaches — context
 * rot. A structured loop's edge is a PERSISTENT memory of what was tried and WHY it failed, fed into each
 * round so round K is strictly smarter than rounds 1..K-1. This ledger is that memory for ONE obra/task:
 *
 *   - record(): append each attempt's approach + outcome (passed / failed-reason / a signature of the diff
 *     shape) — the growing experience of THIS obra;
 *   - guidance(): a "do NOT repeat" digest of failed approaches to inject into the next attempt's intent,
 *     so the provider does not re-walk a dead end;
 *   - convergence(): detects THRASHING — the same failure reason recurring past a threshold — which is the
 *     signal to ESCALATE (the escalation ladder, Next-Lever 2) rather than burn another identical round.
 *
 * Pure + in-memory (unit-testable). Persisting it across worker/process restarts + injecting guidance into
 * the explorer's intent is the thin integration on top.
 */
final class AtlasLoopAttemptLedger
{
    /** @var list<array{round:int, strategy:string, provider:string, passed:bool, reason:string, signature:string}> */
    private array $attempts = [];

    public function record(string $strategy, string $provider, bool $passed, string $reason = '', string $signature = ''): void
    {
        $this->attempts[] = [
            'round' => count($this->attempts) + 1,
            'strategy' => trim($strategy),
            'provider' => trim($provider),
            'passed' => $passed,
            'reason' => trim($reason),
            'signature' => trim($signature),
        ];
    }

    public function rounds(): int
    {
        return count($this->attempts);
    }

    /**
     * Deterministic fixture snapshot for downstream harnesses. Read-only copy: callers cannot mutate the ledger.
     *
     * @return list<array{round:int, strategy:string, provider:string, passed:bool, reason:string, signature:string}>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * A "do NOT repeat" digest of the failed approaches so far — fed into the next attempt's intent.
     */
    public function guidance(int $max = 8): string
    {
        $failed = array_values(array_filter($this->attempts, static fn (array $a): bool => ! $a['passed']));
        if ($failed === []) {
            return '';
        }
        $lines = [];
        $seen = [];
        foreach (array_reverse($failed) as $a) { // most-recent first
            $key = $a['strategy'].'|'.$a['reason'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $lines[] = '- approach "'.($a['strategy'] !== '' ? $a['strategy'] : 'default')
                .'" (via '.($a['provider'] !== '' ? $a['provider'] : 'provider').') FAILED: '
                .($a['reason'] !== '' ? $a['reason'] : 'no_winner');
            if (count($lines) >= max(1, $max)) {
                break;
            }
        }

        return "Prior rounds in THIS obra already tried these and FAILED — do NOT repeat them; try a genuinely different approach:\n".implode("\n", $lines);
    }

    /**
     * @return array{thrashing:bool, rounds:int, failed:int, distinct_failure_reasons:int, dominant_reason:?string, dominant_count:int}
     */
    public function convergence(int $repeatThreshold = 3): array
    {
        $repeatThreshold = max(2, $repeatThreshold);
        $failed = array_values(array_filter($this->attempts, static fn (array $a): bool => ! $a['passed']));
        $reasonCounts = [];
        foreach ($failed as $a) {
            $r = $a['reason'] !== '' ? $a['reason'] : 'no_winner';
            $reasonCounts[$r] = ($reasonCounts[$r] ?? 0) + 1;
        }
        arsort($reasonCounts);
        $dominantReason = $reasonCounts === [] ? null : (string) array_key_first($reasonCounts);
        $dominantCount = $reasonCounts === [] ? 0 : (int) reset($reasonCounts);

        return [
            'thrashing' => $dominantCount >= $repeatThreshold, // same failure recurring => escalate, don't retry
            'rounds' => count($this->attempts),
            'failed' => count($failed),
            'distinct_failure_reasons' => count($reasonCounts),
            'dominant_reason' => $dominantReason,
            'dominant_count' => $dominantCount,
        ];
    }
}
