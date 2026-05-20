<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\LongHorizon;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\LongHorizon\AtlasLongHorizonCanon;
use App\Services\Ai\LongHorizon\TimeAwareWorldModelService;
use App\Support\TemporalTruth\TemporalTruthCanon;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class TimeAwareWorldModelServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootWorldModelTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
        parent::tearDown();
    }

    public function test_snapshot_buckets_current_expired_stale_and_superseded_edges(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $model = $this->worldModel();
        $this->node($model, 'node:a', 'app/Services/A.php');
        $this->node($model, 'node:b', 'tests/A.php');
        $this->edge($model, 'node:a', 'node:b', ['valid_from' => $now->subDay(), 'valid_until' => $now->addDay()]);
        $this->edge($model, 'node:b', 'node:a', ['valid_until' => $now->subMinute()]);
        $this->edge($model, 'node:a', 'node:a', ['stale_after' => $now->subMinute()]);
        $superseder = $this->edge($model, 'node:b', 'node:b');
        $this->edge($model, 'node:a', 'node:b', ['superseded_by' => $superseder->id]);

        $payload = (new TimeAwareWorldModelService)->snapshot([
            'world_model_id' => $model->model_id,
            'at' => $now,
        ]);

        $this->assertSame(AtlasLongHorizonCanon::TIME_AWARE_WORLD_MODEL_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(TimeAwareWorldModelService::STATUS_READY, $payload['status']);
        $this->assertSame(3, $payload['summary']['current_edges']);
        $this->assertSame(1, $payload['summary']['expired_edges']);
        $this->assertSame(1, $payload['summary']['stale_edges']);
        $this->assertSame(1, $payload['summary']['superseded_edges']);
        $this->assertTrue($payload['freshness_policy']['planner_must_ignore_expired_edges']);
    }

    public function test_legacy_null_temporal_edges_are_current(): void
    {
        $model = $this->worldModel();
        $this->node($model, 'node:a', 'app/A.php');
        $this->node($model, 'node:b', 'app/B.php');
        $this->edge($model, 'node:a', 'node:b');

        $payload = (new TimeAwareWorldModelService)->snapshot(['world_model_id' => $model->model_id]);

        $this->assertSame(1, $payload['summary']['current_edges']);
        $this->assertSame(1, $payload['summary']['legacy_current_edges']);
    }

    public function test_flow_and_path_filters_limit_nodes_and_edges(): void
    {
        $model = $this->worldModel();
        $this->node($model, 'node:dev', 'app/Dev.php', 'programming.dev');
        $this->node($model, 'node:research', 'app/Research.php', 'research');
        $this->edge($model, 'node:dev', 'node:research');

        $payload = (new TimeAwareWorldModelService)->snapshot([
            'world_model_id' => $model->model_id,
            'flow_id' => 'programming.dev',
            'path_contains' => 'Dev',
        ]);

        $this->assertSame(1, $payload['summary']['nodes_considered']);
        $this->assertSame('node:dev', $payload['nodes'][0]['node_id']);
    }

    public function test_non_uuid_model_id_resolves_without_uuid_cast_error(): void
    {
        $model = $this->worldModel('aewm_non_uuid_model_id');
        $this->node($model, 'node:a', 'app/A.php');

        $payload = (new TimeAwareWorldModelService)->snapshot([
            'world_model_id' => 'aewm_non_uuid_model_id',
            'at' => CarbonImmutable::parse('2026-05-19T12:00:00Z'),
        ]);

        $this->assertSame(TimeAwareWorldModelService::STATUS_READY, $payload['status']);
        $this->assertSame('aewm_non_uuid_model_id', data_get($payload, 'world_model.model_id'));
    }

    public function test_missing_tables_block_honestly(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');

        $payload = (new TimeAwareWorldModelService)->snapshot();

        $this->assertSame(TimeAwareWorldModelService::STATUS_BLOCKED, $payload['status']);
        $this->assertSame(['world_model_tables_missing'], $payload['blockers']);
    }

    public function test_hash_is_deterministic(): void
    {
        $now = CarbonImmutable::parse('2026-05-19T12:00:00Z');
        $model = $this->worldModel();
        $this->node($model, 'node:a', 'app/A.php');

        $a = (new TimeAwareWorldModelService)->snapshot(['world_model_id' => $model->model_id, 'at' => $now]);
        $b = (new TimeAwareWorldModelService)->snapshot(['world_model_id' => $model->model_id, 'at' => $now]);

        $this->assertSame($a['snapshot_hash'], $b['snapshot_hash']);
    }

    public function test_command_emits_json(): void
    {
        $model = $this->worldModel();
        $this->node($model, 'node:a', 'app/A.php');

        $exit = Artisan::call('atlas:long-horizon:world-model', [
            '--world-model-id' => $model->model_id,
            '--json' => true,
        ]);

        $this->assertSame(0, $exit);
        $payload = json_decode(Artisan::output(), true);
        $this->assertSame(AtlasLongHorizonCanon::TIME_AWARE_WORLD_MODEL_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame(TimeAwareWorldModelService::STATUS_READY, $payload['status']);
    }

    private function worldModel(?string $modelId = null): AiCodebaseWorldModel
    {
        return AiCodebaseWorldModel::query()->create([
            'model_id' => $modelId ?? 'wm_'.substr(hash('sha256', (string) str()->uuid()), 0, 12),
            'scope' => 'atlas-server',
            'status' => 'built',
            'capabilities' => ['programming'],
            'risks' => [],
            'receipt' => ['schema_version' => 'atlas.ai.autonomous_engineering.codebase_world_model.v1'],
            'model_hash' => hash('sha256', (string) str()->uuid()),
        ]);
    }

    private function node(AiCodebaseWorldModel $model, string $nodeId, string $path, ?string $flowId = null): AiCodebaseWorldModelNode
    {
        return AiCodebaseWorldModelNode::query()->create([
            'world_model_id' => $model->id,
            'node_id' => $nodeId,
            'node_type' => str_contains($path, 'tests/') ? 'test' : 'file',
            'path' => $path,
            'flow_id' => $flowId,
            'capabilities' => ['programming'],
            'risks' => [],
            'metadata' => [],
        ]);
    }

    /**
     * @param  array<string,mixed>  $overrides
     */
    private function edge(AiCodebaseWorldModel $model, string $from, string $to, array $overrides = []): AiCodebaseWorldModelEdge
    {
        return AiCodebaseWorldModelEdge::query()->create(array_merge([
            'world_model_id' => $model->id,
            'from_node_id' => $from,
            'to_node_id' => $to,
            'edge_type' => 'depends_on',
            'metadata' => [],
            'authority_level' => TemporalTruthCanon::AUTHORITY_AUTOMATION,
        ], $overrides));
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
