<?php

namespace Tests\Feature\Ai\Finance;

use App\Services\Ai\Finance\AtlasFinanceDomainContract;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceDomainProfileMigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createProfileTables();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        parent::tearDown();
    }

    public function test_finance_migration_upserts_low_autonomy_analysis_only_profiles(): void
    {
        $migration = require base_path('database/migrations/2026_05_05_080000_expand_finance_domain_contract.php');
        $migration->up();
        $contract = app(AtlasFinanceDomainContract::class);

        $domain = DB::table('ai_domain_profiles')->where('id', 'finance')->first();
        $forge = DB::table('ai_flow_profiles')->where('id', 'finance.forge')->first();

        $this->assertNotNull($domain);
        $this->assertSame('finance.market_research', $domain->default_flow);
        $this->assertSame('low', $domain->autonomy_default);
        $this->assertFalse((bool) $domain->background_allowed);
        $this->assertSame('analysis_review_only', data_get(json_decode($domain->gate_policy, true), 'autonomy_ceiling'));
        $this->assertFalse((bool) data_get(json_decode($domain->gate_policy, true), 'market_execution_allowed'));

        $this->assertSame(count($contract->flowDefinitions()), DB::table('ai_flow_profiles')->where('domain_id', 'finance')->count());
        $this->assertSame(
            collect(array_keys($contract->flowDefinitions()))->sort()->values()->all(),
            DB::table('ai_flow_profiles')->where('domain_id', 'finance')->orderBy('id')->pluck('id')->sort()->values()->all(),
        );
        $this->assertNotNull($forge);
        $this->assertSame('low', $forge->autonomy);
        $this->assertSame('FinanceForgeRuntime', $forge->runtime);
        $this->assertTrue((bool) data_get(json_decode($forge->execution_policy, true), 'requires_human_approval'));
        $this->assertFalse((bool) data_get(json_decode($forge->execution_policy, true), 'market_execution_allowed'));
        $this->assertContains('finance_compliance_review', data_get(json_decode($forge->gate_policy, true), 'required'));
        $this->assertContains('place_order', data_get(json_decode($forge->metadata, true), 'forbidden_actions'));
    }

    private function createProfileTables(): void
    {
        Schema::create('ai_domain_profiles', function (Blueprint $table): void {
            $table->string('id', 120)->primary();
            $table->string('label', 160);
            $table->string('status', 40)->default('active')->index();
            $table->string('default_flow', 160)->nullable()->index();
            $table->string('orchestrator', 160)->nullable();
            $table->string('runtime_family', 120)->nullable();
            $table->text('description')->nullable();
            $table->string('autonomy_default', 40)->default('medium');
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
            $table->string('id', 160)->primary();
            $table->string('domain_id', 120)->index();
            $table->string('label', 160);
            $table->string('status', 40)->default('active')->index();
            $table->string('orchestrator', 160)->nullable();
            $table->string('runtime', 160)->nullable()->index();
            $table->text('description')->nullable();
            $table->string('autonomy', 40)->default('medium');
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
