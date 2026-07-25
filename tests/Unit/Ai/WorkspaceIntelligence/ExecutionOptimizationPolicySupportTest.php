<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\Support\ExecutionOptimizationPolicySupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pure Support peel for AWIS execution-optimization policy maps — no I/O, no mother, no DB.
 */
final class ExecutionOptimizationPolicySupportTest extends TestCase
{
    #[Test]
    public function classify_command_lanes_prefers_fast_and_blocks_avoid_and_slow(): void
    {
        $lanes = Support::classifyCommandLanes(
            avoidCommands: ['rm -rf /'],
            slowCommands: ['php artisan test --slow'],
            flakyCommands: ['flaky-suite'],
            fastCommands: ['php artisan test --filter=Unit'],
            heavyCommands: ['composer install'],
            rankedCandidates: [
                'php artisan test --filter=Unit',
                'php artisan test --slow',
                'composer install',
                'php artisan route:list',
                'rm -rf /',
            ],
        );

        $this->assertSame(['php artisan test --filter=Unit'], $lanes['preferred']);
        $this->assertContains('php artisan test --slow', $lanes['blocked']);
        $this->assertContains('rm -rf /', $lanes['blocked']);
        $this->assertContains('composer install', $lanes['deferred']);
        $this->assertContains('flaky-suite', $lanes['deferred']);
        $this->assertContains('php artisan route:list', $lanes['standard']);
        $this->assertNotContains('rm -rf /', $lanes['preferred']);
        $this->assertNotContains('php artisan test --slow', $lanes['preferred']);
    }

    #[Test]
    public function classify_command_lanes_when_no_fast_includes_ranked_minus_blocked(): void
    {
        // Original semantics: empty fast list → preferred = ranked not blocked
        // (deferred is NOT excluded in the first pass; only when preferred ends empty).
        $lanes = Support::classifyCommandLanes(
            avoidCommands: ['blocked'],
            slowCommands: [],
            flakyCommands: [],
            fastCommands: [],
            heavyCommands: ['heavy'],
            rankedCandidates: ['cmd-a', 'heavy', 'blocked', 'cmd-b'],
        );

        $this->assertSame(['cmd-a', 'heavy', 'cmd-b'], $lanes['preferred']);
        $this->assertSame(['heavy'], $lanes['deferred']);
        $this->assertSame(['blocked'], $lanes['blocked']);
        $this->assertSame([], $lanes['standard']);
    }

    #[Test]
    public function classify_command_lanes_second_pass_excludes_deferred_when_first_empty(): void
    {
        // First pass keeps only fast∩ranked not blocked. When every fast cmd is blocked,
        // preferred empties and second pass takes ranked minus blocked minus deferred.
        $lanes = Support::classifyCommandLanes(
            avoidCommands: ['only-fast'],
            slowCommands: [],
            flakyCommands: [],
            fastCommands: ['only-fast'],
            heavyCommands: ['heavy'],
            rankedCandidates: ['only-fast', 'heavy', 'cmd-b'],
        );

        $this->assertSame(['cmd-b'], $lanes['preferred']);
        $this->assertContains('heavy', $lanes['deferred']);
        $this->assertContains('only-fast', $lanes['blocked']);
    }

    #[Test]
    public function policy_feedback_tightens_on_mixed_or_failing_refs(): void
    {
        $feedback = Support::policyFeedbackFromProfiles([
            ['policy_ref' => 'policy:good', 'effectiveness' => 'effective'],
            ['policy_ref' => 'policy:bad', 'effectiveness' => 'failing'],
            ['policy_ref' => '', 'effectiveness' => 'effective'],
            'skip-me',
        ]);

        $this->assertTrue($feedback['needs_tighter_policy']);
        $this->assertSame(4, $feedback['standard_command_limit']);
        $this->assertSame('tighten_default_to_preferred_fast_commands', $feedback['next_adjustment']);
        $this->assertSame(['policy:good'], $feedback['effective_policy_refs']);
        $this->assertSame(['policy:bad'], $feedback['failing_policy_refs']);
        $this->assertTrue($feedback['deep_requires_operator']);
    }

    #[Test]
    public function policy_feedback_reuses_effective_shape_when_clean(): void
    {
        $feedback = Support::policyFeedbackFromProfiles([
            ['policy_ref' => 'policy:good', 'effectiveness' => 'effective'],
        ]);

        $this->assertFalse($feedback['needs_tighter_policy']);
        $this->assertSame(8, $feedback['standard_command_limit']);
        $this->assertSame('reuse_effective_policy_shape', $feedback['next_adjustment']);
    }

    #[Test]
    public function validation_tier_for_route_maps_feedback_and_grades(): void
    {
        $deep = Support::validationTierForExecutionRoute(
            'failing',
            'fast',
            'prefer_scope_commands',
            ['php artisan test'],
            [],
            [],
        );
        $this->assertSame('deep', $deep['tier']);
        $this->assertSame('route_feedback_requires_guarded_validation', $deep['reason']);

        $instant = Support::validationTierForExecutionRoute(
            'effective',
            'fast',
            'prefer_scope_commands',
            ['php artisan test'],
            [],
            [],
        );
        $this->assertSame('instant', $instant['tier']);

        $guardedInstant = Support::validationTierForExecutionRoute(
            'effective',
            'fast',
            'prefer_scope_commands',
            ['php artisan test'],
            [],
            [],
            ['tier:instant' => 'mixed'],
        );
        $this->assertSame('standard', $guardedInstant['tier']);
        $this->assertSame('instant_tier_feedback_guarded', $guardedInstant['reason']);

        $blocked = Support::validationTierForExecutionRoute(
            'unknown',
            'slow',
            'deep_validation_only',
            [],
            [],
            ['slow-cmd'],
        );
        $this->assertSame('deep', $blocked['tier']);
    }

    #[Test]
    public function scoped_execution_routes_partition_commands_and_defer_on_mixed_feedback(): void
    {
        $key = 'app/Services';
        $routeRef = 'area:'.hash('sha256', $key);

        $routes = Support::scopedExecutionRoutes(
            [
                [
                    'key' => $key,
                    'commands' => ['fast-cmd', 'slow-cmd', 'heavy-cmd'],
                    'observed_count' => 3,
                    'performance_grade' => 'normal',
                    'duration_ms_p95' => 12_000,
                ],
                ['key' => ''],
                'skip',
            ],
            blocked: ['slow-cmd'],
            deferred: ['heavy-cmd'],
            routeFeedback: [$routeRef => 'mixed'],
            tierFeedback: [],
            routeKind: 'area',
        );

        $this->assertCount(1, $routes);
        $this->assertSame($key, $routes[0]['key']);
        $this->assertSame($routeRef, $routes[0]['route_ref']);
        $this->assertSame([], $routes[0]['preferred_commands']);
        $this->assertContains('fast-cmd', $routes[0]['deferred_commands']);
        $this->assertContains('heavy-cmd', $routes[0]['deferred_commands']);
        $this->assertSame(['slow-cmd'], $routes[0]['blocked_commands']);
        $this->assertSame('mixed', $routes[0]['feedback_effectiveness']);
        $this->assertSame('deep_validation_only', $routes[0]['route_mode']);
        $this->assertSame('deep', $routes[0]['recommended_validation_tier']);
    }

    #[Test]
    public function validation_tier_routing_summary_counts_tiers_and_flags_guarded_instant(): void
    {
        $summary = Support::validationTierRoutingSummary(
            [
                ['recommended_validation_tier' => 'instant'],
                ['recommended_validation_tier' => 'standard'],
            ],
            [
                ['recommended_validation_tier' => 'deep'],
                ['recommended_validation_tier' => 'weird'],
            ],
            ['tier:instant' => 'failing', 'tier:standard' => 'effective'],
        );

        $this->assertSame('atlas.awis.validation_tier_routing.v1', $summary['schema_version']);
        $this->assertSame(1, $summary['instant_route_count']);
        $this->assertSame(2, $summary['standard_route_count']); // weird → standard
        $this->assertSame(1, $summary['deep_route_count']);
        $this->assertSame(4, $summary['route_count']);
        $this->assertTrue($summary['tier_feedback']['instant_guarded']);
        $this->assertSame('failing', $summary['tier_feedback']['instant_effectiveness']);
        $this->assertSame('effective', $summary['tier_feedback']['standard_effectiveness']);
    }

    #[Test]
    public function route_and_tier_effectiveness_feedback_filters_kinds(): void
    {
        $routeFeedback = Support::routeEffectivenessFeedback([
            ['route_ref' => 'area:aaa', 'effectiveness' => 'effective'],
            ['route_ref' => 'stack:bbb', 'effectiveness' => 'failing'],
            ['route_ref' => 'area:ccc', 'effectiveness' => 'unknown'],
            ['route_ref' => 'area:ddd', 'effectiveness' => 'mixed'],
            'nope',
        ], 'area');

        $this->assertSame([
            'area:aaa' => 'effective',
            'area:ddd' => 'mixed',
        ], $routeFeedback);

        $tierFeedback = Support::validationTierEffectivenessFeedback([
            ['tier_ref' => 'tier:instant', 'effectiveness' => 'effective'],
            ['tier_ref' => 'tier:bogus', 'effectiveness' => 'effective'],
            ['tier_ref' => 'tier:deep', 'effectiveness' => 'failing'],
            ['tier_ref' => 'tier:standard', 'effectiveness' => 'maybe'],
        ]);

        $this->assertSame([
            'tier:instant' => 'effective',
            'tier:deep' => 'failing',
        ], $tierFeedback);
    }

    #[Test]
    public function validation_tier_definitions_are_stable_maps(): void
    {
        $tiers = Support::validationTierDefinitions(true);

        $this->assertSame(2, $tiers['instant']['max_command_count']);
        $this->assertSame('fast', $tiers['instant']['prefer_performance_grade']);
        $this->assertTrue($tiers['standard']['default_for_unknown_routes']);
        $this->assertTrue($tiers['deep']['requires_operator_or_high_risk_context']);
        $this->assertTrue($tiers['deep']['allow_deferred_commands']);

        $open = Support::validationTierDefinitions(false);
        $this->assertFalse($open['deep']['requires_operator_or_high_risk_context']);
    }
}
