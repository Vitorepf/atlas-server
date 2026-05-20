<?php

namespace Tests\Feature\Ai\StrategicReality;

use App\Services\Ai\StrategicReality\AtlasStrategicRealityCertificationService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesStrategicRealityTables;
use Tests\TestCase;

class AtlasStrategicRealityCommandTest extends TestCase
{
    use CreatesStrategicRealityTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategicRealityTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategicRealityTables();
        parent::tearDown();
    }

    public function test_decide_command_emits_json(): void
    {
        $exit = Artisan::call('atlas:strategic-reality', [
            'action' => 'decide',
            '--question' => 'Qual proxima acao estrategica para o Atlas?',
            '--domain' => 'strategy',
            '--evidence' => ['test:asre:cli'],
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.strategic_reality.decision.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertNotEmpty($payload['decision_hash']);
    }

    public function test_control_plane_command_emits_sanitized_json(): void
    {
        Artisan::call('atlas:strategic-reality', [
            'action' => 'decide',
            '--question' => 'Pergunta sensivel que nao deve vazar no painel ASRE',
            '--evidence' => ['test:asre:sanitized'],
            '--json' => true,
        ]);

        $exit = Artisan::call('atlas:strategic-reality:control-plane', [
            '--hours' => 24,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.strategic_reality.control_plane.v1', $payload['schema_version']);
        $this->assertStringNotContainsString('Pergunta sensivel', Artisan::output());
        $this->assertSame(1, data_get($payload, 'summary.strategic_decisions_total'));
    }

    public function test_certify_command_strict_passes(): void
    {
        $exit = Artisan::call('atlas:strategic-reality:certify', [
            '--json' => true,
            '--strict' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasStrategicRealityCertificationService::STATUS_PASSED, $payload['status']);
        $this->assertNotEmpty($payload['certification_hash']);
    }
}
