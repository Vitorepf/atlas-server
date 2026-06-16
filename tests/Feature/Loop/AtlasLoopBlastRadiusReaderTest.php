<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AiCodebaseWorldModel;
use App\Models\AiCodebaseWorldModelEdge;
use App\Models\AiCodebaseWorldModelNode;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBlastRadiusReader;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ACDE B4a — proves the blast-radius brain reads the live code graph: the edited file's reverse-dependency
 * consumers are surfaced as repo paths + a risk band; the edited file and unrelated files are excluded; and
 * the flag OFF is byte-identical empty.
 */
final class AtlasLoopBlastRadiusReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $t) {
            Schema::dropIfExists($t);
        }
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (['ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $t) {
            Schema::dropIfExists($t);
        }
        parent::tearDown();
    }

    private function seedGraph(): string
    {
        $model = AiCodebaseWorldModel::query()->create([
            'goal_record_id' => null,
            'model_id' => 'm-'.substr(hash('sha256', (string) Str::uuid()), 0, 18),
            'scope' => 'atlas-server-symbols',
            'status' => 'built',
            'capabilities' => ['code_graph'],
            'risks' => [],
            'receipt' => [],
            'model_hash' => hash('sha256', (string) Str::uuid()),
        ]);
        $modelId = (string) $model->id;

        // target file + two distinct consumers + one unrelated file (no edge to the target).
        $nodes = [
            ['node_id' => 'n_target', 'path' => 'app/Foo/Target.php'],
            ['node_id' => 'n_consumerA', 'path' => 'app/Foo/ConsumerA.php'],
            ['node_id' => 'n_consumerB', 'path' => 'app/Bar/ConsumerB.php'],
            ['node_id' => 'n_unrelated', 'path' => 'app/Baz/Unrelated.php'],
        ];
        foreach ($nodes as $n) {
            AiCodebaseWorldModelNode::query()->create([
                'world_model_id' => $modelId,
                'node_id' => $n['node_id'],
                'node_type' => 'file',
                'path' => $n['path'],
            ]);
        }
        // reverse-dependency edges: ConsumerA -> Target, ConsumerB -> Target (they point AT the target).
        foreach (['n_consumerA', 'n_consumerB'] as $from) {
            AiCodebaseWorldModelEdge::query()->create([
                'world_model_id' => $modelId,
                'from_node_id' => $from,
                'to_node_id' => 'n_target',
                'edge_type' => 'consumes',
            ]);
        }

        return $modelId;
    }

    public function test_surfaces_reverse_dependency_consumers_as_paths_excluding_self_and_unrelated(): void
    {
        config(['atlas.loop.blast_radius_brain_enabled' => true]);
        $this->seedGraph();

        $blast = (new AtlasLoopBlastRadiusReader)->read('app/Foo/Target.php');

        $this->assertNotSame([], $blast, 'a file with consumers yields a blast-radius summary');
        $this->assertSame(2, $blast['consumer_count']);
        $this->assertContains('app/Foo/ConsumerA.php', $blast['consumers']);
        $this->assertContains('app/Bar/ConsumerB.php', $blast['consumers']);
        $this->assertNotContains('app/Foo/Target.php', $blast['consumers'], 'the edited file is not its own consumer');
        $this->assertNotContains('app/Baz/Unrelated.php', $blast['consumers'], 'a file with no edge to the target is not a consumer');
        $this->assertContains($blast['risk'], ['low', 'medium', 'high', 'critical']);
    }

    public function test_a_file_with_no_consumers_yields_empty(): void
    {
        config(['atlas.loop.blast_radius_brain_enabled' => true]);
        $this->seedGraph();

        // Unrelated has no incoming edges => no consumers => empty (never a false signal).
        $this->assertSame([], (new AtlasLoopBlastRadiusReader)->read('app/Baz/Unrelated.php'));
    }

    public function test_off_is_byte_identical_empty(): void
    {
        config(['atlas.loop.blast_radius_brain_enabled' => false]);
        $this->seedGraph();

        $this->assertSame([], (new AtlasLoopBlastRadiusReader)->read('app/Foo/Target.php'), 'flag OFF => empty => byte-identical');
    }
}
