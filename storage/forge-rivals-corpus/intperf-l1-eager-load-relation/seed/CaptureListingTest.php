<?php

declare(strict_types=1);

namespace Tests\Unit\Captures;

use App\Domain\Captures\CaptureListing;
use App\Domain\Captures\CaptureRepository;
use PHPUnit\Framework\TestCase;

final class CaptureListingTest extends TestCase
{
    public function test_recent_uses_at_most_two_queries(): void
    {
        $repo = new CaptureRepository;
        $listing = new CaptureListing($repo);
        $listing->recent(4);

        $this->assertLessThanOrEqual(2, $repo->queryCount(), 'CaptureListing::recent() must eager-load authors');
    }

    public function test_payload_shape_is_stable_after_eager_load(): void
    {
        $repo = new CaptureRepository;
        $listing = new CaptureListing($repo);
        $rows = $listing->recent(4);

        $this->assertCount(4, $rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('id', $row);
            $this->assertArrayHasKey('body', $row);
            $this->assertArrayHasKey('author', $row);
        }
        $this->assertSame('Alice', $rows[0]['author']);
        $this->assertSame('Bob', $rows[1]['author']);
    }
}
