<?php

declare(strict_types=1);

namespace App\Services\Report;

/**
 * Placeholder. O arm precisa popular esta classe extraindo a agregação
 * de ReportController::index().
 *
 * Contract esperado pelo unit test:
 *   - public function summarize(array<string,int> $counts): array
 *     com keys 'open', 'closed', 'top_status', 'totals'.
 */
final class ReportService
{
    /**
     * @param  array<string,int>  $counts
     * @return array<string,mixed>
     */
    public function summarize(array $counts): array
    {
        throw new \LogicException('ReportService::summarize not yet implemented — extract from ReportController.');
    }
}
