<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopConsolidationCertifier;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopSelfArchitectureScanner;
use App\Services\Ai\AutonomousEvolution\Consolidation\AtlasLoopSelfDependencyGraphReporter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopConsolidationCertifierTest extends TestCase
{
    private string $baselineFile = '';

    private string $fixtureDir = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->baselineFile = sys_get_temp_dir().'/atlas-certifier-baseline-'.bin2hex(random_bytes(4)).'.json';
        $this->fixtureDir = sys_get_temp_dir().'/atlas-certifier-fixture-'.bin2hex(random_bytes(4));
        mkdir($this->fixtureDir, 0755, true);
    }

    protected function tearDown(): void
    {
        @unlink($this->baselineFile);
        $this->rmdir($this->fixtureDir);
        parent::tearDown();
    }

    public function test_certifier_fails_when_nothing_changed_vs_baseline(): void
    {
        $this->seedFixture(10, 2);
        $scanner = new AtlasLoopSelfArchitectureScanner;
        $scan = $scanner->scan($this->fixtureDir);
        $graph = (new AtlasLoopSelfDependencyGraphReporter)->report($scan['perFileEdges'] ?? []);

        $baseline = [
            'totalLoc' => $scan['totalLoc'],
            'refillerLoc' => $scan['refillerLoc'],
            'edgeCount' => $graph['edgeCount'],
            'cycleCount' => $graph['cycleCount'],
        ];
        file_put_contents($this->baselineFile, (string) json_encode($baseline));

        $certifier = new AtlasLoopConsolidationCertifier($scanner, new AtlasLoopSelfDependencyGraphReporter);
        $result = $certifier->certify($this->baselineFile, $this->fixtureDir);

        $this->assertSame('not_certified', $result['verdict'], 'same snapshot as baseline must NOT certify');
    }

    public function test_certifier_passes_when_all_axes_strictly_decreased(): void
    {
        $baseline = ['totalLoc' => 200, 'refillerLoc' => 50, 'edgeCount' => 10, 'cycleCount' => 2];
        file_put_contents($this->baselineFile, (string) json_encode($baseline));

        $this->seedFixture(5, 1);
        $scanner = new AtlasLoopSelfArchitectureScanner;
        $graphReporter = new AtlasLoopSelfDependencyGraphReporter;

        $scan = $scanner->scan($this->fixtureDir);
        $graph = $graphReporter->report($scan['perFileEdges'] ?? []);
        $this->assertLessThan(200, $scan['totalLoc']);
        $this->assertLessThan(50, $scan['refillerLoc']);
        $this->assertLessThan(10, $graph['edgeCount']);
        $this->assertLessThan(2, $graph['cycleCount']);

        $certifier = new AtlasLoopConsolidationCertifier($scanner, $graphReporter);
        $result = $certifier->certify($this->baselineFile, $this->fixtureDir);

        $this->assertSame('certified', $result['verdict']);
        foreach ($result['axes'] as $axis => $data) {
            $this->assertTrue($data['decreased'], "axis {$axis} must show decreased=true");
        }
    }

    public function test_certifier_refuses_missing_baseline(): void
    {
        $scanner = new AtlasLoopSelfArchitectureScanner;
        $certifier = new AtlasLoopConsolidationCertifier($scanner, new AtlasLoopSelfDependencyGraphReporter);
        $result = $certifier->certify('/nonexistent/baseline.json');

        $this->assertSame('baseline_missing', $result['verdict']);
    }

    public function test_certifier_refuses_malformed_baseline(): void
    {
        file_put_contents($this->baselineFile, '{"oops": true}');

        $scanner = new AtlasLoopSelfArchitectureScanner;
        $certifier = new AtlasLoopConsolidationCertifier($scanner, new AtlasLoopSelfDependencyGraphReporter);
        $result = $certifier->certify($this->baselineFile);

        $this->assertSame('baseline_malformed', $result['verdict']);
    }

    public function test_output_has_no_score_rating_grade_or_quality_key(): void
    {
        $baseline = ['totalLoc' => 999, 'refillerLoc' => 999, 'edgeCount' => 999, 'cycleCount' => 999];
        file_put_contents($this->baselineFile, (string) json_encode($baseline));

        $this->seedFixture(3, 0);
        $certifier = new AtlasLoopConsolidationCertifier(new AtlasLoopSelfArchitectureScanner, new AtlasLoopSelfDependencyGraphReporter);
        $result = $certifier->certify($this->baselineFile, $this->fixtureDir);

        $json = (string) json_encode($result);
        foreach (['"score"', '"rating"', '"grade"', '"quality"'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $json, "output must not contain {$forbidden}");
        }
    }

    public function test_artisan_command_exits_nonzero_when_not_certified(): void
    {
        $baseline = ['totalLoc' => 1, 'refillerLoc' => 1, 'edgeCount' => 1, 'cycleCount' => 0];
        file_put_contents($this->baselineFile, (string) json_encode($baseline));

        $exit = Artisan::call('atlas:loop:self-architecture:certify', ['--baseline' => $this->baselineFile]);
        $this->assertSame(1, $exit, 'command must exit 1 when not certified');
    }

    private function seedFixture(int $locPerFile, int $edgesPerFile): void
    {
        $this->rmdir($this->fixtureDir);
        mkdir($this->fixtureDir, 0755, true);

        $body = str_repeat("    // line\n", max(0, $locPerFile - 4));
        $uses = '';
        for ($i = 0; $i < $edgesPerFile; $i++) {
            $uses .= "use App\\Services\\Ai\\AutonomousEvolution\\Dep{$i};\n";
        }

        file_put_contents($this->fixtureDir.'/Foo.php', "<?php\n{$uses}\nclass Foo {\n{$body}}\n");
        file_put_contents($this->fixtureDir.'/AtlasLoopQueueRefiller.php', "<?php\n{$uses}\nclass AtlasLoopQueueRefiller {\n{$body}}\n");
    }

    private function rmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $f) {
            is_dir($f) ? $this->rmdir($f) : unlink($f);
        }
        rmdir($dir);
    }
}
