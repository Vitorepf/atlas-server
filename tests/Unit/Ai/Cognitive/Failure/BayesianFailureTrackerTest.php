<?php

namespace Tests\Unit\Ai\Cognitive\Failure;

use App\Services\Ai\Cognitive\Failure\BayesianFailureTracker;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class BayesianFailureTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_160000_create_failure_signatures_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('failure_repetition_alerts');
        Schema::dropIfExists('failure_diversity_metrics');
        Schema::dropIfExists('failure_signatures');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

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
