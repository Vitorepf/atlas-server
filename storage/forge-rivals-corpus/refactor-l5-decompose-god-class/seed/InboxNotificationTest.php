<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\InboxNotification;
use PHPUnit\Framework\TestCase;

final class InboxNotificationTest extends TestCase
{
    public function test_dispatch_records_event(): void
    {
        $svc = new InboxNotification;
        $svc->dispatch(['capture_id' => 'c1']);
        $events = $svc->recorded();
        $this->assertNotEmpty($events);
        $this->assertSame('c1', $events[0]['capture_id']);
    }
}
