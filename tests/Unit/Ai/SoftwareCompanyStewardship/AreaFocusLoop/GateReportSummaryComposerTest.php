<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\GateReportSummaryComposer;
use PHPUnit\Framework\TestCase;

final class GateReportSummaryComposerTest extends TestCase
{
    private GateReportSummaryComposer $composer;

    protected function setUp(): void
    {
        $this->composer = new GateReportSummaryComposer();
    }

    public function testReturnShapeCarriesSchemaVersion(): void
    {
        $result = $this->composer->summarize([]);

        $this->assertSame('atlas.aaeos.gate_report_summary.v1', $result['schema_version']);
    }

    /** Rule (1): direct count of [pass, pass, warn, block], no subtraction. */
    public function testDirectCountTalliesCanonicalStatuses(): void
    {
        $result = $this->composer->summarize([
            ['status' => 'pass'],
            ['status' => 'pass'],
            ['status' => 'warn'],
            ['status' => 'block'],
        ]);

        $this->assertSame(4, $result['total']);
        $this->assertSame(2, $result['pass']);
        $this->assertSame(1, $result['warn']);
        $this->assertSame(1, $result['block']);
        $this->assertSame(0, $result['other']);
        $this->assertSame([], $result['other_statuses']);
        $this->assertSame(
            $result['total'],
            $result['pass'] + $result['warn'] + $result['block'] + $result['other'],
        );
    }

    /** Rule (2): typo 'passs' is NOT counted as pass; subtractive math would have inflated pass. */
    public function testStatusTypoIsNotCountedAsPass(): void
    {
        $result = $this->composer->summarize([
            ['status' => 'passs'],
        ]);

        $this->assertSame(1, $result['total']);
        $this->assertSame(0, $result['pass']);
        $this->assertSame(1, $result['other']);
        $this->assertSame(['passs'], $result['other_statuses']);
        $this->assertSame(
            $result['total'],
            $result['pass'] + $result['warn'] + $result['block'] + $result['other'],
        );
    }

    /** Rule (3): invariant holds on a mixed batch with empty, missing, wrong-case and a real block. */
    public function testInvariantHoldsOnMixedMalformedBatch(): void
    {
        $result = $this->composer->summarize([
            ['status' => 'pass'],
            ['status' => ''],
            ['other_key' => 'no-status'],
            ['status' => 'BLOCK'],
            ['status' => 'block'],
        ]);

        $this->assertSame(5, $result['total']);
        $this->assertSame(1, $result['pass']);
        $this->assertSame(0, $result['warn']);
        $this->assertSame(1, $result['block']);
        $this->assertSame(3, $result['other']);
        $this->assertSame(['', 'BLOCK'], $result['other_statuses']);
        $this->assertSame(
            $result['total'],
            $result['pass'] + $result['warn'] + $result['block'] + $result['other'],
        );
    }

    /** Rule (4): two 'unknown' rows => other:2 but other_statuses deduped to length 1. */
    public function testDuplicateNonCanonicalStatusesAreDeduped(): void
    {
        $result = $this->composer->summarize([
            ['status' => 'unknown'],
            ['status' => 'unknown'],
        ]);

        $this->assertSame(2, $result['other']);
        $this->assertSame(['unknown'], $result['other_statuses']);
        $this->assertCount(1, $result['other_statuses']);
    }

    /** Rule (5): empty input => all zeros, other_statuses=[]. */
    public function testEmptyInputYieldsAllZeros(): void
    {
        $result = $this->composer->summarize([]);

        $this->assertSame(0, $result['total']);
        $this->assertSame(0, $result['pass']);
        $this->assertSame(0, $result['warn']);
        $this->assertSame(0, $result['block']);
        $this->assertSame(0, $result['other']);
        $this->assertSame([], $result['other_statuses']);
    }

    public function testOtherStatusesAreSortedAscending(): void
    {
        $result = $this->composer->summarize([
            ['status' => 'zeta'],
            ['status' => 'alpha'],
            ['status' => 'mike'],
        ]);

        $this->assertSame(['alpha', 'mike', 'zeta'], $result['other_statuses']);
    }

    public function testNonStringStatusIsVisibleAfterCast(): void
    {
        $result = $this->composer->summarize([
            ['status' => 123],
            ['status' => null],
        ]);

        $this->assertSame(2, $result['other']);
        $this->assertSame(['', '123'], $result['other_statuses']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $rows = [
            ['status' => 'pass'],
            ['status' => 'warn'],
            ['status' => 'broken'],
        ];

        $first = $this->composer->summarize($rows);
        $second = $this->composer->summarize($rows);

        $this->assertSame($first, $second);
    }
}
