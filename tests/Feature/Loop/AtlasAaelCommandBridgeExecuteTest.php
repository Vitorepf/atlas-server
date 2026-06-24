<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasAutonomousEvolutionLoopService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesAaelTables;
use Tests\TestCase;

final class AtlasAaelCommandBridgeExecuteTest extends TestCase
{
    use CreatesAaelTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAaelTables();
        CarbonImmutable::setTestNow('2026-06-24 03:53:37');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_bridge_execute_surfaces_bridge_payload_and_never_merges(): void
    {
        Artisan::call('atlas:aael', [
            'action' => 'bridge-execute',
            '--max-tasks' => 3,
            '--max-seconds' => 90,
            '--scenarios-per-task' => 5,
            '--propose-only' => 0,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('atlas.evolution.aael_bridge.v1', data_get($payload, 'bridge_execution.schema_version'));
        $this->assertFalse((bool) data_get($payload, 'bridge_execution.merged_to_main'));
        $this->assertTrue(($payload['bridge_execution']['loop_run']['propose_only'] ?? false) === true);
        $this->assertSame(
            count((array) ($payload['selected_opportunities'] ?? [])),
            data_get($payload, 'bridge_execution.deferred_count', -1),
        );
    }

    public function test_cycle_and_control_plane_actions_remain_byte_identical_to_runtime_payloads(): void
    {
        $runtime = app(AtlasAutonomousEvolutionLoopService::class);
        $cyclePayload = $runtime->runCycle($this->baseInput());
        $controlPayload = $runtime->controlPlane(24);

        Artisan::call('atlas:aael', ['action' => 'cycle', '--objective' => 'AAEL CLI evolution cycle', '--json' => true]);
        $cycleJson = trim(Artisan::output());
        $this->assertSame(trim($this->jsonLine($cyclePayload)), $cycleJson);
        $this->assertArrayNotHasKey('bridge_execution', json_decode($cycleJson, true, flags: JSON_THROW_ON_ERROR));

        Artisan::call('atlas:aael', ['action' => 'control-plane', '--json' => true]);
        $controlJson = trim(Artisan::output());
        $this->assertSame(trim($this->jsonLine($controlPayload)), $controlJson);
        $this->assertArrayNotHasKey('bridge_execution', json_decode($controlJson, true, flags: JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function jsonLine(array $payload): string
    {
        return json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE).PHP_EOL;
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInput(): array
    {
        return [
            'objective' => 'AAEL CLI evolution cycle',
            'workspace' => base_path(),
            'domain' => 'programming',
            'flow_id' => 'atlas_forge',
            'evidence_refs' => [],
        ];
    }
}
