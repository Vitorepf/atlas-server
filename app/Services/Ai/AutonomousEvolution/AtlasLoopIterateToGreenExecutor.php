<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * ADEP keystone — the foundational primitive the loop's execution lane LACKED.
 *
 * The loop used to give the provider ONE invocation + a prompt that NAMED the acceptance test, then
 * judged afterward. It never ran the test, saw the failure, and fed it back — so even a capable
 * agentic provider (codex/minimax) edited semi-blind (the live failure: a multi-file extract-class
 * where the provider delegated to a class it never created → class-not-found → reject). codex/Claude
 * are good at refactoring precisely BECAUSE they iterate edit→test→fix; this closes that loop at the
 * LOOP level so it works provider-agnostically, not by hoping the provider self-iterates.
 *
 * Pure orchestration (no provider/process coupling) so it is unit-testable with injected callables:
 * the caller supplies how to RUN the acceptance and how to RE-INVOKE the provider with a failure.
 * The first provider attempt is the caller's responsibility (already made before pursue()); pursue()
 * runs the test, and on red re-invokes with the exact failure, until green or the iteration budget.
 */
final class AtlasLoopIterateToGreenExecutor
{
    /**
     * @param  callable():array{passed:bool,output:string}  $runTest  runs the acceptance; returns pass + output
     * @param  callable(string $failureOutput):void  $reinvokeProvider  re-invokes the provider given the failure
     * @return array{passed:bool,iterations:int,initially_green:bool}
     */
    public function pursue(callable $runTest, callable $reinvokeProvider, int $maxIterations): array
    {
        $maxIterations = max(0, $maxIterations);

        $test = $this->normalize($runTest());
        $initiallyGreen = $test['passed'];
        $iterations = 0;

        while (! $test['passed'] && $iterations < $maxIterations) {
            $iterations++;
            // Feed the EXACT failure back so the provider fixes THIS error, not guesses again.
            $reinvokeProvider($test['output']);
            $test = $this->normalize($runTest());
        }

        return [
            'passed' => $test['passed'],
            'iterations' => $iterations,
            'initially_green' => $initiallyGreen,
        ];
    }

    /**
     * @param  mixed  $raw
     * @return array{passed:bool,output:string}
     */
    private function normalize($raw): array
    {
        $arr = is_array($raw) ? $raw : [];

        return [
            'passed' => (bool) ($arr['passed'] ?? false),
            'output' => (string) ($arr['output'] ?? ''),
        ];
    }
}
