<?php

declare(strict_types=1);

use App\Services\AtlasCode\AtlasCodePreflightService;
use Tests\TestCase;

final class AtlasCodePreflightServiceTest extends TestCase
{
    public function test_preflight_blocks_matching_plan_and_keeps_notifications_off(): void
    {
        $service = app(AtlasCodePreflightService::class);
        $result = $service->check([
            'main_branch' => 'main',
            'current_branch' => 'atlas-code-cobaia',
            'current_since' => '2026-07-14T00:00:00Z',
            'worktrees' => [],
            'branches' => [],
            'now' => '2026-07-15T00:00:00Z',
        ], 'merge_ff', 'atlas-code-cobaia');

        self::assertSame(AtlasCodePreflightService::SCHEMA_VERSION, $result['schema_version']);
        self::assertFalse($result['allowed']);
        self::assertSame('main_only', $result['rule_id']);
        self::assertFalse($result['notifications']['enabled']);
    }

    public function test_preflight_allows_unrelated_read_action_without_inventing_violation(): void
    {
        $service = app(AtlasCodePreflightService::class);
        $result = $service->check([
            'main_branch' => 'main',
            'current_branch' => 'main',
            'worktrees' => [],
            'branches' => [],
            'now' => '2026-07-15T00:00:00Z',
        ], 'cite_rule_to_agent', 'missing');

        self::assertTrue($result['allowed']);
        self::assertNull($result['rule_id']);
    }
}
