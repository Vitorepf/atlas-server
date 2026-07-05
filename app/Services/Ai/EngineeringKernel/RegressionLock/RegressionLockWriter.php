<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel\RegressionLock;

/**
 * Engineering Kernel mechanism (OBRA #4 S1): turns a repaired failure into a LOCK — but only after
 * the anti-flake stability probe. Um caso instável NUNCA entra na suíte (quarentena): trancamos o
 * CONHECIMENTO da falha, não um teste flaky (anti-Goodhart da spec, AC-1.3).
 *
 * Owns: dedupe sticky por failure_signature (AC-1.4), a sonda de estabilidade N× via runner
 * injetado, e a decisão locked|quarantined gravada no ledger.
 * Must never own: como o caso é executado (o caller injeta o runner: suite real no worktree,
 * teste-irmão, comando de contrato) ou o invariante do floor.
 */
final class RegressionLockWriter
{
    public function __construct(private readonly RegressionLockLedger $ledger = new RegressionLockLedger) {}

    /**
     * Locks a repaired failure after proving stability. The runner executes the failing case ONCE
     * per call (true = green). N consecutive greens => locked; any red => quarantined with the
     * measured stability — the knowledge persists either way, the suite only grows with stable cases.
     *
     * @param  array{failure_signature:string, origin:string, failing_case:string, locked_test_ref?:string}  $meta
     * @param  callable(int):bool  $runOnce
     * @return array<string,mixed>
     */
    public function lockRepairedFailure(array $meta, callable $runOnce, int $stabilityRuns = 3): array
    {
        $signature = trim((string) ($meta['failure_signature'] ?? ''));
        if ($signature === '') {
            return ['flake_status' => 'invalid', 'reason' => 'failure_signature_required'];
        }

        $existing = $this->ledger->has($signature);
        if ($existing !== null) {
            return array_merge($existing, ['deduped' => true]);
        }

        $stabilityRuns = max(1, $stabilityRuns);
        $greens = 0;
        for ($i = 1; $i <= $stabilityRuns; $i++) {
            try {
                if ($runOnce($i) === true) {
                    $greens++;
                } else {
                    break; // um red basta para quarentenar; não queima runs restantes
                }
            } catch (\Throwable) {
                break; // runner que lança = instável — quarentena, nunca lock
            }
        }

        $meta['stability'] = $greens.'/'.$stabilityRuns;

        return $greens === $stabilityRuns
            ? $this->ledger->lock($meta)
            : $this->ledger->quarantine($meta);
    }
}
