<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

use Symfony\Component\Process\Process;
use Throwable;

/**
 * The acceptance keystone of the auto-characterization-test lane. Given a coverage gap (a target file
 * + the EXACT mutation operator a refactor's mutation-adequacy gate found surviving) and a workspace
 * where a provider has just written/strengthened the sibling test, this proves the new test is BOTH
 * valid and adequate:
 *
 *   1. baseline — the test PASSES on the unmutated target (a test red on correct code is invalid);
 *   2. kill     — with the SAME operator's mutant applied, the test now FAILS (it actually pins the
 *                 decision the gate said was uncovered).
 *
 * Airtight by construction: a useless test that passes on BOTH the correct and the mutant code is
 * never certified, so the lane can never raise conversion by shipping coverage theatre. The operator
 * is re-applied via the SINGLE-SOURCE {@see AtlasLoopMutationOperators} the gate itself samples from,
 * so the verifier can never "kill" a mutant the gate would never have produced. The mutant is written
 * to disk only inside a try/finally that ALWAYS restores the original — it never leaks past verify().
 */
final class AtlasLoopCharacterizationTestVerifier
{
    /** @var callable(string,string,int):array{passed:bool,exit_code:?int,output:string} */
    private $runner;

    /**
     * @param  callable(string,string,int):array{passed:bool,exit_code:?int,output:string}|null  $runner
     */
    public function __construct(?callable $runner = null)
    {
        $this->runner = $runner ?? static function (string $workspace, string $command, int $timeout): array {
            $process = Process::fromShellCommandline(
                $command,
                $workspace,
                AtlasLoopHermeticCommandEnvironment::forAcceptance(),
                null,
                (float) $timeout,
            );
            $process->run();

            return [
                'passed' => $process->isSuccessful(),
                'exit_code' => $process->getExitCode(),
                'output' => $process->getOutput()."\n".$process->getErrorOutput(),
            ];
        };
    }

    /**
     * @return array{certified:bool, reason:string, baseline_passed:bool, mutant_killed:bool, operator:string}
     */
    public function verify(string $workspaceRoot, string $targetFile, string $siblingTest, string $operator, int $timeout = 120): array
    {
        $absTarget = rtrim($workspaceRoot, '/').'/'.ltrim($targetFile, '/');
        if (! is_file($absTarget)) {
            return $this->verdict(false, 'target_missing:'.$targetFile, false, false, $operator);
        }
        if (trim($siblingTest) === '') {
            return $this->verdict(false, 'no_sibling_test', false, false, $operator);
        }
        $command = "./vendor/bin/phpunit '".$siblingTest."'";

        // 1. The strengthened test must PASS on the UNMUTATED target — a test that is red on correct
        //    code is invalid and must never be certified (it would just be noise the loop later fights).
        $baseline = ($this->runner)($workspaceRoot, $command, $timeout);
        if (! ($baseline['passed'] ?? false)) {
            return $this->verdict(false, 'baseline_red:new_test_fails_on_correct_code', false, false, $operator);
        }

        // 2. Reproduce the EXACT operator the gate sampled and apply it to the target file.
        $original = @file_get_contents($absTarget);
        if (! is_string($original)) {
            return $this->verdict(false, 'target_unreadable:'.$targetFile, true, false, $operator);
        }
        $mutated = AtlasLoopMutationOperators::applyOperator($operator, $original);
        if ($mutated === null || $mutated === $original) {
            return $this->verdict(false, 'mutant_not_reproducible:'.$operator, true, false, $operator);
        }

        // 3. With the mutant in place, the strengthened test MUST now FAIL — that failure is the proof
        //    the test actually kills the previously-surviving decision mutant. ALWAYS restore the file.
        $mutantRun = ['passed' => true];
        try {
            if (@file_put_contents($absTarget, $mutated) === false) {
                return $this->verdict(false, 'mutant_write_failed:'.$targetFile, true, false, $operator);
            }
            $mutantRun = ($this->runner)($workspaceRoot, $command, $timeout);
        } catch (Throwable $e) {
            return $this->verdict(false, 'mutant_run_error:'.$e->getMessage(), true, false, $operator);
        } finally {
            @file_put_contents($absTarget, $original);
        }

        if ($mutantRun['passed'] ?? false) {
            return $this->verdict(false, 'mutant_survived:test_does_not_kill_'.$operator, true, false, $operator);
        }

        return $this->verdict(true, 'certified:test_kills_'.$operator, true, true, $operator);
    }

    /**
     * @return array{certified:bool, reason:string, baseline_passed:bool, mutant_killed:bool, operator:string}
     */
    private function verdict(bool $certified, string $reason, bool $baselinePassed, bool $mutantKilled, string $operator): array
    {
        return [
            'certified' => $certified,
            'reason' => $reason,
            'baseline_passed' => $baselinePassed,
            'mutant_killed' => $mutantKilled,
            'operator' => $operator,
        ];
    }
}
