<?php

namespace Tests\Feature\Ai\Company\Ventures\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureBusinessRuleMinerService;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionRecorder;
use App\Services\Ai\Company\Ventures\Comprehension\WorkspaceReader;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class VentureBusinessRuleMinerTest extends TestCase
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

    private function service(): VentureBusinessRuleMinerService
    {
        return new VentureBusinessRuleMinerService(new ComprehensionRecorder);
    }

    public function test_threshold_and_multiplier_decimals_are_not_mined_as_prices(): void
    {
        // Real Blackink false-positive shapes: tolerance thresholds and
        // arithmetic factors match the money regex but are NOT prices and must
        // never pollute the pricing canon.
        @mkdir($this->ws.'/app/Console/Commands', 0777, true);
        file_put_contents($this->ws.'/app/Console/Commands/DiagnoseSubscriptions.php', <<<'PHP'
<?php
class DiagnoseSubscriptions {
    public function handle($diff, $price, $amount, $intervalCount) {
        if (abs($diff) > 0.01) { return "*** DIFF"; }
        $week = $amount * 4.33 / $intervalCount;
        $yearly = $price * 12 * 0.85;
        return [$week, $yearly];
    }
}
PHP);

        $run = $this->makeRun();
        $this->service()->scan($run, new WorkspaceReader($this->ws));

        $pricing = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('capability', 'business_rule')
            ->where('category', 'pricing')
            ->get();

        foreach ($pricing as $rule) {
            $this->assertStringNotContainsString('DiagnoseSubscriptions', (string) $rule->evidence_path, 'threshold/multiplier decimals must not be mined as prices');
        }
        // The genuine plan prices in config/plans.php are still mined.
        $this->assertTrue(
            $pricing->contains(fn ($r) => str_contains((string) $r->evidence_path, 'config/plans.php')),
            'real config prices must still be mined',
        );
    }

    public function test_capability_is_business_rule(): void
    {
        $this->assertSame(
            AiVentureComprehensionFinding::CAPABILITY_BUSINESS_RULE,
            $this->service()->capability(),
        );
    }

    public function test_mines_pricing_rule_cited_to_config(): void
    {
        $run = $this->makeRun();
        $report = $this->service()->scan($run, new WorkspaceReader($this->ws));

        $this->assertSame(AiVentureComprehensionFinding::CAPABILITY_BUSINESS_RULE, $report['capability']);
        $this->assertGreaterThan(0, $report['total']);
        $this->assertArrayHasKey('pricing', $report['by_category']);

        $pricing = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('category', 'pricing')
            ->get();

        // The 149.90 BRL plan price must be found AND cited to config/plans.php
        // (the canonical config source, not the controller copy of the same number).
        $raso = $pricing->first(function (AiVentureComprehensionFinding $f): bool {
            return $f->evidence_path === 'config/plans.php'
                && str_contains((string) $f->evidence_snippet, '149.90');
        });

        $this->assertNotNull($raso, 'the 149.90 pricing rule must be mined from config/plans.php');
        $this->assertSame('config/plans.php', $raso->evidence_path);
        $this->assertNotNull($raso->evidence_line);
        $this->assertGreaterThan(0, $raso->evidence_line);
        $this->assertStringContainsString('149.90', (string) $raso->evidence_snippet);
        $this->assertSame('business_rule', $raso->capability);
        $this->assertSame('deterministic', $raso->source);
        $this->assertEqualsWithDelta(0.9, (float) $raso->confidence, 0.001);

        // All three plan prices live in config/plans.php — confirm we caught them.
        $amounts = $pricing
            ->where('evidence_path', 'config/plans.php')
            ->map(fn (AiVentureComprehensionFinding $f) => (string) $f->evidence_snippet)
            ->implode(' ');
        $this->assertStringContainsString('149.90', $amounts);
        $this->assertStringContainsString('249.99', $amounts);
        $this->assertStringContainsString('399.99', $amounts);

        // The label was extracted from the array key.
        $this->assertStringContainsString("raso", (string) $raso->title);
        $this->assertStringContainsString('BRL', (string) $raso->title);
    }

    public function test_mines_trial_days_seven(): void
    {
        $run = $this->makeRun();
        $this->service()->scan($run, new WorkspaceReader($this->ws));

        $trial = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('category', 'trial')
            ->first();

        $this->assertNotNull($trial, 'trial_days rule must be mined');
        $this->assertSame('config/plans.php', $trial->evidence_path);
        $this->assertStringContainsString('7', (string) $trial->title);
        $this->assertStringContainsString('trial_days', (string) $trial->evidence_snippet);
        $this->assertEqualsWithDelta(0.9, (float) $trial->confidence, 0.001);
        $this->assertSame(7, $trial->payload['trial_days'] ?? null);
    }

    public function test_mines_commission_rate_const(): void
    {
        $run = $this->makeRun();
        $this->service()->scan($run, new WorkspaceReader($this->ws));

        $commission = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('category', 'commission')
            ->first();

        $this->assertNotNull($commission, 'commission RATE rule must be mined');
        $this->assertSame('app/Services/CommissionService.php', $commission->evidence_path);
        $this->assertStringContainsString('RATE', (string) $commission->evidence_snippet);
        $this->assertStringContainsString('0.30', (string) $commission->evidence_snippet);
        $this->assertEqualsWithDelta(0.9, (float) $commission->confidence, 0.001);
        $this->assertStringContainsString('30%', (string) $commission->title);
    }

    public function test_every_finding_is_cited(): void
    {
        $run = $this->makeRun();
        $this->service()->scan($run, new WorkspaceReader($this->ws));

        $findings = AiVentureComprehensionFinding::query()->where('run_id', $run->id)->get();
        $this->assertGreaterThan(0, $findings->count());

        foreach ($findings as $finding) {
            $this->assertNotNull($finding->evidence_path, 'finding must cite a path: '.$finding->title);
            $this->assertNotNull($finding->evidence_line, 'finding must cite a line: '.$finding->title);
            $this->assertGreaterThan(0, $finding->evidence_line);
            $this->assertNotSame('', (string) $finding->evidence_snippet, 'finding must carry a snippet: '.$finding->title);
            $this->assertStringNotContainsString('vendor/', (string) $finding->evidence_path, 'must never cite vendor/');
        }
    }

    public function test_candidates_returns_high_confidence_rules(): void
    {
        $run = $this->makeRun();
        $this->service()->scan($run, new WorkspaceReader($this->ws));

        $candidates = $this->service()->candidates($run);

        $this->assertGreaterThanOrEqual(2, count($candidates), 'expected >=2 high-confidence promotable rules');

        foreach ($candidates as $candidate) {
            $this->assertArrayHasKey('category', $candidate);
            $this->assertArrayHasKey('statement', $candidate);
            $this->assertArrayHasKey('evidence_path', $candidate);
            $this->assertArrayHasKey('evidence_line', $candidate);
            $this->assertArrayHasKey('confidence', $candidate);
            $this->assertGreaterThanOrEqual(0.8, $candidate['confidence']);
            $this->assertNotSame('', (string) $candidate['statement']);
            $this->assertNotNull($candidate['evidence_path']);
        }

        $categories = array_column($candidates, 'category');
        $this->assertContains('pricing', $categories);
    }

    public function test_report_shape_is_complete(): void
    {
        $run = $this->makeRun();
        $report = $this->service()->scan($run, new WorkspaceReader($this->ws));

        $this->assertArrayHasKey('capability', $report);
        $this->assertArrayHasKey('total', $report);
        $this->assertArrayHasKey('by_category', $report);
        $this->assertArrayHasKey('high_confidence_count', $report);
        $this->assertArrayHasKey('top', $report);
        $this->assertIsArray($report['by_category']);
        $this->assertIsArray($report['top']);
        $this->assertGreaterThanOrEqual(2, $report['high_confidence_count']);

        // Top is confidence-sorted descending.
        $confidences = array_column($report['top'], 'confidence');
        $sorted = $confidences;
        rsort($sorted);
        $this->assertSame($sorted, $confidences);
    }
}
