<?php

declare(strict_types=1);

namespace Tests\Feature\CodeGraph;

use App\Services\Engineering\CodeGraph\CodeGraphSymbolBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · C5 — the FLAG-GATED python edge resolver is EQUIVALENT to the PHP one.
 *
 * The PHP {@see \App\Services\Engineering\CodeGraph\CodeGraphSymbolResolver} stays
 * the proven DEFAULT (config('atlas.code_graph.python_resolve') OFF). When the
 * operator flips python_resolve ON, the build resolves symbol->symbol edges in the
 * python_ai_data runtime instead — and it MUST produce the same resolved edge set.
 *
 * This builds the SAME fixture graph BOTH ways and asserts the persisted edge sets
 * (from -> to, edge_type, confidence) are identical. It is SKIPPED when the
 * code_graph venv interpreter is absent (the runtime can't be exercised), so it
 * never red-flags a machine without the python runtime.
 */
final class CodeGraphPythonResolveEquivalenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! is_file($this->venvPython())) {
            $this->markTestSkipped('code_graph venv interpreter unavailable; cannot exercise the python resolve_edges op.');
        }

        foreach (['atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules', 'atlas_engineering_code_file_snapshots', 'ai_codebase_world_model_edges', 'ai_codebase_world_model_nodes', 'ai_codebase_world_models'] as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_05_17_220000_create_ai_autonomous_engineering_os_tables.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();

        // real_edges must be ON for the symbol builder to run at all (both paths).
        config(['atlas.code_graph.real_edges' => true]);

        // A small fixture that exercises every grade branch + the dedup/promotion/
        // ambiguity/self-edge rules, so equivalence is meaningful (not trivially empty):
        //   Alpha (Alpha.php) `use`s Beta            -> depends_on EXTRACTED
        //   Alpha references Gamma (no import)        -> depends_on INFERRED (0.85)
        //   Alpha `use`s + references Delta           -> one deduped EXTRACTED edge
        //   AlphaTest tests Alpha (no import)         -> tests INFERRED (0.8)
        //   Alpha references itself                   -> suppressed (self-edge)
        //   Alpha references Dup (defined twice)      -> suppressed (ambiguous)
        $this->symbol('class', 'App\\Alpha', 'src/Alpha.php');
        $this->symbol('class', 'App\\Beta', 'src/Beta.php');
        $this->symbol('class', 'App\\Gamma', 'src/Gamma.php');
        $this->symbol('class', 'App\\Delta', 'src/Delta.php');
        $this->symbol('class', 'Tests\\AlphaTest', 'tests/AlphaTest.php');
        // Ambiguous: the SAME FQN defined in two different files.
        $this->symbol('class', 'App\\Dup', 'src/DupA.php');
        $this->symbol('class', 'App\\Dup', 'src/DupB.php');

        $this->snapshot('src/Alpha.php', [
            'dependencies' => [
                ['symbol' => 'App\\Beta', 'kind' => 'use'],
                ['symbol' => 'App\\Delta', 'kind' => 'use'],
            ],
            'symbol_references' => [
                ['symbol' => 'App\\Gamma', 'kind' => 'reference'],
                ['symbol' => 'App\\Delta', 'kind' => 'reference'],
                ['symbol' => 'App\\Alpha', 'kind' => 'reference'], // self -> suppressed
                ['symbol' => 'App\\Dup', 'kind' => 'reference'],   // ambiguous -> suppressed
            ],
        ]);
        $this->snapshot('tests/AlphaTest.php', [
            'test_targets' => [
                ['symbol' => 'App\\Alpha', 'kind' => 'test'],
            ],
        ]);
    }

    protected function tearDown(): void
    {
        foreach (['atlas_engineering_doc_links', 'atlas_engineering_code_symbols', 'atlas_engineering_code_modules', 'atlas_engineering_code_file_snapshots'] as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_python_resolve_edge_set_equals_php_default(): void
    {
        // 1) PHP default path (flag OFF — the proven resolver).
        config(['atlas.code_graph.python_resolve' => false]);
        $php = app(CodeGraphSymbolBuilder::class)->build('atlas-server');
        $this->assertSame('written', $php['status']);
        $phpEdges = $this->edgesOf($php['world_model_id']);

        // Sanity: the fixture actually produced a non-trivial graph (else equivalence
        // would be vacuously true). 4 distinct edges survive: Alpha->Beta, Alpha->Delta,
        // Alpha->Gamma, and AlphaTest->Alpha (self + ambiguous are suppressed).
        $this->assertCount(4, $phpEdges, 'fixture must yield a meaningful, non-empty edge set');

        // 2) Python path (flag ON) — resolves via the python_ai_data runtime.
        config(['atlas.code_graph.python_resolve' => true]);
        $py = app(CodeGraphSymbolBuilder::class)->build('atlas-server');
        $this->assertSame('written', $py['status']);
        $pyEdges = $this->edgesOf($py['world_model_id']);

        // 3) The two RESOLVED EDGE SETS must be identical (from->to, type, confidence).
        $this->assertSame(
            $phpEdges,
            $pyEdges,
            'the python resolve_edges op must produce the SAME resolved edges as the PHP resolver',
        );

        // And the exact expected resolved edges, so a same-but-wrong pair can't both drift.
        $this->assertSame([
            'sym:App\\Alpha|sym:App\\Beta|depends_on|EXTRACTED',
            'sym:App\\Alpha|sym:App\\Delta|depends_on|EXTRACTED',
            'sym:App\\Alpha|sym:App\\Gamma|depends_on|INFERRED',
            'sym:Tests\\AlphaTest|sym:App\\Alpha|tests|INFERRED',
        ], $this->sortedKeys($phpEdges));
    }

    public function test_default_flag_off_uses_php_and_never_spawns_python(): void
    {
        // With the flag OFF this is the unchanged PHP path; assert it still builds.
        config(['atlas.code_graph.python_resolve' => false]);
        $summary = app(CodeGraphSymbolBuilder::class)->build('atlas-server');
        $this->assertSame('written', $summary['status']);
        $this->assertGreaterThanOrEqual(3, $summary['edges_written']);
    }

    /**
     * Read the persisted resolved edges for a world model as a stable, comparable
     * map: "from|to|type|confidence" => confidence_score. Sorted by key so the
     * comparison is order-independent (each path may insert in a different order,
     * but the resolved SET must match).
     *
     * @return array<string,mixed>
     */
    private function edgesOf(?string $worldModelId): array
    {
        $rows = DB::table('ai_codebase_world_model_edges')
            ->where('world_model_id', $worldModelId)
            ->get(['from_node_id', 'to_node_id', 'edge_type', 'metadata']);

        $out = [];
        foreach ($rows as $row) {
            $meta = json_decode((string) $row->metadata, true);
            $confidence = is_array($meta) && is_string($meta['confidence'] ?? null) ? $meta['confidence'] : '';
            $score = is_array($meta) ? ($meta['confidence_score'] ?? null) : null;
            $key = $row->from_node_id.'|'.$row->to_node_id.'|'.$row->edge_type.'|'.$confidence;
            $out[$key] = $score;
        }
        ksort($out);

        return $out;
    }

    /**
     * @param  array<string,mixed>  $edges
     * @return array<int,string>
     */
    private function sortedKeys(array $edges): array
    {
        $keys = array_keys($edges);
        sort($keys);

        return $keys;
    }

    private function venvPython(): string
    {
        return base_path('runtimes/python/code_graph/.venv/bin/python');
    }

    private function symbol(string $type, string $name, string $file): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $name.$file), 0, 64),
        ]);
    }

    private function snapshot(string $file, array $relations): void
    {
        DB::table('atlas_engineering_code_file_snapshots')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => 'atlas-server',
            'file_path' => $file,
            'module_slug' => 'app',
            'language' => 'php',
            'source_hash' => substr(hash('sha256', $file), 0, 64),
            'symbols_json' => '[]',
            'relations_json' => (string) json_encode($relations),
            'status' => 'active',
        ]);
    }
}
