<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiCouncilCoordinator as LegacyAiCouncilCoordinator;
use App\Services\Ai\AiIntentRouter as LegacyAiIntentRouter;
use App\Services\Ai\AiQualityActionService as LegacyAiQualityActionService;
use App\Services\Ai\AiQualityEvaluator as LegacyAiQualityEvaluator;
use App\Services\Ai\AiSkill as LegacyAiSkill;
use App\Services\Ai\AiSkillStore as LegacyAiSkillStore;
use App\Services\Ai\AiStreamRecorder as LegacyAiStreamRecorder;
use App\Services\Ai\Analysis\AiQualityActionService as CanonicalAiQualityActionService;
use App\Services\Ai\Analysis\AiQualityEvaluator as CanonicalAiQualityEvaluator;
use App\Services\Ai\Arena\AiCouncilCoordinator as CanonicalAiCouncilCoordinator;
use App\Services\Ai\Compaction\CompactionLossPolicy as CanonicalCompactionLossPolicy;
use App\Services\Ai\CompactionLossPolicy as LegacyCompactionLossPolicy;
use App\Services\Ai\Knowledge\YoutubeCanonicalProjection as CanonicalYoutubeCanonicalProjection;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService as CanonicalYouTubeKnowledgeIngestionService;
use App\Services\Ai\Router\AiIntentRouter as CanonicalAiIntentRouter;
use App\Services\Ai\Skills\AiSkill as CanonicalAiSkill;
use App\Services\Ai\Skills\AiSkillStore as CanonicalAiSkillStore;
use App\Services\Ai\Streaming\AiStreamRecorder as CanonicalAiStreamRecorder;
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
}
