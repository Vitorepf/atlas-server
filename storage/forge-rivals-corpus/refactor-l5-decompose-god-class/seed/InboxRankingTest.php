<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\InboxRanking;
use PHPUnit\Framework\TestCase;

final class InboxRankingTest extends TestCase
{
    public function test_rank_orders_by_priority_then_star(): void
    {
        $svc = new InboxRanking;
        $ranked = $svc->rank([
            ['capture_id' => 'a', 'priority' => 1, 'starred' => false],
            ['capture_id' => 'b', 'priority' => 1, 'starred' => true],
        ]);
        $this->assertSame('b', $ranked[0]['capture_id']);
    }
}
