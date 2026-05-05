<?php

namespace Tests\Unit\Ai\PersonalDevelopment;

use App\Services\Ai\PersonalDevelopment\AtlasPersonalDevelopmentOrchestrator;
use App\Services\Ai\PersonalDevelopment\PersonalDevelopmentFlowCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AtlasPersonalDevelopmentOrchestratorTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createRegistryTables();
        $this->seedProfiles();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');

        parent::tearDown();
    }

    public function test_all_personal_development_flows_are_supported(): void
    {
        $orchestrator = app(AtlasPersonalDevelopmentOrchestrator::class);

        $this->assertSame('implemented', $orchestrator->maturity());
        $this->assertSame(['personal_development'], $orchestrator->supportedDomains());
        $this->assertEqualsCanonicalizing(PersonalDevelopmentFlowCatalog::ids(), $orchestrator->supportedFlows());

        foreach ($orchestrator->supportedFlows() as $flow) {
            $plan = $orchestrator->flowPlan($flow);
            $catalog = PersonalDevelopmentFlowCatalog::get($flow);

            $this->assertSame($flow, $plan['flow']);
            $this->assertSame('PersonalDevelopmentRuntime', $plan['runtime']);
            $this->assertSame($catalog['artifact'], data_get($plan, 'flow_contract.artifact'));
            $this->assertSame('database', data_get($plan, 'profile_receipt.source'));
            $this->assertFalse($plan['destructive_actions_allowed']);
            $this->assertTrue((bool) data_get($plan, 'runtime_contract.returns_plan_and_artifacts_only'));
            $this->assertTrue((bool) data_get($plan, 'runtime_contract.non_clinical'));
            $this->assertContains('calendar_write', $plan['blocked_actions']);
            $this->assertContains('medical_treatment_advice', $plan['blocked_actions']);
        }
    }

    public function test_forge_flow_requires_human_approval(): void
    {
        $plan = app(AtlasPersonalDevelopmentOrchestrator::class)->flowPlan('forge');

        $this->assertSame('personal_development.forge', $plan['flow']);
        $this->assertTrue($plan['approval_required']);
        $this->assertContains('forge_flow_requires_human_approval', $plan['approval_reasons']);
        $this->assertTrue((bool) data_get($plan, 'execution_policy.forge_requires_human_approval'));
        $this->assertSame('review_required', data_get($plan, 'flow_contract.risk'));
    }

    public function test_convenience_methods_return_canonical_contracts(): void
    {
        $orchestrator = app(AtlasPersonalDevelopmentOrchestrator::class);

        $reflect = $orchestrator->reflectPlan();
        $forge = $orchestrator->forgePlan();
        $executedForge = $orchestrator->executeForge(['intent' => 'Build weekly operating rhythm.'], [
            'human_approved' => true,
        ]);

        $this->assertSame('personal_development.reflect', $reflect['flow']);
        $this->assertSame('reflection_brief', data_get($reflect, 'flow_contract.artifact'));
        $this->assertSame('personal_development.forge', $forge['flow']);
        $this->assertTrue($forge['approval_required']);
        $this->assertSame('planned', $executedForge['status']);
        $this->assertSame('personal_development.forge', data_get($executedForge, 'runtime.flow'));
        $this->assertTrue((bool) data_get($executedForge, 'runtime.approval_required'));
    }

    public function test_unknown_flow_is_rejected_before_runtime_execution(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported personal development flow');

        app(AtlasPersonalDevelopmentOrchestrator::class)->flowPlan('clinical_diagnosis');
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

    private function seedProfiles(): void
    {
        $now = now();

        DB::table('ai_domain_profiles')->insert([
            'id' => 'personal_development',
            'label' => 'Personal Development',
            'status' => 'active',
            'default_flow' => 'personal_development.reflect',
            'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
            'runtime_family' => 'personal_development',
            'description' => 'Private non-clinical personal development domain.',
            'autonomy_default' => 'low',
            'background_allowed' => false,
            'memory_policy' => json_encode(['provider_safe_only_when_redacted' => true], JSON_THROW_ON_ERROR),
            'gate_policy' => json_encode(['required' => ['privacy_review', 'non_clinical_language']], JSON_THROW_ON_ERROR),
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (PersonalDevelopmentFlowCatalog::ids() as $flow) {
            DB::table('ai_flow_profiles')->insert([
                'id' => $flow,
                'domain_id' => 'personal_development',
                'label' => str($flow)->after('.')->replace('_', ' ')->title()->toString(),
                'status' => 'active',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime' => 'PersonalDevelopmentRuntime',
                'description' => 'Personal development flow.',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'memory_policy' => json_encode(['provider_safe_only_when_redacted' => true], JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode(['required' => ['privacy_review']], JSON_THROW_ON_ERROR),
                'execution_policy' => json_encode([
                    'plan_and_artifacts_only' => true,
                    'forge_requires_human_approval' => $flow === 'personal_development.forge',
                ], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
