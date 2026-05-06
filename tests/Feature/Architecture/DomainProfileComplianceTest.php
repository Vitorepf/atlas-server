<?php

namespace Tests\Feature\Architecture;

use App\Services\Ai\AtlasDomainProfileRegistry;
use App\Services\Ai\Kernel\Domain\AtlasDomainManifestValidator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;
use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestratorRegistry;
use Tests\TestCase;

class DomainProfileComplianceTest extends TestCase
{
    public function test_atlas_domain_catalog_is_compliant(): void
    {
        $catalog = app(AtlasDomainProfileRegistry::class)->catalog();
        $report = app(AtlasDomainManifestValidator::class)->validateCatalog($catalog);

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(8, $report['domains']);
        $this->assertGreaterThanOrEqual(8, $report['flows']);
    }

    public function test_marketing_and_self_improvement_are_first_class_domains(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $marketing = $registry->resolve('marketing.campaign');
        $selfImprovement = $registry->resolve('self_improvement.nightly_review');
        $finance = $registry->resolve('finance.market_research');
        $personalDevelopment = $registry->resolve('personal_development.reflect');

        $this->assertSame('marketing', $marketing['domain_id']);
        $this->assertSame('marketing.campaign', $marketing['flow_id']);
        $this->assertSame('AtlasMarketingOrchestrator', data_get($marketing, 'domain_profile.orchestrator'));
        $this->assertSame('MarketingRuntime', data_get($marketing, 'flow_profile.runtime'));
        $this->assertNotEmpty(data_get($marketing, 'domain_profile.context_policy.sources'));
        $this->assertNotEmpty(data_get($marketing, 'flow_profile.gate_policy.required'));

        $this->assertSame('self_improvement', $selfImprovement['domain_id']);
        $this->assertSame('self_improvement.nightly_review', $selfImprovement['flow_id']);
        $this->assertSame('AtlasSelfImprovementOrchestrator', data_get($selfImprovement, 'domain_profile.orchestrator'));
        $this->assertSame('SelfImprovementRuntime', data_get($selfImprovement, 'flow_profile.runtime'));
        $this->assertTrue((bool) data_get($selfImprovement, 'flow_profile.background_allowed'));

        $this->assertSame('finance', $finance['domain_id']);
        $this->assertSame('finance.market_research', $finance['flow_id']);
        $this->assertSame('AtlasFinanceOrchestrator', data_get($finance, 'domain_profile.orchestrator'));
        $this->assertSame('FinanceResearchRuntime', data_get($finance, 'flow_profile.runtime'));
        $this->assertFalse((bool) data_get($finance, 'flow_profile.execution_policy.market_execution_allowed'));

        $this->assertSame('personal_development', $personalDevelopment['domain_id']);
        $this->assertSame('personal_development.reflect', $personalDevelopment['flow_id']);
        $this->assertSame('AtlasPersonalDevelopmentOrchestrator', data_get($personalDevelopment, 'domain_profile.orchestrator'));
        $this->assertSame('PersonalDevelopmentRuntime', data_get($personalDevelopment, 'flow_profile.runtime'));
        $this->assertSame('plan_only', data_get($personalDevelopment, 'flow_profile.gate_policy.autonomy_ceiling'));
    }

    public function test_general_declares_controlled_answer_and_handoff_flow(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);
        $profile = $registry->resolve('general.answer');

        $this->assertSame('general', $profile['domain_id']);
        $this->assertSame('general.answer', $profile['flow_id']);
        $this->assertSame('StandardResponseOrchestrator', data_get($profile, 'domain_profile.orchestrator'));
        $this->assertSame('answer_or_triage_only', data_get($profile, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertSame('general_answer_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
        $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.specialized_work_allowed'));
        $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.tool_execution_allowed'));
        $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.provider_override_allowed'));
    }

    public function test_health_declares_non_clinical_review_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('health.review');

        $this->assertSame('health', $domain['domain_id']);
        $this->assertSame('AtlasHealthOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('non_clinical_review_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.diagnosis_allowed'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.treatment_allowed'));

        foreach ([
            'health.review' => 'HealthReviewRuntime',
            'health.routine_review' => 'HealthRoutineRuntime',
            'health.recovery_review' => 'HealthRecoveryRuntime',
            'health.safety_review' => 'HealthSafetyRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('health', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasHealthOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('health_review_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.diagnosis_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.treatment_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.dosage_change_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.emergency_decision_allowed'));
        }
    }

    public function test_marketing_declares_full_growth_domain_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'marketing.strategy' => 'MarketingStrategyRuntime',
            'marketing.research' => 'MarketingResearchRuntime',
            'marketing.positioning' => 'MarketingStrategyRuntime',
            'marketing.campaign' => 'MarketingRuntime',
            'marketing.creative' => 'MarketingCreativeRuntime',
            'marketing.copywriting' => 'MarketingCopyRuntime',
            'marketing.media_plan' => 'MarketingMediaRuntime',
            'marketing.landing_page' => 'MarketingAssetRuntime',
            'marketing.email' => 'MarketingAssetRuntime',
            'marketing.social' => 'MarketingAssetRuntime',
            'marketing.video_script' => 'MarketingCreativeRuntime',
            'marketing.ab_test' => 'MarketingExperimentRuntime',
            'marketing.analytics' => 'MarketingAnalyticsRuntime',
            'marketing.brand_review' => 'MarketingReviewRuntime',
            'marketing.forge' => 'MarketingForgeRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('marketing', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasMarketingOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.gate_policy.required'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.context_policy.sources'));
        }

        $this->assertSame('domain_forge_runtime', data_get($registry->resolve('marketing.forge'), 'flow_profile.execution_policy.executor_preference'));
    }

    public function test_strategic_decision_declares_review_only_co_strategist_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('strategic_decision.review');

        $this->assertSame('strategic_decision', $domain['domain_id']);
        $this->assertSame('AtlasStrategicDecisionOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('review_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertContains('rivals_strategy_cases', data_get($domain, 'domain_profile.context_policy.sources'));

        foreach ([
            'strategic_decision.review' => 'StrategicDecisionReviewRuntime',
            'strategic_decision.cooldown' => 'StrategicDecisionCooldownRuntime',
            'strategic_decision.values_alignment' => 'StrategicDecisionAlignmentRuntime',
            'strategic_decision.counterargument' => 'StrategicDecisionCounterargumentRuntime',
            'strategic_decision.regret_tracking' => 'StrategicDecisionRegretRuntime',
            'strategic_decision.longitudinal_pattern' => 'StrategicDecisionPatternRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('strategic_decision', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasStrategicDecisionOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('review_only', data_get($profile, 'flow_profile.gate_policy.autonomy_ceiling'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.commitment_execution_allowed'));
            $this->assertTrue((bool) data_get($profile, 'flow_profile.execution_policy.requires_human_approval'));
            $this->assertContains('operator_agency', data_get($profile, 'flow_profile.gate_policy.global_required'));
        }
    }

    public function test_self_improvement_declares_full_evolution_domain_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'self_improvement.nightly_review',
            'self_improvement.weekly_architecture_audit',
            'self_improvement.capability_gap_scan',
            'self_improvement.benchmark_review',
            'self_improvement.memory_quality_review',
            'self_improvement.tool_runtime_review',
            'self_improvement.repair_loop_review',
            'self_improvement.kernel_pipeline_review',
            'self_improvement.domain_learning_review',
            'self_improvement.docs_drift_review',
            'self_improvement.provider_performance_review',
            'self_improvement.proposal_generation',
        ] as $flowId) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('self_improvement', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasSelfImprovementOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame('SelfImprovementRuntime', data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('proposal_only', data_get($profile, 'flow_profile.gate_policy.autonomy_ceiling'));
            $this->assertTrue((bool) data_get($profile, 'flow_profile.execution_policy.proposal_only'));
        }
    }

    public function test_programming_declares_specialized_domain_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'programming.dev' => 'ProviderExecution',
            'programming.repair' => 'ProviderExecution',
            'programming.review' => 'ProviderExecution',
            'programming.refactor' => 'ProviderExecution',
            'programming.qa' => 'EngineeringHarness',
            'programming.security' => 'EngineeringHarness',
            'programming.database' => 'EngineeringHarness',
            'programming.visual' => 'EngineeringHarness',
            'programming.forge' => 'EngineeringHarness',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('programming', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasProgrammingOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.execution_policy.executor_preference'));
        }

        $this->assertSame('dev_repair_executor', data_get($registry->resolve('programming.repair'), 'flow_profile.execution_policy.executor_preference'));
        $this->assertSame('read_only', data_get($registry->resolve('programming.review'), 'flow_profile.tool_policy.mode'));
        $this->assertSame('engineering_harness', data_get($registry->resolve('programming.security'), 'flow_profile.execution_policy.executor_preference'));
    }

    public function test_finance_declares_analysis_only_enterprise_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'finance.market_research' => 'FinanceResearchRuntime',
            'finance.risk_review' => 'FinanceRiskRuntime',
            'finance.portfolio_analysis' => 'FinancePortfolioRuntime',
            'finance.trade_thesis' => 'FinanceThesisRuntime',
            'finance.macro_review' => 'FinanceMacroRuntime',
            'finance.earnings_review' => 'FinanceEarningsRuntime',
            'finance.news_impact' => 'FinanceNewsRuntime',
            'finance.compliance_review' => 'FinanceComplianceRuntime',
            'finance.backtest_plan' => 'FinanceBacktestRuntime',
            'finance.forge' => 'FinanceForgeRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('finance', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasFinanceOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('analysis_review_only', data_get($profile, 'flow_profile.gate_policy.autonomy_ceiling'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.market_execution_allowed'));
            $this->assertNotEmpty(data_get($profile, 'flow_profile.execution_policy.required_evidence'));
        }

        $this->assertTrue((bool) data_get($registry->resolve('finance.forge'), 'flow_profile.execution_policy.requires_human_approval'));
    }

    public function test_personal_development_declares_private_plan_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        foreach ([
            'personal_development.reflect',
            'personal_development.daily_review',
            'personal_development.weekly_review',
            'personal_development.habit_design',
            'personal_development.focus_plan',
            'personal_development.learning_plan',
            'personal_development.energy_review',
            'personal_development.goal_decomposition',
            'personal_development.recovery_plan',
            'personal_development.forge',
        ] as $flowId) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('personal_development', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasPersonalDevelopmentOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame('PersonalDevelopmentRuntime', data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('plan_only', data_get($profile, 'flow_profile.gate_policy.autonomy_ceiling'));
            $this->assertTrue((bool) data_get($profile, 'flow_profile.execution_policy.plan_and_artifacts_only'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.calendar_mutation'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.task_mutation'));
        }

        $this->assertTrue((bool) data_get($registry->resolve('personal_development.forge'), 'flow_profile.execution_policy.forge_requires_human_approval'));
    }

    public function test_writing_declares_governed_draft_and_review_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('writing.draft');

        $this->assertSame('writing', $domain['domain_id']);
        $this->assertSame('AtlasWritingOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('draft_and_review', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertContains('operator_voice_samples', data_get($domain, 'domain_profile.context_policy.sources'));

        foreach ([
            'writing.draft' => 'WritingRuntime',
            'writing.edit' => 'WritingEditRuntime',
            'writing.voice_review' => 'WritingVoiceRuntime',
            'writing.publish_review' => 'WritingReviewRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('writing', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasWritingOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('writing_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.external_publish_allowed'));
            $this->assertContains('human_review_required', data_get($profile, 'flow_profile.gate_policy.global_required'));
        }
    }

    public function test_learning_declares_human_learning_plan_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('learning.plan');

        $this->assertSame('learning', $domain['domain_id']);
        $this->assertSame('AtlasLearningOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('plan_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.core_learning_plane_mutation'));

        foreach ([
            'learning.plan' => 'LearningRuntime',
            'learning.practice' => 'LearningPracticeRuntime',
            'learning.review' => 'LearningReviewRuntime',
            'learning.spaced_review' => 'LearningSpacedReviewRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('learning', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasLearningOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('learning_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.calendar_mutation'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.task_mutation'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.core_learning_plane_mutation'));
        }
    }

    public function test_qa_declares_cross_domain_review_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('qa.regression_review');

        $this->assertSame('qa', $domain['domain_id']);
        $this->assertSame('AtlasQaOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('review_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.test_execution_allowed'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.domain_gate_override_allowed'));

        foreach ([
            'qa.regression_review' => 'QaRegressionRuntime',
            'qa.acceptance_review' => 'QaAcceptanceRuntime',
            'qa.evidence_audit' => 'QaEvidenceRuntime',
            'qa.release_readiness' => 'QaReleaseRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('qa', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasQaOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('qa_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.test_execution_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.deploy_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.domain_gate_override_allowed'));
        }
    }

    public function test_security_declares_defensive_review_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('security.threat_review');

        $this->assertSame('security', $domain['domain_id']);
        $this->assertSame('AtlasSecurityOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('defensive_review_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.exploit_execution_allowed'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.secret_access_allowed'));

        foreach ([
            'security.threat_review' => 'SecurityThreatRuntime',
            'security.privacy_review' => 'SecurityPrivacyRuntime',
            'security.compliance_review' => 'SecurityComplianceRuntime',
            'security.incident_review' => 'SecurityIncidentRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('security', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasSecurityOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('security_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.exploit_execution_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.network_scan_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.secret_access_allowed'));
        }
    }

    public function test_operations_declares_diagnostic_only_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('operations.diagnostic');

        $this->assertSame('operations', $domain['domain_id']);
        $this->assertSame('AtlasOperationsOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('diagnostic_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.deploy_allowed'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.infrastructure_mutation_allowed'));

        foreach ([
            'operations.diagnostic' => 'OperationsDiagnosticRuntime',
            'operations.runbook' => 'OperationsRunbookRuntime',
            'operations.incident_review' => 'OperationsIncidentRuntime',
            'operations.readiness_review' => 'OperationsReadinessRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('operations', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('AtlasOperationsOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('operations_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.restart_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.deploy_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.infrastructure_mutation_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.data_deletion_allowed'));
        }
    }

    public function test_background_declares_review_only_safety_flows(): void
    {
        $registry = app(AtlasDomainProfileRegistry::class);

        $domain = $registry->resolve('background.safe');

        $this->assertSame('background', $domain['domain_id']);
        $this->assertSame('BackgroundSafetyOrchestrator', data_get($domain, 'domain_profile.orchestrator'));
        $this->assertSame('review_only', data_get($domain, 'domain_profile.gate_policy.autonomy_ceiling'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.start_jobs_allowed'));
        $this->assertFalse((bool) data_get($domain, 'domain_profile.gate_policy.unbounded_loop_allowed'));

        foreach ([
            'background.safe' => 'BackgroundSafetyRuntime',
            'background.readiness_review' => 'BackgroundReadinessRuntime',
            'background.schedule_review' => 'BackgroundScheduleRuntime',
            'background.permission_review' => 'BackgroundPermissionRuntime',
        ] as $flowId => $runtime) {
            $profile = $registry->resolve($flowId);

            $this->assertSame('background', $profile['domain_id'], $flowId);
            $this->assertSame($flowId, $profile['flow_id']);
            $this->assertSame('BackgroundSafetyOrchestrator', data_get($profile, 'flow_profile.orchestrator'));
            $this->assertSame($runtime, data_get($profile, 'flow_profile.runtime'));
            $this->assertSame('background_safety_packet_runtime', data_get($profile, 'flow_profile.execution_policy.executor_preference'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.start_jobs_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.schedule_mutation_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.permission_escalation_allowed'));
            $this->assertFalse((bool) data_get($profile, 'flow_profile.execution_policy.unbounded_loop_allowed'));
        }
    }

    public function test_every_active_flow_has_executable_orchestrator_contract(): void
    {
        $catalog = app(AtlasDomainProfileRegistry::class)->catalog();
        $orchestrators = app(AtlasDomainOrchestratorRegistry::class);
        $report = $orchestrators->complianceReport();

        $this->assertTrue($report['ok'], implode("\n", $report['errors']));
        $this->assertGreaterThanOrEqual(14, $report['orchestrators']);

        foreach ((array) ($catalog['flows'] ?? []) as $flow) {
            if (($flow['status'] ?? 'active') !== 'active') {
                continue;
            }

            $orchestratorId = (string) ($flow['orchestrator'] ?? '');
            $definition = $orchestrators->get($orchestratorId);

            $this->assertNotNull($definition, "Flow {$flow['id']} references unregistered orchestrator {$orchestratorId}");
            $this->assertTrue(class_exists((string) $definition['class']), "Flow {$flow['id']} orchestrator class is missing");
            $this->assertTrue(is_subclass_of((string) $definition['class'], AtlasDomainOrchestrator::class), "Flow {$flow['id']} orchestrator must implement the SDK contract");

            /** @var AtlasDomainOrchestrator $instance */
            $instance = app((string) $definition['class']);
            $this->assertContains((string) $flow['domain_id'], $instance->supportedDomains(), "Flow {$flow['id']} domain is not supported by {$orchestratorId}");
            $this->assertContains((string) $flow['id'], $instance->supportedFlows(), "Flow {$flow['id']} is not supported by {$orchestratorId}");
        }
    }

    public function test_validator_rejects_orphan_default_flow(): void
    {
        $catalog = [
            'domains' => [
                [
                    'id' => 'marketing',
                    'label' => 'Marketing',
                    'status' => 'active',
                    'default_flow' => 'marketing.missing',
                    'orchestrator' => 'AtlasMarketingOrchestrator',
                    'runtime_family' => 'marketing',
                    'autonomy_default' => 'medium',
                ],
            ],
            'flows' => [],
        ];

        $report = app(AtlasDomainManifestValidator::class)->validateCatalog($catalog);

        $this->assertFalse($report['ok']);
        $this->assertContains('domain marketing default_flow marketing.missing is not declared as an active flow', $report['errors']);
    }
}
