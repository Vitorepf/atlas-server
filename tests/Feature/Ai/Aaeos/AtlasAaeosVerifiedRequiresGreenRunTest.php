<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AtlasAaeosTestRunReceipt;
use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Ai\Aaeos\AtlasAaeosTestExecutionService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * B3 / criterion C2 — the FAIL-ON-STUB proof that `verified` means a REAL test ran
 * GREEN, not that a *Test* symbol merely EXISTS.
 *
 * KEY anti-stub (test_verified_requires_green_run_*): a capability whose named test
 * EXISTS but has NO green-run receipt computes NOT verified (partial,
 * test_resolution=existence_only_unrun). The SAME capability, after a recorded GREEN
 * receipt, computes verified. If the old existence-only=verified logic were restored,
 * case (1) would flip to verified and these tests FAIL — which is the point.
 *
 * sqlite :memory:, NO RefreshDatabase. Only the two tables this proof needs are built,
 * via the real migrations' up().
 */
final class AtlasAaeosVerifiedRequiresGreenRunTest extends TestCase
{
    private const CAPABILITY = 'atlas-aaeos-green-run-proof';

    private const TEST_REF = 'AtlasAaeosImplementationTruthServiceTest';

    protected function setUp(): void
    {
        parent::setUp();

        $this->runMigration('2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php');
        $this->runMigration('2026_06_02_090000_create_atlas_aaeos_test_run_receipts_table.php');

        $this->assertTrue(Schema::hasTable('atlas_engineering_code_symbols'));
        $this->assertTrue(Schema::hasTable('atlas_aaeos_test_run_receipts'));

        // Seed the index so the capability's refs RESOLVE (existence-only): a real
        // class symbol, a CLI command (wiring), and a *Test* symbol (existence match).
        $this->seedSymbol('class', 'App\\Services\\Ai\\Aaeos\\AtlasAaeosImplementationTruthService', 'class-1');
        $this->seedSymbol('cli_command', 'atlas:aaeos:maturity', 'cmd-1');
        $this->seedSymbol(
            'test_method',
            'Tests\\Unit\\Ai\\Aaeos\\'.self::TEST_REF.'::test_under_claim_is_not_drift',
            'test-1',
        );
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');

        parent::tearDown();
    }

    /**
     * CASE 1 (the anti-stub): test EXISTS, NO green receipt => NOT verified.
     * A failing-but-present test graded verified is the bug; this must catch it.
     */
    public function test_verified_requires_green_run_existing_test_without_receipt_is_not_verified(): void
    {
        $this->assertNoGreenReceipt();

        $result = $this->computeProofCapability();

        // The test symbol RESOLVES (existence) ...
        $this->assertTrue($result['resolved']['test'], 'the *Test* symbol must resolve by existence');
        // ... but is NOT green-backed, so the tier is partial, not verified.
        $this->assertSame('partial', $result['computed_state']);
        $this->assertFalse($result['resolved']['test_green']);
        $this->assertSame('existence_only_unrun', $result['test_resolution']);
        $this->assertContains(
            'needs >=1 test that RAN GREEN for verified — a test symbol resolves but has no green-run receipt (run atlas:aaeos:verify-tests)',
            $result['unmet_evidence'],
        );

        // Ledger-level: this capability does NOT count toward by_computed_state.verified.
        $byComputed = $this->proofCapabilityByComputedState();
        $this->assertSame(0, $byComputed['verified'], 'existence-only must not be counted verified');
        $this->assertSame(1, $byComputed['partial']);
    }

    /**
     * CASE 2: the SAME capability after a recorded GREEN receipt => verified.
     */
    public function test_verified_reached_after_green_run_receipt_recorded(): void
    {
        $this->recordGreenReceipt(self::CAPABILITY, self::TEST_REF, passed: true, testsRun: 3);

        $result = $this->computeProofCapability();

        $this->assertTrue($result['resolved']['test_green']);
        $this->assertSame('green_run', $result['test_resolution']);
        $this->assertSame('verified', $result['computed_state']);
        $this->assertSame([], $result['unmet_evidence']);

        $byComputed = $this->proofCapabilityByComputedState();
        $this->assertSame(1, $byComputed['verified'], 'a green-backed capability counts verified');
    }

    /**
     * A RECORDED-BUT-FAILING run (passed=false) is NOT a green receipt => still partial.
     * Distinguishes "a run happened" from "a run passed".
     */
    public function test_recorded_failing_run_does_not_reach_verified(): void
    {
        $this->recordGreenReceipt(self::CAPABILITY, self::TEST_REF, passed: false, testsRun: 1);

        $execution = new AtlasAaeosTestExecutionService;
        $this->assertFalse($execution->hasGreenReceipt(self::CAPABILITY, self::TEST_REF));

        $result = $this->computeProofCapability();
        $this->assertSame('partial', $result['computed_state']);
        $this->assertFalse($result['resolved']['test_green']);
    }

    /**
     * A run that recorded passed=true but tests_run=0 ("No tests executed") is NOT green.
     */
    public function test_zero_tests_run_is_never_green(): void
    {
        $this->recordGreenReceipt(self::CAPABILITY, self::TEST_REF, passed: true, testsRun: 0);

        $execution = new AtlasAaeosTestExecutionService;
        $this->assertFalse(
            $execution->hasGreenReceipt(self::CAPABILITY, self::TEST_REF),
            'passed=true with 0 tests executed must not count as green',
        );
    }

    /**
     * CASE 3: existence-only alone via the pure evaluate() path (symbol+wiring+test+
     * receipt resolve, but greenTestRun is false/null) => NEVER verified.
     */
    public function test_existence_only_pure_evaluate_never_verified(): void
    {
        $resolutions = [
            $this->res('symbol', true),
            $this->res('command', true),
            $this->res('test', true),
            $this->res('receipt', true),
        ];

        $service = $this->service();

        // greenTestRun = false (existence-only): the exact old-bug input.
        $existenceOnly = $service->evaluate('runtime_verified', $resolutions, false);
        $this->assertSame('partial', $existenceOnly['computed_state'], 'existence-only must not be verified');
        $this->assertSame('existence_only_unrun', $existenceOnly['test_resolution']);
        $this->assertTrue($existenceOnly['drift'], 'claiming verified on existence-only is an over-claim');

        // greenTestRun = null (UNKNOWN / table absent): degrade-safe, also not verified.
        $unknown = $service->evaluate('runtime_verified', $resolutions, null);
        $this->assertSame('partial', $unknown['computed_state'], 'unknown green-ness must fail toward partial');

        // greenTestRun = true: now (and only now) verified.
        $green = $service->evaluate('runtime_verified', $resolutions, true);
        $this->assertSame('verified', $green['computed_state']);
        $this->assertSame('green_run', $green['test_resolution']);
    }

    /**
     * Degrade-safe: with the receipts table DROPPED, the green lookup returns false and
     * no capability is verified-by-existence (fails toward partial — never silently keeps
     * existence-only=verified).
     */
    public function test_missing_receipts_table_fails_toward_partial(): void
    {
        Schema::dropIfExists('atlas_aaeos_test_run_receipts');
        $this->assertFalse(Schema::hasTable('atlas_aaeos_test_run_receipts'));

        $execution = new AtlasAaeosTestExecutionService;
        $this->assertFalse($execution->hasGreenReceipt(self::CAPABILITY, self::TEST_REF));

        $result = $this->computeProofCapability();
        $this->assertSame('partial', $result['computed_state']);
        $this->assertFalse($result['resolved']['test_green']);
    }

    /**
     * END-TO-END receipt path with a REAL PHPUnit subprocess against the controlled
     * fixture: a genuinely PASSING test records passed=true (green), a genuinely FAILING
     * test records passed=false (not green). Proves the runner never fabricates green for
     * a present-but-failing test.
     *
     * @group slow
     */
    public function test_runner_records_green_for_real_pass_and_not_green_for_real_failure(): void
    {
        $fixture = base_path('tests/Fixtures/Aaeos/AtlasAaeosGreenRunProofFixtureTest.php');
        $this->assertFileExists($fixture);

        $execution = new AtlasAaeosTestExecutionService(timeout: 120.0);

        $pass = $execution->runAndRecord(self::CAPABILITY, 'test_atlas_green_proof_passes', $fixture);
        if (($pass['ran'] ?? false) !== true) {
            $this->markTestSkipped('PHPUnit subprocess unavailable in this environment: '.($pass['output_tail'] ?? ''));
        }
        $this->assertTrue($pass['passed'], 'a real passing test must record passed=true. tail: '.($pass['output_tail'] ?? ''));
        $this->assertGreaterThanOrEqual(1, $pass['tests_run']);
        $this->assertSame(0, $pass['exit_code']);
        $this->assertTrue(
            (new AtlasAaeosTestExecutionService)->hasGreenReceipt(self::CAPABILITY, 'test_atlas_green_proof_passes'),
        );

        $fail = $execution->runAndRecord(self::CAPABILITY, 'test_atlas_green_proof_fails', $fixture);
        $this->assertTrue($fail['ran'] ?? false);
        $this->assertFalse($fail['passed'], 'a real FAILING test must record passed=false. tail: '.($fail['output_tail'] ?? ''));
        $this->assertNotSame(0, $fail['exit_code']);
        $this->assertFalse(
            (new AtlasAaeosTestExecutionService)->hasGreenReceipt(self::CAPABILITY, 'test_atlas_green_proof_fails'),
            'a present-but-failing test must not produce a green receipt',
        );
    }

    /**
     * Idempotency: re-running the same (capability, test_ref) UPDATES one row, never duplicates.
     */
    public function test_receipt_is_idempotent_per_capability_and_test_ref(): void
    {
        $this->recordGreenReceipt(self::CAPABILITY, self::TEST_REF, passed: false, testsRun: 0);
        $this->recordGreenReceipt(self::CAPABILITY, self::TEST_REF, passed: true, testsRun: 2);

        $rows = AtlasAaeosTestRunReceipt::query()
            ->where('capability_id', self::CAPABILITY)
            ->where('test_ref', self::TEST_REF)
            ->get();

        $this->assertCount(1, $rows, 're-run must update in place, not duplicate');
        $this->assertTrue((bool) $rows->first()->passed);
        $this->assertSame(2, (int) $rows->first()->tests_run);
    }

    // ---- helpers -------------------------------------------------------------

    /**
     * Compute the proof capability's tier through compute() (the live path that
     * consults the green-receipt gate via the seeded resolver + receipts table).
     *
     * @return array<string,mixed>
     */
    private function computeProofCapability(): array
    {
        return $this->service()->compute(
            'runtime_verified',
            [
                ['kind' => 'symbol', 'ref' => 'AtlasAaeosImplementationTruthService'],
                ['kind' => 'command', 'ref' => 'atlas:aaeos:maturity'],
                ['kind' => 'test', 'ref' => self::TEST_REF],
                // A present receipt file (this very test file) satisfies the receipt kind.
                ['kind' => 'receipt', 'ref' => 'tests/Feature/Ai/Aaeos/AtlasAaeosVerifiedRequiresGreenRunTest.php'],
            ],
            self::CAPABILITY,
        );
    }

    /**
     * Mirror the ledger's by_computed_state tally for just the proof capability.
     *
     * @return array{spec:int,partial:int,verified:int}
     */
    private function proofCapabilityByComputedState(): array
    {
        $tally = ['spec' => 0, 'partial' => 0, 'verified' => 0];
        $tally[$this->computeProofCapability()['computed_state']]++;

        return $tally;
    }

    private function assertNoGreenReceipt(): void
    {
        $this->assertFalse((new AtlasAaeosTestExecutionService)->hasGreenReceipt(self::CAPABILITY, self::TEST_REF));
    }

    private function recordGreenReceipt(string $capabilityId, string $testRef, bool $passed, int $testsRun): void
    {
        AtlasAaeosTestRunReceipt::query()->updateOrCreate(
            ['capability_id' => $capabilityId, 'test_ref' => $testRef],
            [
                'filter' => $testRef,
                'passed' => $passed,
                'tests_run' => $testsRun,
                'exit_code' => $passed ? 0 : 1,
                'commit_stamp' => 'teststamp01',
                'output_tail' => $passed ? 'OK (3 tests)' : 'FAILURES!',
                'runner' => 'phpunit',
                'ran_at' => now(),
            ],
        );
    }

    private function seedSymbol(string $type, string $name, string $hashSuffix): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => 'tests/seed/'.$hashSuffix.'.php',
            'language' => 'php',
            'status' => 'active',
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.$hashSuffix,
        ]);
    }

    /**
     * @return array{kind:string, ref:string, resolved:bool, matched:?string}
     */
    private function res(string $kind, bool $resolved): array
    {
        return [
            'kind' => $kind,
            'ref' => $kind.'-ref',
            'resolved' => $resolved,
            'matched' => $resolved ? 'Matched\\'.$kind : null,
        ];
    }

    private function service(): AtlasAaeosImplementationTruthService
    {
        return new AtlasAaeosImplementationTruthService(
            new AtlasAaeosImplementationEvidenceResolver,
            new CanonicalDocsFrontmatterParser,
            new AtlasAaeosTestExecutionService,
        );
    }

    private function runMigration(string $file): void
    {
        $migration = require base_path('database/migrations/'.$file);
        $migration->up();
    }
}
