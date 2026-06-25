<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\ExternalDeps\AtlasLoopExternalDepDriftDetector;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasLoopExternalDepCommandTest extends TestCase
{
    private string $sandbox = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->sandbox = sys_get_temp_dir().'/atlas-loop-deps-'.bin2hex(random_bytes(6));
        @mkdir($this->sandbox.'/'.AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR, 0o755, true);
    }

    protected function tearDown(): void
    {
        $this->rrmdir($this->sandbox);
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

    private function writeComposer(string $hash, array $packages, array $require): array
    {
        $jsonPath = $this->sandbox.'/composer.json';
        $lockPath = $this->sandbox.'/composer.lock';
        $jsonContent = ['require' => $require];
        $lockContent = ['content-hash' => $hash, 'packages' => array_values(array_map(static fn ($n, $v) => ['name' => $n, 'version' => $v], array_keys($packages), $packages))];
        file_put_contents($jsonPath, json_encode($jsonContent));
        file_put_contents($lockPath, json_encode($lockContent));

        return [$jsonPath, $lockPath];
    }

    private function bindPaths(string $jsonPath, string $lockPath): void
    {
        $sandbox = $this->sandbox;
        app()->bind('atlas.loop.deps.paths', fn () => [$jsonPath, $lockPath]);
        app()->bind('atlas.loop.deps.snapshot_root', fn () => $sandbox);
    }

    public function test_inventory_emits_packages_and_lock_present_keys(): void
    {
        [$j, $l] = $this->writeComposer('h1', ['vendor/a' => '1.0.0'], ['vendor/a' => '^1.0']);
        $this->bindPaths($j, $l);

        Artisan::call('atlas:loop:deps', ['action' => 'inventory', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertArrayHasKey('packages', $payload);
        $this->assertArrayHasKey('lock_present', $payload);
        $this->assertTrue($payload['lock_present']);
    }

    public function test_drift_run_twice_on_unchanged_lock_returns_empty_deltas_on_second_run(): void
    {
        [$j, $l] = $this->writeComposer('h1', ['vendor/a' => '1.0.0'], ['vendor/a' => '^1.0']);
        $this->bindPaths($j, $l);

        Artisan::call('atlas:loop:deps', ['action' => 'drift', '--json' => true]);
        $first = json_decode(trim(Artisan::output()), true);

        Artisan::call('atlas:loop:deps', ['action' => 'drift', '--json' => true]);
        $second = json_decode(trim(Artisan::output()), true);

        $this->assertSame([], $second['added_packages']);
        $this->assertSame([], $second['removed_packages']);
        $this->assertSame([], $second['version_changed']);
        $this->assertFalse($second['unexpected']);
        $this->assertFalse($second['content_hash_changed']);
    }

    public function test_history_returns_at_most_limit_snapshots_sorted_newest_first(): void
    {
        // Seed snapshots manually with distinct mtimes.
        $dir = $this->sandbox.'/'.AtlasLoopExternalDepDriftDetector::SNAPSHOT_DIR;
        for ($i = 0; $i < 5; $i++) {
            $name = sprintf('h%d-0000.json', $i);
            $path = $dir.'/'.$name;
            file_put_contents($path, json_encode(['content_hash' => 'h'.$i, 'packages' => array_fill(0, $i + 1, '')]));
            touch($path, 1_700_000_000 + $i * 60);
        }
        app()->bind('atlas.loop.deps.snapshot_root', fn () => $this->sandbox);

        Artisan::call('atlas:loop:deps', ['action' => 'history', '--limit' => 3, '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertCount(3, $payload['snapshots']);
        $hashes = array_column($payload['snapshots'], 'content_hash');
        $this->assertSame(['h4', 'h3', 'h2'], $hashes, 'snapshots must be ordered newest-first by mtime');
        // No judgement fields beyond timestamp/content_hash/package_count.
        foreach ($payload['snapshots'] as $row) {
            $this->assertSame(['timestamp', 'content_hash', 'package_count'], array_keys($row));
        }
    }

    public function test_drift_exits_zero_even_when_drift_is_present(): void
    {
        [$j, $l] = $this->writeComposer('h1', ['vendor/a' => '1.0.0'], ['vendor/a' => '^1.0']);
        $this->bindPaths($j, $l);

        Artisan::call('atlas:loop:deps', ['action' => 'drift', '--json' => true]);

        // Mutate lock without touching constraint — unexpected drift.
        $this->writeComposer('h2', ['vendor/a' => '1.0.1'], ['vendor/a' => '^1.0']);

        $exit = Artisan::call('atlas:loop:deps', ['action' => 'drift', '--json' => true]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit, 'drift is a FACT, not a failure — exit must be 0');
        $this->assertNotEmpty($payload['version_changed']);
    }

    public function test_command_is_registered_in_artisan_list(): void
    {
        $all = array_keys(Artisan::all());
        $this->assertContains('atlas:loop:deps', $all);
    }
}
