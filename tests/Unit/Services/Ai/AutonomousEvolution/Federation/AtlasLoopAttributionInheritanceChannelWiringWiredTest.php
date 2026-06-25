<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\AutonomousEvolution\Federation;

use App\Console\Commands\AtlasLoopFederationCommand;
use App\Services\Ai\AutonomousEvolution\Federation\AtlasLoopAttributionInheritanceChannel;
use Illuminate\Support\Facades\Artisan;
use ReflectionClass;
use Tests\TestCase;

/**
 * Wires AtlasLoopAttributionInheritanceChannel into the live
 * `atlas:loop:federation inheritance` flow. Proves the orphan channel is reached by real
 * production code via the operator CLI.
 */
final class AtlasLoopAttributionInheritanceChannelWiringWiredTest extends TestCase
{
    private string $priorsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->priorsPath = sys_get_temp_dir().'/atlas-attr-inheritance-'.bin2hex(random_bytes(6)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->priorsPath);
        parent::tearDown();
    }

    public function test_command_class_imports_the_channel_symbol(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AtlasLoopFederationCommand::class))->getFileName(),
        );
        $this->assertStringContainsString(
            AtlasLoopAttributionInheritanceChannel::class,
            $source,
            'federation command must reference the inheritance channel so it is no longer an orphan',
        );
    }

    public function test_inheritance_action_merges_per_cycle_priors_into_a_global_prior(): void
    {
        file_put_contents($this->priorsPath, (string) json_encode([
            [
                'cycle_id' => 'cyc-A',
                'files' => ['app/A.php'],
                'global_prior' => [
                    ['shape_token' => 'token-1', 'samples' => 4, 'mean_delta' => 0.5],
                ],
            ],
            [
                'cycle_id' => 'cyc-B',
                'files' => ['app/B.php'],
                'global_prior' => [
                    ['shape_token' => 'token-1', 'samples' => 6, 'mean_delta' => 1.0],
                    ['shape_token' => 'token-2', 'samples' => 2, 'mean_delta' => 0.25],
                ],
            ],
        ]));

        $exit = Artisan::call('atlas:loop:federation', [
            'action' => 'inheritance',
            '--per-cycle-priors' => $this->priorsPath,
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        $payload = json_decode(trim(Artisan::output()), true);
        $this->assertIsArray($payload);
        $this->assertSame(AtlasLoopAttributionInheritanceChannel::SCHEMA_VERSION, $payload['schema']);

        $byShape = [];
        foreach ((array) $payload['global_prior'] as $row) {
            $byShape[(string) $row['shape_token']] = $row;
        }

        // token-1: weighted average of (4 @ 0.5) + (6 @ 1.0) = (2 + 6) / 10 = 0.8
        $this->assertSame(10, $byShape['token-1']['samples']);
        $this->assertSame(0.8, $byShape['token-1']['mean_delta']);
        $this->assertSame(2, $byShape['token-2']['samples']);
        $this->assertSame(0.25, $byShape['token-2']['mean_delta']);

        $cycleIds = array_map(
            static fn (array $r): string => (string) $r['cycle_id'],
            (array) $payload['contributing_cycles'],
        );
        $this->assertContains('cyc-A', $cycleIds);
        $this->assertContains('cyc-B', $cycleIds);
    }

    public function test_inheritance_action_refuses_overlapping_cycle_files(): void
    {
        file_put_contents($this->priorsPath, (string) json_encode([
            ['cycle_id' => 'cyc-X', 'files' => ['app/Shared.php'], 'global_prior' => []],
            ['cycle_id' => 'cyc-Y', 'files' => ['app/Shared.php'], 'global_prior' => []],
        ]));

        $exit = Artisan::call('atlas:loop:federation', [
            'action' => 'inheritance',
            '--per-cycle-priors' => $this->priorsPath,
            '--json' => true,
        ]);
        $this->assertNotSame(0, $exit, 'overlapping cycle files must fail');
    }
}
