<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopCrossFileConsumerGateService;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use App\Services\Ai\Support\AiStringListNormalizer;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * item9 — MACHINE-VERIFIED completeness criteria resolver.
 *
 * Derives a completeness checklist ONLY from already-trusted signals and resolves each
 * criterion's `satisfied` by RE-RUNNING its bound command/metric in the candidate workspace —
 * never model-declared. The emitted list is shaped EXACTLY for {@see AtlasLoopCompletenessGate}:
 * each entry is array{id:string, satisfied:bool, required:bool} (both keys ALWAYS set explicitly,
 * because the gate defaults a missing `required` to TRUE and a missing `satisfied` to FALSE).
 *
 * Three derivation sources, all FAIL-OPEN (an empty-derivable input returns [] so the gate stays
 * byte-identical / fail-open):
 *   (A) one criterion per frozen acceptance command, resolved by running the command in $workspace
 *       and checking exit 0 (re-using the SAME Symfony Process + PATH-injection runner the
 *       cross-file gate uses — re-implemented here because that gate's commandEnv() is private);
 *   (B) one criterion per cross-file consumer contract that carries a runnable command, resolved by
 *       delegating to AtlasLoopCrossFileConsumerGateService::evaluate() and reading consumer_runs
 *       (skipped / command-missing runs emit no criterion — keeps fail-open);
 *   (C) for refactor contracts (complexity_proof=true AND metric_kind='minimize'), a single
 *       `class_no_longer_god` criterion resolved by re-measuring the changed .php files with
 *       AtlasLoopSignalAnalyzer::aggregateComplexity + the SAME git-stash baseline machinery the
 *       certifier uses; satisfied iff AtlasLoopSignalAnalyzer::complexityReduced holds. A null
 *       analyzer / no .php changes / unmeasurable diff => satisfied=false (fail-CLOSED for the
 *       refactor criterion, matching the certifier's own complexity gate).
 */
final class AtlasLoopCompletenessCriteriaResolver
{
    public function __construct(
        private readonly ?AtlasLoopCrossFileConsumerGateService $crossFileConsumerGate = null,
        private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
    ) {}

    /**
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $changedFiles  raw git-diff changed files (NOT allowed_files-scoped)
     * @return list<array{id:string, satisfied:bool, required:bool}>
     */
    public function resolve(string $objective, array $acceptance, string $workspace, array $changedFiles = []): array
    {
        $timeout = max(1, (int) ($acceptance['timeout_seconds'] ?? config('atlas.loop.cross_file_consumer_gate.timeout_seconds', 120)));

        $criteria = [];
        foreach ($this->acceptanceCommandCriteria($acceptance, $workspace, $timeout) as $criterion) {
            $criteria[] = $criterion;
        }
        foreach ($this->consumerContractCriteria($acceptance, $workspace, $changedFiles, $timeout) as $criterion) {
            $criteria[] = $criterion;
        }
        foreach ($this->refactorGodClassCriteria($acceptance, $workspace, $changedFiles) as $criterion) {
            $criteria[] = $criterion;
        }

        return array_values($criteria);
    }

    /**
     * Source (A): one criterion per frozen acceptance command, RE-RUN in the candidate workspace.
     *
     * @param  array<string,mixed>  $acceptance
     * @return list<array{id:string, satisfied:bool, required:bool}>
     */
    private function acceptanceCommandCriteria(array $acceptance, string $workspace, int $timeout): array
    {
        $criteria = [];
        foreach (AiStringListNormalizer::trimmedStrings($acceptance['commands'] ?? []) as $i => $command) {
            $criteria[] = [
                'id' => 'acceptance_command_'.($i + 1),
                'satisfied' => $this->commandSucceeds($command, $workspace, $timeout),
                'required' => true,
            ];
        }

        return $criteria;
    }

    /**
     * Source (B): one criterion per cross-file consumer contract that actually RAN with a command.
     * Skipped / command-missing runs emit NO criterion (fail-open: an unverifiable consumer is not
     * treated as a failing requirement). Any error returns no consumer criteria.
     *
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $changedFiles
     * @return list<array{id:string, satisfied:bool, required:bool}>
     */
    private function consumerContractCriteria(array $acceptance, string $workspace, array $changedFiles, int $timeout): array
    {
        if ($this->crossFileConsumerGate === null) {
            return [];
        }

        try {
            $result = $this->crossFileConsumerGate->evaluate($workspace, $acceptance, $changedFiles, [
                'enabled' => true,
                'timeout_seconds' => $timeout,
            ]);
        } catch (Throwable) {
            return [];
        }

        $criteria = [];
        foreach ((array) ($result['consumer_runs'] ?? []) as $run) {
            if (! is_array($run) || (bool) ($run['skipped'] ?? false)) {
                continue;
            }
            // The command lives on the run directly OR on its contract summary (contractSummary()).
            $command = trim((string) ($run['command'] ?? data_get($run, 'contract.command', '')));
            if ($command === '') {
                continue;
            }
            $criteria[] = [
                'id' => 'consumer_contract_'.(int) ($run['contract_index'] ?? (count($criteria) + 1)),
                'satisfied' => (bool) ($run['passed'] ?? false),
                'required' => true,
            ];
        }

        return $criteria;
    }

    /**
     * Source (C): the refactor `class_no_longer_god` criterion — RE-MEASURED, never model-declared.
     * Measures the changed .php files with the analyzer's aggregateComplexity for the CANDIDATE (live
     * tree), stashes to the committed baseline, re-measures, restores in finally, and uses the SAME
     * complexityReduced verdict the certifier uses (no inline re-implementation of the rule).
     *
     * Fail-CLOSED (satisfied=false) when: not a refactor contract, the analyzer is null, no .php
     * changed files, the candidate/baseline is unmeasurable, or there is no stash entry (no-op diff).
     *
     * @param  array<string,mixed>  $acceptance
     * @param  list<string>  $changedFiles
     * @return list<array{id:string, satisfied:bool, required:bool}>
     */
    private function refactorGodClassCriteria(array $acceptance, string $workspace, array $changedFiles): array
    {
        $isRefactor = (bool) ($acceptance['complexity_proof'] ?? false)
            && (string) ($acceptance['metric_kind'] ?? '') === 'minimize';
        if (! $isRefactor) {
            return [];
        }

        return [[
            'id' => 'class_no_longer_god',
            'satisfied' => $this->complexityActuallyReduced($workspace, $changedFiles),
            'required' => true,
        ]];
    }

    /**
     * Re-measure the changed .php files (candidate vs stashed baseline) and return the analyzer's
     * single-source complexityReduced verdict. Fail-CLOSED on a null analyzer, no measurable file,
     * an unparseable side, or no stash entry (replicates the certifier's stashCreated() pre-check so
     * a no-op candidate never pops a PRE-EXISTING stash and corrupts the tree).
     *
     * @param  list<string>  $changedFiles
     */
    private function complexityActuallyReduced(string $workspace, array $changedFiles): bool
    {
        $analyzer = $this->signalAnalyzer;
        if ($analyzer === null) {
            return false; // fail-closed: integrator binds a live analyzer for real measurement
        }

        $phpFiles = array_values(array_filter(
            $changedFiles,
            static fn (string $f): bool => str_ends_with($f, '.php'),
        ));
        if ($phpFiles === []) {
            return false; // nothing measurable -> fail-closed
        }
        $absPaths = array_map(
            static fn (string $f): string => rtrim($workspace, '/').'/'.ltrim($f, '/'),
            $phpFiles,
        );

        // CANDIDATE first: the diff is live in the working tree right now.
        $candidate = $analyzer->aggregateComplexity($absPaths);
        if (! ($candidate['measured'] ?? false)) {
            return false; // candidate unparseable -> fail-closed
        }

        $stash = new Process(['git', 'stash', 'push', '--include-untracked', '--quiet'], $workspace, null, null, 60.0);
        $stash->run();
        // DEFECT 3 GUARD: replicate the certifier's stashCreated() pre-check. If the push failed OR
        // captured nothing (no-op candidate), there is no NEW stash to pop — popping here would pop a
        // PRE-EXISTING stash and corrupt the tree. Bail WITHOUT popping.
        if (! $stash->isSuccessful() || ! $this->stashCreated($workspace)) {
            return false; // no diff to stash (no-op candidate) -> fail-closed
        }

        try {
            $baseline = $analyzer->aggregateComplexity($absPaths);
        } finally {
            (new Process(['git', 'stash', 'pop', '--quiet'], $workspace, null, null, 60.0))->run();
        }

        if (! ($baseline['measured'] ?? false)) {
            return false; // baseline unparseable -> fail-closed
        }

        // SAME single source as the certifier's own complexity gate (no inline rule duplication), so
        // the criterion and the gate cannot drift.
        return AtlasLoopSignalAnalyzer::complexityReduced(
            $baseline,
            $candidate,
            (bool) config('atlas.loop.complexity_decisions_gate', true),
            (bool) config('atlas.loop.complexity_per_file_max_gate', true),
        ) === true;
    }

    /** True when a stash entry exists (the push actually captured changes). */
    private function stashCreated(string $workspace): bool
    {
        $list = new Process(['git', 'stash', 'list'], $workspace, null, null, 30.0);
        $list->run();

        return trim((string) $list->getOutput()) !== '';
    }

    /**
     * Run a command in $workspace via the SAME Symfony Process + PATH-injection pattern the cross-file
     * gate uses (re-implemented here: that gate's commandEnv() is private). Fail-OPEN on any Process
     * error: a flaky command must never hard-crash the resolver — exit !== 0 simply yields false.
     */
    private function commandSucceeds(string $command, string $workspace, int $timeout): bool
    {
        try {
            $process = Process::fromShellCommandline($command, $workspace, $this->commandEnv([]), null, (float) $timeout);
            $process->run();

            return ($process->getExitCode() ?? 1) === 0;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * PATH-injection env block: prepend the running PHP binary's directory to PATH so a bare `php`
     * inside an acceptance command resolves to the SAME interpreter (mirrors the cross-file gate's
     * private commandEnv()).
     *
     * @param  array<string,string>  $env
     * @return array<string,string>
     */
    private function commandEnv(array $env): array
    {
        $binary = PHP_BINARY;
        if (is_string($binary) && $binary !== '') {
            $binDir = \dirname($binary);
            $currentPath = getenv('PATH');
            $env['PATH'] = $binDir.((is_string($currentPath) && $currentPath !== '') ? PATH_SEPARATOR.$currentPath : '');
        }

        return $env;
    }
}
