<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\AgenticEngineeringOs\Maturity\AtlasImplementationEvidenceResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * PERF CONTRACT (no behavior change) — the resolver loads the code-symbol candidate set
 * ONCE per instance and matches in PHP, so resolving hundreds of refs issues O(1) queries
 * against atlas_engineering_code_symbols instead of one leading-wildcard seq-scan per ref
 * (the >180s POST /ai/interactions fatal). This test pins BOTH:
 *   1. query count is bounded (exactly one symbol query per instance, independent of ref
 *      count) — the regression that the N+1 fix exists to prevent; and
 *   2. results are unchanged (resolved flags, matched values, sorted/distinct paths, FQN
 *      binding) — the correctness contract the AAEOS maturity ledger depends on.
 *
 * If the per-ref query were reintroduced, assertion (1) flips (query count scales with
 * refs) — which is the point. sqlite :memory:, the table built via the real migration up().
 */
final class AtlasAaeosEvidenceResolverLoadOnceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $migration = require base_path(
            'database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php',
        );
        $migration->up();
        $this->assertTrue(Schema::hasTable('atlas_engineering_code_symbols'));

        // A small but representative index: each matchable kind, plus a same-named-method
        // imposter on an unrelated class (the FQN-binding guard), plus an archived/inactive
        // row that must never match.
        $this->seedSymbol('class', 'App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService', 'app/Services/Ai/Aaeos/AtlasImplementationTruthService.php');
        $this->seedSymbol('method', 'App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService::compute', 'app/Services/Ai/Aaeos/AtlasImplementationTruthService.php');
        $this->seedSymbol('route', 'POST /ai/interactions', 'routes/api.php', signature: 'POST /ai/interactions');
        $this->seedSymbol('cli_command', 'atlas:aeos:maturity', 'app/Console/Commands/Aaeos/MaturityCommand.php');
        $this->seedSymbol('migration_table', 'atlas_aaeos_test_run_receipts', 'database/migrations/x.php');
        $this->seedSymbol('test_method', 'Tests\\Unit\\Ai\\Aaeos\\AtlasAaeosImplementationTruthServiceTest::test_partial_requires_symbol_plus_wiring', 'tests/Unit/Ai/Aaeos/AtlasAaeosImplementationTruthServiceTest.php');
        $this->seedSymbol('class', 'Tests\\Unit\\Ai\\Aaeos\\AtlasAaeosImplementationTruthServiceTest', 'tests/Unit/Ai/Aaeos/AtlasAaeosImplementationTruthServiceTest.php');
        // An archived row with a name that WOULD boundary-match must be ignored (status gate).
        $this->seedSymbol('class', 'App\\Ghost\\AtlasImplementationTruthService', 'app/Ghost/Stale.php', status: 'archived');
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');

        parent::tearDown();
    }

    /**
     * THE PERF PROOF: one resolver instance, MANY resolve()/resolveSymbolFilePaths()/FQN
     * calls (every kind, repeated) → exactly ONE query touches the symbols table.
     */
    public function test_resolving_many_refs_issues_one_symbol_query_per_instance(): void
    {
        $resolver = new AtlasImplementationEvidenceResolver;

        DB::flushQueryLog();
        DB::enableQueryLog();

        // Hundreds of resolutions across every kind, plus the file-path / FQN helpers.
        for ($i = 0; $i < 50; $i++) {
            $resolver->resolve('symbol', 'AtlasImplementationTruthService');
            $resolver->resolve('symbol', 'AtlasImplementationTruthService::compute');
            $resolver->resolve('symbol', 'DefinitelyNotIndexed'.$i); // unresolved path too
            $resolver->resolve('route', '/ai/interactions');
            $resolver->resolve('command', 'atlas:aeos:maturity');
            $resolver->resolve('migration', 'atlas_aaeos_test_run_receipts');
            $resolver->resolve('test', 'AtlasAaeosImplementationTruthServiceTest');
            $resolver->resolveSymbolFilePaths('AtlasImplementationTruthService');
            $resolver->resolveTestFilePath('AtlasAaeosImplementationTruthServiceTest');
            $resolver->resolveTestFqn('AtlasAaeosImplementationTruthServiceTest::test_partial_requires_symbol_plus_wiring');
        }

        $symbolQueries = $this->symbolTableQueryCount(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(
            1,
            $symbolQueries,
            'resolving hundreds of refs must hit atlas_engineering_code_symbols exactly once '
                .'(load-once + match-in-PHP); a per-ref query is the N+1 this fix removes',
        );
    }

    /**
     * REQUEST-SHARED, not cross-request: one create orchestration builds many resolver
     * instances through different consumers, so the loaded index is cached scoped-to-the-
     * request — the FIRST resolver loads it, every later instance in the SAME request reuses
     * it (zero further loads). A NEW request (here: forgetScopedInstances(), which Laravel
     * runs between requests) reloads exactly once, so the cache can never go stale across a
     * re-index/deploy. This is the property that turns ~30 loads/create into 1.
     */
    public function test_index_is_shared_within_a_request_and_reloads_next_request(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $a = new AtlasImplementationEvidenceResolver;
        $a->resolve('symbol', 'AtlasImplementationTruthService');
        $this->assertSame(1, $this->symbolTableQueryCount(DB::getQueryLog()), 'first resolver in the request: one load');

        // A DIFFERENT instance in the SAME request reuses the shared index — no second load.
        DB::flushQueryLog();
        $b = new AtlasImplementationEvidenceResolver;
        $b->resolve('symbol', 'AtlasImplementationTruthService');
        $b->resolve('route', '/ai/interactions');
        $this->assertSame(0, $this->symbolTableQueryCount(DB::getQueryLog()), 'second instance, same request: reuses the shared index, zero loads');

        // Next request: scoped bindings are cleared, so the index reloads exactly once.
        $this->app->forgetScopedInstances();
        DB::flushQueryLog();
        $c = new AtlasImplementationEvidenceResolver;
        $c->resolve('symbol', 'AtlasImplementationTruthService');
        $this->assertSame(1, $this->symbolTableQueryCount(DB::getQueryLog()), 'new request: reloads once (never a stale cross-request cache)');

        DB::disableQueryLog();
    }

    /**
     * SEMANTICS PIN — the load-once path returns the SAME answers the per-ref queries did:
     * exact/suffix symbol resolution, route/command/migration substring, test existence,
     * sorted-distinct file paths, FQN binding, and the status gate (archived never matches).
     */
    public function test_results_are_unchanged_by_the_load_once_refactor(): void
    {
        $resolver = new AtlasImplementationEvidenceResolver;

        // symbol: FQN-suffix and method-suffix both resolve to the indexed canonical name.
        $class = $resolver->resolve('symbol', 'AtlasImplementationTruthService');
        $this->assertTrue($class['resolved']);
        $this->assertSame('App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService', $class['matched']);

        $method = $resolver->resolve('symbol', 'AtlasImplementationTruthService::compute');
        $this->assertTrue($method['resolved']);
        $this->assertSame('App\\Services\\Ai\\Aaeos\\AtlasImplementationTruthService::compute', $method['matched']);

        // A bare fragment that no symbol ends with does NOT resolve.
        $this->assertFalse($resolver->resolve('symbol', 'NopeNotAThing')['resolved']);

        // route / command / migration resolve by substring of name or signature.
        $this->assertTrue($resolver->resolve('route', '/ai/interactions')['resolved']);
        $this->assertTrue($resolver->resolve('command', 'atlas:aeos:maturity')['resolved']);
        $this->assertTrue($resolver->resolve('migration', 'atlas_aaeos_test_run_receipts')['resolved']);

        // test existence (a *Test* symbol containing the ref).
        $this->assertTrue($resolver->resolve('test', 'AtlasAaeosImplementationTruthServiceTest')['resolved']);

        // sorted, distinct file paths for the symbol ref.
        $paths = $resolver->resolveSymbolFilePaths('AtlasImplementationTruthService');
        $this->assertSame(['app/Services/Ai/Aaeos/AtlasImplementationTruthService.php'], $paths);

        // test file path resolves to the test class file (class-priority).
        $this->assertSame(
            'tests/Unit/Ai/Aaeos/AtlasAaeosImplementationTruthServiceTest.php',
            $resolver->resolveTestFilePath('AtlasAaeosImplementationTruthServiceTest'),
        );

        // FQN binding to the canonical class + method.
        $fqn = $resolver->resolveTestFqn('AtlasAaeosImplementationTruthServiceTest::test_partial_requires_symbol_plus_wiring');
        $this->assertIsArray($fqn);
        $this->assertSame('Tests\\Unit\\Ai\\Aaeos\\AtlasAaeosImplementationTruthServiceTest', $fqn['class']);
        $this->assertSame('test_partial_requires_symbol_plus_wiring', $fqn['method']);

        // STATUS GATE: the archived ghost row (whose name boundary-matches) must NOT win —
        // matchSymbol returns the active row, never the archived one.
        $this->assertNotSame('App\\Ghost\\AtlasImplementationTruthService', $class['matched']);
    }

    /**
     * Count only the queries that read the code-symbols table (ignore migration/setup SQL
     * that may run lazily on the connection).
     *
     * @param  array<int,array{query:string}>  $log
     */
    private function symbolTableQueryCount(array $log): int
    {
        return count(array_filter(
            $log,
            static fn (array $entry): bool => str_contains((string) $entry['query'], 'atlas_engineering_code_symbols'),
        ));
    }

    private function seedSymbol(string $type, string $name, string $filePath, string $status = 'active', ?string $signature = null): void
    {
        AtlasEngineeringCodeSymbol::query()->create([
            'symbol_type' => $type,
            'symbol_name' => $name,
            'file_path' => $filePath,
            'signature' => $signature,
            'language' => 'php',
            'status' => $status,
            'docs_status' => 'documented',
            'source_hash' => 'seed-'.md5($type.'|'.$name),
        ]);
    }
}
