<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelNode;
use App\Models\AiForgeIntake;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\LongHorizon\AtlasTeosFinalCertificationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\Concerns\CreatesForgeLongHorizonStateTable;
use Tests\Concerns\CreatesLongHorizonPersistenceTables;
use Tests\TestCase;

class AtlasTeosFinalCertificationServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;
    use CreatesForgeLongHorizonStateTable;
    use CreatesLongHorizonPersistenceTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createAtlasMemoryEntryTable();
        $this->createForgeLongHorizonStateTable();
        $this->createLongHorizonPersistenceTables();
        $this->bootWorldModelTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
        parent::tearDown();
    }

    public function test_ready_when_all_local_release_surfaces_are_available(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('normal');
        $intake = $this->intake($now->subDay());
        $worldModel = $this->worldModel();
        $this->node($worldModel, 'node:a', 'app/A.php');

        $payload = app(AtlasTeosFinalCertificationService::class)->certify([
            'now' => $now,
            'intake' => $intake->uuid,
            'world_model_id' => $worldModel->model_id,
        ]);

        $this->assertSame(AtlasTeosFinalCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(AtlasTeosFinalCertificationService::STATUS_READY, $payload['status']);
        $this->assertSame(6, $payload['summary']['pass']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
        $this->assertFalse($payload['claim_policy']['allows_external_superiority_claim']);
    }

    public function test_missing_world_model_yields_partial_with_honest_warning(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('normal');
        $intake = $this->intake($now->subDay());

        $payload = app(AtlasTeosFinalCertificationService::class)->certify([
            'now' => $now,
            'intake' => $intake->uuid,
        ]);

        $this->assertSame(AtlasTeosFinalCertificationService::STATUS_PARTIAL, $payload['status']);
        $this->assertSame(1, $payload['summary']['warn']);
        $this->assertSame('time_aware_world_model', $payload['warnings'][0]['id']);
    }

    public function test_hash_is_deterministic(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $this->memory('normal');
        $intake = $this->intake($now->subDay());
        $worldModel = $this->worldModel();
        $this->node($worldModel, 'node:a', 'app/A.php');

        $a = app(AtlasTeosFinalCertificationService::class)->certify([
            'now' => $now,
            'intake' => $intake->uuid,
            'world_model_id' => $worldModel->model_id,
        ]);
        $b = app(AtlasTeosFinalCertificationService::class)->certify([
            'now' => $now,
            'intake' => $intake->uuid,
            'world_model_id' => $worldModel->model_id,
        ]);

        $this->assertSame($a['certification_hash'], $b['certification_hash']);
    }

    public function test_command_emits_json(): void
    {
        $this->memory('normal');
        $intake = $this->intake(CarbonImmutable::parse('2026-05-18T12:00:00Z'));
        $worldModel = $this->worldModel();
        $this->node($worldModel, 'node:a', 'app/A.php');

        $exit = Artisan::call('atlas:teos:final-certify', [
            '--intake' => $intake->uuid,
            '--world-model-id' => $worldModel->model_id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasTeosFinalCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertTrue($payload['claim_policy']['benchmark_not_run']);
    }

    private function memory(string $title): AtlasMemoryEntry
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');

        return AtlasMemoryEntry::query()->create([
            'memory_type' => 'technical_context',
            'scope_type' => 'global',
            'title' => $title,
            'body' => 'redacted',
            'summary' => 'summary',
            'importance' => 3,
            'priority' => 50,
            'confidence' => 0.9,
            'privacy_class' => 'normal',
            'external_ai_allowed' => true,
            'redaction_status' => 'clean',
            'source_type' => 'test',
            'source_id' => sha1($title),
            'source_label' => 'test',
            'status' => 'active',
            'tags' => [],
            'metadata' => [],
            'recorded_at' => $now,
            'last_used_at' => $now,
            'authority_level' => 'verified',
        ]);
    }

    private function intake(CarbonImmutable $createdAt): AiForgeIntake
    {
        $intake = AiForgeIntake::query()->create([
            'schema_version' => 'atlas.forge.intake.v1',
            'uuid' => (string) str()->uuid(),
            'origin' => 'test',
            'recommended_forge_mode' => 'obra',
            'obra_title' => 'Obra TEOS',
            'workspace_slug' => 'atlas',
            'original_user_intent' => 'redacted',
            'risk_band' => 'medium',
            'definition_of_done' => ['done'],
            'required_evidence' => ['test'],
            'status' => 'ready',
            'intake_hash' => hash('sha256', (string) str()->uuid()),
            'actor_type' => 'system',
        ]);
        $intake->timestamps = false;
        $intake->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        return $intake->refresh();
    }

    private function worldModel(): AiCodebaseWorldModel
    {
        return AiCodebaseWorldModel::query()->create([
            'model_id' => 'wm_'.substr(hash('sha256', (string) str()->uuid()), 0, 12),
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['programming'],
            'risks' => [],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', (string) str()->uuid()),
        ]);
    }

    private function node(AiCodebaseWorldModel $model, string $nodeId, string $path): AiCodebaseWorldModelNode
    {
        return AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => $nodeId,
            'node_type' => 'file',
            'path' => $path,
            'flow_id' => 'programming.dev',
            'capabilities' => ['programming'],
            'risks' => [],
            'metadata' => [],
        ]);
    }

    private function bootWorldModelTables(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');

        Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('goal_record_id')->nullable();
            $table->string('schema_version', 120)->default('atlas.ai.autonomous_engineering.codebase_world_model.v1');
            $table->string('model_id', 120)->unique();
            $table->string('scope', 160)->default('atlas-server');
            $table->string('status', 40)->default('built');
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('receipt')->nullable();
            $table->string('model_hash', 64)->unique();
            $table->timestamps();
        });

        Schema::create('ai_codebase_world_model_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('world_model_id');
            $table->string('node_id', 160);
            $table->string('node_type', 80);
            $table->string('path', 500)->nullable();
            $table->string('flow_id', 80)->nullable();
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_codebase_world_model_edges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('world_model_id');
            $table->string('from_node_id', 160);
            $table->string('to_node_id', 160);
            $table->string('edge_type', 80);
            $table->json('metadata')->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('observed_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('stale_after')->nullable()->index();
            $table->string('source_hash', 64)->nullable();
            $table->uuid('superseded_by')->nullable()->index();
            $table->string('authority_level', 40)->nullable()->index();
            $table->timestamps();
        });
    }
}
