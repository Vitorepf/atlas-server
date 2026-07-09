<?php

declare(strict_types=1);

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Spatial\AtlasCortexFsLocalityReporter;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

final class AtlasCortexFsLocalityReporterTest extends TestCase
{
    private string $sandbox = '';

    private string $scopeRoot = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-loc-'.bin2hex(random_bytes(6));
        // Build a fixture tree mimicking the allowed scope.
        $this->scopeRoot = $this->sandbox.'/app/Services/Ai/AutonomousEvolution/Loop';
        @mkdir($this->scopeRoot.'/Discovery/Cortex', 0o755, true);
        @mkdir($this->scopeRoot.'/Anomaly', 0o755, true);

        file_put_contents($this->scopeRoot.'/Foo.php', '<?php');
        file_put_contents($this->scopeRoot.'/Bar.php', '<?php');
        file_put_contents($this->scopeRoot.'/Discovery/Cortex/Walker.php', '<?php');
        file_put_contents($this->scopeRoot.'/Discovery/Cortex/Aggregator.php', '<?php');
        file_put_contents($this->scopeRoot.'/Anomaly/Detector.php', '<?php');

        @mkdir($this->sandbox.'/storage/atlas/cortex/spatial/fs', 0o755, true);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->sandbox);
        parent::tearDown();
    }

    public function test_reports_neighbors_per_file_and_depth_deterministically(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=true
ATLAS_LOOP_MASTER_ENABLED=true
"); return $p; })();
        $reporter = new AtlasCortexFsLocalityReporter($this->scopeRoot, $this->sandbox.'/storage', [1, 2]);

        $a = $reporter->report()->toArray();
        $b = $reporter->report()->toArray();

        $this->assertSame($a, $b, 'records must be byte-identical on replay');
        // 5 files × 2 depths = 10 records
        $this->assertCount(10, $a);
        // Each record carries file_path + depth + neighbor lists, no score field.
        foreach ($a as $row) {
            $this->assertArrayHasKey('neighbor_count', $row);
            $this->assertArrayHasKey('neighbor_fqcns', $row);
            $this->assertArrayNotHasKey('score', $row);
            $this->assertArrayNotHasKey('rank', $row);
        }
    }

    public function test_master_off_writes_zero_bytes_and_returns_empty_collection(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=false
ATLAS_LOOP_MASTER_ENABLED=false
"); return $p; })();
        $reporter = new AtlasCortexFsLocalityReporter($this->scopeRoot, $this->sandbox.'/storage');

        $out = $reporter->report();
        $this->assertCount(0, $out);

        $jsonl = $this->sandbox.'/storage/atlas/cortex/spatial/fs/locality.jsonl';
        $this->assertTrue(! is_file($jsonl) || filesize($jsonl) === 0);
    }

    public function test_scope_outside_allowed_boundary_throws_runtime_exception(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=true
ATLAS_LOOP_MASTER_ENABLED=true
"); return $p; })();
        // Build a different tree outside the AutonomousEvolution scope.
        $bad = $this->sandbox.'/app/Services/Ai/MarketingDomain';
        @mkdir($bad, 0o755, true);
        file_put_contents($bad.'/Bridge.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('outside allowed boundary');
        (new AtlasCortexFsLocalityReporter($bad, $this->sandbox.'/storage'))->report();
    }
}
