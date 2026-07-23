<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticEngineeringOs\Maturity;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Services\Ai\Support\DatabaseTableAvailability;
use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * B3 / criterion C2 — RUNS a capability's named test FOR REAL and records a
 * GREEN-RUN RECEIPT. This is the EXPENSIVE, OPT-IN half of "verified means a real
 * green run": it spawns a PHPUnit process (seconds per test), so it runs ONLY from
 * the `atlas:aeos:verify-tests` command — NEVER on a maturity read.
 *
 * Honest-by-construction: a run is GREEN only when the process exits 0 AND at least
 * one test actually executed. A `--filter` that matches nothing prints
 * "No tests executed!" and exits non-zero — that is NOT green and is recorded as
 * passed=false / tests_run=0, never silently treated as a pass (never-fabricate).
 *
 * The receipt is the ONLY thing that lets a capability reach the `verified` tier in
 * AtlasImplementationTruthService. Mirrors the repo's proven runner pattern
 * (RealMeasureCommandPort / FoundryEvidenceVerifierService): Symfony Process,
 * setTimeout, read-only allowlist head.
 *
 * @see app/Services/Ai/Aaeos/AtlasImplementationTruthService.php
 * @see database/migrations/2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php
 */
class AtlasCapabilityTestExecutionService
{
    public const FIELD_CLASS = 'class';
    public const FIELD_EXPLAIN = 'explain';
    public const SCHEMA = 'atlas.aaeos.test_run_receipt.v1';
    public const FIELD_RUNNER = 'runner';
    public const FIELD_EXIT_CODE = 'exit_code';
    public const FIELD_TESTS_RUN = 'tests_run';
    public const FIELD_OUTPUT_TAIL = 'output_tail';
    public const FIELD_REASON = 'reason';
    public const FIELD_TEST_FILE_HASH = 'test_file_hash';
    public const FIELD_IMPL_FILES_HASH = 'impl_files_hash';
    public const FIELD_STATUS = 'status';

    /**
     * How many trailing chars of the runner output to keep in the receipt (audit, not the whole log).
     */
    public const OUTPUT_TAIL_CHARS = 1600;

    public const FIELD_PASSED = 'passed';

    public const FIELD_RAN = 'ran';
    public const FIELD_CAPABILITY_ID = 'capability_id';
    public const FIELD_TEST_REF = 'test_ref';
    public const FIELD_FILTER = 'filter';
    public const FIELD_COMMIT_STAMP = 'commit_stamp';
    public const FIELD_RAN_AT = 'ran_at';
    public const FIELD_METHOD = 'method';
    public const FIELD_BORN_STALE = 'born_stale';
    public const FIELD_FRESH_HASHES = 'fresh_hashes';
    public const FIELD_GIT_PORCELAIN = 'git_porcelain';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_SEALED = 'sealed';
    public const FIELD_VETO_PROPAGATION = 'veto_propagation';
    public const FIELD_AMBIGUOUS_TEST_REF = 'ambiguous_test_ref';
    public const FIELD_ATLAS_AAEOS_TEST_RUN_RECEIPTS = 'atlas_aaeos_test_run_receipts';
    public const FIELD_SQLITE = 'sqlite';
    public const FIELD_TESTING = 'testing';
    public const FIELD_EMPTY_FILTER = 'empty_filter';
    public const FIELD_PHPUNIT_BINARY_MISSING = 'phpunit_binary_missing';
    public const FIELD_PROCESS_UNAVAILABLE = 'process_unavailable';
    public const FIELD_REVIEW = 'review';
    public const FIELD_DELIVERY = 'delivery';
    public const FIELD_GIT = 'git';
    public const FIELD_APP_ENV = 'APP_ENV';
    public const FIELD_DB_CONNECTION = 'DB_CONNECTION';
    public const FIELD_DB_DATABASE = 'DB_DATABASE';
    public const FIELD_HEAD = 'HEAD';
    public const FIELD_HOME = 'HOME';
    public const FIELD_PATH = 'PATH';
    public const FIELD_REV_PARSE = 'rev-parse';
    public const FIELD___FILTER = '--filter';
    public const FIELD___NO_COVERAGE = '--no-coverage';
    public const FIELD___PORCELAIN = '--porcelain';

    public function __construct(
        private readonly float $timeout = 180.0,
        private readonly AtlasImplementationEvidenceResolver $resolver = new AtlasImplementationEvidenceResolver,
        private readonly AtlasVetoPropagationResolver $vetoResolver = new AtlasVetoPropagationResolver,
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
     *     carry a hash. Re-running atlas:aeos:verify-tests stamps fresh hashes on every row.
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
        $capabilityId = AiValueNormalizer::trimmedStringOrNull($capabilityId) ?? '';
        if ($capabilityId === '') {
            return false;
        }

        if (! $this->receiptsTableExists()) {
            return false;
        }

        try {
            $query = AtlasAaeosTestRunReceipt::query()
                ->green()
                ->where(self::FIELD_CAPABILITY_ID, $capabilityId);

            if ($testRef !== null && (AiValueNormalizer::trimmedStringOrNull($testRef) ?? '') !== '') {
                $query->where(self::FIELD_TEST_REF, AiValueNormalizer::trimmedStringOrNull($testRef) ?? '');
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
        return $this->hashFresh(AiValueNormalizer::trimmedScalarStringOrNull($receipt->test_file_hash ?? null) ?? '', $currentTestFileHash)
            && $this->hashFresh(AiValueNormalizer::trimmedScalarStringOrNull($receipt->impl_files_hash ?? null) ?? '', $currentImplFilesHash);
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
        $capabilityId = AiValueNormalizer::trimmedStringOrNull($capabilityId) ?? '';
        $testRef = AiValueNormalizer::trimmedStringOrNull($testRef) ?? '';

        // FIX 2 — FQN-bound filter. Anchor the run to the resolver's RESOLVED
        // Namespace\Class(::method); a bare fragment that does not resolve to a real
        // Class/Class::method is REFUSED (passed=false, reason=ambiguous_test_ref) so it
        // can never match a same-named method in a different class and bank a broad green.
        // An explicit fixture path bypasses index resolution (controlled proof runs).
        //
        // PATH-SCOPE (Onda 3 / Residual Elite): when the index knows the test file,
        // pass it as a positional PHPUnit arg so the runner does NOT load the full
        // configured testsuites. Full-suite discovery currently fatals on deleted
        // ACDE-morto symbols (GAP-17 LoopExecutionDriver chain) still referenced by
        // leftover AE tests — filter-only mint then exits 255 with tests_run=0 for
        // every capability. Path-scoped collection keeps mint honest and greenable
        // without restoring the dead loop. Explicit fixture paths still use the
        // plain short-name filter (controlled proof runs outside the index).
        if ($explicitPath !== null && (AiValueNormalizer::trimmedStringOrNull($explicitPath) ?? '') !== '' && is_file($explicitPath)) {
            $filter = $this->plainFilterForTestRef($testRef);
            $run = $this->runFilter($filter, $explicitPath);
        } else {
            $fqn = $this->resolver->resolveTestFqn($testRef);
            if ($fqn === null) {
                $run = $this->ambiguousRun($testRef);
                $filter = $run[self::FIELD_RUNNER];
            } else {
                $filter = $this->anchoredFilter($fqn);
                $scopedPath = $this->absoluteTestPath($this->resolver->resolveTestFilePath($testRef));
                $run = $this->runFilter($filter, $scopedPath);
            }
        }

        $payload = [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA,
            self::FIELD_CAPABILITY_ID => $capabilityId,
            self::FIELD_TEST_REF => $testRef,
            self::FIELD_FILTER => $filter,
            self::FIELD_PASSED => $run[self::FIELD_PASSED],
            self::FIELD_TESTS_RUN => $run[self::FIELD_TESTS_RUN],
            self::FIELD_EXIT_CODE => $run[self::FIELD_EXIT_CODE],
            self::FIELD_COMMIT_STAMP => $this->commitStamp(),
            self::FIELD_TEST_FILE_HASH => $testFileHash,
            self::FIELD_IMPL_FILES_HASH => $implFilesHash,
            self::FIELD_OUTPUT_TAIL => $run[self::FIELD_OUTPUT_TAIL],
            self::FIELD_RUNNER => $run[self::FIELD_RUNNER],
            self::FIELD_RAN => $run[self::FIELD_RAN],
            self::FIELD_REASON => $run[self::FIELD_REASON] ?? null,
            self::FIELD_RAN_AT => now()->toJSON(),
        ];

        // A red run is a delivery-stage veto: resolve where it must propagate via the
        // canonical department transition graph, same as any other cross-department veto.
        if (! $run[self::FIELD_PASSED]) {
            $payload[self::FIELD_VETO_PROPAGATION] = $this->vetoResolver->resolve(self::FIELD_REVIEW, self::FIELD_DELIVERY);
        }

        // PIP-03 — ambiguous refs never ran PHPUnit; persisting them would pollute
        // atlas_aaeos_test_run_receipts with tests_run=0 / exit=-1 noise. Red runs where
        // the test EXISTS and actually failed still persist — honest signal that stays.
        if (($run[self::FIELD_REASON] ?? null) !== self::FIELD_AMBIGUOUS_TEST_REF) {
            $this->persist($payload);
        }

        return $payload;
    }

    /**
     * PIP-02 — post-mint seal: recompute freshness on a NEW truth resolver (no memo)
     * and require hasGreenReceipt against the live index. Returns born_stale when the
     * receipt was minted against hashes that no longer match the DB index.
     *
     * @param  array<int,array{kind?:string, ref?:string}>  $evidenceRefs
     * @return array{
     *   sealed:bool,
     *   born_stale:bool,
     *   fresh_hashes:array{test_file_hash:?string, impl_files_hash:?string},
     *   explain:array<string,mixed>|null,
     *   git_porcelain:?string
     * }
     */
    public function verifyGreenMintSeal(string $capabilityId, string $testRef, array $evidenceRefs): array
    {
        $truth = new AtlasImplementationTruthService(
            new AtlasImplementationEvidenceResolver,
            new \App\Services\Semantic\CanonicalDocsFrontmatterParser,
            $this,
        );
        $freshHashes = $truth->freshnessHashes($evidenceRefs, $testRef);
        $sealed = $this->hasGreenReceipt(
            $capabilityId,
            $testRef,
            $freshHashes[self::FIELD_TEST_FILE_HASH] ?? null,
            $freshHashes[self::FIELD_IMPL_FILES_HASH] ?? null,
        );

        return [
            self::FIELD_SEALED => $sealed,
            self::FIELD_BORN_STALE => ! $sealed,
            self::FIELD_FRESH_HASHES => $freshHashes,
            self::FIELD_EXPLAIN => $sealed ? null : $truth->explainImplFilesHash($evidenceRefs),
            self::FIELD_GIT_PORCELAIN => $this->gitPorcelainForensics(),
        ];
    }

    /**
     * Forensic metadata only — never a gate (commit_stamp drift != content drift).
     */
    private function gitPorcelainForensics(): ?string
    {
        if (! class_exists(Process::class)) {
            return null;
        }

        try {
            $process = new Process([self::FIELD_GIT, '-C', base_path(), self::FIELD_STATUS, self::FIELD___PORCELAIN]);
            $process->setTimeout(10.0);
            $process->run();
            $out = AiValueNormalizer::trimmedStringOrNull($process->getOutput()) ?? '';

            return $out !== '' ? $out : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Derive a PHPUnit --filter from a declared test ref WITHOUT index resolution. Used
     * only for explicit fixture-backed proof runs (a controlled file passed by path).
     * Accepts a bare class, Class::method, or a bare test_* method; strips namespace to
     * match PHPUnit's short-name matching.
     */
    public function plainFilterForTestRef(string $testRef): string
    {
        $ref = AiValueNormalizer::trimmedStringOrNull($testRef) ?? '';
        if ($ref === '') {
            return '';
        }

        // Class::method -> prefer the method (most specific, cheapest run).
        if (str_contains($ref, '::')) {
            $method = AiValueNormalizer::trimmedStringOrNull(substr($ref, (int) strrpos($ref, '::') + 2)) ?? '';
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
        $class = ltrim(AiValueNormalizer::trimmedStringOrNull($fqn[self::FIELD_CLASS]) ?? '', '\\');
        $classPattern = preg_quote($class, '/');

        if (($fqn[self::FIELD_METHOD] ?? null) !== null && $fqn[self::FIELD_METHOD] !== '') {
            // Bind to the exact class::method end of the FQN.
            return '/'.$classPattern.'::'.preg_quote($fqn[self::FIELD_METHOD], '/').'$/';
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
            return $this->blockedRun(self::FIELD_EMPTY_FILTER);
        }

        if (! class_exists(Process::class)) {
            return $this->blockedRun(self::FIELD_PROCESS_UNAVAILABLE);
        }

        $binary = $this->phpunitBinary();
        if ($binary === null) {
            return $this->blockedRun(self::FIELD_PHPUNIT_BINARY_MISSING);
        }

        $command = [$binary];
        // Positional path arg (when given) lets --filter collect a file outside the
        // configured testsuites — only for explicit fixture-backed proof runs.
        if ($explicitPath !== null && (AiValueNormalizer::trimmedStringOrNull($explicitPath) ?? '') !== '' && is_file($explicitPath)) {
            $command[] = $explicitPath;
        }
        array_push($command, self::FIELD___FILTER, $filter, self::FIELD___NO_COVERAGE);

        $process = new Process(
            $command,
            base_path(),
            // Force a sqlite :memory: DB for the spawned suite so it never touches
            // the live pgsql runtime, mirroring the test harness env.
            [self::FIELD_DB_CONNECTION => self::FIELD_SQLITE, self::FIELD_DB_DATABASE => ':memory:'] + $this->inheritedEnv(),
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
            self::FIELD_RAN => true,
            self::FIELD_PASSED => $passed,
            self::FIELD_TESTS_RUN => $testsRun,
            self::FIELD_EXIT_CODE => $exitCode,
            self::FIELD_OUTPUT_TAIL => $this->tail($output),
            self::FIELD_RUNNER => $binary.' --filter '.$filter,
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
     * Turn an index-relative test file_path into an absolute path PHPUnit can collect.
     * Null/blank/missing paths degrade to null (filter-only fallback) — never invent a path.
     */
    private function absoluteTestPath(?string $relativeOrAbsolute): ?string
    {
        $path = AiValueNormalizer::trimmedStringOrNull($relativeOrAbsolute) ?? '';
        if ($path === '') {
            return null;
        }
        if (is_file($path)) {
            return $path;
        }
        $absolute = base_path($path);

        return is_file($absolute) ? $absolute : null;
    }

    /**
     * @return array<string,string>
     */
    private function inheritedEnv(): array
    {
        $env = [];
        foreach ([self::FIELD_PATH, self::FIELD_HOME, self::FIELD_APP_ENV] as $key) {
            $value = getenv($key);
            if (is_string($value) && $value !== '') {
                $env[$key] = $value;
            }
        }
        // Default the spawned suite to the testing env unless the operator set one.
        $env[self::FIELD_APP_ENV] = $env[self::FIELD_APP_ENV] ?? self::FIELD_TESTING;

        return $env;
    }

    private function commitStamp(): ?string
    {
        if (! class_exists(Process::class)) {
            return null;
        }
        try {
            $process = new Process([self::FIELD_GIT, '-C', base_path(), self::FIELD_REV_PARSE, '--short=12', self::FIELD_HEAD]);
            $process->setTimeout(10.0);
            $process->run();
            $stamp = AiValueNormalizer::trimmedStringOrNull($process->getOutput()) ?? '';

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
            self::FIELD_RAN => false,
            self::FIELD_PASSED => false,
            self::FIELD_TESTS_RUN => 0,
            self::FIELD_EXIT_CODE => -1,
            self::FIELD_OUTPUT_TAIL => 'blocked:'.$reason,
            self::FIELD_RUNNER => $reason,
            self::FIELD_REASON => $reason,
        ];
    }

    /**
     * FIX 2 — a declared test ref that does NOT resolve to a real indexed
     * Class/Class::method is AMBIGUOUS: it is never RUN (no broad short-name filter that
     * could green a same-named method in another class) and is returned passed=false with
     * reason=ambiguous_test_ref (not persisted — PIP-03), so it can never grant `verified`.
     *
     * @return array{ran:false,passed:false,tests_run:int,exit_code:int,output_tail:string,runner:string,reason:string}
     */
    private function ambiguousRun(string $testRef): array
    {
        return [
            self::FIELD_RAN => false,
            self::FIELD_PASSED => false,
            self::FIELD_TESTS_RUN => 0,
            self::FIELD_EXIT_CODE => -1,
            self::FIELD_OUTPUT_TAIL => 'ambiguous_test_ref: "'.$testRef.'" does not resolve to an indexed Class or Class::method — refusing to run a broad filter',
            self::FIELD_RUNNER => self::FIELD_AMBIGUOUS_TEST_REF,
            self::FIELD_REASON => self::FIELD_AMBIGUOUS_TEST_REF,
        ];
    }

    private function tail(string $output): string
    {
        $output = AiValueNormalizer::trimmedStringOrNull($output) ?? '';
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
                    self::FIELD_CAPABILITY_ID => AiValueNormalizer::trimmedScalarStringOrNull($payload[self::FIELD_CAPABILITY_ID] ?? null) ?? '',
                    self::FIELD_TEST_REF => AiValueNormalizer::trimmedScalarStringOrNull($payload[self::FIELD_TEST_REF] ?? null) ?? '',
                ],
                [
                    self::FIELD_FILTER => AiValueNormalizer::trimmedScalarStringOrNull($payload[self::FIELD_FILTER] ?? null) ?? '',
                    self::FIELD_PASSED => (AiValueNormalizer::boolOrNull($payload[self::FIELD_PASSED] ?? null) ?? false),
                    self::FIELD_TESTS_RUN => (int) (AiValueNormalizer::finiteFloatOrNull($payload[self::FIELD_TESTS_RUN] ?? null) ?? 0),
                    self::FIELD_EXIT_CODE => $payload[self::FIELD_EXIT_CODE] !== null ? (int) (AiValueNormalizer::finiteFloatOrNull($payload[self::FIELD_EXIT_CODE] ?? null) ?? 0) : null,
                    self::FIELD_COMMIT_STAMP => $payload[self::FIELD_COMMIT_STAMP],
                    // B3 freshness: bind the receipt to the code+test content it proved.
                    self::FIELD_TEST_FILE_HASH => $payload[self::FIELD_TEST_FILE_HASH] ?? null,
                    self::FIELD_IMPL_FILES_HASH => $payload[self::FIELD_IMPL_FILES_HASH] ?? null,
                    self::FIELD_OUTPUT_TAIL => $payload[self::FIELD_OUTPUT_TAIL],
                    // `runner` is a short audit breadcrumb in a varchar(120) column; an
                    // FQN-anchored --filter regex can exceed that, so cap it (never let an
                    // audit label fail the write that records the green run itself).
                    self::FIELD_RUNNER => mb_substr(AiValueNormalizer::trimmedScalarStringOrNull($payload[self::FIELD_RUNNER] ?? null) ?? '', 0, 120),
                    self::FIELD_RAN_AT => now(),
                ],
            );
        } catch (Throwable) {
            // Persistence failure must not crash the command; the payload is still returned.
        }
    }

    private function receiptsTableExists(): bool
    {
        return DatabaseTableAvailability::has(self::FIELD_ATLAS_AAEOS_TEST_RUN_RECEIPTS);
    }
}
