<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MAXD-07 — Drill-down federado module→símbolos + veredito do órgão órfão.
 *
 * Prova o contrato:
 * - `--expand=code` (opt-in) devolve símbolos reais lidos de
 *   `atlas_engineering_code_symbols` para os nós module do resultado,
 *   com `module_id` citado, ordenação determinística e `federated=true`;
 * - store node/edge count NÃO muda com o expand (federated, não persistido);
 * - `--expand-per-module` respeita cap default (5) e hard-cap (20);
 * - sem `--expand`, o resultado NÃO carrega `federated_expansions` (progressive
 *   disclosure padrão).
 */
final class Maxd07FederatedCodeExpandTest extends TestCase
{
    private const M1 = 'memory:memory_entry:mem-1';

    private const C1 = 'code:module:atlas-server/services-ai-memory';

    protected function setUp(): void
    {
        parent::setUp();

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->down();
        $migration->up();
        (require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php'))->up();

        $this->bootCodeIntelligenceTables();

        config()->set('atlas.aurg.enabled', true);

        $this->seedBrain();
        $this->seedCodeIntelligence(symbolCount: 10);
    }

    public function test_default_query_does_not_expand_and_omits_federated_field(): void
    {
        $result = $this->service()->query('embedding decision');

        $this->assertArrayNotHasKey('federated_expansions', $result);
        $this->assertArrayNotHasKey('expand', $result);
    }

    public function test_expand_code_returns_top_n_symbols_marked_federated(): void
    {
        $result = $this->service()->query('embedding decision', [
            'expand' => 'code',
        ]);

        $this->assertArrayHasKey('federated_expansions', $result);
        $this->assertSame(['code'], $result['expand']);
        $code = $result['federated_expansions']['code'];

        $this->assertTrue($code['ready']);
        $this->assertSame(5, $code['per_module_cap']);
        $this->assertSame(20, $code['per_module_hard_cap']);
        $this->assertGreaterThanOrEqual(1, $code['modules_expanded']);

        $moduleItems = collect($code['items'])->where('module_node_id', self::C1);
        $this->assertCount(1, $moduleItems);
        $item = $moduleItems->first();
        $this->assertTrue($item['federated']);
        $this->assertSame('services-ai-memory', $item['slug']);
        // Deterministic order: symbol_name asc.
        $names = array_column($item['symbols'], 'symbol_name');
        $sorted = $names;
        sort($sorted);
        $this->assertSame($sorted, $names, 'symbols must be returned in deterministic name asc order');
        // Cap of 5.
        $this->assertCount(5, $item['symbols']);
        $this->assertTrue($item['symbols_capped']);

        foreach ($item['symbols'] as $sym) {
            $this->assertTrue($sym['federated']);
            $this->assertArrayHasKey('id', $sym);
            $this->assertArrayHasKey('symbol_type', $sym);
            $this->assertArrayHasKey('file_path', $sym);
        }
    }

    public function test_store_node_and_edge_count_invariant_across_expand(): void
    {
        $nodesBefore = AtlasAurgNode::query()->count();
        $edgesBefore = AtlasAurgEdge::query()->count();

        $result = $this->service()->query('embedding decision', [
            'expand' => 'code',
            'expand_symbols_per_module' => 3,
        ]);

        $nodesAfter = AtlasAurgNode::query()->count();
        $edgesAfter = AtlasAurgEdge::query()->count();

        $this->assertSame($nodesBefore, $nodesAfter, 'expand must not persist any new node in the AURG store');
        $this->assertSame($edgesBefore, $edgesAfter, 'expand must not persist any new edge in the AURG store');

        $storeInvariant = $result['federated_expansions']['code']['store_invariant'];
        $this->assertSame(0, $storeInvariant['delta_nodes']);
        $this->assertSame(0, $storeInvariant['delta_edges']);
        $this->assertSame($nodesBefore, $storeInvariant['nodes_before']);
        $this->assertSame($nodesAfter, $storeInvariant['nodes_after']);
    }

    public function test_per_module_hard_cap_is_enforced(): void
    {
        $this->seedCodeIntelligence(symbolCount: 40); // saturate beyond hard cap

        $result = $this->service()->query('embedding decision', [
            'expand' => 'code',
            'expand_symbols_per_module' => 999, // caller tries to jump the cap
        ]);

        $item = collect($result['federated_expansions']['code']['items'])
            ->firstWhere('module_node_id', self::C1);
        $this->assertNotNull($item);
        // Hard cap 20, not 999.
        $this->assertSame(AtlasRealityGraphQueryService::EXPAND_SYMBOLS_PER_MODULE_HARD_CAP, count($item['symbols']));
        $this->assertSame(20, $result['federated_expansions']['code']['per_module_cap']);
    }

    private function service(): AtlasRealityGraphQueryService
    {
        return $this->app->make(AtlasRealityGraphQueryService::class);
    }

    private function seedBrain(): void
    {
        $nodes = [
            [
                'id' => self::M1,
                'kind' => 'memory_entry',
                'source_kind' => 'memory',
                'source_id' => 'mem-1',
                'label' => 'Semantic embedding decision for memoria vector search',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['type' => 'decision', 'scope' => 'global'],
            ],
            [
                'id' => self::C1,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => 'atlas-server/services-ai-memory',
                'label' => 'Ai Memory',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => 'services-ai-memory', 'layer' => 'services'],
            ],
        ];
        foreach ($nodes as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }

        AtlasAurgEdge::query()->create([
            'from_node_id' => self::M1,
            'to_node_id' => self::C1,
            'kind' => 'references',
            'source' => 'linker_memory_code',
            'confidence' => 1.0,
            'meta' => ['matched_path' => 'app/Services/Ai/Memory'],
        ]);
    }

    private function bootCodeIntelligenceTables(): void
    {
        if (! Schema::hasTable('atlas_engineering_code_modules')) {
            Schema::create('atlas_engineering_code_modules', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->string('slug', 160)->unique();
                $t->string('name', 220);
                $t->string('layer', 80)->nullable();
                $t->string('root_path', 500)->nullable();
                $t->string('status', 32)->default('active');
                $t->string('source_hash', 64)->default('');
                $t->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            Schema::create('atlas_engineering_code_symbols', function (Blueprint $t): void {
                $t->uuid('id')->primary();
                $t->uuid('module_id')->nullable();
                $t->string('symbol_type', 60);
                $t->string('symbol_name', 300);
                $t->string('file_path', 500);
                $t->unsignedInteger('line_start')->nullable();
                $t->unsignedInteger('line_end')->nullable();
                $t->string('language', 40)->nullable();
                $t->text('signature')->nullable();
                $t->string('namespace', 220)->nullable();
                $t->string('status', 32)->default('active');
                $t->string('source_hash', 64)->default('');
                $t->timestamps();
            });
        }
    }

    private function seedCodeIntelligence(int $symbolCount): void
    {
        DB::table('atlas_engineering_code_symbols')->truncate();
        DB::table('atlas_engineering_code_modules')->truncate();

        $moduleId = (string) Str::uuid();
        DB::table('atlas_engineering_code_modules')->insert([
            'id' => $moduleId,
            'slug' => 'services-ai-memory',
            'name' => 'Ai Memory',
            'layer' => 'services',
            'root_path' => 'app/Services/Ai/Memory',
            'status' => 'active',
            'source_hash' => hash('sha256', $moduleId),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        for ($i = 0; $i < $symbolCount; $i++) {
            DB::table('atlas_engineering_code_symbols')->insert([
                'id' => (string) Str::uuid(),
                'module_id' => $moduleId,
                'symbol_type' => 'class',
                'symbol_name' => sprintf('Symbol%03d', $i),
                'file_path' => sprintf('app/Services/Ai/Memory/Symbol%03d.php', $i),
                'line_start' => 1,
                'line_end' => 20,
                'language' => 'php',
                'signature' => null,
                'namespace' => 'App\\Services\\Ai\\Memory',
                'status' => 'active',
                'source_hash' => hash('sha256', 'sym-'.$i),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
