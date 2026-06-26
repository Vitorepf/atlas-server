<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionAgentReviewMergeSection;
use Tests\TestCase;

/**
 * Smoke-tests that the 109 agentReviewMerge methods on the god-class remain
 * reachable via dynamic dispatch after extraction into the section
 * collaborator. We exercise one early-life-cycle method and one late-life-cycle
 * method, both with empty options (the audit path returns a structured payload
 * containing schema + status).
 */
class AtlasAiSelfConstructionReadinessProjectionAgentReviewMergeSectionTest extends TestCase
{
    public function test_section_class_exposes_109_methods(): void
    {
        $reflection = new \ReflectionClass(ReadinessProjectionAgentReviewMergeSection::class);
        $public = array_filter(
            $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
            static fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'agentReviewMerge'),
        );

        self::assertCount(109, $public);
    }

    public function test_god_class_delegates_action_template_via_dynamic_dispatch(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $result = $service->{'agentReviewMergeActionTemplate'}([]);

        self::assertIsArray($result);
        self::assertArrayHasKey('schema_version', $result);
    }

    public function test_god_class_delegates_execution_checklist_via_dynamic_dispatch(): void
    {
        $service = app(AtlasSelfConstructionReadinessService::class);
        $result = $service->{'agentReviewMergeExecutionChecklist'}([]);

        self::assertIsArray($result);
        self::assertArrayHasKey('schema_version', $result);
    }
}