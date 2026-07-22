<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasEvolutionFrozenJudge;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopFeatureObjectiveBuilder;
use Tests\TestCase;

/**
 * Lever 3 — the feature lane. A `feature` task pins NEW behavior with a FROZEN, gated acceptance test
 * that must be EARNED (revert_recheck => RED-on-revert), implementable across multiple/new files.
 */
final class AtlasLoopFeatureObjectiveBuilderTest extends TestCase
{
    public function test_builds_a_multi_file_feature_task_with_diff_earned_antigaming(): void
    {
        $out = (new AtlasLoopFeatureObjectiveBuilder())->build(
            'Rate-limit the export endpoint',
            'POST /export must reject a 4th request within 60s with HTTP 429.',
            'tests/Feature/Export/ExportRateLimitTest.php',
            ['app/Http/Controllers/ExportController.php', 'app/Support/RateLimiter/SlidingWindowLimiter.php'],
            'hermes_cli',
        );

        $payload = $out['payload'];
        $this->assertSame('feature', $payload['objective_kind']);
        $this->assertSame('framework', $payload['materializer']);
        // GATE metric (the spec test passes or it does not) + revert_recheck (the anti-gaming keystone).
        $this->assertSame(AtlasEvolutionFrozenJudge::METRIC_GATE, $payload['acceptance']['metric_kind']);
        $this->assertTrue($payload['acceptance']['revert_recheck'], 'the feature must EARN the test RED->GREEN');
        // Multi-file implementation surface (incl. a NEW file) is allowed; the test + config are frozen.
        $this->assertSame([
            'app/Http/Controllers/ExportController.php',
            'app/Support/RateLimiter/SlidingWindowLimiter.php',
        ], $payload['allowed_files']);
        $this->assertContains('tests/Feature/Export/ExportRateLimitTest.php', $payload['acceptance']['frozen_globs']);
        $this->assertNotContains('tests/Feature/Export/ExportRateLimitTest.php', $payload['acceptance']['allowed_globs'], 'the spec test is FROZEN, never editable');
        $this->assertSame('hermes_cli', $payload['provider']);
        // The objective tells the provider to implement to GREEN + warns about the revert-recheck.
        $this->assertStringContainsString('IMPLEMENT', $out['objective']);
        $this->assertStringContainsString('REVERT', $out['objective']);
        $this->assertStringContainsString('ExportRateLimitTest.php', $out['objective']);
    }

    public function test_normalizes_paths_and_dedupes_impl_files(): void
    {
        $out = (new AtlasLoopFeatureObjectiveBuilder())->build(
            'x', 'spec', '\\tests\\X\\YTest.php', ['/app/A.php', 'app/A.php', 'app\\B.php'],
        );
        $this->assertSame(['app/A.php', 'app/B.php'], $out['payload']['allowed_files'], 'normalized + deduped');
        $this->assertContains('tests/X/YTest.php', $out['payload']['acceptance']['frozen_globs']);
        $this->assertArrayNotHasKey('provider', $out['payload'], 'no provider pin when none given');
    }
}
