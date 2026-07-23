<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\AtlasAobgBlackboardService;
use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\AtlasOpenBrainGuardService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Engineering\CodeGraph\CodeGraphWorkspaceIdentity;
use App\Services\Engineering\EngineeringCodeIntelligenceService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Concerns\CreatesAtlasMemoryEntryTable;
use Tests\TestCase;

/**
 * AOBG N2.F2 — the SENTINEL: the brain checks a proposed edit BEFORE it lands
 * (PreToolUse guardrails). The killer-safety surface.
 *
 * Locks the safety-first contract over a sqlite, COST-FREE fixture (no provider call
 * anywhere — pure local DB reads). Tables are built with the sqlite-safe concern +
 * inline builders (the suite avoids RefreshDatabase because some migrations are
 * Postgres-only raw SQL), exactly like the N2.F1 file-context test.
 *
 * The non-negotiable assertions:
 *  - DEFAULT (block flag OFF) NEVER blocks a normal edit — only allow|warn.
 *  - a seeded decision-governed file edit returns WARN (advisory).
 *  - BLOCK happens ONLY with the flag ON AND a highest-confidence violation
 *    (sensitive-class touch OR an EXACT registered-decision contradiction).
 *  - FAIL-OPEN: a thrown error inside any check → allow (never block by accident).
 */
final class AtlasOpenBrainGuardServiceTest extends TestCase
{
    use CreatesAtlasMemoryEntryTable;

    private const TARGET_FILE = 'app/Services/Ai/Foo/WidgetEmbeddingResolver.php';

    private const STEM = 'WidgetEmbeddingResolver';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAtlasMemoryEntryTable();
        $this->createCodeSymbolsTable();
        $this->createAurgTables();
        $this->createBlackboardTable();

        config()->set('atlas.aurg.enabled', true);
        config()->set('atlas.aurg.query_rank_enabled', false);
        // Safety-first DEFAULT: block disabled. Individual tests opt it on.
        config()->set('atlas.aobg.guard.block_enabled', false);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');
        Schema::dropIfExists('atlas_aurg_edges');
        Schema::dropIfExists('atlas_aurg_nodes');
        Schema::dropIfExists('atlas_engineering_doc_links');
        Schema::dropIfExists('atlas_engineering_code_symbols');
        Schema::dropIfExists('atlas_engineering_code_modules');
        $this->dropAtlasMemoryEntryTable();
        parent::tearDown();
    }

    // ---------------- (a) decision violation ----------------

    public function test_governed_decision_file_edit_returns_warn_not_block_by_default(): void
    {
        // A registered DECISION that references the file's stem governs this module.
        $this->seedMemory('dec-1', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');

        $verdict = $this->service()->evaluate(self::TARGET_FILE, [
            'diff' => 'some unrelated change to the resolver',
        ]);

        $this->assertSame(AtlasOpenBrainGuardService::SCHEMA, $verdict['schema']);
        $this->assertTrue($verdict['provider_bound']);
        $this->assertTrue($verdict['checks']['decision_violation']);
        // SAFETY-FIRST: governed decision -> WARN by default, NEVER block.
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
        $this->assertNotEmpty($verdict['reasons']);
        $this->assertNotSame('', trim((string) $verdict['warning']));
    }

    public function test_exact_decision_contradiction_blocks_only_when_flag_on(): void
    {
        // A decision that STATES A CONSTRAINT ("must use the local embedding engine")
        // and a diff that NEGATES it (introduces a new OpenAiEmbeddingClient) — an
        // EXACT contradiction (the constrained subject "embedding" appears in the diff
        // alongside an introduce signal).
        $this->seedMemory('dec-1', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');
        $contradictingDiff = '+ use App\\Embedding\\OpenAiEmbeddingClient;'."\n".'+ $client = new OpenAiEmbeddingClient();';

        // Flag OFF (default): the highest-confidence contradiction still only WARNS.
        $warnOnly = $this->service()->evaluate(self::TARGET_FILE, ['diff' => $contradictingDiff]);
        $this->assertTrue($warnOnly['checks']['exact_decision_contradiction']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $warnOnly['decision']);

        // Flag ON: NOW the exact contradiction is allowed to BLOCK.
        config()->set('atlas.aobg.guard.block_enabled', true);
        $blocked = $this->service()->evaluate(self::TARGET_FILE, ['diff' => $contradictingDiff]);
        $this->assertTrue($blocked['checks']['exact_decision_contradiction']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_BLOCK, $blocked['decision']);
    }

    public function test_governed_decision_with_no_diff_is_never_exact(): void
    {
        // Without a diff we cannot prove a contradiction — must stay advisory, so even
        // with the flag ON it can only WARN (never block on governance-alone).
        config()->set('atlas.aobg.guard.block_enabled', true);
        $this->seedMemory('dec-1', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');

        $verdict = $this->service()->evaluate(self::TARGET_FILE);

        $this->assertTrue($verdict['checks']['decision_violation']);
        $this->assertFalse($verdict['checks']['exact_decision_contradiction']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
    }

    // ---------------- (c) sensitive-class ----------------

    public function test_sensitive_class_path_blocks_only_when_flag_on(): void
    {
        // Flag OFF (default): a sensitive-class touch still only WARNS.
        $warnOnly = $this->service()->evaluate('secrets/vault/api.keys', ['diff' => 'TOKEN=abc']);
        $this->assertTrue($warnOnly['checks']['sensitive_class']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $warnOnly['decision']);
        // The warning NAMES the class but never echoes the would-be content.
        $this->assertStringContainsString('secret', strtolower((string) $warnOnly['warning']));
        $this->assertStringNotContainsString('abc', (string) $warnOnly['warning']);

        // Flag ON: NOW a sovereign-file touch is allowed to BLOCK.
        config()->set('atlas.aobg.guard.block_enabled', true);
        $blocked = $this->service()->evaluate('secrets/vault/api.keys', ['diff' => 'TOKEN=abc']);
        $this->assertTrue($blocked['checks']['sensitive_class']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_BLOCK, $blocked['decision']);
    }

    public function test_dot_env_credential_file_is_classified_secret(): void
    {
        $verdict = $this->service()->evaluate('config/.env', ['diff' => 'KEY=val']);
        $this->assertTrue($verdict['checks']['sensitive_class']);
        $this->assertSame('secret', $verdict['evidence'][0]['class']);
    }

    // ---------------- (b) duplication ----------------

    public function test_duplication_warns_but_never_blocks_even_with_flag_on(): void
    {
        // The code-graph already has a class with the proposed name in ANOTHER file.
        $this->seedSymbol('PaymentLedgerWriter', 'app/Services/Ledger/PaymentLedgerWriter.php', 'class', 'class PaymentLedgerWriter');

        // A WRITE that re-creates that class (the file stem matches the existing class).
        config()->set('atlas.aobg.guard.block_enabled', true); // even armed...
        $verdict = $this->service()->evaluate('app/Services/Billing/PaymentLedgerWriter.php', [
            'diff' => '+ class PaymentLedgerWriter {}',
        ]);

        $this->assertTrue($verdict['checks']['duplication']);
        // Duplication is ADVISORY only — it can NEVER block, even with the flag ON.
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
        $this->assertStringContainsString('PaymentLedgerWriter', (string) $verdict['warning']);
        $this->assertStringContainsString('app/Services/Ledger/PaymentLedgerWriter.php', (string) $verdict['warning']);
    }

    public function test_duplication_does_not_flag_a_symbol_already_in_the_same_file(): void
    {
        // The "existing" symbol lives in the very file being edited — that is an EDIT
        // of it, not a duplicate-creation; must NOT flag.
        $this->seedSymbol(self::STEM, self::TARGET_FILE, 'class', 'class '.self::STEM);

        $verdict = $this->service()->evaluate(self::TARGET_FILE, ['diff' => '+ class '.self::STEM.' {}']);

        $this->assertFalse($verdict['checks']['duplication']);
    }

    // ---------------- (d) N2.F4 blackboard cross-engine claim ----------------

    public function test_guard_surfaces_a_cross_engine_claim_conflict_as_advisory_warn(): void
    {
        // Another engine (codex) holds an active claim on the SAME file claude_code is
        // about to edit. The guard must surface it — WARN, never block (even armed).
        $this->seedClaim('codex', 'file', self::TARGET_FILE);

        config()->set('atlas.aobg.guard.block_enabled', true); // even armed...
        $verdict = $this->service()->evaluate(self::TARGET_FILE, [
            'diff' => 'some change',
            'engine' => 'claude_code', // the asking engine is excluded from its own claims
        ]);

        $this->assertTrue($verdict['checks']['blackboard_claim']);
        // Coordination is ADVISORY only — a cross-engine claim can NEVER block.
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
        $this->assertStringContainsString('codex', (string) $verdict['warning']);
        $this->assertStringContainsString('CROSS-ENGINE-CLAIM', (string) $verdict['warning']);
        $this->assertSame(1, (int) ($verdict['counts']['claim_conflicts'] ?? 0));
    }

    public function test_guard_does_not_warn_about_the_asking_engine_own_claim(): void
    {
        // claude_code holds the claim AND is the one editing — that is its OWN in-flight
        // work, NOT a cross-engine conflict. Must NOT fire the blackboard check.
        $this->seedClaim('claude_code', 'file', self::TARGET_FILE);

        $verdict = $this->service()->evaluate(self::TARGET_FILE, [
            'diff' => 'some change',
            'engine' => 'claude_code',
        ]);

        $this->assertFalse($verdict['checks']['blackboard_claim']);
    }

    public function test_hot_file_without_own_claim_warns_with_versioned_hot_list(): void
    {
        $verdict = $this->service()->evaluate('routes/console.php', [
            'diff' => '+ // shared route edit',
            'engine' => 'claude_code',
        ]);

        $this->assertTrue($verdict['checks']['blackboard_hot_file_claim']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
        $this->assertSame('acos-max-elev-22.v1', $verdict['evidence'][0]['hot_list_version']);
        $this->assertStringContainsString('HOT-FILE-CLAIM', (string) $verdict['warning']);
        $this->assertStringContainsString('atlas_claim_task', (string) $verdict['warning']);
    }

    public function test_hot_file_with_own_claim_is_allow_when_no_other_guard_fires(): void
    {
        $this->seedClaim('claude_code', 'file', 'routes/console.php');

        $verdict = $this->service()->evaluate('routes/console.php', [
            'diff' => '+ // shared route edit',
            'engine' => 'claude_code',
        ]);

        $this->assertFalse($verdict['checks']['blackboard_hot_file_claim']);
        $this->assertFalse($verdict['checks']['blackboard_claim']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_ALLOW, $verdict['decision']);
    }

    public function test_hot_file_with_foreign_claim_warns_as_cross_engine_conflict(): void
    {
        $this->seedClaim('codex', 'file', 'routes/console.php');
        $this->seedClaim('cursor', 'file', 'routes/console.php');

        $verdict = $this->service()->evaluate('routes/console.php', [
            'diff' => '+ // shared route edit',
            'engine' => 'claude_code',
        ]);

        $this->assertTrue($verdict['checks']['blackboard_claim']);
        $this->assertTrue($verdict['checks']['blackboard_hot_file_claim']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_WARN, $verdict['decision']);
        $this->assertStringContainsString('CROSS-ENGINE-CLAIM', (string) $verdict['warning']);
        $this->assertStringContainsString('codex', (string) $verdict['warning']);
        $this->assertStringContainsString('cursor', (string) $verdict['warning']);
        $this->assertSame(2, (int) ($verdict['counts']['claim_conflicts'] ?? 0));
    }

    public function test_non_hot_file_without_claim_has_no_claim_requirement_warning(): void
    {
        $verdict = $this->service()->evaluate('app/Services/Ai/NotShared/Worker.php', [
            'diff' => '+ class Worker {}',
            'engine' => 'claude_code',
        ]);

        $this->assertFalse($verdict['checks']['blackboard_hot_file_claim']);
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_ALLOW, $verdict['decision']);
    }

    // ---------------- clean edit + safety floor ----------------

    public function test_clean_edit_with_empty_brain_is_allow(): void
    {
        // Nothing seeded that touches this file: a clean, non-governed, non-sensitive,
        // non-duplicate edit must ALLOW.
        $verdict = $this->service()->evaluate('app/Services/Zzz/QqxxUnknownThing.php', [
            'diff' => '+ class QqxxUnknownThing {}',
        ]);

        $this->assertSame(AtlasOpenBrainGuardService::DECISION_ALLOW, $verdict['decision']);
        $this->assertFalse($verdict['has_finding']);
        $this->assertSame([], $verdict['reasons']);
        $this->assertSame('', $verdict['warning']);
    }

    public function test_default_flag_off_never_blocks_a_normal_governed_edit(): void
    {
        // The headline safety invariant: with the DEFAULT config, NO input class can
        // produce a block. Seed ALL three violation kinds at once and assert the
        // decision is at worst WARN.
        $this->seedMemory('dec-1', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');
        $this->seedSymbol('WidgetEmbeddingResolver', 'app/Services/Other/WidgetEmbeddingResolver.php', 'class', 'class WidgetEmbeddingResolver');

        // Sensitive path + exact contradiction + duplication all firing together.
        $verdict = $this->service()->evaluate('app/Services/Finance/WidgetEmbeddingResolver.php', [
            'diff' => '+ use App\\Embedding\\OpenAiEmbeddingClient; + class WidgetEmbeddingResolver {}',
        ]);

        $this->assertFalse(config('atlas.aobg.guard.block_enabled'));
        $this->assertNotSame(
            AtlasOpenBrainGuardService::DECISION_BLOCK,
            $verdict['decision'],
            'with the block flag OFF the guard must NEVER block, no matter how many violations fire',
        );
        $this->assertContains($verdict['decision'], [
            AtlasOpenBrainGuardService::DECISION_ALLOW,
            AtlasOpenBrainGuardService::DECISION_WARN,
        ]);
    }

    public function test_fail_open_allows_when_a_check_throws(): void
    {
        // A workspace-identity that THROWS simulates a broken brain. The guard must
        // FAIL OPEN to allow — never block (or stall) a session by accident — even
        // with the block flag armed.
        config()->set('atlas.aobg.guard.block_enabled', true);

        $boom = new class extends CodeGraphWorkspaceIdentity
        {
            public function __construct() {}

            public function default(): string
            {
                throw new RuntimeException('brain down');
            }

            public function resolve(?string $path = null): string
            {
                throw new RuntimeException('brain down');
            }

            public function resolveWorkspaceOrId(?string $value): string
            {
                throw new RuntimeException('brain down');
            }
        };

        $service = new AtlasOpenBrainGuardService(
            $this->app->make(EngineeringCodeIntelligenceService::class),
            $this->app->make(AtlasRealityGraphQueryService::class),
            $this->app->make(AtlasHybridMemoryRetrievalService::class),
            $boom,
            $this->app->make(AtlasAobgBlackboardService::class),
        );

        $verdict = $service->evaluate('secrets/vault/api.keys', ['diff' => '+ class Foo {}']);

        $this->assertSame(AtlasOpenBrainGuardService::DECISION_ALLOW, $verdict['decision']);
        $this->assertTrue($verdict['fail_open'] ?? false);
        $this->assertFalse($verdict['has_finding']);
    }

    public function test_blank_path_is_allow_and_never_throws(): void
    {
        $verdict = $this->service()->evaluate('   ');
        $this->assertSame(AtlasOpenBrainGuardService::DECISION_ALLOW, $verdict['decision']);
        $this->assertFalse($verdict['has_finding']);
    }

    // ---------------- CLI ----------------

    public function test_cli_command_runs_json_and_exits_zero_for_every_decision(): void
    {
        $this->seedMemory('dec-1', self::STEM.' must use the local embedding engine', true, 'normal', 'decision');

        // warn path
        $this->artisan('atlas:aobg:guard', ['path' => self::TARGET_FILE, '--diff' => 'change', '--json' => true])
            ->assertSuccessful();
        // sensitive-class with --block forced: still exits 0 (decision rides in payload)
        $this->artisan('atlas:aobg:guard', ['path' => 'secrets/vault/api.keys', '--block' => true, '--json' => true])
            ->assertSuccessful();
        // blank path is a clean exit 0 (fail-safe, never a gate)
        $this->artisan('atlas:aobg:guard', ['path' => '   '])->assertSuccessful();
        // default (rendered) render also exits 0
        $this->artisan('atlas:aobg:guard', ['path' => self::TARGET_FILE])->assertSuccessful();
    }

    // ------------------------------------------------------------------
    // fixtures (all sqlite, cost-free — no provider call) — mirrors N2.F1
    // ------------------------------------------------------------------

    private function service(): AtlasOpenBrainGuardService
    {
        return $this->app->make(AtlasOpenBrainGuardService::class);
    }

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
    }

    private function createBlackboardTable(): void
    {
        Schema::dropIfExists('atlas_aobg_blackboard');

        $migration = require database_path('migrations/2026_06_10_120000_create_atlas_aobg_blackboard_table.php');
        $migration->up();
    }

    private function seedClaim(string $engine, string $kind, string $target): void
    {
        $workspaceId = $this->app->make(CodeGraphWorkspaceIdentity::class)->default();
        DB::table('atlas_aobg_blackboard')->insert([
            'id' => $engine.':'.$kind.':'.substr(hash('sha1', $workspaceId.'|'.$target), 0, 16),
            'workspace_id' => $workspaceId,
            'engine' => $engine,
            'kind' => $kind,
            'target' => $target,
            'status' => 'active',
            'claimed_at' => now(),
            'expires_at' => now()->addHour(),
            'meta' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
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
            'workspace_id' => $this->app->make(CodeGraphWorkspaceIdentity::class)->default(),
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
}
