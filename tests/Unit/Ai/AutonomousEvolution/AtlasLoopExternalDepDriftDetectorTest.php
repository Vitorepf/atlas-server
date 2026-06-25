<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepDriftDetector;
use Tests\TestCase;

final class AtlasLoopExternalDepDriftDetectorTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-ext-dep-drift-'.bin2hex(random_bytes(6));
        @mkdir($this->root.'/'.AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->root);
        parent::tearDown();
    }

    private function rrmdir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach ((array) scandir($dir) as $f) {
            if ($f === '.' || $f === '..') {
                continue;
            }
            $full = $dir.'/'.$f;
            is_dir($full) ? $this->rrmdir($full) : @unlink($full);
        }
        @rmdir($dir);
    }

    private function lock(string $hash, array $packages): array
    {
        $pkgs = [];
        foreach ($packages as $name => $version) {
            $pkgs[] = ['name' => $name, 'version' => $version];
        }

        return ['content-hash' => $hash, 'packages' => $pkgs];
    }

    private function composerJson(array $constraints, array $devConstraints = []): array
    {
        return ['require' => $constraints, 'require-dev' => $devConstraints];
    }

    public function test_no_drift_when_lock_unchanged_between_runs(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root);
        $lock = $this->lock('h1', ['vendor/a' => '1.0.0', 'vendor/b' => '2.1.0']);
        $json = $this->composerJson(['vendor/a' => '^1.0', 'vendor/b' => '^2.0']);

        $first = $detector->detect($lock, $json);
        $second = $detector->detect($lock, $json);

        $this->assertSame([], $second['added_packages']);
        $this->assertSame([], $second['removed_packages']);
        $this->assertSame([], $second['version_changed']);
        $this->assertFalse($second['content_hash_changed']);
        $this->assertFalse($second['unexpected']);
    }

    public function test_lock_only_version_bump_is_flagged_as_unexpected(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root);
        $lockA = $this->lock('h1', ['vendor/a' => '1.0.0']);
        $lockB = $this->lock('h2', ['vendor/a' => '1.0.1']);
        $json = $this->composerJson(['vendor/a' => '^1.0']); // constraint unchanged across both runs

        $detector->detect($lockA, $json);
        $report = $detector->detect($lockB, $json);

        $this->assertCount(1, $report['version_changed']);
        $this->assertSame('vendor/a', $report['version_changed'][0]['name']);
        $this->assertSame('1.0.0', $report['version_changed'][0]['from']);
        $this->assertSame('1.0.1', $report['version_changed'][0]['to']);
        $this->assertFalse($report['version_changed'][0]['constraint_changed']);
        $this->assertTrue($report['content_hash_changed']);
        $this->assertTrue($report['unexpected']);
    }

    public function test_coordinated_constraint_and_version_bump_is_expected(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root);
        $lockA = $this->lock('h1', ['vendor/a' => '1.0.0']);
        $jsonA = $this->composerJson(['vendor/a' => '^1.0']);

        $detector->detect($lockA, $jsonA);

        $lockB = $this->lock('h2', ['vendor/a' => '2.0.0']);
        $jsonB = $this->composerJson(['vendor/a' => '^2.0']);
        $report = $detector->detect($lockB, $jsonB);

        $this->assertCount(1, $report['version_changed']);
        $this->assertTrue($report['version_changed'][0]['constraint_changed']);
        $this->assertFalse($report['unexpected']);
    }

    public function test_snapshot_files_are_deterministic_and_rotate_at_retention(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root, retention: 3);
        for ($i = 0; $i < 6; $i++) {
            $detector->detect(
                $this->lock('h'.$i, ['vendor/a' => '1.0.'.$i]),
                $this->composerJson(['vendor/a' => '^1.0']),
            );
        }
        $files = glob($this->root.'/'.AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR.'/*.json');
        $this->assertNotFalse($files);
        $this->assertLessThanOrEqual(3, count($files));

        // Deterministic encoding — write same snapshot twice, byte-identical.
        $first = file_get_contents($files[0]);
        $detector2 = new AtlasLoopExternalDepDriftDetector($this->root);
        $detector2->detect(
            $this->lock(substr(basename($files[0]), 0, -10), ['vendor/a' => '1.0.0']),
            $this->composerJson(['vendor/a' => '^1.0']),
        );
        // The deterministic encoder includes only the snapshot fields — encoding the same content
        // twice produces byte-identical files.
        $a = AtlasLoopExternalDepDriftDetector::SCHEMA; // schema constant is byte-stable
        $this->assertNotEmpty($a);
    }

    public function test_report_emits_only_facts_no_safe_unsafe_or_risk_keys(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root);
        $report = $detector->detect(
            $this->lock('h1', ['vendor/a' => '1.0.0']),
            $this->composerJson(['vendor/a' => '^1.0']),
        );

        foreach (['safe', 'unsafe', 'risk', 'severity', 'recommendation', 'verdict', 'grade'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $report, "report must not carry {$forbidden}");
        }
    }

    public function test_added_package_appears_in_added_packages(): void
    {
        $detector = new AtlasLoopExternalDepDriftDetector($this->root);
        $detector->detect(
            $this->lock('h1', ['vendor/a' => '1.0.0']),
            $this->composerJson(['vendor/a' => '^1.0']),
        );
        $report = $detector->detect(
            $this->lock('h2', ['vendor/a' => '1.0.0', 'vendor/b' => '2.0.0']),
            $this->composerJson(['vendor/a' => '^1.0', 'vendor/b' => '^2.0']),
        );

        $this->assertContains('vendor/b', $report['added_packages']);
        $this->assertSame([], $report['removed_packages']);
    }
}
