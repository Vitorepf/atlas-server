<?php

namespace Tests\Unit\Ai;

use App\Services\Ai\AiCouncilCoordinator as LegacyAiCouncilCoordinator;
use App\Services\Ai\Arena\AiCouncilCoordinator as CanonicalAiCouncilCoordinator;
use App\Services\Ai\Knowledge\YoutubeCanonicalProjection as CanonicalYoutubeCanonicalProjection;
use App\Services\Ai\Knowledge\YouTubeKnowledgeIngestionService as CanonicalYouTubeKnowledgeIngestionService;
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
}
