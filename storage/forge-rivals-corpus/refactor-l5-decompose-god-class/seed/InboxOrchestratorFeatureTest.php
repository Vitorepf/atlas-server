<?php

declare(strict_types=1);

namespace Tests\Feature\Inbox;

use App\Domain\Inbox\InboxOrchestrator;
use PHPUnit\Framework\TestCase;

final class InboxOrchestratorFeatureTest extends TestCase
{
    public function test_ingest_returns_canonical_row_shape(): void
    {
        $o = new InboxOrchestrator;
        $row = $o->ingest([
            'capture_id' => 'c1',
            'body' => '  hello ',
            'priority' => 2,
            'starred' => true,
        ]);
        $this->assertSame('c1', $row['capture_id']);
        $this->assertSame('hello', $row['body']);
        $this->assertSame(2, $row['priority']);
        $this->assertTrue($row['starred']);
    }

    public function test_rank_orders_high_priority_first(): void
    {
        $o = new InboxOrchestrator;
        $ranked = $o->rank([
            ['capture_id' => 'low', 'priority' => 0, 'starred' => false],
            ['capture_id' => 'star', 'priority' => 1, 'starred' => true],
            ['capture_id' => 'top', 'priority' => 3, 'starred' => false],
        ]);
        $this->assertSame('top', $ranked[0]['capture_id']);
    }

    public function test_notify_emits_event(): void
    {
        $o = new InboxOrchestrator;
        $o->ingest(['capture_id' => 'c1', 'body' => 'x', 'priority' => 1, 'starred' => false]);
        $o->notify(['capture_id' => 'c1']);
        $kinds = array_column($o->recordedEvents(), 'kind');
        $this->assertContains('ingested', $kinds);
        $this->assertContains('notified', $kinds);
    }

    public function test_facade_stays_thin_after_refactor(): void
    {
        $contents = (string) file_get_contents(__DIR__.'/InboxOrchestrator.php');
        $loc = substr_count($contents, "\n");
        $this->assertLessThanOrEqual(120, $loc, 'InboxOrchestrator must be a thin facade after refactor');
    }
}
