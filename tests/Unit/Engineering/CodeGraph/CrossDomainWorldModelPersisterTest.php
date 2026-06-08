<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering\CodeGraph;

use App\Services\Engineering\CodeGraph\CrossDomainWorldModelPersister;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * @covers \App\Services\Engineering\CodeGraph\CrossDomainWorldModelPersister
 */
class CrossDomainWorldModelPersisterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Boot ONLY the world-model tables, driver-agnostically (the core migration is
        // raw Postgres in places, so no RefreshDatabase).
        $this->dropTables();

        Schema::create('ai_codebase_world_models', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('goal_record_id')->nullable();
            $table->string('schema_version', 120)->default('x');
            $table->string('model_id', 120)->unique();
            $table->string('scope', 160)->default('atlas-server');
            $table->string('status', 40)->default('built');
            $table->json('capabilities')->nullable();
            $table->json('risks')->nullable();
            $table->json('receipt')->nullable();
            $table->string('model_hash', 64);
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
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        $this->dropTables();

        parent::tearDown();
    }

    public function test_persists_canonical_graph_into_cross_domain_world_model(): void
    {
        [$nodes, $edges] = $this->tinyGraph();

        $result = app(CrossDomainWorldModelPersister::class)->persist($nodes, $edges);

        $this->assertSame('cross-domain', $result['model_id']);
        $this->assertNotNull($result['world_model_id']);
        $this->assertSame(2, $result['node_count']);
        $this->assertSame(1, $result['edge_count']);

        // The world model exists with the cross-domain scope.
        $model = DB::table('ai_codebase_world_models')->where('model_id', 'cross-domain')->first();
        $this->assertNotNull($model);
        $this->assertSame('cross-domain', $model->scope);
        $this->assertSame((string) $model->id, $result['world_model_id']);

        // Rows landed against that model.
        $this->assertSame(2, DB::table('ai_codebase_world_model_nodes')->where('world_model_id', $model->id)->count());
        $this->assertSame(1, DB::table('ai_codebase_world_model_edges')->where('world_model_id', $model->id)->count());

        // Canonical node ids preserved + label folded into metadata.
        $engNode = DB::table('ai_codebase_world_model_nodes')->where('node_id', 'domain:engineering')->first();
        $this->assertNotNull($engNode);
        $this->assertSame('domain', $engNode->node_type);
        $meta = json_decode((string) $engNode->metadata, true);
        $this->assertSame('engineering', $meta['domain']);
        $this->assertSame('Software Engineering', $meta['label']);

        // Edge preserved with EXTRACTED confidence folded into metadata.
        $edge = DB::table('ai_codebase_world_model_edges')->first();
        $this->assertSame('domain:engineering', $edge->from_node_id);
        $this->assertSame('domain:finance', $edge->to_node_id);
        $this->assertSame('hands_off_to', $edge->edge_type);
        $edgeMeta = json_decode((string) $edge->metadata, true);
        $this->assertSame('EXTRACTED', $edgeMeta['confidence']);
        $this->assertTrue($edgeMeta['cross_domain']);
    }

    public function test_rebuild_is_idempotent_no_duplicate_model_or_rows(): void
    {
        [$nodes, $edges] = $this->tinyGraph();
        $persister = app(CrossDomainWorldModelPersister::class);

        $first = $persister->persist($nodes, $edges);
        $second = $persister->persist($nodes, $edges);

        // Same single model reused (no duplicate).
        $this->assertSame($first['world_model_id'], $second['world_model_id']);
        $this->assertSame(1, DB::table('ai_codebase_world_models')->where('model_id', 'cross-domain')->count());

        // Counts re-reported, rows replaced not accumulated.
        $this->assertSame(2, $second['node_count']);
        $this->assertSame(1, $second['edge_count']);
        $this->assertSame(2, DB::table('ai_codebase_world_model_nodes')->count());
        $this->assertSame(1, DB::table('ai_codebase_world_model_edges')->count());
    }

    public function test_fail_open_when_tables_absent(): void
    {
        $this->dropTables();

        [$nodes, $edges] = $this->tinyGraph();
        $result = app(CrossDomainWorldModelPersister::class)->persist($nodes, $edges);

        $this->assertNull($result['world_model_id']);
        $this->assertSame(0, $result['node_count']);
        $this->assertSame(0, $result['edge_count']);
        $this->assertSame('cross-domain', $result['model_id']);
    }

    /**
     * @return array{0:list<array<string,mixed>>, 1:list<array<string,mixed>>}
     */
    private function tinyGraph(): array
    {
        $nodes = [
            [
                'node_id' => 'domain:engineering',
                'node_type' => 'domain',
                'label' => 'Software Engineering',
                'metadata' => ['domain' => 'engineering', 'privacy_class' => 'normal'],
            ],
            [
                'node_id' => 'domain:finance',
                'node_type' => 'domain',
                'label' => 'Finance',
                'metadata' => ['domain' => 'finance', 'privacy_class' => 'sensitive'],
            ],
        ];
        $edges = [
            [
                'from_node_id' => 'domain:engineering',
                'to_node_id' => 'domain:finance',
                'edge_type' => 'hands_off_to',
                'confidence' => 'EXTRACTED',
                'confidence_score' => 1.0,
                'metadata' => ['cross_domain' => true, 'occurrences' => 1],
            ],
        ];

        return [$nodes, $edges];
    }

    private function dropTables(): void
    {
        Schema::dropIfExists('ai_codebase_world_model_edges');
        Schema::dropIfExists('ai_codebase_world_model_nodes');
        Schema::dropIfExists('ai_codebase_world_models');
    }
}
