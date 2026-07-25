<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasOpenBrainFileContextService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * AOBG N2.F1 — the ACTIVE brain's PostToolUse file-context surface.
 *
 * Locks the contract over a sqlite, COST-FREE fixture seeding the three brains a
 * file-delta fuses (no provider call anywhere — pure local DB reads). Tables are
 * built with the sqlite-safe concern + inline builders (the suite avoids
 * RefreshDatabase because some migrations are Postgres-only raw SQL):
 *  - code-graph symbols (atlas_engineering_code_symbols, W-1 workspace_id keyed) —
 *    one DEFINED in the target file + one CONSUMER in another file;
 *  - AURG reality-graph nodes/edges seeded so the file's MODULE has a decision +
 *    a SENSITIVE domain neighbor (the provider-bound exclusion case);
 *  - provider-safe + secret memory entries that REFERENCE the file's class stem.
 *
 * Asserts: the delta names the file's decisions/neighbors/missions; honest empty
 * for an unknown file; provider_bound excludes the sensitive domain + secret
 * memory; the relevance floor drops over-recalled generic memory; budget ceiling;
 * the CLI exits 0 and validates input; fail-open never throws.
 *
 * AURG fixture topology (the file lives under the Cmod module):
 *   D1(decision/memory) -references-> Cmod(code module) ; D1 -belongs_to-> Dfin(SENSITIVE)
 */
final class AtlasOpenBrainFileContextServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    /** The file the engine "touched" (under the seeded module path). */
    private const TARGET_FILE = 'app/Services/Ai/Foo/WidgetEmbeddingResolver.php';

    private const STEM = 'WidgetEmbeddingResolver';

    private const D1 = 'memory:memory_entry:dec-1';

    private const CMOD = 'code:module:atlas-server/services-ai-foo';

    private const DFIN = 'domain:domain:finance';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->createCodeSymbolsTable();
        $this->createAurgTables();

        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.aurg.query_rank_enabled', false); // deterministic, no runtime
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    public function test_file_context_returns_decisions_neighbors_and_missions(): void
    {
        // A symbol DEFINED in the target file + a CONSUMER in another file.
        $this->seedSymbol(self::STEM, self::TARGET_FILE, 'class', 'class '.self::STEM);
        $this->seedSymbol(
            'WidgetConsumerController',
            'app/Http/Controllers/WidgetConsumerController.php',
            'class',
            'class WidgetConsumerController uses '.self::STEM,
        );
        $this->seedAurg();
        // A provider-safe memory whose body references the file's class stem.
        $this->seedMemory('dec-1', self::STEM.' embedding decision', true, 'normal', 'decision');

        $delta = $this->service()->contextFor(self::TARGET_FILE);

        $this->assertSame(AtlasOpenBrainFileContextService::SCHEMA, $delta['schema']);
        $this->assertTrue($delta['provider_bound']);
        $this->assertTrue($delta['has_context']);
        $this->assertSame('curated top-K (not exhaustive)', $delta['honesty']);

        // "Symbols defined in this file" — the resolver class.
        $definedNames = array_column($delta['defined_symbols'], 'symbol_name');
        $this->assertContains(self::STEM, $definedNames);

        // "Consumed by Z" — the controller that references the stem, NOT the file itself.
        $consumerNames = array_column($delta['consumers'], 'symbol_name');
        $this->assertContains('WidgetConsumerController', $consumerNames);
        $this->assertNotContains(self::STEM, $consumerNames, 'a symbol in the file itself is not its consumer');

        // "Decision X / mission Y" — a cross-layer path touching the file's module.
        $targets = array_column($delta['reality_graph_paths'], 'target');
        $this->assertContains(self::CMOD, $targets);

        // The provider-safe memory referencing the file is present.
        $memoryTitles = array_column($delta['memory'], 'title');
        $this->assertNotEmpty(array_filter($memoryTitles, fn (string $t): bool => str_contains($t, self::STEM)));

        // Rendered markdown carries all four section headers.
        $this->assertStringContainsString('# Atlas brain — what is known about this file (AOBG)', $delta['markdown']);
        $this->assertStringContainsString('## Decisions / missions touching this file', $delta['markdown']);
        $this->assertStringContainsString('## Memory referencing this file', $delta['markdown']);
        $this->assertStringContainsString('## Consumed by', $delta['markdown']);
        $this->assertStringContainsString('## Symbols defined in this file', $delta['markdown']);
    }

    public function test_unknown_file_is_honest_empty_and_never_throws(): void
    {
        // No symbols, no AURG node for this module, no memory mentioning the stem.
        $this->seedAurg(); // brain has OTHER content, but nothing about this file
        $this->seedMemory('dec-1', 'Some unrelated decision about caching', true, 'normal', 'decision');

        $delta = $this->service()->contextFor('app/Services/Zzz/QqxxUnknownThing.php');

        $this->assertFalse($delta['has_context']);
        $this->assertSame([], $delta['defined_symbols']);
        $this->assertSame([], $delta['consumers']);
        $this->assertSame([], $delta['reality_graph_paths']);
        $this->assertSame([], $delta['memory']);
        $this->assertSame([], $delta['provenance']['sources_present']);
        $this->assertTrue($delta['provider_bound']);

        // Empty sections are LABELLED honestly in markdown (not silently dropped).
        $this->assertStringContainsString('_file not in the workspace code index_', $delta['markdown']);
        $this->assertStringContainsString('_no provider-safe paths', $delta['markdown']);
        $this->assertStringContainsString('_no provider-safe memory', $delta['markdown']);
        $this->assertStringContainsString('_no known consumers', $delta['markdown']);

        // A blank path is also a clean, non-throwing empty delta.
        $blank = $this->service()->contextFor('   ');
        $this->assertSame('', $blank['path']);
        $this->assertFalse($blank['has_context']);
        $this->assertSame([], $blank['memory']);
    }

    public function test_relevance_floor_drops_over_recalled_generic_memory(): void
    {
        // A generic high-importance memory that does NOT mention the file's stem.
        // The hybrid recall ranks it high, but the file-delta must DROP it: a
        // PostToolUse hook firing on every file open cannot inject generic noise.
        $this->seedMemory('generic', 'Atlas canonical architecture principle', true, 'normal', 'principle');
        // A memory that DOES mention the stem must survive.
        $this->seedMemory('relevant', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');

        $delta = $this->service()->contextFor(self::TARGET_FILE);

        $titles = array_column($delta['memory'], 'title');
        $this->assertNotEmpty(
            array_filter($titles, fn (string $t): bool => str_contains($t, self::STEM)),
            'a memory mentioning the file stem must be kept',
        );
        $this->assertEmpty(
            array_filter($titles, fn (string $t): bool => str_contains($t, 'canonical architecture')),
            'a generic memory that never mentions the file must be dropped (no over-recall on the hot path)',
        );
    }

    public function test_provider_bound_excludes_sensitive_domain_and_secret_memory(): void
    {
        $this->seedAurg();
        $this->seedMemory('safe', self::STEM.' safe note', true, 'normal', 'decision');
        // A SECRET memory whose title also references the stem — must NOT ride out.
        $this->seedMemory('secret', self::STEM.' secret vault key', false, 'secret', 'decision');

        $delta = $this->service()->contextFor(self::TARGET_FILE);

        // Sensitive AURG domain excluded from any provider-bound path chain.
        $pathNodeIds = [];
        foreach ($delta['reality_graph_paths'] as $path) {
            foreach ($path['chain'] as $node) {
                $pathNodeIds[] = $node['id'];
            }
        }
        $this->assertNotContains(self::DFIN, $pathNodeIds, 'sensitive domain must be excluded from provider-bound paths');

        // The secret memory body/key never appears anywhere in the delta.
        $blob = (string) json_encode($delta['memory']).$delta['markdown'];
        $this->assertStringNotContainsString('secret vault key', $blob);
        $this->assertStringContainsString('safe note', $blob);
    }

    public function test_total_budget_is_a_real_ceiling_on_the_measured_delta(): void
    {
        // Seed many CONSUMERS so the neighbors section wants to be large, then assert
        // the MEASURED estimated_chars never exceeds the requested total.
        $this->seedSymbol(self::STEM, self::TARGET_FILE, 'class', 'class '.self::STEM);
        for ($i = 0; $i < 20; $i++) {
            $this->seedSymbol(
                'WidgetConsumer'.$i,
                'app/Http/Controllers/WidgetConsumer'.$i.'.php',
                'class',
                'class WidgetConsumer'.$i.' uses '.self::STEM.' with a longer signature body for weight',
            );
        }

        $tight = $this->service()->contextFor(self::TARGET_FILE, ['budget' => 600]);
        $this->assertSame(600, $tight['budget']['total_chars']);
        $this->assertLessThanOrEqual(
            600,
            $tight['budget']['estimated_chars'],
            'measured estimated_chars must respect the total ceiling',
        );
        // Never starves: the defined section keeps at least its top hit.
        $this->assertGreaterThanOrEqual(1, count($tight['defined_symbols']));

        // A generous total leaves more consumers in (proving the ceiling trimmed it).
        $generous = $this->service()->contextFor(self::TARGET_FILE, ['budget' => 100000]);
        $this->assertGreaterThan(count($tight['consumers']), count($generous['consumers']));
    }

    public function test_cli_command_runs_json_and_validates_input(): void
    {
        $this->seedSymbol(self::STEM, self::TARGET_FILE, 'class', 'class '.self::STEM);

        $this->artisan('atlas:aobg:file-context', ['path' => self::TARGET_FILE, '--json' => true])
            ->assertSuccessful();

        // Blank path is still a clean exit 0 (fail-safe, never a gate).
        $this->artisan('atlas:aobg:file-context', ['path' => '   '])->assertSuccessful();

        // Default (markdown) render also exits 0.
        $this->artisan('atlas:aobg:file-context', ['path' => self::TARGET_FILE])->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call)
    // ------------------------------------------------------------------

    private function service(): AtlasOpenBrainFileContextService
    {
        return $this->app->make(AtlasOpenBrainFileContextService::class);
    }

    /**
     * EngineeringCodeIntelligenceService::symbols() requires all THREE code-intel
     * tables to exist (tablesExist()), reads the full symbolPayload column set, and
     * filters with scopeActive() (status + archived_at). Build the symbols table
     * with every column the read path touches (W-1 workspace_id keyed so scoping is
     * exercised) plus the two companion tables so tablesExist() passes. All sqlite-
     * safe, self-contained — no chained migrations.
     */
    private function createCodeSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');

        Schema::create('atlas_engineering_code_modules', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('slug', 200)->index();
            $table->string('name', 200)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::create('atlas_engineering_code_symbols', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('module_id')->nullable()->index();
            $table->string('symbol_type', 60)->index();
            $table->string('symbol_name', 300)->index();
            $table->string('file_path', 500)->index();
            $table->unsignedInteger('line_start')->nullable();
            $table->unsignedInteger('line_end')->nullable();
            $table->string('language', 40)->nullable()->index();
            $table->text('signature')->nullable();
            $table->string('namespace', 220)->nullable();
            $table->string('parent_symbol', 300)->nullable();
            $table->string('visibility', 40)->nullable();
            $table->string('status', 32)->default('active')->index();
            $table->string('docs_status', 40)->default('undocumented')->index();
            $table->string('source_hash', 64)->index();
            $table->timestamp('indexed_at')->nullable();
            $table->timestamp('archived_at')->nullable()->index();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->json('related_doc_ids_json')->default('[]');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('atlas_engineering_doc_links', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32)->default('active')->index();
            $table->string('workspace_id', 160)->default('atlas-server')->index();
            $table->timestamp('archived_at')->nullable()->index();
            $table->timestamps();
        });
    }

    private function createAurgTables(): void
    {
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');

        $migration = require database_path('migrations/2026_06_09_120000_create_atlas_aurg_graph_tables.php');
        $migration->up();
        $temporalMigration = require database_path('migrations/2026_07_07_181500_add_temporal_truth_to_atlas_aurg_edges.php');
        $temporalMigration->up();
    }

    private function seedSymbol(string $name, string $filePath, string $type, string $signature): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $filePath,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => hash('sha256', $filePath.$name),
            'workspace_id' => $this->app->make(\App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity::class)->default(),
            'related_doc_ids_json' => '[]',
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function seedMemory(string $sourceId, string $title, bool $providerSafe, string $privacyClass, string $memoryType): void
    {
        AtlasMemoryEntry::create([
            'memory_type' => $memoryType,
            'scope_type' => 'global',
            'title' => $title,
            'summary' => $providerSafe ? $title.' summary note' : 'secret vault key summary',
            'body' => $providerSafe ? $title.' body about the embedding resolver' : 'secret vault key material',
            'status' => 'active',
            'privacy_class' => $privacyClass,
            'external_ai_allowed' => $providerSafe,
            'redaction_status' => 'clean',
            'source_type' => 'test_fixture',
            'source_id' => $sourceId,
            'recorded_at' => now(),
        ]);
    }

    private function seedAurg(): void
    {
        $nodes = [
            [
                'id' => self::D1,
                'kind' => 'memory_entry',
                'source_kind' => 'memory',
                'source_id' => 'dec-1',
                'label' => self::STEM.' embedding decision for the foo services module',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['type' => 'decision'],
            ],
            [
                'id' => self::CMOD,
                'kind' => 'module',
                'source_kind' => 'code',
                'source_id' => 'atlas-server/services-ai-foo',
                'label' => 'Ai Foo Services',
                'workspace_id' => 'atlas-server',
                'provider_safe' => true,
                'sensitive' => false,
                'meta' => ['slug' => 'services-ai-foo', 'root_path' => 'app/Services/Ai/Foo'],
            ],
            [
                'id' => self::DFIN,
                'kind' => 'domain',
                'source_kind' => 'domain',
                'source_id' => 'finance',
                'label' => 'Finance',
                'provider_safe' => false,
                'sensitive' => true,
                'meta' => [],
            ],
        ];
        foreach ($nodes as $node) {
            AtlasAurgNode::query()->create($node + ['content_hash' => hash('sha256', $node['id'])]);
        }

        $edges = [
            [self::D1, self::CMOD, 'references', 'linker_memory_code', 1.0, ['matched_path' => 'app/Services/Ai/Foo']],
            [self::D1, self::DFIN, 'belongs_to', 'linker_memory_domain', 1.0, ['matched_domain' => 'finance']],
        ];
        foreach ($edges as [$from, $to, $kind, $source, $confidence, $meta]) {
            AtlasAurgEdge::query()->create([
                'from_node_id' => $from,
                'to_node_id' => $to,
                'kind' => $kind,
                'source' => $source,
                'confidence' => $confidence,
                'meta' => $meta,
            ]);
        }
    }
}
