<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Intelligence;

use App\Services\Ai\Programming\AtlasDev\Intelligence\ReviewIntelligenceService;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ReviewFinding;
use App\Services\Ai\Programming\AtlasDev\Schemas\ReviewReceipt;
use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components\SchemaContractAssertions;

/**
 * Canonical contract tests for Atlas Dev Review Intelligence — first version.
 *
 * Covers the 7 audit-mandated scenarios from the prompt:
 *
 *   1. review findings com severity
 *   2. missing test detection
 *   3. regression/security/performance/data-loss risks
 *   4. file/line refs quando disponivel
 *   5. JSON estavel do receipt
 *   6. blocker explicito quando contexto insuficiente
 *   7. ordering por severity descending no emit
 */
final class ReviewIntelligenceServiceTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_blocker_when_no_changed_files_and_no_diff(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
        ]);

        $this->assertSame(ReviewReceipt::STATUS_BLOCKED_INSUFFICIENT_CONTEXT, $receipt->status);
        $this->assertContains('missing_changed_files_and_diff', $receipt->blockerReasons);
        $this->assertSame([], $receipt->findings);
        $this->assertSame(0.0, $receipt->confidence);
    }

    public function test_no_concerns_when_clean_diff_with_matching_test(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => [
                'app/Http/Controllers/HealthzController.php',
                'tests/Feature/HealthzControllerTest.php',
            ],
            'diff_chunks' => [
                ['file' => 'app/Http/Controllers/HealthzController.php', 'body' => 'public function index() { return response()->json(["ok" => true]); }', 'line' => 12],
            ],
            'test_paths' => ['tests/Feature/HealthzControllerTest.php'],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_NO_CONCERNS, $receipt->status);
        $this->assertSame([], $receipt->findings);
        $this->assertSame(0, $receipt->missingTestsCount);
    }

    public function test_missing_test_is_flagged_with_test_gap_true(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['app/Service.php'],
            'diff_chunks' => [
                ['file' => 'app/Service.php', 'body' => 'public function run() { return true; }', 'line' => 8],
            ],
            'test_paths' => [],
        ]);

        $this->assertSame(ReviewReceipt::STATUS_REVIEWED, $receipt->status);
        $this->assertCount(1, $receipt->findings);
        $finding = $receipt->findings[0];
        $this->assertTrue($finding->testGap);
        $this->assertSame(ReviewFinding::RISK_TEST_GAP, $finding->riskType);
        $this->assertSame('app/Service.php', $finding->file);
        $this->assertSame(1, $receipt->missingTestsCount);
    }

    public function test_detects_data_loss_keyword_with_critical_severity(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['database/migrations/2026_05_18_drop_table.php', 'tests/Feature/MigrationTest.php'],
            'diff_chunks' => [
                ['file' => 'database/migrations/2026_05_18_drop_table.php', 'body' => 'DB::statement("DROP TABLE users");', 'line' => 15],
            ],
            'test_paths' => ['tests/Feature/MigrationTest.php'],
        ]);

        $finding = $this->firstFindingOfType($receipt, ReviewFinding::RISK_DATA_LOSS);
        $this->assertNotNull($finding);
        $this->assertSame(ReviewFinding::SEVERITY_CRITICAL, $finding->severity);
        $this->assertSame(15, $finding->line);
        $this->assertSame('database/migrations/2026_05_18_drop_table.php', $finding->file);
    }

    public function test_detects_secret_leak_file_with_blocker_severity(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['.env', 'tests/Feature/Test.php'],
            'diff_chunks' => [],
            'test_paths' => ['tests/Feature/Test.php'],
        ]);

        $finding = $this->firstFindingOfType($receipt, ReviewFinding::RISK_SECRET_LEAK);
        $this->assertNotNull($finding);
        $this->assertSame(ReviewFinding::SEVERITY_BLOCKER, $finding->severity);
        $this->assertSame(ReviewReceipt::STATUS_ESCALATE, $receipt->status);
    }

    public function test_detects_scope_violation_against_allowed_files(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['app/Allowed.php', 'app/Forbidden.php'],
            'diff_chunks' => [
                ['file' => 'app/Allowed.php', 'body' => 'class Allowed {}', 'line' => 1],
                ['file' => 'app/Forbidden.php', 'body' => 'class Forbidden {}', 'line' => 1],
            ],
            'test_paths' => ['tests/Feature/AllowedTest.php', 'tests/Feature/ForbiddenTest.php'],
            'allowed_files' => ['app/Allowed.php'],
        ]);

        $scopeFinding = $this->firstFindingOfType($receipt, ReviewFinding::RISK_SCOPE_VIOLATION);
        $this->assertNotNull($scopeFinding);
        $this->assertSame('app/Forbidden.php', $scopeFinding->file);
        $this->assertSame(ReviewFinding::SEVERITY_HIGH, $scopeFinding->severity);
    }

    public function test_forbidden_files_get_blocker_severity(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['config/secrets.php'],
            'diff_chunks' => [['file' => 'config/secrets.php', 'body' => 'return [];', 'line' => 1]],
            'forbidden_files' => ['config/*'],
        ]);

        $finding = $this->firstFindingOfType($receipt, ReviewFinding::RISK_SCOPE_VIOLATION);
        $this->assertNotNull($finding);
        $this->assertSame(ReviewFinding::SEVERITY_BLOCKER, $finding->severity);
    }

    public function test_findings_are_sorted_by_severity_blockers_first(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['.env', 'app/Service.php', 'tests/Feature/ServiceTest.php'],
            'diff_chunks' => [
                ['file' => 'app/Service.php', 'body' => 'while (true) { sleep(1); }', 'line' => 10],
            ],
            'test_paths' => ['tests/Feature/ServiceTest.php'],
        ]);

        $this->assertGreaterThanOrEqual(2, count($receipt->findings));
        $first = $receipt->findings[0];
        // First finding should be the secret_leak (blocker), not the perf one (medium).
        $this->assertContains($first->severity, [
            ReviewFinding::SEVERITY_BLOCKER,
            ReviewFinding::SEVERITY_CRITICAL,
        ]);
        $this->assertSame(ReviewFinding::RISK_SECRET_LEAK, $first->riskType);
    }

    public function test_operator_risk_rule_is_emitted_with_supplied_severity(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['app/Billing/InvoiceService.php', 'tests/Feature/Billing/InvoiceServiceTest.php'],
            'diff_chunks' => [['file' => 'app/Billing/InvoiceService.php', 'body' => 'public function charge() {}', 'line' => 5]],
            'test_paths' => ['tests/Feature/Billing/InvoiceServiceTest.php'],
            'risk_rules' => [[
                'risk_type' => ReviewFinding::RISK_REGRESSION,
                'severity' => ReviewFinding::SEVERITY_HIGH,
                'file_glob' => 'app/Billing/*',
                'message' => 'Any Billing change requires sign-off from finance.',
                'remediation' => 'Add finance reviewer to the PR.',
            ]],
        ]);

        $finding = $this->firstFindingOfType($receipt, ReviewFinding::RISK_REGRESSION);
        $this->assertNotNull($finding);
        $this->assertSame(ReviewFinding::SEVERITY_HIGH, $finding->severity);
    }

    public function test_json_roundtrip_is_stable(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
            'changed_files' => ['app/Service.php'],
            'diff_chunks' => [['file' => 'app/Service.php', 'body' => 'public function run() { return true; }', 'line' => 8]],
        ]);

        $this->assertContractSurface($receipt);
        $hydrated = ReviewReceipt::fromArray($receipt->toCanonicalArray());
        $this->assertSame($receipt->toCanonicalArray(), $hydrated->toCanonicalArray());
        $this->assertSame($receipt->hash(), $hydrated->hash());
    }

    public function test_blocker_receipt_passes_contract_surface(): void
    {
        $receipt = (new ReviewIntelligenceService)->analyse([
            'run_id' => 'run-1',
        ]);

        $this->assertContractSurface($receipt);
    }

    private function firstFindingOfType(ReviewReceipt $receipt, string $riskType): ?ReviewFinding
    {
        foreach ($receipt->findings as $f) {
            if ($f->riskType === $riskType) {
                return $f;
            }
        }

        return null;
    }
}
