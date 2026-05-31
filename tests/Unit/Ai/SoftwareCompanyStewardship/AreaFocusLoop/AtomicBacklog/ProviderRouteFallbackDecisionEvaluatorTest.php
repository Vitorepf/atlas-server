<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\ProviderRouteFallbackDecisionEvaluator;
use PHPUnit\Framework\TestCase;

final class ProviderRouteFallbackDecisionEvaluatorTest extends TestCase
{
    private ProviderRouteFallbackDecisionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new ProviderRouteFallbackDecisionEvaluator();
    }

    public function testValidLearnedRouteWins(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('claude_cli', $result['selected_provider']);
        $this->assertSame('learned', $result['route_source']);
        $this->assertNull($result['fallback_reason']);
        $this->assertTrue($result['receipt_required']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testMissingRoleFallsBackToDefault(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'builder',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => null,
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('role_missing_in_learned_route', $result['fallback_reason']);
        $this->assertTrue($result['receipt_required']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testVerdictNotFollowLearnedFallsBackWithReason(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_IGNORE_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('adml_verdict_not_follow_learned', $result['fallback_reason']);
        $this->assertTrue($result['receipt_required']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testPrivacyMismatchBlocksLearnedRoute(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'sensitive',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('privacy_mismatch', $result['fallback_reason']);
        $this->assertTrue($result['receipt_required']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testDefaultUnavailableBlocksHonestly(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => null,
        ];

        $defaultRoute = [
            'provider' => null,
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertNull($result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('default_route_unavailable', $result['fallback_reason']);
        $this->assertTrue($result['receipt_required']);
        $this->assertFalse($result['provider_invoked']);
    }

    public function testTaskCategoryMismatchFallsBack(): void
    {
        $job = [
            'task_category' => 'code_review',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('task_category_mismatch', $result['fallback_reason']);
    }

    public function testLearnedRouteUnavailableFallsBack(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => false,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('atlas.provider.route_fallback_decision.v1', $result['schema_version']);
        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('learned_route_unavailable', $result['fallback_reason']);
    }

    public function testJobWithoutTaskCategoryAllowsLearnedRoute(): void
    {
        $job = [
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => null,
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'adml_verdict' => 'VERDICT_FOLLOW_LEARNED',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('claude_cli', $result['selected_provider']);
        $this->assertSame('learned', $result['route_source']);
    }

    public function testAdmlVerdictFollowLearnedStringAllowsLearnedRoute(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'verdict' => 'follow_learned',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('claude_cli', $result['selected_provider']);
        $this->assertSame('learned', $result['route_source']);
    }

    public function testAdmlVerdictTrueAllowsLearnedRoute(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => 'claude_cli',
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy_class' => 'internal',
            'available' => true,
            'verdict' => true,
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('claude_cli', $result['selected_provider']);
        $this->assertSame('learned', $result['route_source']);
    }

    public function testEmptyLearnedRouteFallsBack(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('learned_route_empty', $result['fallback_reason']);
    }

    public function testLearnedRouteWithEmptyProviderFallsBack(): void
    {
        $job = [
            'task_category' => 'code_generation',
            'role' => 'backend',
            'privacy' => 'internal',
        ];

        $learnedRoute = [
            'provider' => '',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('sonnet', $result['selected_provider']);
        $this->assertSame('default', $result['route_source']);
        $this->assertSame('learned_route_empty', $result['fallback_reason']);
    }

    public function testAllConditionsMetWithAlternativeFollowLearnedValue(): void
    {
        $job = [
            'task_category' => 'analysis',
            'role' => 'frontend',
            'privacy' => 'public',
        ];

        $learnedRoute = [
            'provider' => 'gemini_cli',
            'task_category' => 'analysis',
            'role' => 'frontend',
            'privacy_class' => 'public',
            'availability' => true,
            'adml_verdict' => 'allow',
        ];

        $defaultRoute = [
            'provider' => 'sonnet',
        ];

        $result = $this->evaluator->decide($job, $learnedRoute, $defaultRoute);

        $this->assertSame('gemini_cli', $result['selected_provider']);
        $this->assertSame('learned', $result['route_source']);
        $this->assertNull($result['fallback_reason']);
    }
}