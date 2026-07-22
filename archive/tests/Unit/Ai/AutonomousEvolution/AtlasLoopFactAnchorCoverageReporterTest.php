<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorCoverageReporter;
use App\Services\Ai\AutonomousEvolution\SymbolicAnchoring\AtlasLoopFactAnchorExtractor;
use Tests\TestCase;

final class AtlasLoopFactAnchorCoverageReporterTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-anchor-cov-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/app/Demo', 0o755, true);
        for ($i = 1; $i <= 3; $i++) {
            file_put_contents($this->root.'/app/Demo/F'.$i.'.php', "<?php\n");
        }
    }

    protected function tearDown(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            @unlink($this->root.'/app/Demo/F'.$i.'.php');
        }
        @rmdir($this->root.'/app/Demo');
        @rmdir($this->root.'/app');
        @rmdir($this->root);
        parent::tearDown();
    }

    private function reporter(): AtlasLoopFactAnchorCoverageReporter
    {
        return new AtlasLoopFactAnchorCoverageReporter(new AtlasLoopFactAnchorExtractor($this->root));
    }

    public function test_window_of_10_with_6_required_and_4_anchored_yields_6667_pct(): void
    {
        $window = [
            // Critical (anchor_required=true), 4 anchored, 2 unanchored.
            ['text' => 'See app/Demo/F1.php', 'anchor_required' => true, 'source' => 'comprehension'],
            ['text' => 'See app/Demo/F2.php', 'anchor_required' => true, 'source' => 'comprehension'],
            ['text' => 'See app/Demo/F3.php', 'anchor_required' => true, 'source' => 'comprehension'],
            ['text' => 'See app/Demo/F1.php and app/Demo/F2.php', 'anchor_required' => true, 'source' => 'decision'],
            ['text' => 'narrative critical without anchors', 'anchor_required' => true, 'source' => 'decision'],
            ['text' => 'critical with phantom app/Phantom/Missing.php', 'anchor_required' => true, 'source' => 'decision'],
            // Narrative (excluded), 4 entries.
            ['text' => 'narrative 1', 'anchor_required' => false, 'source' => 'operator'],
            ['text' => 'narrative 2', 'anchor_required' => false, 'source' => 'operator'],
            ['text' => 'narrative 3 references app/Demo/F3.php', 'anchor_required' => false, 'source' => 'operator'],
            ['text' => 'narrative 4', 'anchor_required' => false, 'source' => 'operator'],
        ];

        $verdict = $this->reporter()->report($window);

        $this->assertSame(10, $verdict['total_facts']);
        $this->assertSame(6, $verdict['critical_facts_total']);
        $this->assertSame(4, $verdict['anchored_facts_in_critical']);
        $this->assertSame(66.67, $verdict['coverage_pct']);
        $this->assertSame(4, $verdict['excluded_narrative']);

        $hist = $verdict['anchor_density_histogram'];
        $this->assertSame($hist['0'] + $hist['1'] + $hist['2'] + $hist['3+'], 10);
    }

    public function test_emitted_fact_has_no_score_grade_or_rating_key(): void
    {
        $verdict = $this->reporter()->report([]);
        $this->assertSame('ANCHOR_COVERAGE_FACT', $verdict['type']);
        foreach (array_keys($verdict) as $key) {
            $this->assertDoesNotMatchRegularExpression('/score|grade|rating/i', (string) $key);
        }
    }

    public function test_empty_window_yields_zero_coverage(): void
    {
        $verdict = $this->reporter()->report([]);
        $this->assertSame(0, $verdict['total_facts']);
        $this->assertSame(0.0, $verdict['coverage_pct']);
    }

    public function test_all_anchored_yields_100pct(): void
    {
        $verdict = $this->reporter()->report([
            ['text' => 'app/Demo/F1.php', 'anchor_required' => true],
            ['text' => 'app/Demo/F2.php', 'anchor_required' => true],
        ]);
        $this->assertSame(100.0, $verdict['coverage_pct']);
    }

    public function test_none_anchored_yields_zero_pct(): void
    {
        $verdict = $this->reporter()->report([
            ['text' => 'nothing here', 'anchor_required' => true],
            ['text' => 'still nothing', 'anchor_required' => true],
        ]);
        $this->assertSame(0.0, $verdict['coverage_pct']);
    }

    public function test_phantom_only_window_reports_unresolved_count(): void
    {
        $verdict = $this->reporter()->report([
            ['text' => 'app/Phantom/Missing1.php', 'anchor_required' => true],
            ['text' => 'app/Phantom/Missing2.php', 'anchor_required' => true],
        ]);
        $this->assertSame(0.0, $verdict['coverage_pct']);
        $this->assertSame(2, $verdict['unresolved_phantom_facts']);
    }
}
