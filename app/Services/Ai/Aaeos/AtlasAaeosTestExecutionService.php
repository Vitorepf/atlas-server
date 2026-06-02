<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Models\AtlasAaeosTestRunReceipt;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * B3 / criterion C2 — RUNS a capability's named test FOR REAL and records a
 * GREEN-RUN RECEIPT. This is the EXPENSIVE, OPT-IN half of "verified means a real
 * green run": it spawns a PHPUnit process (seconds per test), so it runs ONLY from
 * the `atlas:aaeos:verify-tests` command — NEVER on a maturity read.
 *
 * Honest-by-construction: a run is GREEN only when the process exits 0 AND at least
 * one test actually executed. A `--filter` that matches nothing prints
 * "No tests executed!" and exits non-zero — that is NOT green and is recorded as
 * passed=false / tests_run=0, never silently treated as a pass (never-fabricate).
 *
 * The receipt is the ONLY thing that lets a capability reach the `verified` tier in
 * AtlasAaeosImplementationTruthService. Mirrors the repo's proven runner pattern
 * (RealMeasureCommandPort / FoundryEvidenceVerifierService): Symfony Process,
 * setTimeout, read-only allowlist head.
 *
 * @see app/Services/Ai/Aaeos/AtlasAaeosImplementationTruthService.php
 * @see database/migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php
 */
class AtlasAaeosTestExecutionService
{
    public const SCHEMA = 'atlas.aaeos.test_run_receipt.v1';

    /**
     * How many trailing chars of the runner output to keep in the receipt (audit, not the whole log).
     */
    private const OUTPUT_TAIL_CHARS = 1600;

    public function __construct(
        private readonly float $timeout = 180.0,
    ) {}

    /**
     * Is there a GREEN-RUN RECEIPT for this capability? This is the gate the truth
     * service consults before allowing the `verified` tier.
     *
     * Degrade-safe: if the receipts table is absent (fresh DB / a context that never
     * migrated it) this returns false, so NO capability is verified-by-existence —
     * the truth service fails toward `partial`, never silently keeps existence-only.
     *
     * When $testRef is given, the green run must be for THAT named test; otherwise any
     * green receipt for the capability counts. A green run requires passed=true AND
     * tests_run>=1 (a no-match "No tests executed" run can never satisfy this).
     */
    public function hasGreenReceipt(string $capabilityId, ?string $testRef = null): bool
    {
        $capabilityId = trim($capabilityId);
        if ($capabilityId === '') {
            return false;
        }

        if (! $this->receiptsTableExists()) {
            return false;
        }

        try {
            $query = AtlasAaeosTestRunReceipt::query()
                ->green()
                ->where('capability_id', $capabilityId);

            if ($testRef !== null && trim($testRef) !== '') {
                $query->where('test_ref', trim($testRef));
            }

            return $query->exists();
        } catch (Throwable) {
            // Any storage error degrades to "no green proof" — never to a false pass.
            return false;
        }
    }

    /**
     * Run a single named test FOR REAL and upsert its GREEN-RUN RECEIPT.
     *
     * $explicitPath, when given, is passed to PHPUnit as a positional path arg so a
     * test file OUTSIDE the configured testsuites can still be collected by --filter
     * (used to prove the receipt path against a controlled fixture). Capability refs
     * from docs never set it — production runs collect from the configured testsuites.
     *
     * @return array<string,mixed> the receipt payload (schema, passed, tests_run, exit_code, filter, ran_at, output_tail, ...)
     */
    public function runAndRecord(string $capabilityId, string $testRef, ?string $explicitPath = null): array
    {
        $capabilityId = trim($capabilityId);
        $testRef = trim($testRef);
        $filter = $this->filterForTestRef($testRef);

        $run = $this->runFilter($filter, $explicitPath);

        $payload = [
            'schema_version' => self::SCHEMA,
            'capability_id' => $capabilityId,
            'test_ref' => $testRef,
            'filter' => $filter,
            'passed' => $run['passed'],
            'tests_run' => $run['tests_run'],
            'exit_code' => $run['exit_code'],
            'commit_stamp' => $this->commitStamp(),
            'output_tail' => $run['output_tail'],
            'runner' => $run['runner'],
            'ran' => $run['ran'],
            'ran_at' => now()->toJSON(),
        ];

        $this->persist($payload);

        return $payload;
    }

    /**
     * Derive the PHPUnit --filter argument from a declared test ref. Accepts a bare
     * class name (run the whole class), Class::method, or a bare test_* method name.
     * Strips any namespace so the filter matches PHPUnit's short-name matching.
     */
    public function filterForTestRef(string $testRef): string
    {
        $ref = trim($testRef);
        if ($ref === '') {
            return '';
        }

        // Class::method -> prefer the method (most specific, cheapest run).
        if (str_contains($ref, '::')) {
            $method = trim((string) substr($ref, (int) strrpos($ref, '::') + 2));
            if ($method !== '') {
                return $method;
            }
        }

        // Strip namespace from a FQN class.
        if (str_contains($ref, '\\')) {
            $ref = (string) substr($ref, (int) strrpos($ref, '\\') + 1);
        }

        return $ref;
    }

    /**
     * Spawn a real PHPUnit process for the filter and read the honest outcome.
     *
     * @return array{ran:bool,passed:bool,tests_run:int,exit_code:int,output_tail:string,runner:string}
     */
    private function runFilter(string $filter, ?string $explicitPath = null): array
    {
        if ($filter === '') {
            return $this->blockedRun('empty_filter');
        }

        if (! class_exists(Process::class)) {
            return $this->blockedRun('process_unavailable');
        }

        $binary = $this->phpunitBinary();
        if ($binary === null) {
            return $this->blockedRun('phpunit_binary_missing');
        }

        $command = [$binary];
        // Positional path arg (when given) lets --filter collect a file outside the
        // configured testsuites — only for explicit fixture-backed proof runs.
        if ($explicitPath !== null && trim($explicitPath) !== '' && is_file($explicitPath)) {
            $command[] = $explicitPath;
        }
        array_push($command, '--filter', $filter, '--no-coverage');

        $process = new Process(
            $command,
            base_path(),
            // Force a sqlite :memory: DB for the spawned suite so it never touches
            // the live pgsql runtime, mirroring the test harness env.
            ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:'] + $this->inheritedEnv(),
        );
        $process->setTimeout($this->timeout);

        try {
            $process->run();
        } catch (Throwable $e) {
            return $this->blockedRun('process_error:'.$e::class);
        }

        $output = $process->getOutput()."\n".$process->getErrorOutput();
        $exitCode = (int) ($process->getExitCode() ?? -1);
        $testsRun = $this->parseTestsRun($output);

        // GREEN only when the process exited clean AND >=1 test actually ran. A
        // no-match filter ("No tests executed!") exits non-zero and runs 0 — never green.
        $passed = $exitCode === 0 && $testsRun >= 1;

        return [
            'ran' => true,
            'passed' => $passed,
            'tests_run' => $testsRun,
            'exit_code' => $exitCode,
            'output_tail' => $this->tail($output),
            'runner' => $binary.' --filter '.$filter,
        ];
    }

    /**
     * Parse how many tests actually executed. PHPUnit prints "OK (N tests, ...)" on a
     * clean run and "Tests: N, Assertions: ..." on a run with failures; a no-match run
     * prints "No tests executed!" (0). Returns 0 when nothing can be parsed (fail-safe).
     */
    private function parseTestsRun(string $output): int
    {
        if (preg_match('/No tests executed/i', $output) === 1) {
            return 0;
        }
        if (preg_match('/OK(?: \(|, )(\d+)\s+test/i', $output, $m) === 1) {
            return (int) $m[1];
        }
        if (preg_match('/Tests:\s+(\d+)/i', $output, $m) === 1) {
            return (int) $m[1];
        }

        return 0;
    }

    private function phpunitBinary(): ?string
    {
        foreach (['vendor/bin/phpunit', 'vendor/bin/pest'] as $candidate) {
            $path = base_path($candidate);
            if (is_file($path)) {
                return $path;
            }
        }

        return null;
    }

    /**
     * @return array<string,string>
     */
    private function inheritedEnv(): array
    {
        $env = [];
        foreach (['PATH', 'HOME', 'APP_ENV'] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        // Default the spawned suite to the testing env unless the operator set one.
        $env['APP_ENV'] = $env['APP_ENV'] ?? 'testing';

        return $env;
    }

    private function commitStamp(): ?string
    {
        if (! class_exists(Process::class)) {
            return null;
        }
        try {
            $process = new Process(['git', '-C', base_path(), 'rev-parse', '--short=12', 'HEAD']);
            $process->setTimeout(10.0);
            $process->run();
            $stamp = trim($process->getOutput());

            return $stamp !== '' ? $stamp : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array{ran:false,passed:false,tests_run:int,exit_code:int,output_tail:string,runner:string}
     */
    private function blockedRun(string $reason): array
    {
        return [
            'ran' => false,
            'passed' => false,
            'tests_run' => 0,
            'exit_code' => -1,
            'output_tail' => 'blocked:'.$reason,
            'runner' => $reason,
        ];
    }

    private function tail(string $output): string
    {
        $output = trim($output);
        if (mb_strlen($output) <= self::OUTPUT_TAIL_CHARS) {
            return $output;
        }

        return '…'.mb_substr($output, -self::OUTPUT_TAIL_CHARS);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function persist(array $payload): void
    {
        if (! $this->receiptsTableExists()) {
            return;
        }

        try {
            AtlasAaeosTestRunReceipt::query()->updateOrCreate(
                [
                    'capability_id' => (string) $payload['capability_id'],
                    'test_ref' => (string) $payload['test_ref'],
                ],
                [
                    'filter' => (string) $payload['filter'],
                    'passed' => (bool) $payload['passed'],
                    'tests_run' => (int) $payload['tests_run'],
                    'exit_code' => $payload['exit_code'] !== null ? (int) $payload['exit_code'] : null,
                    'commit_stamp' => $payload['commit_stamp'],
                    'output_tail' => $payload['output_tail'],
                    'runner' => $payload['runner'],
                    'ran_at' => now(),
                ],
            );
        } catch (Throwable) {
            // Persistence failure must not crash the command; the payload is still returned.
        }
    }

    private function receiptsTableExists(): bool
    {
        try {
            return Schema::hasTable('atlas_aaeos_test_run_receipts');
        } catch (Throwable) {
            return false;
        }
    }
}
