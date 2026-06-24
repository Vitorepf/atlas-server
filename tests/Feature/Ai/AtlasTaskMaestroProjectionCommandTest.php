<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadConsumptionRateReporter;
use App\Services\Ai\SelfConstruction\Maestro\Projection\AtlasMaestroWorkloadProjectionFactEmitter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Tests\TestCase;

final class AtlasTaskMaestroProjectionCommandTest extends TestCase
{
    private string $envFile = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->envFile = sys_get_temp_dir().'/atlas-maestro-projection-'.bin2hex(random_bytes(5)).'.env';
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=true\n");
        AtlasLoopMasterSwitch::$envPathOverride = $this->envFile;
        @unlink(storage_path('app/atlas/self-construction/maestro/projection/history.jsonl'));
    }

    protected function tearDown(): void
    {
        AtlasLoopMasterSwitch::$envPathOverride = null;
        if ($this->envFile !== '') {
            @unlink($this->envFile);
        }
        @unlink(storage_path('app/atlas/self-construction/maestro/projection/history.jsonl'));
        parent::tearDown();
    }

    public function test_rate_and_empty_emit_fact_schemas_via_artisan(): void
    {
        $this->seedPacket('p-1', 'queued');
        $this->seedPacket('p-2', 'claimable');

        $rateExit = Artisan::call('atlas:task:maestro-projection', ['verb' => 'rate', '--json' => true]);
        $this->assertSame(0, $rateExit);
        $this->assertStringContainsString('"schema": "atlas.maestro.projection.consumption_rate.v1"', Artisan::output());

        $emptyExit = Artisan::call('atlas:task:maestro-projection', ['verb' => 'empty', '--json' => true]);
        $this->assertSame(0, $emptyExit);
        $this->assertStringContainsString('"schema": "atlas.maestro.projection.time_to_empty.v1"', Artisan::output());
    }

    public function test_master_switch_off_disables_without_resolving_reporters(): void
    {
        file_put_contents($this->envFile, "ATLAS_LOOP_MASTER_ENABLED=false\n");

        $rateResolves = 0;
        $emptyResolves = 0;
        $this->app->bind(AtlasMaestroWorkloadConsumptionRateReporter::class, function () use (&$rateResolves) {
            $rateResolves++;

            return new AtlasMaestroWorkloadConsumptionRateReporter;
        });
        $this->app->bind(AtlasMaestroWorkloadProjectionFactEmitter::class, function () use (&$emptyResolves) {
            $emptyResolves++;

            return new AtlasMaestroWorkloadProjectionFactEmitter;
        });

        [$exit, $output] = $this->callProjectionCommand(['verb' => 'rate', '--json' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $rateResolves);
        $this->assertSame(0, $emptyResolves);
        $this->assertStringContainsString('"schema": "atlas.maestro.projection.disabled.v1"', $output);
        $this->assertStringContainsString('"status": "disabled"', $output);
    }

    public function test_history_returns_last_twenty_rows_or_empty_array(): void
    {
        $historyPath = storage_path('app/atlas/self-construction/maestro/projection/history.jsonl');
        File::ensureDirectoryExists(dirname($historyPath));

        [$emptyExit, $emptyOutput] = $this->callProjectionCommand(['verb' => 'history', '--json' => true]);
        $this->assertSame(0, $emptyExit);
        $this->assertStringContainsString('"schema": "atlas.maestro.projection.history.v1"', $emptyOutput);
        $this->assertStringContainsString('"rows": []', $emptyOutput);

        $lines = [];
        for ($i = 1; $i <= 25; $i++) {
            $lines[] = json_encode(['schema' => 'row', 'i' => $i], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }
        file_put_contents($historyPath, implode(PHP_EOL, $lines).PHP_EOL);

        [$historyExit, $output] = $this->callProjectionCommand(['verb' => 'history', '--json' => true]);
        $this->assertSame(0, $historyExit);
        $this->assertStringContainsString('"schema": "atlas.maestro.projection.history.v1"', $output);
        $this->assertStringContainsString('"i": 6', $output);
        $this->assertStringContainsString('"i": 25', $output);
        $this->assertStringNotContainsString('"i": 5', $output);
    }

    private function seedPacket(string $taskPacketId, string $status): void
    {
        $repo = app(AgentControlPlaneTaskPacketQueueRepository::class);
        $repo->enqueue([
            'task_packet_id' => $taskPacketId,
            'task_packet_hash' => hash('sha256', $taskPacketId.'-'.$status),
            'status' => $status,
            'objective' => 'projection '.$taskPacketId,
            'allowed_files' => ['app/Fake.php'],
            'scope_in' => ['app/Fake.php'],
            'acceptance_criteria' => ['ok'],
            'required_evidence' => ['ok'],
        ], [
            'metadata' => ['client_id' => 'worker-a'],
        ]);
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return array{0:int,1:string}
     */
    private function callProjectionCommand(array $arguments): array
    {
        $kernel = $this->app->make(ConsoleKernel::class);
        $exit = $kernel->call('atlas:task:maestro-projection', $arguments);

        return [$exit, $kernel->output()];
    }
}
