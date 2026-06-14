<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Contracts;

/**
 * The broader-regression gate seam for obra-auto-merge. The production implementation
 * ({@see \App\Services\Ai\AutonomousEvolution\AtlasLoopBroaderRegressionGate}) actually runs
 * the affected test suites + the never-merge invariant test + boot-smoke + php -l; a test may
 * substitute a deterministic fake (green/red) to exercise the merge orchestration without
 * spawning the whole suite. Keeping the gate behind a contract is what makes the KEY SAFETY
 * TEST (a certified obra whose change breaks an OUTSIDE test => merge BLOCKED) deterministic.
 */
interface BroaderRegressionGateContract
{
    /**
     * @param  list<string>  $changedFiles  obra changed files, relative to repo root
     * @return array{schema_version:string, passed:bool, reason:?string, suites:list<array<string,mixed>>, boot_smoke:array<string,mixed>, php_lint:array<string,mixed>, selected_tests:list<string>}
     */
    public function evaluate(string $repoRoot, array $changedFiles): array;
}
