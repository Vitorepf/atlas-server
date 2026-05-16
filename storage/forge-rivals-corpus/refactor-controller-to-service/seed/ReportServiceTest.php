<?php

declare(strict_types=1);

namespace Tests\Unit\Report;

use App\Services\Report\ReportService;
use PHPUnit\Framework\TestCase;

/**
 * Unit test do service extraído. Cobre 3 cenários isolados (open>closed,
 * closed>open, tie) sem qualquer dependência de HTTP.
 */
final class ReportServiceTest extends TestCase
{
    public function test_open_majority_returns_open_top(): void
    {
        $svc = new ReportService;
        $payload = $svc->summarize(['open' => 10, 'closed' => 3]);
        $this->assertSame('open', $payload['top_status']);
        $this->assertSame(['open' => 10, 'closed' => 3], $payload['totals']);
    }

    public function test_closed_majority_returns_closed_top(): void
    {
        $svc = new ReportService;
        $payload = $svc->summarize(['open' => 1, 'closed' => 8]);
        $this->assertSame('closed', $payload['top_status']);
    }

    public function test_tie_returns_tie_label(): void
    {
        $svc = new ReportService;
        $payload = $svc->summarize(['open' => 5, 'closed' => 5]);
        $this->assertSame('tie', $payload['top_status']);
    }
}
