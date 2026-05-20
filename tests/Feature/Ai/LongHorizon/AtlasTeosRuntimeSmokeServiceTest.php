<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiForgeIntake;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use App\Services\Ai\LongHorizon\AtlasTeosRuntimeSmokeService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class AtlasTeosRuntimeSmokeServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use CreatesForgeLongHorizonStateTable;
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas.token', 'test-token-with-enough-length-123');
        $this->bootAutonomousSchema();
        $this->createAtlasMemoryEntryTable();
        $this->createForgeLongHorizonStateTable();
        $this->createLongHorizonPersistenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropLongHorizonPersistenceTables();
        $this->dropForgeLongHorizonStateTable();
        $this->dropAtlasMemoryEntryTable();
        $this->dropAutonomousSchema();

        parent::tearDown();
    }

    public function test_runtime_smoke_materializes_data_and_final_certification_is_ready(): void
    {
        $payload = app(AtlasTeosRuntimeSmokeService::class)->run([
            'goal' => 'certifique TEOS-I2 até TEOS-I5 com smoke local',
        ]);

        $this->assertSame(AtlasTeosRuntimeSmokeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasTeosRuntimeSmokeService::STATUS_READY, $payload['status']);
        $this->assertTrue($payload['writes']);
        $this->assertSame(AtlasTeosFinalCertificationService::STATUS_READY, data_get($payload, 'final_certification.status'));
        $this->assertTrue(data_get($payload, 'claim_policy.benchmark_not_run'));
        $this->assertFalse(data_get($payload, 'claim_policy.rivals_compared'));
        $this->assertFalse(data_get($payload, 'claim_policy.provider_calls_made'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.world_model_nodes'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.forge_milestones'));
        $this->assertGreaterThanOrEqual(1, data_get($payload, 'counts.forge_work_packets'));
        $this->assertNotEmpty($payload['smoke_hash']);

        $this->assertTrue(AiCodebaseWorldModel::query()->where('model_id', data_get($payload, 'created.world_model_id'))->exists());
        $this->assertTrue(AiForgeIntake::query()->where('uuid', data_get($payload, 'created.forge_intake_uuid'))->exists());
        $this->assertTrue(AtlasMemoryEntry::query()->where('source_type', 'teos_runtime_smoke')->exists());
    }

    public function test_runtime_smoke_blocks_when_required_tables_are_missing(): void
    {
        Schema::dropIfExists('ai_codebase_world_models');

        $payload = app(AtlasTeosRuntimeSmokeService::class)->run();

        $this->assertSame(AtlasTeosRuntimeSmokeService::STATUS_BLOCKED, $payload['status']);
        $this->assertFalse($payload['writes']);
        $this->assertSame('missing_required_tables', data_get($payload, 'blockers.0.reason'));
        $this->assertContains('ai_codebase_world_models', data_get($payload, 'blockers.0.evidence.missing_tables'));
        $this->assertTrue(data_get($payload, 'claim_policy.benchmark_not_run'));
    }

    public function test_command_emits_json_and_supports_strict_mode(): void
    {
        $exit = Artisan::call('atlas:teos:runtime-smoke', [
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasTeosRuntimeSmokeService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasTeosRuntimeSmokeService::STATUS_READY, $payload['status']);
        $this->assertSame(AtlasTeosFinalCertificationService::STATUS_READY, data_get($payload, 'final_certification.status'));
    }

    private function bootAutonomousSchema(): void
    {
        $this->dropAutonomousSchema();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    private function dropAutonomousSchema(): void
    {
        foreach ([
            'ai_autonomous_engineering_certifications',
            'ai_rivals_shadow_runs',
            'ai_engineering_control_plane_events',
            'ai_repair_loops',
            'ai_execution_plans',
            'ai_mandatory_rag_gates',
            'ai_codebase_world_model_edges',
            'ai_codebase_world_model_nodes',
            'ai_codebase_world_models',
            'ai_autonomous_work_steps',
            'ai_autonomous_work_cycles',
            'ai_autonomous_engineering_goals',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }
}
