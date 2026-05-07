<?php

namespace Tests\Unit\Ai\Cognitive\Pattern;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\Pattern\ProcessPatternEvidenceTracker;
use App\Services\Ai\Cognitive\Pattern\ProcessPatternRepository;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ProcessPatternEvidenceTrackerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        (require database_path('migrations/2026_05_05_020000_create_atlas_ledger_events_table.php'))->up();
        (require database_path('migrations/2026_05_07_150000_create_process_patterns_table.php'))->up();
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('process_pattern_applications');
        Schema::dropIfExists('process_patterns');
        Schema::dropIfExists('atlas_ledger_events');

        parent::tearDown();
    }

    public function test_tracker_records_application_updates_counters_and_ledger(): void
    {
        $pattern = app(ProcessPatternRepository::class)->findByName('validate-then-scale');
        $application = app(ProcessPatternEvidenceTracker::class)->apply($pattern['id'], 'programming', 'success');
        $updated = app(ProcessPatternRepository::class)->findByName('validate-then-scale');

        $this->assertSame('applied', $application['status']);
        $this->assertSame(1, data_get($updated, 'metrics.applied_count'));
        $this->assertSame(1, data_get($updated, 'metrics.success_count'));
        $this->assertSame(1, AtlasLedgerEvent::query()->where('event_type', LedgerEventType::ProcessPatternApplied->value)->count());
    }
}
