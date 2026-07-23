<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\Policy\AtlasDomainProfileRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasDomainProfileRegistryTest extends TestCase
{
    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        parent::tearDown();
    }

    public function test_static_registry_resolves_programming_profiles_without_tables(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        $profile = app(AtlasDomainProfileRegistry::class)->resolve('programming.forge');

        $this->assertSame('static_fallback', $profile['source']);
        $this->assertSame('programming', $profile['domain_id']);
        $this->assertSame('programming.forge', $profile['flow_id']);
        $this->assertSame('AtlasProgrammingOrchestrator', data_get($profile, 'domain_profile.orchestrator'));
        $this->assertSame('EngineeringHarness', data_get($profile, 'flow_profile.runtime'));
    }

    public function test_static_registry_resolves_marketing_forge_without_tables(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        $profile = app(AtlasDomainProfileRegistry::class)->resolve('marketing.forge');

        $this->assertSame('static_fallback', $profile['source']);
        $this->assertSame('marketing', $profile['domain_id']);
        $this->assertSame('marketing.forge', $profile['flow_id']);
        $this->assertSame('AtlasMarketingOrchestrator', data_get($profile, 'domain_profile.orchestrator'));
        $this->assertSame('MarketingForgeRuntime', data_get($profile, 'flow_profile.runtime'));
        $this->assertSame('domain_forge_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
        $this->assertNotEmpty(data_get($profile, 'domain_profile.context_policy.sources'));
        $this->assertNotEmpty(data_get($profile, 'flow_profile.gate_policy.required'));
    }

    public function test_database_registry_overrides_static_profiles_when_tables_exist(): void
    {
        $this->createRegistryTables();

        DB::table('ai_domain_profiles')->insert([
            'id' => 'programming',
            'label' => 'Programming DB',
            'status' => 'active',
            'default_flow' => 'programming.dev',
            'orchestrator' => 'DbProgrammingOrchestrator',
            'runtime_family' => 'engineering',
            'autonomy_default' => 'medium',
            'background_allowed' => false,
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('ai_flow_profiles')->insert([
            'id' => 'programming.dev',
            'domain_id' => 'programming',
            'label' => 'Dev DB',
            'status' => 'active',
            'orchestrator' => 'DbProgrammingOrchestrator',
            'runtime' => 'DbRuntime',
            'autonomy' => 'medium',
            'background_allowed' => false,
            'requires_human_approval_for_destructive' => true,
            'execution_policy' => json_encode(['executor_preference' => 'db_executor'], JSON_THROW_ON_ERROR),
            'metadata' => '{}',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $profile = app(AtlasDomainProfileRegistry::class)->resolve('programming.dev');

        $this->assertSame('database', $profile['source']);
        $this->assertSame('programming', $profile['domain_id']);
        $this->assertSame('programming.dev', $profile['flow_id']);
        $this->assertSame('DbProgrammingOrchestrator', data_get($profile, 'domain_profile.orchestrator'));
        $this->assertSame('DbRuntime', data_get($profile, 'flow_profile.runtime'));
        $this->assertSame('db_executor', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
    }

    private function createRegistryTables(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        Schema::create('ai_domain_profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('label');
            $table->string('status')->default('active');
            $table->string('default_flow')->nullable();
            $table->string('orchestrator')->nullable();
            $table->string('runtime_family')->nullable();
            $table->string('autonomy_default')->default('medium');
            $table->boolean('background_allowed')->default(false);
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });

        Schema::create('ai_flow_profiles', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('domain_id');
            $table->string('label');
            $table->string('status')->default('active');
            $table->string('orchestrator')->nullable();
            $table->string('runtime')->nullable();
            $table->string('autonomy')->default('medium');
            $table->boolean('background_allowed')->default(false);
            $table->boolean('requires_human_approval_for_destructive')->default(true);
            $table->json('execution_policy')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
