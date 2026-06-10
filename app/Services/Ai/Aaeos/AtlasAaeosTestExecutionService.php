<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\File;
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
        private readonly AtlasAaeosImplementationEvidenceResolver $resolver = new AtlasAaeosImplementationEvidenceResolver,
    ) {}

    /**
     * Is there a GREEN-CURRENT receipt for this capability? This is the gate the truth
     * service consults before allowing the `verified` tier.
     *
     * "Green-current" (B3 freshness, criterion C2) = a real GREEN run (passed=true AND
     * tests_run>=1) THAT STILL MATCHES THE CURRENT CODE+TEST. When the caller passes the
     * current content hashes, a receipt grants verified ONLY when its stored hashes equal
     * them — so the guarantee DECAYS the moment the implementation or test file content
     * changes (a stale green stops counting; the capability degrades away from verified).
     *
     * Degrade-safe:
     *   - Receipts table absent -> false (no capability is verified-by-existence; the
     *     truth service fails toward `partial`, never silently keeps existence-only).
     *   - A receipt whose stored hash != the current hash (code/test edited, OR the file
     *     is now missing/unreadable so the current hash is a sentinel) -> NOT green-current.
     *   - A receipt with a NULL stored hash predates freshness tracking and makes no
     *     freshness CLAIM: it is grandfathered (still green) so legacy receipts and the
     *     existing proof flow keep working; the DROP is enforced for any receipt that DOES
     *     carry a hash. Re-running atlas:aaeos:verify-tests stamps fresh hashes on every row.
     *   - Both current hashes null (a caller with no freshness context) -> freshness is not
     *     applicable and the green scope alone decides (backward compatible).
     *
     * When $testRef is given, the green run must be for THAT named test; otherwise any
     * green receipt for the capability counts.
     */
    public function hasGreenReceipt(
        string $capabilityId,
        ?string $testRef = null,
        ?string $currentTestFileHash = null,
        ?string $currentImplFilesHash = null,
    ): bool {
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

            // No freshness context at all -> the green scope alone decides.
            if ($currentTestFileHash === null && $currentImplFilesHash === null) {
                return $query->exists();
            }

            // Freshness-aware: at least ONE green receipt must still match current content.
            foreach ($query->get() as $receipt) {
                if ($this->receiptIsFresh($receipt, $currentTestFileHash, $currentImplFilesHash)) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            // Any storage error degrades to "no green proof" — never to a false pass.
            return false;
        }
    }

    /**
     * Is this green receipt still FRESH against the current content hashes? A stored hash
     * must equal the current one to pass; a NULL stored hash is grandfathered (no claim);
     * a current sentinel (file missing/unreadable) can never equal a real stored hash, so
     * a once-proven file that is now gone correctly reads as stale.
     */
    private function receiptIsFresh(
        AtlasAaeosTestRunReceipt $receipt,
        ?string $currentTestFileHash,
        ?string $currentImplFilesHash,
    ): bool {
        return $this->hashFresh((string) ($receipt->test_file_hash ?? ''), $currentTestFileHash)
            && $this->hashFresh((string) ($receipt->impl_files_hash ?? ''), $currentImplFilesHash);
    }

    /**
     * One hash dimension: grandfather a missing/blank stored hash (legacy receipt, no
     * freshness claim); when a stored hash exists it must equal the current hash exactly.
     * A null current hash means the caller did not constrain this dimension -> pass.
     */
    private function hashFresh(string $storedHash, ?string $currentHash): bool
    {
        if ($storedHash === '') {
            return true; // pre-freshness receipt: no claim to violate
        }
        if ($currentHash === null) {
            return true; // caller did not constrain this dimension
        }

        return hash_equals($storedHash, $currentHash);
    }

    /**
     * Run a single named test FOR REAL and upsert its GREEN-RUN RECEIPT.
     *
     * $explicitPath, when given, is passed to PHPUnit as a positional path arg so a
     * test file OUTSIDE the configured testsuites can still be collected by --filter
     * (used to prove the receipt path against a controlled fixture). Capability refs
     * from docs never set it — production runs collect from the configured testsuites.
     *
     * $testFileHash / $implFilesHash are the B3 freshness content hashes (FIX 1). They
     * are computed by the caller (the truth service, which owns the capability's
     * evidence_refs + resolver) and STORED on the receipt, binding this green run to the
     * exact code+test content it proved. A later maturity read recomputes them and a
     * mismatch (code/test edited) stops the receipt from granting `verified`.
     *
     * @return array<string,mixed> the receipt payload (schema, passed, tests_run, exit_code, filter, ran_at, output_tail, ...)
     */
    public function runAndRecord(
        string $capabilityId,
        string $testRef,
        ?string $explicitPath = null,
        ?string $testFileHash = null,
        ?string $implFilesHash = null,
    ): array {
        $capabilityId = trim($capabilityId);
        $testRef = trim($testRef);

        // FIX 2 — FQN-bound filter. Anchor the run to the resolver's RESOLVED
        // Namespace\Class(::method); a bare fragment that does not resolve to a real
        // Class/Class::method is REFUSED (passed=false, reason=ambiguous_test_ref) so it
        // can never match a same-named method in a different class and bank a broad green.
        // An explicit fixture path bypasses index resolution (controlled proof runs).
        if ($explicitPath !== null && trim($explicitPath) !== '' && is_file($explicitPath)) {
            $filter = $this->plainFilterForTestRef($testRef);
            $run = $this->runFilter($filter, $explicitPath);
        } else {
            $fqn = $this->resolver->resolveTestFqn($testRef);
            if ($fqn === null) {
                $run = $this->ambiguousRun($testRef);
                $filter = $run['runner'];
            } else {
                $filter = $this->anchoredFilter($fqn);
                $run = $this->runFilter($filter, null);
            }
        }

        $payload = [
            'schema_version' => self::SCHEMA,
            'capability_id' => $capabilityId,
            'test_ref' => $testRef,
            'filter' => $filter,
            'passed' => $run['passed'],
            'tests_run' => $run['tests_run'],
            'exit_code' => $run['exit_code'],
            'commit_stamp' => $this->commitStamp(),
            'test_file_hash' => $testFileHash,
            'impl_files_hash' => $implFilesHash,
            'output_tail' => $run['output_tail'],
            'runner' => $run['runner'],
            'ran' => $run['ran'],
            'reason' => $run['reason'] ?? null,
            'ran_at' => now()->toJSON(),
        ];

        $this->persist($payload);

        return $payload;
    }

    /**
     * Derive a PHPUnit --filter from a declared test ref WITHOUT index resolution. Used
     * only for explicit fixture-backed proof runs (a controlled file passed by path).
     * Accepts a bare class, Class::method, or a bare test_* method; strips namespace to
     * match PHPUnit's short-name matching.
     */
    public function plainFilterForTestRef(string $testRef): string
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
     * Back-compat alias. The PRODUCTION filter is now FQN-bound and derived inside
     * runAndRecord() from the resolver (see anchoredFilter()); this preserves the old
     * short-name behavior for any caller that asks for a filter without resolution.
     */
    public function filterForTestRef(string $testRef): string
    {
        return $this->plainFilterForTestRef($testRef);
    }

    /**
     * FIX 2 — build a PHPUnit --filter regex ANCHORED to the resolved fully-qualified
     * class so it cannot match a same-named method in a different class. PHPUnit matches
     * --filter as a regex against "Namespace\Class::method"; anchoring on the FQ class
     * (and, when present, the exact method end) binds the run to the DECLARED class.
     *
     * @param  array{class:string, method:?string}  $fqn
     */
    private function anchoredFilter(array $fqn): string
    {
        $class = ltrim($fqn['class'], '\\');
        $classPattern = preg_quote($class, '/');

        if (($fqn['method'] ?? null) !== null && $fqn['method'] !== '') {
            // Bind to the exact class::method end of the FQN.
            return '/'.$classPattern.'::'.preg_quote($fqn['method'], '/').'$/';
        }

        // Whole class: bind the class boundary so "FooTest" does not match "BarFooTest".
        return '/(\\\\|^)'.$classPattern.'(::|$)/';
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
     * @return array{ran:false,passed:false,tests_run:int,exit_code:int,output_tail:string,runner:string,reason:string}
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
            'reason' => $reason,
        ];
    }

    /**
     * FIX 2 — a declared test ref that does NOT resolve to a real indexed
     * Class/Class::method is AMBIGUOUS: it is never RUN (no broad short-name filter that
     * could green a same-named method in another class) and is recorded passed=false with
     * reason=ambiguous_test_ref, so it can never grant `verified`.
     *
     * @return array{ran:false,passed:false,tests_run:int,exit_code:int,output_tail:string,runner:string,reason:string}
     */
    private function ambiguousRun(string $testRef): array
    {
        return [
            'ran' => false,
            'passed' => false,
            'tests_run' => 0,
            'exit_code' => -1,
            'output_tail' => 'ambiguous_test_ref: "'.$testRef.'" does not resolve to an indexed Class or Class::method — refusing to run a broad filter',
            'runner' => 'ambiguous_test_ref',
            'reason' => 'ambiguous_test_ref',
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
                    // B3 freshness: bind the receipt to the code+test content it proved.
                    'test_file_hash' => $payload['test_file_hash'] ?? null,
                    'impl_files_hash' => $payload['impl_files_hash'] ?? null,
                    'output_tail' => $payload['output_tail'],
                    // `runner` is a short audit breadcrumb in a varchar(120) column; an
                    // FQN-anchored --filter regex can exceed that, so cap it (never let an
                    // audit label fail the write that records the green run itself).
                    'runner' => mb_substr((string) $payload['runner'], 0, 120),
                    'ran_at' => now(),
                ],
            );
        } catch (Throwable) {
            // Persistence failure must not crash the command; the payload is still returned.
        }
    }

    private function receiptsTableExists(): bool
    {
        return DatabaseTableAvailability::has('atlas_aaeos_test_run_receipts');
    }
}
