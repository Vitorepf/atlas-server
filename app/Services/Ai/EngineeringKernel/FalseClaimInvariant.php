<?php

declare(strict_types=1);

namespace App\Services\Ai\EngineeringKernel;

/**
 * Engineering Kernel mechanism: the anti-fake-green invariant (SovereignHonestyFloor
 * invariant #1), extracted so it is the SINGLE source of "what counts as a real test
 * run". The floor delegates to it, and the OutcomeMemory write-path proof gate reuses
 * the SAME rule — a claimed pass with zero tests / zero assertions / a fixed-smoke
 * artifact / a lint-as-suite / a claimed suite with no test runner is a lie, full stop.
 *
 * Kept pure and stateless so a fake-green cannot slip through a second, drifted copy
 * of this rule. Behaviour is byte-for-byte the floor's prior private falseClaimBlocked.
 */
final class FalseClaimInvariant
{
    /**
     * @return array{status:string,detail:string}
     */
    public function evaluate(ExecutionEvidence $e): array
    {
        $claimsPass = $e->claimedStatus === 'passed';

        // A claimed pass with zero tests or zero assertions is a lie, full stop.
        if ($claimsPass && $e->testsRun < 1) {
            return $this->fail('claimed_pass_with_zero_tests_run');
        }
        if ($claimsPass && $e->assertionsExecuted < 1) {
            return $this->fail('claimed_pass_with_zero_assertions');
        }

        // The fixed smoke artifact is the legacy fake-green kernel's fingerprint.
        foreach ($e->artifacts as $artifact) {
            if (str_contains($artifact, SovereignHonestyFloor::FIXED_SMOKE_SIGNATURE)) {
                return $this->fail('fixed_smoke_artifact_is_not_a_test_run');
            }
        }

        // If it claims a suite (selected_tests) but actually only ran a lint, that is the exact
        // "php -l as suite" lie the legacy fake-green kernel commits.
        $ranALint = false;
        $ranATestRunner = false;
        foreach ($e->commands as $cmd) {
            if ($this->isLintCommand($cmd)) {
                $ranALint = true;
            }
            if ($this->isTestRunnerCommand($cmd)) {
                $ranATestRunner = true;
            }
        }
        if ($claimsPass && $ranALint && ! $ranATestRunner) {
            return $this->fail('lint_only_run_presented_as_suite');
        }
        if ($claimsPass && $e->selectedTests !== [] && ! $ranATestRunner) {
            return $this->fail('claimed_suite_without_running_a_test_runner');
        }

        return $this->pass('real_execution_evidence_present');
    }

    private function isLintCommand(string $cmd): bool
    {
        return (bool) preg_match('/(^|\s)php\s+-l(\s|$)/', $cmd);
    }

    private function isTestRunnerCommand(string $cmd): bool
    {
        $needle = strtolower($cmd);

        return str_contains($needle, 'phpunit')
            || str_contains($needle, 'artisan test')
            || str_contains($needle, 'paratest')
            || str_contains($needle, 'pest');
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function pass(string $detail): array
    {
        return ['status' => 'pass', 'detail' => $detail];
    }

    /**
     * @return array{status:string,detail:string}
     */
    private function fail(string $detail): array
    {
        return ['status' => 'fail', 'detail' => $detail];
    }
}
