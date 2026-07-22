<?php

namespace Tests\Unit\Ai\Cognitive\Failure;

use App\Services\Ai\Cognitive\Failure\BayesianFailureTracker;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class BayesianFailureTrackerTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_160000_create_failure_signatures_table.php'];

    private const LEDGER_COMPANION_TABLES = ['failure_repetition_alerts', 'failure_diversity_metrics', 'failure_signatures'];

    public function test_tracker_computes_failure_diversity_index(): void
    {
        $repository = app(FailureSignatureRepository::class);
        $repository->record(['domain' => 'programming', 'message' => 'runtime failed while executing harness']);
        $repository->record(['domain' => 'programming', 'message' => 'runtime failed while executing harness']);
        $repository->record(['domain' => 'programming', 'message' => 'policy denied by profile']);

        $metric = app(BayesianFailureTracker::class)->compute('programming', 30);

        $this->assertSame('computed', $metric['status']);
        $this->assertSame(3, $metric['total_failures']);
        $this->assertSame(2, $metric['unique_signatures']);
        $this->assertEquals(0.667, $metric['diversity_index']);
    }
}
