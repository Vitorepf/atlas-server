<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev\Http;

use App\Models\AtlasWorkspaceProfile;
use App\Services\Ai\AtlasOpenBrainService;
use App\Services\Ai\Programming\AtlasDev\Persistence\ReceiptStorage;
use App\Services\Ai\Programming\AtlasDev\Pipeline\AtlasDevFastPathOrchestrator;
use App\Services\Ai\ExecutionAuthority\AwisExecutionGatePort;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base scaffolding for HTTP tests against the Atlas Dev endpoints.
 *
 * Provides:
 *   - isolated temp workspace with .git scaffolding;
 *   - isolated temp receipts directory bound into the container;
 *   - HTTP::preventStrayRequests + a fake Open Brain so the plan layer never
 *     fans out to real HTTP / DB.
 */
abstract class AtlasDevHttpTestCase extends TestCase
{
    use \Tests\Unit\Ai\Programming\AtlasDev\Provider\AtlasDevProviderFixtures;

    protected string $tmpStorage;

    protected string $tmpWorkspace;

    /** @var array<string,string> */
    protected array $headers = ['X-Atlas-Token' => 'test-token-with-enough-length-123'];

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('atlas.token', 'test-token-with-enough-length-123');
        config()->set('atlas_dev.efficient.plan_enabled', true);
        config()->set('atlas_dev.efficient.run_enabled', true);
        config()->set('atlas_dev.efficient.run_dispatch_mode', 'inline');
        // F-09: HMAC signing key for ConfirmationTokenService. The endpoint
        // suite always sets a hermetic key so test outcomes are not coupled
        // to whatever the developer's .env happens to expose.
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('K', 32)));

        Http::preventStrayRequests();
        Http::fake();

        $this->tmpStorage = sys_get_temp_dir().'/atlas-dev-http-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        config()->set('atlas_dev.receipts_path', $this->tmpStorage);
        $this->app->forgetInstance(ReceiptStorage::class);
        $this->app->forgetInstance(AtlasDevFastPathOrchestrator::class);

        $this->tmpWorkspace = sys_get_temp_dir().'/atlas-dev-http-ws-'.bin2hex(random_bytes(4));
        mkdir($this->tmpWorkspace.'/app/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/tests/Unit/Services/Foo', 0o755, true);
        mkdir($this->tmpWorkspace.'/.git/refs/heads', 0o755, true);
        file_put_contents($this->tmpWorkspace.'/.git/HEAD', 'ref: refs/heads/main');
        file_put_contents(
            $this->tmpWorkspace.'/.git/refs/heads/main',
            '0123456789abcdef0123456789abcdef01234567',
        );
        file_put_contents(
            $this->tmpWorkspace.'/app/Services/Foo/FooService.php',
            "<?php\nclass FooService {}\n",
        );
        file_put_contents(
            $this->tmpWorkspace.'/tests/Unit/Services/Foo/FooServiceTest.php',
            "<?php\nclass FooServiceTest {}\n",
        );
        $this->bootstrapAwisWorkspaceRegistry();
        $this->bindAllowedAwisExecutionGate();

        $this->app->instance(AtlasOpenBrainService::class, new FakeAtlasOpenBrainService);

        // The M3 senior critic and the E1-E6 elevations landed after these HTTP
        // contracts froze; their flags downgrade PASSED->needs_review (or fail
        // hard) across the whole suite. Bind a no-concerns critic and pin the
        // elevations off by default; gate-focused suites opt back in per-test.
        $this->bindNoConcernsCritic($this->app);
        foreach (['e1', 'e2', 'e3', 'e4', 'e5', 'e6'] as $elevation) {
            config()->set('atlas_dev.elevations.'.$elevation.'.mode', 'off');
        }

        $this->bootstrapAtlasDevSecurityTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('atlas_dev_run_index');
        Schema::dropIfExists('atlas_dev_confirmation_tokens');
        Schema::dropIfExists('atlas_workspace_profiles');

        File::deleteDirectory($this->tmpStorage);
        File::deleteDirectory($this->tmpWorkspace);
        parent::tearDown();
    }

    /**
     * `PlanController` mints anti-replay confirmation tokens (DB-backed),
     * so the HTTP suite must bootstrap the Atlas Dev security tables on the
     * in-memory sqlite test connection. Mirrors the production migration in
     * `database/migrations/2026_05_16_010000_create_atlas_dev_security_tables.php`.
     */
    protected function bootstrapAtlasDevSecurityTables(): void
    {
        if (! Schema::hasTable('atlas_dev_confirmation_tokens')) {
            Schema::create('atlas_dev_confirmation_tokens', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('run_id', 128)->index();
                $table->string('token_hash', 128)->unique();
                $table->string('surface_id', 80)->index();
                $table->string('task_contract_hash', 128)->index();
                $table->string('compact_sdd_hash', 128)->nullable()->index();
                $table->timestamp('issued_at');
                $table->timestamp('expires_at')->index();
                $table->timestamp('used_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('atlas_dev_run_index')) {
            Schema::create('atlas_dev_run_index', function (Blueprint $table): void {
                $table->string('run_id', 128)->primary();
                $table->string('surface_id', 80)->index();
                $table->string('workspace_hash', 128)->index();
                $table->string('thread_id', 128)->nullable()->index();
                $table->string('routing_decision', 32);
                $table->string('task_kind', 40);
                $table->string('risk_level', 8);
                $table->string('completion_state', 32)->nullable();
                $table->string('last_receipt_hash', 128)->nullable();
                $table->timestamps();
            });
        }
    }

    protected function bootstrapAwisWorkspaceRegistry(): void
    {
        if (! Schema::hasTable('atlas_workspace_profiles')) {
            Schema::create('atlas_workspace_profiles', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('slug', 120)->unique();
                $table->string('name', 200);
                $table->string('kind', 80)->default('product');
                $table->string('workspace_path', 1000)->nullable();
                $table->string('repo_root', 1000)->nullable();
                $table->string('production_status', 80)->default('development');
                $table->text('stack_summary')->nullable();
                $table->json('commands')->nullable();
                $table->json('test_commands')->nullable();
                $table->json('build_commands')->nullable();
                $table->string('dev_server_command', 1000)->nullable();
                $table->json('critical_areas')->nullable();
                $table->string('docs_status', 80)->default('unknown');
                $table->string('default_risk', 40)->default('medium');
                $table->text('deployment_notes')->nullable();
                $table->json('surfaces_enabled')->nullable();
                $table->string('source', 80)->default('test');
                $table->string('status', 40)->default('active');
                $table->timestamps();
            });
        }

        AtlasWorkspaceProfile::query()->updateOrCreate(
            ['slug' => 'atlas-dev-http'],
            [
                'name' => 'Atlas Dev HTTP Test Workspace',
                'kind' => 'test_workspace',
                'workspace_path' => $this->tmpWorkspace,
                'repo_root' => $this->tmpWorkspace,
                'production_status' => 'development',
                'stack_summary' => 'Hermetic Atlas Dev HTTP workspace',
                'commands' => ['test' => 'php artisan test'],
                'test_commands' => ['php artisan test'],
                'build_commands' => [],
                'critical_areas' => ['app/Services/Foo', 'tests/Unit/Services/Foo'],
                'docs_status' => 'complete',
                'default_risk' => 'medium',
                'deployment_notes' => 'Test-only AWIS registry row.',
                'surfaces_enabled' => ['atlas_ai', 'code'],
                'source' => 'test',
                'status' => 'active',
            ],
        );
    }

    protected function bindAllowedAwisExecutionGate(): void
    {
        $this->app->instance(AwisExecutionGatePort::class, new class implements AwisExecutionGatePort
        {
            /**
             * @param  array<int,string>  $conversationTexts
             * @return array<string,mixed>
             */
            public function gate(?string $workspace = null, string $mode = 'conversation', string $task = '', array $conversationTexts = []): array
            {
                return [
                    'allowed' => true,
                    'status' => 'ready',
                    'mode' => $mode,
                    'blockers' => [],
                ];
            }
        });
    }

    /**
     * @return array<string,mixed>
     */
    protected function postPlan(array $payload): array
    {
        $response = $this->withHeaders($this->headers)->postJson('/ai/interactions/atlas-dev/plan', $payload);
        $response->assertStatus(200);

        return $response->json('data') ?? [];
    }

    /**
     * @return array<string,mixed>
     */
    protected function defaultRepairPayload(): array
    {
        return [
            'surface_id' => 'atlas_cli_dev',
            'workspace' => $this->tmpWorkspace,
            'raw_intent' => 'corrija o teste falhando em tests/Unit/Services/Foo/FooServiceTest.php',
            'user_constraints' => [],
        ];
    }
}
