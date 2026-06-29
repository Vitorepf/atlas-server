<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\BehaviorDelta\AtlasLoopBehaviorDeltaSnapshotter;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the behavior-Δ snapshotter is live at the operator surface: it inventories a fixture scope's symbols
 * with their public-API signature hashes and emits a deterministic, byte-stable snapshot.
 */
final class AtlasLoopBehaviorDeltaSnapshotCommandTest extends TestCase
{
    private string $repo = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->repo = sys_get_temp_dir().'/atlas-behavior-delta-'.bin2hex(random_bytes(5));
        @mkdir($this->repo.'/scopelib', 0o755, true);
        file_put_contents($this->repo.'/scopelib/Widget.php', <<<'PHP'
            <?php
            namespace Fixture\Demo;
            final class Widget {
                public function alpha(): void {}
                public function beta(): void {}
                private function secret(): void {}
            }
            PHP);
    }

    protected function tearDown(): void
    {
        @unlink($this->repo.'/scopelib/Widget.php');
        @rmdir($this->repo.'/scopelib');
        @rmdir($this->repo);
        parent::tearDown();
    }

    private function snapshotFacts(): array
    {
        $exit = Artisan::call('atlas:loop:behavior-delta-snapshot', [
            '--repo-root' => $this->repo,
            '--scope-root' => 'scopelib',
            '--json' => true,
        ]);

        return ['exit' => $exit, 'decoded' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_snapshots_fixture_scope_symbols(): void
    {
        ['exit' => $exit, 'decoded' => $decoded] = $this->snapshotFacts();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopBehaviorDeltaSnapshotter::SCHEMA, $decoded['schema']);
        $this->assertCount(1, $decoded['symbols'], (string) json_encode($decoded));
        $this->assertSame('Fixture\\Demo\\Widget', $decoded['symbols'][0]['fqcn']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $decoded['symbols'][0]['public_api_signature_hash']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $decoded['api_surface_hash']);
    }

    public function test_snapshot_is_byte_stable_across_runs(): void
    {
        $a = $this->snapshotFacts()['decoded'];
        $b = $this->snapshotFacts()['decoded'];

        $this->assertSame($a['api_surface_hash'], $b['api_surface_hash']);
        $this->assertSame($a['captured_at'], $b['captured_at']);
        $this->assertSame($a, $b);
    }
}
