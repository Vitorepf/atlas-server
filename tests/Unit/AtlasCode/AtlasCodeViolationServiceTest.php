<?php

declare(strict_types=1);

namespace Tests\Unit\AtlasCode;

use App\Services\AtlasCode\AtlasCodeViolationService;
use PHPUnit\Framework\TestCase;

final class AtlasCodeViolationServiceTest extends TestCase
{
    public function test_scanner_is_pure_and_names_all_five_canonical_rules(): void
    {
        $result = (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'feature/cobaia',
            'current_since' => '2026-07-01T00:00:00Z',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/tmp/foreign', 'head' => 'a']],
            'obra_return_deadline_days' => 3,
            'now' => '2026-07-15T00:00:00Z',
            'branches' => [
                ['name' => 'feature/cobaia', 'committed_at' => '2026-07-01T00:00:00Z', 'reachable_from_main' => true],
                ['name' => 'orphan', 'committed_at' => '2026-07-14T00:00:00Z', 'reachable_from_main' => false],
            ],
            'main_head' => 'main-hash',
            'mirror_head' => 'old-hash',
        ]);

        $rules = array_values(array_unique(array_map(static fn (array $item): string => $item['rule_id'], $result['violations'])));
        sort($rules);
        self::assertSame([
            'main_only', 'mirror_drift', 'obra_return_deadline', 'orphan_branch', 'worktree_allowlist',
        ], $rules);
        self::assertCount(5, $result['plan']);
    }

    public function test_healthy_facts_are_silent_and_do_not_invent_missing_mirror_data(): void
    {
        self::assertSame(['violations' => [], 'plan' => []], (new AtlasCodeViolationService())->scan([
            'main_branch' => 'main',
            'current_branch' => 'main',
            'allowed_worktree_roots' => ['/repo'],
            'worktrees' => [['path' => '/repo', 'head' => 'main-hash']],
            'branches' => [['name' => 'main', 'reachable_from_main' => true]],
        ]));
    }
}
