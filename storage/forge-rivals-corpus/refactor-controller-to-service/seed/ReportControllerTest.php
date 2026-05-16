<?php

declare(strict_types=1);

namespace Tests\Feature\Report;

use App\Http\Controllers\ReportController;
use PHPUnit\Framework\TestCase;

/**
 * Feature test do contrato HTTP. Este test NÃO pode ser modificado pelo arm;
 * ele guarda que o refactor preservou comportamento.
 */
final class ReportControllerTest extends TestCase
{
    public function test_index_returns_expected_payload(): void
    {
        $controller = new ReportController;
        $payload = $controller->index(['open' => 7, 'closed' => 4]);

        $this->assertSame([
            'open' => 7,
            'closed' => 4,
            'top_status' => 'open',
            'totals' => ['open' => 7, 'closed' => 4],
        ], $payload);
    }

    public function test_tie_breaker_uses_tie_label(): void
    {
        $payload = (new ReportController)->index(['open' => 5, 'closed' => 5]);
        $this->assertSame('tie', $payload['top_status']);
    }
}
