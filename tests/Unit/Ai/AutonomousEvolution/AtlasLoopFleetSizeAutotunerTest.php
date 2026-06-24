<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Console\Commands\AtlasLoopKeepaliveCommand;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFleetGovernor;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFleetSizeAutotuner;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\Output;
use Tests\TestCase;

final class AtlasLoopFleetSizeAutotunerTest extends TestCase
{
    #[DataProvider('pressureCases')]
    public function test_recommendation_is_deterministic_for_pressure_cases(array $sample, int $expectedCap, string $expectedReason): void
    {
        $autotuner = new AtlasLoopFleetSizeAutotuner;

        $first = $autotuner->recommend(4, $sample);
        $second = $autotuner->recommend(4, $sample);

        $this->assertSame($first, $second);
        $this->assertSame(AtlasLoopFleetSizeAutotuner::SCHEMA, $first['schema']);
        $this->assertSame(4, $first['current_cap']);
        $this->assertSame($expectedCap, $first['recommended_cap']);
        $this->assertGreaterThanOrEqual(0, $first['pressure_score']);
        $this->assertLessThanOrEqual(100, $first['pressure_score']);
        $this->assertContains($expectedReason, $first['reason']);
    }

    public static function pressureCases(): array
    {
        return [
            'low' => [['fleet_in_flight' => 0, 'load_avg_1m' => 0.5, 'mem_free_pct' => 90, 'provider_p95_ms' => 100], 5, 'low_pressure_can_raise_cap'],
            'medium' => [['fleet_in_flight' => 3, 'load_avg_1m' => 1.5, 'mem_free_pct' => 45, 'provider_p95_ms' => 900], 4, 'medium_pressure_hold_cap'],
            'high' => [['fleet_in_flight' => 8, 'load_avg_1m' => 3.0, 'mem_free_pct' => 10, 'provider_p95_ms' => 3000], 3, 'high_pressure_reduce_cap'],
        ];
    }

    public function test_keepalive_writes_advisory_without_changing_governor_or_config(): void
    {
        $dir = sys_get_temp_dir().'/atlas-fleet-autotune-'.bin2hex(random_bytes(6));
        config([
            'atlas.loop.fleet_autotuner_enabled' => true,
            'atlas.loop.fleet_global_worker_cap' => 4,
        ]);
        $autotuner = new AtlasLoopFleetSizeAutotuner(
            pressureSampler: static fn (): array => ['fleet_in_flight' => 1, 'load_avg_1m' => 0.5, 'mem_free_pct' => 80, 'provider_p95_ms' => 100],
            snapshotDir: $dir,
        );
        $beforeAdmission = (new AtlasLoopFleetGovernor)->admit(2, (int) config('atlas.loop.fleet_global_worker_cap'));

        $cmd = new class($autotuner) extends AtlasLoopKeepaliveCommand
        {
            public function __construct(AtlasLoopFleetSizeAutotuner $autotuner)
            {
                parent::__construct(fleetSizeAutotuner: $autotuner);
            }

            protected function masterSwitchEnabled(): bool
            {
                return true;
            }

            protected function runKeepalive(array &$out): int
            {
                $this->emitFleetSizeAdvisory();
                $out['schema_version'] = 'atlas.loop.keepalive.v1';
                $out['checked'] = 0;
                $this->line((string) json_encode($out, JSON_THROW_ON_ERROR));

                return self::SUCCESS;
            }
        };
        $cmd->setLaravel(app());
        $captured = [];

        $exit = $cmd->run(new ArrayInput(['--json' => true]), new class($captured) extends Output
        {
            public function __construct(private array &$captured)
            {
                parent::__construct();
            }

            protected function doWrite(string $message, bool $newline): void
            {
                $this->captured[] = $message;
            }
        });

        $afterAdmission = (new AtlasLoopFleetGovernor)->admit(2, (int) config('atlas.loop.fleet_global_worker_cap'));
        $snapshot = json_decode((string) file_get_contents($dir.'/latest.json'), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasLoopFleetSizeAutotuner::SCHEMA, $snapshot['schema']);
        $this->assertSame($beforeAdmission, $afterAdmission);
        $this->assertSame(4, config('atlas.loop.fleet_global_worker_cap'));
    }
}
