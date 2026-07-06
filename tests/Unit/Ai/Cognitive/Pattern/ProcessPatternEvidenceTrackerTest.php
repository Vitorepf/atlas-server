<?php

namespace Tests\Unit\Ai\Cognitive\Pattern;

use App\Models\AtlasLedgerEvent;
use App\Services\Ai\Cognitive\Pattern\ProcessPatternEvidenceTracker;
use App\Services\Ai\Cognitive\Pattern\ProcessPatternRepository;
use App\Services\Ai\Kernel\Evidence\LedgerEventType;
use Tests\Concerns\TestsWithLedgerEvents;
use Tests\TestCase;

class ProcessPatternEvidenceTrackerTest extends TestCase
{
    use TestsWithLedgerEvents;

    private const LEDGER_COMPANION_MIGRATIONS = ['2026_05_07_150000_create_process_patterns_table.php'];

    private const LEDGER_COMPANION_TABLES = ['process_pattern_applications', 'process_patterns'];

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
