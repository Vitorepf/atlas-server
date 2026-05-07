<?php

namespace Tests\Unit\Ai\Cognitive\Failure;

use App\Services\Ai\Cognitive\Failure\FailureRepetitionAlerter;
use App\Services\Ai\Cognitive\Failure\FailureSignatureRepository;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FailureRepetitionAlerterTest extends TestCase
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

    public function test_alerter_triggers_on_third_repeated_signature(): void
    {
        $repository = app(FailureSignatureRepository::class);
        $alerter = app(FailureRepetitionAlerter::class);
        $last = null;

        for ($i = 0; $i < 3; $i++) {
            $last = $repository->record(['domain' => 'programming', 'message' => 'provider timeout while calling model']);
        }

        $alert = $alerter->evaluate($last);

        $this->assertSame('triggered', $alert['status']);
        $this->assertSame(3, $alert['repetition_count']);
        $this->assertSame('critical', $alert['severity']);
    }
}
