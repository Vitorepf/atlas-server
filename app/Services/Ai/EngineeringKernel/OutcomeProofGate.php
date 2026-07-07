<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: the OutcomeMemory write-path proof gate.
 *
 * The precondition for the Learning Loop: an outcome may only feed learning when its
 * "success" is a PROVEN-REAL execution, never a fake-green. It reuses the SAME rule as
 * the SovereignHonestyFloor (FalseClaimInvariant) so the two paths cannot drift.
 *
 * Three verdicts, kept honest and non-Goodhart:
 *   - proven_real: claimed success AND real execution evidence is present AND passes.
 *   - fake_green:  claimed success AND execution evidence is present but is a lie
 *                  (0 tests, 0 assertions, fixed-smoke, lint-as-suite). This is what must
 *                  never earn a learning promotion.
 *   - unproven:    claimed success but NO execution evidence supplied at this layer — we
 *                  cannot prove real, but neither is it a positive lie. Marked, not counted
 *                  as fake-green, so legitimate-but-thin records are not destroyed.
 *
 * A non-success status is never fake-green (a failure/blocked/needs_review makes no green claim).
 */
final class OutcomeProofGate
{
    /** Persisted, queryable learning-candidate marker for a suppressed fake-green (all surfaces). */
    public const FAKE_GREEN_MARKER = 'fake_green_suppressed';

    public function __construct(
        private readonly FalseClaimInvariant $invariant = new FalseClaimInvariant,
    ) {}

    /**
     * @param  string  $normalizedStatus  the recorder's normalized status (e.g. 'success'|'failed'|...)
     * @param  array<string,mixed>  $execution  optional execution evidence block (commands, tests_run, ...)
     * @return array{proven_real:bool,fake_green:bool,evidence_present:bool,reason:string}
     */
    public function assess(string $normalizedStatus, array $execution): array
    {
        $claimsSuccess = in_array($normalizedStatus, ['success', 'succeeded', 'passed', 'ready'], true);
        $evidencePresent = $execution !== [];

        // No green claim → nothing to fake. Truthful non-success records pass through.
        if (! $claimsSuccess) {
            return $this->verdict(true, false, $evidencePresent, 'no_success_claim');
        }

        // A success with no execution evidence at this layer: unproven, but not a lie.
        if (! $evidencePresent) {
            return $this->verdict(false, false, false, 'no_execution_evidence_supplied');
        }

        // Evidence present → run the shared anti-fake-green invariant with a 'passed' claim.
        $result = $this->invariant->evaluate(ExecutionEvidence::fromArray(
            ['claimed_status' => 'passed'] + $execution,
        ));

        if ($result['status'] === 'pass') {
            return $this->verdict(true, false, true, $result['detail']);
        }

        return $this->verdict(false, true, true, $result['detail']);
    }

    /**
     * @return array{proven_real:bool,fake_green:bool,evidence_present:bool,reason:string}
     */
    private function verdict(bool $provenReal, bool $fakeGreen, bool $evidencePresent, string $reason): array
    {
        return [
            'proven_real' => $provenReal,
            'fake_green' => $fakeGreen,
            'evidence_present' => $evidencePresent,
            'reason' => $reason,
        ];
    }
}
