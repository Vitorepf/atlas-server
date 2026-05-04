<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_domain_profiles')) {
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
        }

        if (! Schema::hasTable('ai_flow_profiles')) {
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

                $table->foreign('domain_id')
                    ->references('id')
                    ->on('ai_domain_profiles')
                    ->cascadeOnUpdate()
                    ->restrictOnDelete();
            });
        }

        $this->installUpdatedAtTriggers();
        $this->seedCanonicalProfiles();
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('DROP TRIGGER IF EXISTS trg_ai_flow_profiles_updated_at ON ai_flow_profiles;');
            DB::statement('DROP TRIGGER IF EXISTS trg_ai_domain_profiles_updated_at ON ai_domain_profiles;');
        }

        Schema::dropIfExists('ai_flow_profiles');
        Schema::dropIfExists('ai_domain_profiles');
    }

    private function installUpdatedAtTriggers(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP TRIGGER IF EXISTS trg_ai_domain_profiles_updated_at ON ai_domain_profiles;');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ai_domain_profiles_updated_at
            BEFORE UPDATE ON ai_domain_profiles
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);

        DB::statement('DROP TRIGGER IF EXISTS trg_ai_flow_profiles_updated_at ON ai_flow_profiles;');
        DB::statement(<<<'SQL'
            CREATE TRIGGER trg_ai_flow_profiles_updated_at
            BEFORE UPDATE ON ai_flow_profiles
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
        SQL);
    }

    private function seedCanonicalProfiles(): void
    {
        $now = now();

        foreach ($this->domainProfiles() as $profile) {
            $exists = DB::table('ai_domain_profiles')->where('id', $profile['id'])->exists();
            $values = array_merge($profile, [
                'model_policy' => json_encode($profile['model_policy'] ?? [], JSON_THROW_ON_ERROR),
                'context_policy' => json_encode($profile['context_policy'] ?? [], JSON_THROW_ON_ERROR),
                'skill_policy' => json_encode($profile['skill_policy'] ?? [], JSON_THROW_ON_ERROR),
                'tool_policy' => json_encode($profile['tool_policy'] ?? [], JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode($profile['memory_policy'] ?? [], JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode($profile['gate_policy'] ?? [], JSON_THROW_ON_ERROR),
                'metadata' => json_encode($profile['metadata'] ?? [], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            if (! $exists) {
                $values['created_at'] = $now;
            }

            DB::table('ai_domain_profiles')->updateOrInsert(
                ['id' => $profile['id']],
                $values
            );
        }

        foreach ($this->flowProfiles() as $profile) {
            $exists = DB::table('ai_flow_profiles')->where('id', $profile['id'])->exists();
            $values = array_merge($profile, [
                'model_policy' => json_encode($profile['model_policy'] ?? [], JSON_THROW_ON_ERROR),
                'context_policy' => json_encode($profile['context_policy'] ?? [], JSON_THROW_ON_ERROR),
                'skill_policy' => json_encode($profile['skill_policy'] ?? [], JSON_THROW_ON_ERROR),
                'tool_policy' => json_encode($profile['tool_policy'] ?? [], JSON_THROW_ON_ERROR),
                'memory_policy' => json_encode($profile['memory_policy'] ?? [], JSON_THROW_ON_ERROR),
                'gate_policy' => json_encode($profile['gate_policy'] ?? [], JSON_THROW_ON_ERROR),
                'execution_policy' => json_encode($profile['execution_policy'] ?? [], JSON_THROW_ON_ERROR),
                'metadata' => json_encode($profile['metadata'] ?? [], JSON_THROW_ON_ERROR),
                'updated_at' => $now,
            ]);

            if (! $exists) {
                $values['created_at'] = $now;
            }

            DB::table('ai_flow_profiles')->updateOrInsert(
                ['id' => $profile['id']],
                $values
            );
        }
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function domainProfiles(): array
    {
        return [
            [
                'id' => 'general',
                'label' => 'General',
                'status' => 'active',
                'default_flow' => 'general.answer',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime_family' => 'conversation',
                'description' => 'Conversation, synthesis, organization, and default Atlas responses.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
            ],
            [
                'id' => 'research',
                'label' => 'Research',
                'status' => 'active',
                'default_flow' => 'research.quick',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime_family' => 'research',
                'description' => 'Grounded research, source discovery, synthesis, and contradiction checks.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
            ],
            [
                'id' => 'programming',
                'label' => 'Programming',
                'status' => 'active',
                'default_flow' => 'programming.dev',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime_family' => 'engineering',
                'description' => 'Code, debugging, refactor, QA, security, and engineering implementation.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
            ],
            [
                'id' => 'finance',
                'label' => 'Finance',
                'status' => 'active',
                'default_flow' => 'finance.research',
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime_family' => 'finance',
                'description' => 'Financial research, portfolio review, risk checks, and decision support.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
            ],
            [
                'id' => 'personal_development',
                'label' => 'Personal Development',
                'status' => 'active',
                'default_flow' => 'personal_development.reflect',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime_family' => 'personal_development',
                'description' => 'Personal goals, reflection, books, behavior design, and accountability loops.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
            ],
            [
                'id' => 'health',
                'label' => 'Health',
                'status' => 'active',
                'default_flow' => 'health.review',
                'orchestrator' => 'AtlasHealthOrchestrator',
                'runtime_family' => 'health',
                'description' => 'Health review and evidence-aware wellness workflows.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
            ],
            [
                'id' => 'learning',
                'label' => 'Learning',
                'status' => 'active',
                'default_flow' => 'learning.plan',
                'orchestrator' => 'AtlasLearningOrchestrator',
                'runtime_family' => 'learning',
                'description' => 'Study plans, comprehension loops, practice, and spaced review.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
            ],
            [
                'id' => 'writing',
                'label' => 'Writing',
                'status' => 'active',
                'default_flow' => 'writing.draft',
                'orchestrator' => 'AtlasWritingOrchestrator',
                'runtime_family' => 'writing',
                'description' => 'Drafting, editing, voice, structure, and publication workflows.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
            ],
            [
                'id' => 'qa',
                'label' => 'QA',
                'status' => 'active',
                'default_flow' => 'qa.regression_review',
                'orchestrator' => 'AtlasQaOrchestrator',
                'runtime_family' => 'quality',
                'description' => 'Quality review, regression checks, test design, and release readiness.',
                'autonomy_default' => 'medium',
                'background_allowed' => false,
            ],
            [
                'id' => 'security',
                'label' => 'Security',
                'status' => 'active',
                'default_flow' => 'security.threat_review',
                'orchestrator' => 'AtlasSecurityOrchestrator',
                'runtime_family' => 'security',
                'description' => 'Threat review, vulnerability checks, secure design, and evidence gates.',
                'autonomy_default' => 'low',
                'background_allowed' => false,
            ],
            [
                'id' => 'operations',
                'label' => 'Operations',
                'status' => 'active',
                'default_flow' => 'operations.diagnostic',
                'orchestrator' => 'AtlasOperationsOrchestrator',
                'runtime_family' => 'operations',
                'description' => 'Diagnostics, runbooks, system health, and operational next actions.',
                'autonomy_default' => 'medium',
                'background_allowed' => true,
            ],
            [
                'id' => 'background',
                'label' => 'Background',
                'status' => 'active',
                'default_flow' => 'background.safe',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime_family' => 'background',
                'description' => 'Conservative background execution envelope for scheduled Atlas work.',
                'autonomy_default' => 'low',
                'background_allowed' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function flowProfiles(): array
    {
        return [
            [
                'id' => 'general.answer',
                'domain_id' => 'general',
                'label' => 'Answer',
                'status' => 'active',
                'orchestrator' => 'StandardResponseOrchestrator',
                'runtime' => 'StandardAiResponse',
                'description' => 'Default concise Atlas response flow.',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
            ],
            [
                'id' => 'research.quick',
                'domain_id' => 'research',
                'label' => 'Quick Research',
                'status' => 'active',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime' => 'ResearchRuntime',
                'description' => 'Fast grounded research with source sanity checks.',
                'autonomy' => 'medium',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
            ],
            [
                'id' => 'research.super',
                'domain_id' => 'research',
                'label' => 'Super Research',
                'status' => 'active',
                'orchestrator' => 'AtlasResearchOrchestrator',
                'runtime' => 'ResearchRuntime',
                'description' => 'Deep research with discovery, extraction, contradiction search, synthesis, and evidence gates.',
                'autonomy' => 'high',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
            ],
            [
                'id' => 'programming.dev',
                'domain_id' => 'programming',
                'label' => 'Dev',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'ProviderExecution',
                'description' => 'Daily programming, bugs, small and medium implementation, and scoped refactor.',
                'autonomy' => 'medium',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'skill_policy' => [
                    'preset' => 'domain',
                    'required_bundles' => ['dev-quality-gate'],
                    'require_skill_trace' => true,
                ],
                'execution_policy' => ['executor_preference' => 'simple_provider_execution'],
            ],
            [
                'id' => 'programming.forge',
                'domain_id' => 'programming',
                'label' => 'Forge',
                'status' => 'active',
                'orchestrator' => 'AtlasProgrammingOrchestrator',
                'runtime' => 'EngineeringHarness',
                'description' => 'Maximum-power programming flow with Harness, worktree/sandbox, tests, gates, artifacts, score, and memory.',
                'autonomy' => 'high',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
                'skill_policy' => [
                    'preset' => 'domain',
                    'required_bundles' => ['engineering-blueprint', 'dev-quality-gate', 'code-reviewer'],
                    'require_skill_trace' => true,
                ],
                'execution_policy' => ['executor_preference' => 'engineering_harness'],
            ],
            [
                'id' => 'finance.research',
                'domain_id' => 'finance',
                'label' => 'Finance Research',
                'status' => 'active',
                'orchestrator' => 'AtlasFinanceOrchestrator',
                'runtime' => 'FinanceRuntime',
                'description' => 'Finance research with risk, source, and non-advice gates.',
                'autonomy' => 'low',
                'background_allowed' => false,
                'requires_human_approval_for_destructive' => true,
            ],
            [
                'id' => 'personal_development.reflect',
                'domain_id' => 'personal_development',
                'label' => 'Reflect',
                'status' => 'active',
                'orchestrator' => 'AtlasPersonalDevelopmentOrchestrator',
                'runtime' => 'PersonalDevelopmentRuntime',
                'description' => 'Reflection and action planning grounded in personal context and memory.',
                'autonomy' => 'medium',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
            ],
            [
                'id' => 'background.safe',
                'domain_id' => 'background',
                'label' => 'Safe Background',
                'status' => 'active',
                'orchestrator' => 'BackgroundSafetyOrchestrator',
                'runtime' => 'StandardAiResponse',
                'description' => 'Conservative background flow requiring explicit allow and bounded autonomy.',
                'autonomy' => 'low',
                'background_allowed' => true,
                'requires_human_approval_for_destructive' => true,
            ],
        ];
    }
};
