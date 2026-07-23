<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiContextPackBuilder as LegacyAiContextPackBuilder;
use App\Services\Ai\AiContextSnapshotRecorder as LegacyAiContextSnapshotRecorder;
use App\Services\Ai\AiConversationContextBuilder as LegacyAiConversationContextBuilder;
use App\Services\Ai\AiConversationRecorder as LegacyAiConversationRecorder;
use App\Services\Ai\AiCouncilCoordinator as LegacyAiCouncilCoordinator;
use App\Services\Ai\AiDecisionReceiptRefreshService as LegacyAiDecisionReceiptRefreshService;
use App\Services\Ai\AiExecutionPresentationState as LegacyAiExecutionPresentationState;
use App\Services\Ai\AiIntentRouter as LegacyAiIntentRouter;
use App\Services\Ai\AiInteractionSteeringService as LegacyAiInteractionSteeringService;
use App\Services\Ai\AiPermissionDecision as LegacyAiPermissionDecision;
use App\Services\Ai\AiPermissionEngine as LegacyAiPermissionEngine;
use App\Services\Ai\AiPermissionEngineSupport as LegacyAiPermissionEngineSupport;
use App\Services\Ai\AiPrompt as LegacyAiPrompt;
use App\Services\Ai\AiQualityActionService as LegacyAiQualityActionService;
use App\Services\Ai\AiQualityEvaluator as LegacyAiQualityEvaluator;
use App\Services\Ai\AiSessionManager as LegacyAiSessionManager;
use App\Services\Ai\AiSessionStateService as LegacyAiSessionStateService;
use App\Services\Ai\AiSkill as LegacyAiSkill;
use App\Services\Ai\AiSkillStore as LegacyAiSkillStore;
use App\Services\Ai\AiStreamRecorder as LegacyAiStreamRecorder;
use App\Services\Ai\AiSurfaceHandoffService as LegacyAiSurfaceHandoffService;
use App\Services\Ai\AiThreadDeletionService as LegacyAiThreadDeletionService;
use App\Services\Ai\AiThreadResolver as LegacyAiThreadResolver;
use App\Services\Ai\AiTraceArtifactsProjection as LegacyAiTraceArtifactsProjection;
use App\Services\Ai\AiTraceEngineeringReviewProjection as LegacyAiTraceEngineeringReviewProjection;
use App\Services\Ai\AiWorkerLogger as LegacyAiWorkerLogger;
use App\Services\Ai\Analysis\AiQualityActionService as CanonicalAiQualityActionService;
use App\Services\Ai\Analysis\AiQualityEvaluator as CanonicalAiQualityEvaluator;
use App\Services\Ai\Arena\AiCouncilCoordinator as CanonicalAiCouncilCoordinator;
use App\Services\Ai\AtlasDecide\AiDecisionReceiptRefreshService as CanonicalAiDecisionReceiptRefreshService;
use App\Services\Ai\AtlasDialecticTensionService as LegacyAtlasDialecticTensionService;
use App\Services\Ai\AtlasFinalResponseSanitizer as LegacyAtlasFinalResponseSanitizer;
use App\Services\Ai\AtlasProviderProjectionAuditPurgePolicy as LegacyAtlasProviderProjectionAuditPurgePolicy;
use App\Services\Ai\AtlasProviderProjectionAuditService as LegacyAtlasProviderProjectionAuditService;
use App\Services\Ai\AtlasProviderProjectionService as LegacyAtlasProviderProjectionService;
use App\Services\Ai\Compaction\CompactionLossPolicy as CanonicalCompactionLossPolicy;
use App\Services\Ai\CompactionLossPolicy as LegacyCompactionLossPolicy;
use App\Services\Ai\Context\AiContextPackBuilder as CanonicalAiContextPackBuilder;
use App\Services\Ai\Context\AiContextSnapshotRecorder as CanonicalAiContextSnapshotRecorder;
use App\Services\Ai\Context\AiConversationContextBuilder as CanonicalAiConversationContextBuilder;
use App\Services\Ai\Context\AiConversationRecorder as CanonicalAiConversationRecorder;
use App\Services\Ai\Context\AtlasDialecticTensionService as CanonicalAtlasDialecticTensionService;
use App\Services\Ai\ControlPlane\AiInteractionSteeringService as CanonicalAiInteractionSteeringService;
use App\Services\Ai\ConversationOps\AiSessionManager as CanonicalAiSessionManager;
use App\Services\Ai\ConversationOps\AiSessionStateService as CanonicalAiSessionStateService;
use App\Services\Ai\ConversationOps\AiThreadDeletionService as CanonicalAiThreadDeletionService;
use App\Services\Ai\ConversationOps\AiThreadResolver as CanonicalAiThreadResolver;
use App\Services\Ai\Governance\AiPermissionDecision as CanonicalAiPermissionDecision;
use App\Services\Ai\Governance\AiPermissionEngine as CanonicalAiPermissionEngine;
use App\Services\Ai\Governance\AiPermissionEngineSupport as CanonicalAiPermissionEngineSupport;
use App\Services\Ai\HumanSurface\AiExecutionPresentationState as CanonicalAiExecutionPresentationState;
use App\Services\Ai\Instrumentation\AiTraceArtifactsProjection as CanonicalAiTraceArtifactsProjection;
use App\Services\Ai\Instrumentation\AiTraceEngineeringReviewProjection as CanonicalAiTraceEngineeringReviewProjection;
use App\Services\Ai\Instrumentation\AiWorkerLogger as CanonicalAiWorkerLogger;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditPurgePolicy as CanonicalAtlasProviderProjectionAuditPurgePolicy;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionAuditService as CanonicalAtlasProviderProjectionAuditService;
use App\Services\Ai\Instrumentation\AtlasProviderProjectionService as CanonicalAtlasProviderProjectionService;
use App\Services\Ai\Knowledge\YoutubeCanonicalProjection as CanonicalYoutubeCanonicalProjection;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService as CanonicalYouTubeKnowledgeIngestionService;
use App\Services\Ai\Router\AiIntentRouter as CanonicalAiIntentRouter;
use App\Services\Ai\Skills\AiSkill as CanonicalAiSkill;
use App\Services\Ai\Skills\AiSkillStore as CanonicalAiSkillStore;
use App\Services\Ai\Streaming\AiStreamRecorder as CanonicalAiStreamRecorder;
use App\Services\Ai\Surface\AiSurfaceHandoffService as CanonicalAiSurfaceHandoffService;
use App\Services\Ai\Surface\AtlasFinalResponseSanitizer as CanonicalAtlasFinalResponseSanitizer;
use App\Services\Ai\ValueObjects\AiPrompt as CanonicalAiPrompt;
use App\Services\Ai\YoutubeCanonicalProjection as LegacyYoutubeCanonicalProjection;
use App\Services\Ai\YouTubeKnowledgeIngestionService as LegacyYouTubeKnowledgeIngestionService;
use Tests\TestCase;

final class RootSinglesKnowledgeCompatibilityTest extends TestCase
{
    public function test_knowledge_services_resolve_from_canonical_namespaces_with_legacy_aliases(): void
    {
        $ingestion = app(CanonicalYouTubeKnowledgeIngestionService::class);
        $projection = app(CanonicalYoutubeCanonicalProjection::class);

        self::assertInstanceOf(LegacyYouTubeKnowledgeIngestionService::class, $ingestion);
        self::assertInstanceOf(LegacyYoutubeCanonicalProjection::class, $projection);
    }

    public function test_arena_coordinator_resolves_from_its_canonical_namespace_with_a_legacy_alias(): void
    {
        $coordinator = app(CanonicalAiCouncilCoordinator::class);

        self::assertInstanceOf(LegacyAiCouncilCoordinator::class, $coordinator);
    }

    public function test_compaction_policy_resolves_from_its_canonical_namespace_with_a_legacy_alias(): void
    {
        $policy = app(CanonicalCompactionLossPolicy::class);

        self::assertInstanceOf(LegacyCompactionLossPolicy::class, $policy);
    }

    public function test_context_control_plane_and_decision_services_resolve_from_canonical_namespaces_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAiContextPackBuilder::class));
        self::assertTrue(class_exists(LegacyAiContextPackBuilder::class));
        self::assertTrue(is_a(CanonicalAiContextPackBuilder::class, LegacyAiContextPackBuilder::class, true));
        self::assertTrue(class_exists(CanonicalAiContextSnapshotRecorder::class));
        self::assertTrue(class_exists(LegacyAiContextSnapshotRecorder::class));
        self::assertTrue(is_a(CanonicalAiContextSnapshotRecorder::class, LegacyAiContextSnapshotRecorder::class, true));
        self::assertTrue(class_exists(CanonicalAiConversationContextBuilder::class));
        self::assertTrue(class_exists(LegacyAiConversationContextBuilder::class));
        self::assertTrue(is_a(CanonicalAiConversationContextBuilder::class, LegacyAiConversationContextBuilder::class, true));
        self::assertTrue(class_exists(CanonicalAiConversationRecorder::class));
        self::assertTrue(class_exists(LegacyAiConversationRecorder::class));
        self::assertTrue(is_a(CanonicalAiConversationRecorder::class, LegacyAiConversationRecorder::class, true));
        self::assertTrue(class_exists(CanonicalAtlasDialecticTensionService::class));
        self::assertTrue(class_exists(LegacyAtlasDialecticTensionService::class));
        self::assertTrue(is_a(CanonicalAtlasDialecticTensionService::class, LegacyAtlasDialecticTensionService::class, true));
        self::assertTrue(class_exists(CanonicalAiPrompt::class));
        self::assertTrue(class_exists(LegacyAiPrompt::class));
        self::assertTrue(is_a(CanonicalAiPrompt::class, LegacyAiPrompt::class, true));
        self::assertTrue(class_exists(CanonicalAiInteractionSteeringService::class));
        self::assertTrue(class_exists(LegacyAiInteractionSteeringService::class));
        self::assertTrue(is_a(CanonicalAiInteractionSteeringService::class, LegacyAiInteractionSteeringService::class, true));
        self::assertTrue(class_exists(CanonicalAiDecisionReceiptRefreshService::class));
        self::assertTrue(class_exists(LegacyAiDecisionReceiptRefreshService::class));
        self::assertTrue(is_a(CanonicalAiDecisionReceiptRefreshService::class, LegacyAiDecisionReceiptRefreshService::class, true));
    }

    public function test_intent_router_resolves_from_its_canonical_namespace_with_a_legacy_alias(): void
    {
        $router = app(CanonicalAiIntentRouter::class);

        self::assertInstanceOf(LegacyAiIntentRouter::class, $router);
    }

    public function test_stream_recorder_resolves_from_its_canonical_namespace_with_a_legacy_alias(): void
    {
        $recorder = app(CanonicalAiStreamRecorder::class);

        self::assertInstanceOf(LegacyAiStreamRecorder::class, $recorder);
    }

    public function test_skills_resolve_from_their_canonical_namespace_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAiSkill::class));
        self::assertTrue(class_exists(LegacyAiSkill::class));
        self::assertTrue(is_a(CanonicalAiSkill::class, LegacyAiSkill::class, true));
        self::assertTrue(class_exists(CanonicalAiSkillStore::class));
        self::assertTrue(class_exists(LegacyAiSkillStore::class));
        self::assertTrue(is_a(CanonicalAiSkillStore::class, LegacyAiSkillStore::class, true));
    }

    public function test_analysis_services_resolve_from_their_canonical_namespace_with_legacy_aliases(): void
    {
        $evaluator = app(CanonicalAiQualityEvaluator::class);
        $actions = app(CanonicalAiQualityActionService::class);

        self::assertInstanceOf(LegacyAiQualityEvaluator::class, $evaluator);
        self::assertInstanceOf(LegacyAiQualityActionService::class, $actions);
    }

    public function test_permission_services_resolve_from_their_canonical_namespace_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAiPermissionDecision::class));
        self::assertTrue(class_exists(LegacyAiPermissionDecision::class));
        self::assertTrue(is_a(CanonicalAiPermissionDecision::class, LegacyAiPermissionDecision::class, true));
        self::assertTrue(class_exists(CanonicalAiPermissionEngine::class));
        self::assertTrue(class_exists(LegacyAiPermissionEngine::class));
        self::assertTrue(is_a(CanonicalAiPermissionEngine::class, LegacyAiPermissionEngine::class, true));
        self::assertTrue(class_exists(CanonicalAiPermissionEngineSupport::class));
        self::assertTrue(class_exists(LegacyAiPermissionEngineSupport::class));
        self::assertTrue(is_a(CanonicalAiPermissionEngineSupport::class, LegacyAiPermissionEngineSupport::class, true));
    }

    public function test_surface_services_resolve_from_their_canonical_namespaces_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAtlasFinalResponseSanitizer::class));
        self::assertTrue(class_exists(LegacyAtlasFinalResponseSanitizer::class));
        self::assertTrue(is_a(CanonicalAtlasFinalResponseSanitizer::class, LegacyAtlasFinalResponseSanitizer::class, true));
        self::assertTrue(class_exists(CanonicalAiSurfaceHandoffService::class));
        self::assertTrue(class_exists(LegacyAiSurfaceHandoffService::class));
        self::assertTrue(is_a(CanonicalAiSurfaceHandoffService::class, LegacyAiSurfaceHandoffService::class, true));
        self::assertTrue(class_exists(CanonicalAiExecutionPresentationState::class));
        self::assertTrue(class_exists(LegacyAiExecutionPresentationState::class));
        self::assertTrue(is_a(CanonicalAiExecutionPresentationState::class, LegacyAiExecutionPresentationState::class, true));
    }

    public function test_conversation_ops_services_resolve_from_their_canonical_namespace_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAiSessionManager::class));
        self::assertTrue(class_exists(LegacyAiSessionManager::class));
        self::assertTrue(is_a(CanonicalAiSessionManager::class, LegacyAiSessionManager::class, true));
        self::assertTrue(class_exists(CanonicalAiSessionStateService::class));
        self::assertTrue(class_exists(LegacyAiSessionStateService::class));
        self::assertTrue(is_a(CanonicalAiSessionStateService::class, LegacyAiSessionStateService::class, true));
        self::assertTrue(class_exists(CanonicalAiThreadDeletionService::class));
        self::assertTrue(class_exists(LegacyAiThreadDeletionService::class));
        self::assertTrue(is_a(CanonicalAiThreadDeletionService::class, LegacyAiThreadDeletionService::class, true));
        self::assertTrue(class_exists(CanonicalAiThreadResolver::class));
        self::assertTrue(class_exists(LegacyAiThreadResolver::class));
        self::assertTrue(is_a(CanonicalAiThreadResolver::class, LegacyAiThreadResolver::class, true));
    }

    public function test_instrumentation_services_resolve_from_their_canonical_namespace_with_legacy_aliases(): void
    {
        self::assertTrue(class_exists(CanonicalAiTraceArtifactsProjection::class));
        self::assertTrue(class_exists(LegacyAiTraceArtifactsProjection::class));
        self::assertTrue(is_a(CanonicalAiTraceArtifactsProjection::class, LegacyAiTraceArtifactsProjection::class, true));
        self::assertTrue(class_exists(CanonicalAiTraceEngineeringReviewProjection::class));
        self::assertTrue(class_exists(LegacyAiTraceEngineeringReviewProjection::class));
        self::assertTrue(is_a(CanonicalAiTraceEngineeringReviewProjection::class, LegacyAiTraceEngineeringReviewProjection::class, true));
        self::assertTrue(class_exists(CanonicalAiWorkerLogger::class));
        self::assertTrue(class_exists(LegacyAiWorkerLogger::class));
        self::assertTrue(is_a(CanonicalAiWorkerLogger::class, LegacyAiWorkerLogger::class, true));
        self::assertTrue(class_exists(CanonicalAtlasProviderProjectionService::class));
        self::assertTrue(class_exists(LegacyAtlasProviderProjectionService::class));
        self::assertTrue(is_a(CanonicalAtlasProviderProjectionService::class, LegacyAtlasProviderProjectionService::class, true));
        self::assertTrue(class_exists(CanonicalAtlasProviderProjectionAuditService::class));
        self::assertTrue(class_exists(LegacyAtlasProviderProjectionAuditService::class));
        self::assertTrue(is_a(CanonicalAtlasProviderProjectionAuditService::class, LegacyAtlasProviderProjectionAuditService::class, true));
        self::assertTrue(class_exists(CanonicalAtlasProviderProjectionAuditPurgePolicy::class));
        self::assertTrue(class_exists(LegacyAtlasProviderProjectionAuditPurgePolicy::class));
        self::assertTrue(is_a(CanonicalAtlasProviderProjectionAuditPurgePolicy::class, LegacyAtlasProviderProjectionAuditPurgePolicy::class, true));
    }
}
