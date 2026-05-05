<?php

namespace Tests\Feature\Ai\PersonalDevelopment;

use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentFlowCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PersonalDevelopmentDomainContractMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createRegistryTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        parent::tearDown();
    }

    public function test_migration_installs_isolated_personal_development_domain_contract(): void
    {
        $migration = require base_path('database/migrations/2026_05_05_080000_expand_personal_development_domain_contract.php');

        $migration->up();

        $domain = DB::table('ai_domain_profiles')->where('id', 'personal_development')->first();
        $flows = DB::table('ai_flow_profiles')->where('domain_id', 'personal_development')->orderBy('id')->get();

        $this->assertNotNull($domain);
        $this->assertSame('AtlasPersonalDevelopmentOrchestrator', $domain->orchestrator);
        $this->assertSame('personal_development.reflect', $domain->default_flow);
        $this->assertFalse((bool) $domain->background_allowed);
        $this->assertSame('private', data_get(json_decode($domain->memory_policy, true, flags: JSON_THROW_ON_ERROR), 'privacy_default'));
        $this->assertTrue((bool) data_get(json_decode($domain->memory_policy, true, flags: JSON_THROW_ON_ERROR), 'provider_safe_only_when_redacted'));
        $this->assertContains('psychological_diagnosis', data_get(json_decode($domain->gate_policy, true, flags: JSON_THROW_ON_ERROR), 'forbidden'));

        $expectedFlows = PersonalDevelopmentFlowCatalog::ids();
        sort($expectedFlows);

        $this->assertCount(count(PersonalDevelopmentFlowCatalog::ids()), $flows);
        $this->assertSame($expectedFlows, $flows->pluck('id')->all());

        $forge = $flows->firstWhere('id', 'personal_development.forge');
        $forgePolicy = json_decode($forge->execution_policy, true, flags: JSON_THROW_ON_ERROR);

        $this->assertTrue((bool) data_get($forgePolicy, 'plan_and_artifacts_only'));
        $this->assertTrue((bool) data_get($forgePolicy, 'forge_requires_human_approval'));
        $this->assertFalse((bool) data_get($forgePolicy, 'calendar_mutation'));
        $this->assertFalse((bool) data_get($forgePolicy, 'task_mutation'));
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
            $table->text('description')->nullable();
            $table->string('autonomy_default')->default('low');
            $table->boolean('background_allowed')->default(false);
            $table->json('model_policy')->default('{}');
            $table->json('context_policy')->default('{}');
            $table->json('skill_policy')->default('{}');
            $table->json('tool_policy')->default('{}');
            $table->json('memory_policy')->default('{}');
            $table->json('gate_policy')->default('{}');
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
            $table->text('description')->nullable();
            $table->string('autonomy')->default('low');
            $table->boolean('background_allowed')->default(false);
            $table->boolean('requires_human_approval_for_destructive')->default(true);
            $table->json('model_policy')->default('{}');
            $table->json('context_policy')->default('{}');
            $table->json('skill_policy')->default('{}');
            $table->json('tool_policy')->default('{}');
            $table->json('memory_policy')->default('{}');
            $table->json('gate_policy')->default('{}');
            $table->json('execution_policy')->default('{}');
            $table->json('metadata')->default('{}');
            $table->timestamps();
        });
    }
}
