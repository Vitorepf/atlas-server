<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\Comprehension\Capabilities\VentureImprovementScannerService;
use App\Services\Ai\VentureFoundry\Comprehension\ComprehensionRecorder;
use App\Services\Ai\VentureFoundry\Comprehension\WorkspaceReader;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class VentureImprovementScannerTest extends TestCase
{
    use CreatesVentureComprehensionTables;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        parent::tearDown();
    }

    private function makeRun(): AiVentureComprehensionRun
    {
        return AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => (string) Str::uuid(),
            'workspace_path' => $this->ws,
            'status' => 'running',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => (string) Str::uuid()]),
        ]);
    }

    private function service(): VentureImprovementScannerService
    {
        return new VentureImprovementScannerService(new ComprehensionRecorder);
    }

    public function test_capability_id_is_improvement(): void
    {
        $this->assertSame(
            AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT,
            $this->service()->capability(),
        );
    }

    public function test_standalone_fresh_run_produces_high_leverage_security_hardening_for_hardcoded_secret(): void
    {
        $run = $this->makeRun(); // fresh run, no pre-seeded problems
        $reader = new WorkspaceReader($this->ws);

        $report = $this->service()->scan($run, $reader);

        // Report shape.
        $this->assertSame(AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT, $report['capability']);
        $this->assertGreaterThan(0, $report['total']);
        $this->assertArrayHasKey('by_area', $report);
        $this->assertArrayHasKey('top_leverage', $report);

        // A security_hardening improvement was produced for the hardcoded secret.
        $security = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('capability', AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT)
            ->where('category', VentureImprovementScannerService::AREA_SECURITY_HARDENING)
            ->first();

        $this->assertNotNull($security, 'a security_hardening improvement must be produced');
        $this->assertGreaterThanOrEqual(3.0, (float) $security->leverage_score, 'secret fix must be high leverage');
        $this->assertSame('app/Http/Controllers/BillingController.php', $security->evidence_path);
        $this->assertSame(12, (int) $security->evidence_line, 'cites the hardcoded sk_live_ line');
        $this->assertStringContainsString('REDACTED', (string) $security->evidence_snippet, 'secret must be redacted in evidence');
        $this->assertNotEmpty($security->evidence_refs);

        // impact/effort/leverage present and numeric on every finding.
        $all = AiVentureComprehensionFinding::query()->where('run_id', $run->id)->get();
        foreach ($all as $finding) {
            $this->assertIsNumeric($finding->impact_score);
            $this->assertIsNumeric($finding->effort_score);
            $this->assertIsNumeric($finding->leverage_score);
            $this->assertGreaterThan(0, (float) $finding->impact_score);
            $this->assertGreaterThan(0, (float) $finding->effort_score);
            // leverage == round(impact/effort, 3)
            $this->assertEqualsWithDelta(
                round((float) $finding->impact_score / (float) $finding->effort_score, 3),
                (float) $finding->leverage_score,
                0.0005,
                'leverage must equal round(impact/effort, 3)',
            );
        }
    }

    public function test_top_leverage_is_sorted_descending_and_secret_leads(): void
    {
        $run = $this->makeRun();
        $reader = new WorkspaceReader($this->ws);

        $report = $this->service()->scan($run, $reader);

        $top = $report['top_leverage'];
        $this->assertNotEmpty($top);

        // Monotonic non-increasing leverage in the reported top list.
        $prev = INF;
        foreach ($top as $row) {
            $this->assertIsNumeric($row['leverage']);
            $this->assertLessThanOrEqual($prev, (float) $row['leverage'], 'top_leverage must be sorted desc');
            $prev = (float) $row['leverage'];
        }

        // The hardcoded secret (impact 5 / effort 1 = leverage 5) leads.
        $this->assertSame(VentureImprovementScannerService::AREA_SECURITY_HARDENING, $top[0]['area']);
        $this->assertEqualsWithDelta(5.0, (float) $top[0]['leverage'], 0.0005);

        // Persisted rows agree: the global max leverage is the security finding.
        $maxLeverage = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->max('leverage_score');
        $leader = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->orderByDesc('leverage_score')
            ->first();
        $this->assertEqualsWithDelta(5.0, (float) $maxLeverage, 0.0005);
        $this->assertSame(VentureImprovementScannerService::AREA_SECURITY_HARDENING, $leader->category);
    }

    public function test_every_finding_is_cited_with_path_and_line(): void
    {
        $run = $this->makeRun();
        $reader = new WorkspaceReader($this->ws);

        $this->service()->scan($run, $reader);

        $findings = AiVentureComprehensionFinding::query()->where('run_id', $run->id)->get();
        $this->assertNotEmpty($findings);
        foreach ($findings as $finding) {
            $this->assertNotNull($finding->evidence_path, "finding [{$finding->title}] must cite a path");
            $this->assertNotNull($finding->evidence_line, "finding [{$finding->title}] must cite a line");
            $this->assertGreaterThan(0, (int) $finding->evidence_line);
            $this->assertNotEmpty($finding->evidence_refs, "finding [{$finding->title}] must carry evidence refs");
        }
    }

    public function test_produces_test_coverage_improvement_for_untested_classes(): void
    {
        $run = $this->makeRun();
        $reader = new WorkspaceReader($this->ws);

        $this->service()->scan($run, $reader);

        // BillingController and CommissionService have no *Test in the fake repo.
        $coverage = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('category', VentureImprovementScannerService::AREA_TEST_COVERAGE)
            ->get();

        $this->assertNotEmpty($coverage, 'untested Controllers/Services must yield test_coverage improvements');
        $cited = $coverage->pluck('evidence_path')->all();
        $this->assertContains('app/Http/Controllers/BillingController.php', $cited);
    }

    public function test_works_standalone_on_a_brand_new_run_with_no_problems(): void
    {
        // Distinct fresh run/workspace with ZERO pre-seeded 'problem' findings.
        $run = $this->makeRun();
        $this->assertSame(0, AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('capability', AiVentureComprehensionFinding::CAPABILITY_PROBLEM)
            ->count());

        $reader = new WorkspaceReader($this->ws);
        $report = $this->service()->scan($run, $reader);

        $this->assertGreaterThan(0, $report['total'], 'must work standalone by scanning the workspace directly');
    }
}
