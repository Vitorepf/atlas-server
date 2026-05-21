<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Models\AtlasRealityEntity;
use App\Models\AtlasRealityRelationship;
use App\Services\Ai\Context\AtlasUnifiedRealityGraphService;
use App\Services\Ai\Mission\MissionCanonicalHash;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\CreatesStrategicRealityTables;
use Tests\TestCase;

final class UnifiedRealityGraphTest extends TestCase
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

    public function test_snapshot_projects_entities_edges_sources_and_claim_policy(): void
    {
        [$project, $decision] = $this->seedRealityGraph();

        $payload = app(AtlasUnifiedRealityGraphService::class)->snapshot([
            'risk' => 'medium',
            'hours' => 24,
        ]);

        $this->assertSame(AtlasUnifiedRealityGraphService::SNAPSHOT_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(2, data_get($payload, 'summary.entities_total'));
        $this->assertSame(1, data_get($payload, 'summary.edges_total'));
        $this->assertGreaterThanOrEqual(3, data_get($payload, 'summary.sources_total'));
        $this->assertSame('passed', data_get($payload, 'quality_gate.status'));
        $this->assertFalse(data_get($payload, 'claim_policy.truth_graph_complete'));
        $this->assertFalse(data_get($payload, 'claim_policy.providers_invoked'));
        $this->assertFalse(data_get($payload, 'claim_policy.writes'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['snapshot_hash']);

        $firstEntity = $payload['entities'][0];
        $this->assertSame(AtlasUnifiedRealityGraphService::ENTITY_SCHEMA, $firstEntity['schema_version']);
        $this->assertSame(MissionCanonicalHash::sha256($project->name), $firstEntity['name_hash']);
        $this->assertSame('current', $firstEntity['freshness_status']);
        $this->assertGreaterThan(0.6, $firstEntity['confidence']);

        $edge = $payload['edges'][0];
        $this->assertSame(AtlasUnifiedRealityGraphService::EDGE_SCHEMA, $edge['schema_version']);
        $this->assertSame('governed_by', $edge['relationship_type']);
        $this->assertSame(MissionCanonicalHash::sha256((string) $project->id), $edge['source_entity_ref']);
        $this->assertSame(MissionCanonicalHash::sha256((string) $decision->id), $edge['target_entity_ref']);

        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('fonte secreta nao deve vazar', $json);
    }

    public function test_high_risk_snapshot_blocks_when_entities_are_unsourced_or_stale(): void
    {
        AtlasRealityEntity::query()->create([
            'schema_version' => 'atlas.strategic_reality.entity.v1',
            'status' => 'active',
            'entity_key' => 'project-stale',
            'entity_type' => 'project',
            'name' => 'Projeto Stale',
            'authority_level' => 'operator_declared',
            'freshness_status' => 'current',
            'observed_at' => CarbonImmutable::now()->subDays(20),
            'valid_until' => CarbonImmutable::now()->subDay(),
            'attributes' => [],
            'evidence_refs' => [],
            'source_refs' => [],
            'entity_hash' => MissionCanonicalHash::sha256('project-stale'),
        ]);

        $payload = app(AtlasUnifiedRealityGraphService::class)->snapshot([
            'risk' => 'high',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('watch', data_get($payload, 'quality_gate.status'));
        $this->assertContains('stale_entities_present', data_get($payload, 'quality_gate.warnings'));
        $this->assertContains('unsourced_entities_present', data_get($payload, 'quality_gate.warnings'));
    }

    public function test_command_emits_canonical_json(): void
    {
        $this->seedRealityGraph();

        $exitCode = Artisan::call('atlas:context:reality-graph', [
            '--hours' => 24,
            '--risk' => 'medium',
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(AtlasUnifiedRealityGraphService::SNAPSHOT_SCHEMA, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(2, data_get($payload, 'summary.entities_total'));
    }

    /**
     * @return array{0:AtlasRealityEntity,1:AtlasRealityEntity}
     */
    private function seedRealityGraph(): array
    {
        $project = AtlasRealityEntity::query()->create([
            'schema_version' => 'atlas.strategic_reality.entity.v1',
            'status' => 'active',
            'entity_key' => 'project-atlas-aucri',
            'entity_type' => 'project',
            'name' => 'Atlas AUCRI',
            'authority_level' => 'canonical_evidence',
            'freshness_status' => 'current',
            'observed_at' => CarbonImmutable::now(),
            'valid_until' => CarbonImmutable::now()->addDays(10),
            'attributes' => ['raw_note' => 'fonte secreta nao deve vazar'],
            'evidence_refs' => ['docs/engineering-knowledge-base/atlas-unified-context-retrieval-intelligence.md'],
            'source_refs' => ['atlas.aucri.plan'],
            'entity_hash' => MissionCanonicalHash::sha256('project-atlas-aucri'),
        ]);

        $decision = AtlasRealityEntity::query()->create([
            'schema_version' => 'atlas.strategic_reality.entity.v1',
            'status' => 'active',
            'entity_key' => 'decision-implement-aurg',
            'entity_type' => 'decision',
            'name' => 'Implementar AURG bounded',
            'authority_level' => 'system_observed',
            'freshness_status' => 'current',
            'observed_at' => CarbonImmutable::now(),
            'valid_until' => CarbonImmutable::now()->addDays(7),
            'attributes' => [],
            'evidence_refs' => ['tests/Feature/Ai/Context/UnifiedRealityGraphTest.php'],
            'source_refs' => [],
            'entity_hash' => MissionCanonicalHash::sha256('decision-implement-aurg'),
        ]);

        AtlasRealityRelationship::query()->create([
            'schema_version' => 'atlas.strategic_reality.relationship.v1',
            'status' => 'active',
            'source_entity_id' => $project->id,
            'target_entity_id' => $decision->id,
            'relationship_type' => 'governed_by',
            'weight' => 0.91,
            'attributes' => ['raw_note' => 'fonte secreta nao deve vazar'],
            'evidence_refs' => ['receipt:aurg-test'],
            'relationship_hash' => MissionCanonicalHash::sha256('project-decision'),
        ]);

        return [$project, $decision];
    }
}
