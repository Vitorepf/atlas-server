<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusCandidateQuarantineService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * WIRE-OBSERVE pin: every ledger entry appended by appendFromCycle() now
 * carries four advisory fields — transient_recurrence_count, transient_decay,
 * eligibility_score and eligibility_band — computed from the same append-only
 * ledger and context the append already owns. shouldQuarantine() and every
 * pre-existing entry field are byte-identical to before.
 */
final class AreaFocusCandidateQuarantineObserveSignalsWireTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tmp = sys_get_temp_dir().'/atlas_quarantine_observe_'.uniqid('', true);
        File::ensureDirectoryExists($this->tmp);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tmp);
        parent::tearDown();
    }

    private function service(): AreaFocusCandidateQuarantineService
    {
        $service = app(AreaFocusCandidateQuarantineService::class);
        $service->setStorageRootForTesting($this->tmp);

        return $service;
    }

    public function test_permanent_blocker_entry_scores_seventy_and_bands_retry_bounded(): void
    {
        $entry = $this->service()->appendFromCycle(
            'agentic_engineering_os',
            'dev_forge',
            ['finding_id' => 'find_perm'],
            ['owner_runtime_result_failed'],
        );

        // Pre-existing fields untouched.
        $this->assertSame('permanent', $entry['retry_after']);

        $this->assertSame(0, $entry['transient_recurrence_count'], 'no transient blocker => no streak');
        $this->assertSame('transient', $entry['transient_decay']['classification']);
        $this->assertFalse($entry['transient_decay']['decayed']);
        // permanent=70 severity points, no recurrence/repair/work modifiers.
        $this->assertSame(70, $entry['eligibility_score']['score']);
        $this->assertSame(70, $entry['eligibility_score']['components']['severity_points']);
        $this->assertSame('retry_bounded', $entry['eligibility_band']['band']);
        $this->assertSame('allow_bounded_retry', $entry['eligibility_band']['action']);
    }

    public function test_transient_streak_counts_up_and_decays_past_the_observe_budget(): void
    {
        $service = $this->service();
        $finding = ['finding_id' => 'find_transient'];
        $blockers = ['owner_runtime_provider_timeout'];
        $context = ['retry_after' => $this->timestamp('+10 minutes')];

        $counts = [];
        $lastEntry = [];
        foreach (range(1, 4) as $attempt) {
            $lastEntry = $service->appendFromCycle('agentic_engineering_os', 'dev_forge', $finding, $blockers, $context);
            $counts[] = $lastEntry['transient_recurrence_count'];
        }

        // The streak counts THIS entry too, so it increments 1,2,3,4.
        $this->assertSame([1, 2, 3, 4], $counts);
        // Budget is 3: the 4th consecutive recurrence decays to permanent.
        $this->assertSame(
            AreaFocusCandidateQuarantineService::TRANSIENT_DECAY_OBSERVE_BUDGET,
            $lastEntry['transient_decay']['budget'],
        );
        $this->assertSame('decayed_permanent', $lastEntry['transient_decay']['classification']);
        $this->assertTrue($lastEntry['transient_decay']['decayed']);
        $this->assertSame(0, $lastEntry['transient_decay']['remaining']);
        // transient=5 severity + 4*6 recurrence points = 29 => keep band.
        $this->assertSame(29, $lastEntry['eligibility_score']['score']);
        $this->assertSame('keep', $lastEntry['eligibility_band']['band']);
        // Pre-existing behavior untouched: transient never quarantines.
        $this->assertFalse($service->shouldQuarantine($blockers));
    }

    private function timestamp(string $modifier): string
    {
        return (new \DateTimeImmutable($modifier, new \DateTimeZone('UTC')))->format(DATE_ATOM);
    }
}
