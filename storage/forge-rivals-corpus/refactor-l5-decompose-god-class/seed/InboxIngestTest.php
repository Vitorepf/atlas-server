<?php

declare(strict_types=1);

namespace Tests\Unit\Inbox;

use App\Domain\Inbox\InboxIngest;
use PHPUnit\Framework\TestCase;

final class InboxIngestTest extends TestCase
{
    public function test_ingest_trims_body(): void
    {
        $svc = new InboxIngest;
        $row = $svc->ingest(['capture_id' => 'c1', 'body' => '  trimmed  ', 'priority' => 0, 'starred' => false]);
        $this->assertSame('trimmed', $row['body']);
    }
}
