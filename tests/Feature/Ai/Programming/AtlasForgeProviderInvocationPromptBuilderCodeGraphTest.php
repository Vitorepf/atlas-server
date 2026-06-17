<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Models\AtlasProject;
use App\Services\Ai\Programming\AtlasForgeProviderInvocationPromptBuilder;
use App\Services\Engineering\CodeGraph\CodeGraphContextRetriever;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * AP-815 · I-4 (Stage 2 — Forge seam) — proves the precise code-graph context pack
 * actually reaches the GOVERNED Forge provider prompt, default-safe.
 *
 * The contract under test, on {@see AtlasForgeProviderInvocationPromptBuilder::build()}:
 *
 *   - FLAG OFF (the default `atlas.code_graph.auto_context = false`): the prompt is
 *     BYTE-IDENTICAL to the pre-seam behaviour. We prove this two ways: the
 *     `evidence_contract.code_graph_pack` key is absent, AND the fully-wired (container)
 *     builder's prompt_hash equals the prompt_hash of a BARE `new ...()` builder (the
 *     bare builder reproduces the old, dependency-free behaviour) — so wiring the seam in
 *     is a true no-op until the operator flips the flag.
 *
 *   - FLAG ON, with a symbol seeded in the read-model whose name matches the intent and
 *     whose file is one of the WorkItem allowed_files: the descriptor (query = intent,
 *     changed_files = allowed_files) drives the shared retriever and the assembled prompt
 *     carries a NON-EMPTY `evidence_contract.code_graph_pack.included`, and its
 *     prompt_hash DIFFERS from the flag-OFF hash (the pack genuinely entered the prompt).
 *
 * Proven WITHOUT burning provider tokens: we assert on the BUILT prompt array and its
 * canonical prompt_hash (the same `hash('sha256', json_encode(...))` the invocation
 * service uses for audit), never invoking any provider — a true MODE_DRY_RUN assertion.
 *
 * Boots only the tables it needs (atlas_projects + the code-intelligence read-model),
 * the repo's established pattern for this area where a core migration is pgsql-only.
 */
final class AtlasForgeProviderInvocationPromptBuilderCodeGraphTest extends TestCase
{
    private const CODE_TABLES = [
        'atlas_engineering_doc_links',
        'atlas_engineering_code_symbols',
        'atlas_engineering_code_modules',
        'atlas_engineering_code_file_snapshots',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Default-OFF must be the baseline for every test; individual tests flip it ON.
        config()->set('atlas.code_graph.auto_context', false);
        // Keep retrieval deterministic + token-free: never reach the python BM25 runtime;
        // the keyword fallback order is sufficient and provider-free.
        config()->set('atlas.code_graph.real_edges', false);

        $this->ensureProjectsTable();

        foreach (self::CODE_TABLES as $table) {
            Schema::dropIfExists($table);
        }
        (require database_path('migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_21_211200_create_atlas_engineering_code_file_snapshots_table.php'))->up();
        (require database_path('migrations/2026_06_08_233000_add_workspace_id_to_code_intelligence_tables.php'))->up();
    }

    protected function tearDown(): void
    {
        foreach (self::CODE_TABLES as $table) {
            Schema::dropIfExists($table);
        }

        parent::tearDown();
    }

    public function test_flag_off_prompt_is_byte_identical_and_has_no_code_graph_pack(): void
    {
        // A symbol that WOULD match if the flag were on — proves OFF is inert, not merely
        // "found nothing".
        $this->seedSymbol('atlas-server', 'App\\Services\\PaymentGatewayResolver', 'app/Services/PaymentGatewayResolver.php', 'class PaymentGatewayResolver');

        $obra = $this->makeObra(allowedFiles: ['app/Services/PaymentGatewayResolver.php'], intent: 'PaymentGatewayResolver wiring');
        $dispatchPlan = $this->dispatchPlan();

        // Fully container-wired builder (the production object, with the seam) ...
        $wired = app(AtlasForgeProviderInvocationPromptBuilder::class);
        $wiredPrompt = $wired->build($obra, $dispatchPlan);

        // ... vs a BARE builder with NO dependencies = the pre-seam behaviour reproduced.
        $bare = new AtlasForgeProviderInvocationPromptBuilder;
        $barePrompt = $bare->build($obra, $dispatchPlan);

        // The key must be absent entirely (not present-but-empty) → byte-identical shape.
        $this->assertArrayNotHasKey(
            'code_graph_pack',
            $wiredPrompt['evidence_contract'],
            'flag OFF: the code_graph_pack key must be absent from evidence_contract',
        );

        // Byte-identical proof: the wired (seam present, flag off) prompt hashes the same
        // as the bare (no seam at all) prompt.
        $this->assertSame(
            $this->promptHash($barePrompt),
            $this->promptHash($wiredPrompt),
            'flag OFF: wiring the code-graph seam must not change the prompt_hash',
        );
    }

    public function test_flag_on_injects_a_non_empty_code_graph_pack_into_evidence_contract(): void
    {
        // Seed a symbol whose name matches the intent AND whose file is an allowed_file.
        $this->seedSymbol('atlas-server', 'App\\Services\\PaymentGatewayResolver', 'app/Services/PaymentGatewayResolver.php', 'class PaymentGatewayResolver implements GatewayContract');

        $obra = $this->makeObra(allowedFiles: ['app/Services/PaymentGatewayResolver.php'], intent: 'PaymentGatewayResolver wiring');
        $dispatchPlan = $this->dispatchPlan();

        $builder = app(AtlasForgeProviderInvocationPromptBuilder::class);

        // Baseline OFF hash (same obra/plan) to prove the ON pack actually changed it.
        config()->set('atlas.code_graph.auto_context', false);
        $offPrompt = $builder->build($obra, $dispatchPlan);
        $offHash = $this->promptHash($offPrompt);

        // Flip the flag ON and rebuild.
        config()->set('atlas.code_graph.auto_context', true);
        $onPrompt = $builder->build($obra, $dispatchPlan);

        $this->assertArrayHasKey('code_graph_pack', $onPrompt['evidence_contract'], 'flag ON: the pack key must be present');
        $pack = $onPrompt['evidence_contract']['code_graph_pack'];

        $this->assertIsArray($pack['included'] ?? null, 'the pack must carry an included list');
        $this->assertNotEmpty($pack['included'], 'flag ON: the seeded matching symbol must be packed into the prompt');

        // The seeded symbol id ('sym:'.symbol_name) is actually in the prompt.
        $ids = array_map(static fn ($n): string => is_array($n) ? (string) ($n['id'] ?? '') : '', $pack['included']);
        $this->assertContains('sym:App\\Services\\PaymentGatewayResolver', $ids);

        // The pack genuinely entered the prompt: the hash moved off the OFF baseline.
        $this->assertNotSame($offHash, $this->promptHash($onPrompt), 'flag ON: injecting the pack must change the prompt_hash');
    }

    public function test_flag_on_with_no_matching_symbol_stays_fail_safe_empty_pack(): void
    {
        // Flag ON but nothing in the read-model matches the intent/allowed_files → an
        // honest empty (but present) pack, exit clean, no throw. The retriever matches
        // ONLY on symbol_name (LIKE), and the changed-file paths are tokenised into extra
        // terms — so the intent AND the allowed-file tokens must share NO substring with
        // the seeded symbol name ('quux'/'zeta'/'mismatchnonexistent' vs 'TotallyUnrelated').
        $this->seedSymbol('atlas-server', 'App\\Services\\TotallyUnrelatedThing', 'app/Services/TotallyUnrelatedThing.php', 'class TotallyUnrelatedThing');

        $obra = $this->makeObra(allowedFiles: ['x/QuuxZeta.php'], intent: 'mismatchnonexistent');
        config()->set('atlas.code_graph.auto_context', true);

        $prompt = app(AtlasForgeProviderInvocationPromptBuilder::class)->build($obra, $this->dispatchPlan());

        $this->assertArrayHasKey('code_graph_pack', $prompt['evidence_contract']);
        $this->assertSame([], $prompt['evidence_contract']['code_graph_pack']['included'], 'no match → empty included list');
        $this->assertSame(0, $prompt['evidence_contract']['code_graph_pack']['count']);
    }

    public function test_flag_on_resolves_workspace_from_project_metadata_path(): void
    {
        // Prove the descriptor's workspace scoping uses the project's workspace_path:
        // a foreign path resolves to a distinct id; a symbol seeded ONLY under the primary
        // atlas-server id must NOT leak into a foreign-workspace obra's pack.
        $identity = app(CodeGraphWorkspaceIdentity::class);
        $foreignPath = sys_get_temp_dir().'/atlas-forge-cg-ws-'.uniqid();
        @mkdir($foreignPath, 0777, true);
        $foreignId = $identity->resolve($foreignPath);
        $this->assertNotSame('atlas-server', $foreignId, 'a foreign path must get its own workspace id');

        $this->seedSymbol('atlas-server', 'App\\Services\\PaymentGatewayResolver', 'app/Services/PaymentGatewayResolver.php', 'class PaymentGatewayResolver');

        config()->set('atlas.code_graph.auto_context', true);
        $builder = app(AtlasForgeProviderInvocationPromptBuilder::class);

        // Obra pinned to the FOREIGN workspace path → must see none of the atlas-server rows.
        $foreignObra = $this->makeObra(
            allowedFiles: ['app/Services/PaymentGatewayResolver.php'],
            intent: 'PaymentGatewayResolver wiring',
            workspacePath: $foreignPath,
        );
        $foreignPrompt = $builder->build($foreignObra, $this->dispatchPlan());
        $this->assertSame([], $foreignPrompt['evidence_contract']['code_graph_pack']['included'], 'foreign-workspace obra must not see atlas-server symbols');

        // Obra with no workspace_path → resolves to the primary default → sees the symbol.
        $primaryObra = $this->makeObra(
            allowedFiles: ['app/Services/PaymentGatewayResolver.php'],
            intent: 'PaymentGatewayResolver wiring',
            workspacePath: null,
        );
        $primaryPrompt = $builder->build($primaryObra, $this->dispatchPlan());
        $this->assertNotEmpty($primaryPrompt['evidence_contract']['code_graph_pack']['included'], 'default-workspace obra must see the atlas-server symbol');

        @rmdir($foreignPath);
    }

    public function test_flag_on_is_fail_safe_when_retriever_throws(): void
    {
        // A retriever that explodes must degrade to the OFF behaviour (key absent), never
        // break the governed prompt.
        $boom = new class extends CodeGraphContextRetriever
        {
            public function __construct() {}

            public function packFor(string $query, string $workspaceId, int $budget = self::DEFAULT_BUDGET, array $changedFiles = [], array $assemblyOptions = []): array
            {
                throw new \RuntimeException('retriever boom');
            }
        };
        $this->app->instance(CodeGraphContextRetriever::class, $boom);

        config()->set('atlas.code_graph.auto_context', true);
        $obra = $this->makeObra(allowedFiles: ['app/Services/PaymentGatewayResolver.php'], intent: 'PaymentGatewayResolver wiring');

        $prompt = app(AtlasForgeProviderInvocationPromptBuilder::class)->build($obra, $this->dispatchPlan());

        $this->assertArrayNotHasKey('code_graph_pack', $prompt['evidence_contract'], 'a throwing retriever must degrade to no pack (fail-safe)');
    }

    /**
     * Canonical prompt_hash — mirrors AtlasForgeProviderInvocationService's audit hash.
     *
     * @param  array<string,mixed>  $prompt
     */
    private function promptHash(array $prompt): string
    {
        return hash('sha256', (string) json_encode($prompt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function seedSymbol(string $workspace, string $name, string $file, ?string $signature = null): void
    {
        DB::table('atlas_engineering_code_symbols')->insert([
            'id' => (string) Str::uuid(),
            'workspace_id' => $workspace,
            'symbol_type' => 'class',
            'symbol_name' => $name,
            'file_path' => $file,
            'language' => 'php',
            'signature' => $signature,
            'status' => 'active',
            'source_hash' => substr(hash('sha256', $workspace.$name.$file), 0, 64),
        ]);
    }

    /**
     * @param  array<int,string>  $allowedFiles
     */
    private function makeObra(array $allowedFiles, string $intent, ?string $workspacePath = '__primary__'): AtlasProject
    {
        $metadata = [
            'workspace_slug' => 'atlas',
            'intent' => $intent,
            'allowed_files' => $allowedFiles,
            'origin' => 'atlas-forge-codegraph-test',
        ];
        // '__primary__' sentinel → omit workspace_path so identity resolves to the default;
        // a real path pins the obra to a foreign workspace; null also omits it.
        if ($workspacePath !== '__primary__' && $workspacePath !== null) {
            $metadata['workspace_path'] = $workspacePath;
        }

        return AtlasProject::create([
            'id' => (string) Str::uuid(),
            'title' => 'Forge code-graph seam test Obra',
            'description' => 'AP-815 I-4 Forge seam',
            'status' => 'active',
            'domain' => 'atlas',
            'goal' => $intent,
            'desired_outcome' => 'pack reaches the governed prompt',
            'priority' => 'normal',
            'metadata' => $metadata,
        ]);
    }

    /**
     * A minimal, deterministic dispatch plan so the prompt is fully formed (no blockers
     * relevant to the seam). Token-free; never reaches a provider.
     *
     * @return array<string,mixed>
     */
    private function dispatchPlan(): array
    {
        return [
            'role' => 'primary_builder',
            'provider' => 'atlas-local',
            'model' => 'atlas-runtime',
            'dispatch_id' => 'dispatch_codegraph_test',
            'decision_receipt_id' => 'rcpt_codegraph_test',
            'decision_receipt_hash' => str_repeat('a', 64),
            'provider_topology_id' => 'topo_codegraph_test',
            'quality_gates' => ['composer test'],
        ];
    }

    private function ensureProjectsTable(): void
    {
        if (Schema::hasTable('atlas_projects')) {
            return;
        }

        Schema::create('atlas_projects', function (Blueprint $t): void {
            $t->uuid('id')->primary();
            $t->string('title')->nullable();
            $t->text('description')->nullable();
            $t->string('status')->default('active');
            $t->string('domain')->default('atlas');
            $t->text('goal')->nullable();
            $t->text('next_action')->nullable();
            $t->text('desired_outcome')->nullable();
            $t->text('definition_of_done')->nullable();
            $t->string('priority')->nullable();
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }
}
