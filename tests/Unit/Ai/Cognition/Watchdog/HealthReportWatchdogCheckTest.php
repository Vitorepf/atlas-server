<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition\Watchdog;

use App\Services\Ai\Cognition\Watchdog\Checks\HealthReportWatchdogCheck;
use PHPUnit\Framework\TestCase;

final class HealthReportWatchdogCheckTest extends TestCase
{
    public function test_catalog_preserves_ten_former_wrapper_ids_and_methods(): void
    {
        $catalog = HealthReportWatchdogCheck::catalog();
        $ids = array_column($catalog, 'id');
        $methods = array_column($catalog, 'report_method');

        $this->assertCount(10, $catalog);
        $this->assertContains('mem-09.memory_quality', $ids);
        $this->assertContains('fee-13.learning_cadence', $ids);
        $this->assertContains('rag-10.aurg_coverage', $ids);
        $this->assertContains('rag-12.rag_dimension', $ids);
        $this->assertContains('com-10.context_feedback_health', $ids);
        $this->assertContains('cpt-09.compaction_soak', $ids);
        $this->assertContains('pip-08.scorecard_stability', $ids);
        $this->assertContains('ope-08.lift_cycle_closure', $ids);
        $this->assertContains('ope-10.scorecard_receipts_diagnosis', $ids);
        $this->assertContains('eng-11.enforce_readiness', $ids);
        $this->assertSame(count($ids), count(array_unique($ids)));
        $this->assertContains('memoryQualityCheck', $methods);
        $this->assertContains('engineeringEnforceReadinessReport', $methods);
    }
}
