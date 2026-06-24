<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Metrics;

use App\Services\Ai\AutonomousEvolution\Metrics\AtlasLoopPrimitiveArmedRatio;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use ReflectionMethod;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AtlasLoopPrimitiveArmedRatioTest extends TestCase
{
    /** @var list<string> */
    private array $paths = [];

    private string $originalStoragePath;

    private string $originalLocalDiskRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalStoragePath = app()->storagePath();
        $this->originalLocalDiskRoot = (string) config('filesystems.disks.local.root');

        if (! Schema::hasTable('atlas_loop_delivery_contracts')) {
            (require base_path('database/migrations/2026_06_16_000200_create_atlas_loop_delivery_contracts_table.php'))->up();
        }
        if (! Schema::hasTable('atlas_loop_origination_outcomes')) {
            (require base_path('database/migrations/2026_06_16_000300_create_atlas_loop_origination_outcomes_table.php'))->up();
        }
        if (! Schema::hasColumn('atlas_loop_delivery_contracts', 'payload')) {
            Schema::table('atlas_loop_delivery_contracts', function ($table): void {
                $table->json('payload')->nullable();
            });
        }
        if (! Schema::hasColumn('atlas_loop_origination_outcomes', 'payload')) {
            Schema::table('atlas_loop_origination_outcomes', function ($table): void {
                $table->json('payload')->nullable();
            });
        }

        DB::table('atlas_loop_delivery_contracts')->delete();
        DB::table('atlas_loop_origination_outcomes')->delete();
    }

    protected function tearDown(): void
    {
        app()->useStoragePath($this->originalStoragePath);
        config(['filesystems.disks.local.root' => $this->originalLocalDiskRoot]);

        foreach ($this->paths as $path) {
            (new Process(['rm', '-rf', $path]))->run();
        }

        parent::tearDown();
    }

    public function test_zero_built_and_armed_returns_zero_ratio_without_division_by_zero(): void
    {
        $metric = new AtlasLoopPrimitiveArmedRatio;
        $method = new ReflectionMethod($metric, 'buildMetric');
        $method->setAccessible(true);

        $payload = $method->invoke($metric, [], [], new DateTimeImmutable('2030-01-01T00:00:00Z'));

        $this->assertSame('atlas.loop.primitive_armed_ratio.v1', $payload['schema']);
        $this->assertSame(0, $payload['built']);
        $this->assertSame(0, $payload['armed']);
        $this->assertSame(0.0, $payload['ratio']);
        $this->assertSame([], $payload['unarmed_sample']);
        $this->assertSame(60, $payload['window_days']);
        $this->assertSame('2030-01-01T00:00:00+00:00', $payload['computed_at']);
    }

    public function test_measure_detects_real_built_primitives_from_the_repo_tree(): void
    {
        $storage = $this->storageRoot();
        app()->useStoragePath($storage);

        $payload = (new AtlasLoopPrimitiveArmedRatio)->measure(new DateTimeImmutable('2035-01-01T00:00:00Z'));

        $this->assertGreaterThanOrEqual(100, $payload['built']);
        $this->assertIsFloat($payload['ratio']);
    }

    public function test_origination_payload_within_window_arms_a_primitive_but_old_rows_and_class_exists_alone_do_not(): void
    {
        $storage = $this->storageRoot();
        app()->useStoragePath($storage);

        $now = new DateTimeImmutable('2035-01-01T00:00:00Z');
        $target = 'App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionWorker';
        $oldTarget = 'App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionEngine';
        $control = 'App\Services\Ai\AutonomousEvolution\AtlasLoopGroundedProjectionRoles';

        DB::table('atlas_loop_origination_outcomes')->insert([
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'shape_token' => 'shape-target',
                'accepted' => true,
                'proposal_id' => 'prop-target',
                'target_path' => null,
                'payload' => json_encode(['target' => $target], JSON_UNESCAPED_SLASHES),
                'created_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
                'updated_at' => $now->modify('-1 day')->format('Y-m-d H:i:s'),
            ],
            [
                'id' => (string) \Illuminate\Support\Str::uuid(),
                'shape_token' => 'shape-old',
                'accepted' => true,
                'proposal_id' => 'prop-old',
                'target_path' => null,
                'payload' => json_encode(['target' => $oldTarget], JSON_UNESCAPED_SLASHES),
                'created_at' => $now->modify('-120 days')->format('Y-m-d H:i:s'),
                'updated_at' => $now->modify('-120 days')->format('Y-m-d H:i:s'),
            ],
        ]);

        $armed = $this->discoverArmed($now);

        $this->assertContains($target, $armed);
        $this->assertNotContains($oldTarget, $armed);
        $this->assertNotContains($control, $armed, 'class_exists alone must never arm a primitive');
    }

    public function test_projection_outcome_json_file_arms_a_primitive(): void
    {
        $storage = $this->storageRoot();
        app()->useStoragePath($storage);

        $now = new DateTimeImmutable('2035-01-01T00:00:00Z');
        $target = 'App\Services\Ai\AutonomousEvolution\AtlasLoopOriginationPipeline';
        $dir = $storage.'/app/atlas/loop/projection-outcomes';
        mkdir($dir, 0o755, true);
        $path = $dir.'/campaign.json';
        file_put_contents($path, json_encode(['target' => $target], JSON_UNESCAPED_SLASHES));
        touch($path, $now->getTimestamp());

        $armed = $this->discoverArmed($now);

        $this->assertContains($target, $armed);
    }

    /**
     * @return list<string>
     */
    private function discoverArmed(DateTimeImmutable $now): array
    {
        $metric = new AtlasLoopPrimitiveArmedRatio;
        $builtMethod = new ReflectionMethod($metric, 'discoverBuiltPrimitives');
        $builtMethod->setAccessible(true);
        $built = $builtMethod->invoke($metric, base_path('app/Services/Ai/AutonomousEvolution'));

        $armedMethod = new ReflectionMethod($metric, 'discoverArmed');
        $armedMethod->setAccessible(true);

        return $armedMethod->invoke($metric, $built, $now);
    }

    private function storageRoot(): string
    {
        $root = sys_get_temp_dir().'/atlas-loop-primitive-ratio-'.bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        $this->paths[] = $root;
        config(['filesystems.disks.local.root' => $root.'/app']);

        return $root;
    }
}
