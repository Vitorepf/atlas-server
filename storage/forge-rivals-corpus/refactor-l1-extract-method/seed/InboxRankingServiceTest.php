<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\InboxRankingService;
use PHPUnit\Framework\TestCase;

final class InboxRankingServiceTest extends TestCase
{
    public function test_recent_starred_high_priority_scores_highest(): void
    {
        $svc = new InboxRankingService;
        $ranked = $svc->rank([
            ['id' => 'fresh-star', 'age_hours' => 1.0, 'priority' => 3, 'starred' => true],
            ['id' => 'stale-cold', 'age_hours' => 200.0, 'priority' => 0, 'starred' => false],
        ]);
        $this->assertSame('fresh-star', $ranked[0]['id']);
        $this->assertGreaterThan($ranked[1]['score'], $ranked[0]['score']);
    }

    public function test_score_is_deterministic_byte_for_byte(): void
    {
        $svc = new InboxRankingService;
        $items = [
            ['id' => 'a', 'age_hours' => 24.0, 'priority' => 1, 'starred' => false],
            ['id' => 'b', 'age_hours' => 48.0, 'priority' => 2, 'starred' => true],
        ];

        $first = $svc->rank($items);
        $second = $svc->rank($items);

        $this->assertSame($first, $second);
        $this->assertSame(0.629, round((float) $first[1]['score'], 3));
    }

    public function test_uses_calculate_score_helper(): void
    {
        // The refactor must introduce a private calculateScore method.
        $reflection = new \ReflectionClass(InboxRankingService::class);
        $this->assertTrue($reflection->hasMethod('calculateScore'), 'expected a private calculateScore() helper after refactor');
        $method = $reflection->getMethod('calculateScore');
        $this->assertTrue($method->isPrivate(), 'calculateScore() must be private');
    }
}
