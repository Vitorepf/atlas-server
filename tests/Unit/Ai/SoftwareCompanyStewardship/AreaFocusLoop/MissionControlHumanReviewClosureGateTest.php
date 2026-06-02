<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\MissionControlHumanReviewClosureGate;
use PHPUnit\Framework\TestCase;

final class MissionControlHumanReviewClosureGateTest extends TestCase
{
    private MissionControlHumanReviewClosureGate $gate;

    protected function setUp(): void
    {
        $this->gate = new MissionControlHumanReviewClosureGate();
    }

    public function testL3DoesNotRequireP14SurfaceAndAllowsCertification(): void
    {
        $result = $this->gate->evaluate('L3', []);

        $this->assertSame('atlas.aaeos.mission_control_human_review_closure.v1', $result['schema_version']);
        $this->assertSame(3, $result['level']);
        $this->assertFalse($result['human_review_required']);
        $this->assertSame('not_required', $result['human_review_status']);
        $this->assertSame('', $result['mission_control_snapshot_ref']);
        $this->assertTrue($result['certification_allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testL4RequiresP14AndPassesWithFreshReviewedSnapshot(): void
    {
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-2026-06-01',
            'age_seconds' => 600,
            'reviewed' => true,
        ]);

        $this->assertSame(4, $result['level']);
        $this->assertTrue($result['human_review_required']);
        $this->assertSame('satisfied', $result['human_review_status']);
        $this->assertSame('mc-snapshot-2026-06-01', $result['mission_control_snapshot_ref']);
        $this->assertTrue($result['snapshot_present']);
        $this->assertFalse($result['snapshot_stale']);
        $this->assertTrue($result['certification_allowed']);
        $this->assertSame([], $result['blockers']);
    }

    public function testL4WithStaleSnapshotFailsCertification(): void
    {
        $result = $this->gate->evaluate(4, [
            'ref' => 'mc-snapshot-old',
            'age_seconds' => 90000,
            'reviewed' => true,
        ]);

        $this->assertTrue($result['human_review_required']);
        $this->assertTrue($result['snapshot_present']);
        $this->assertTrue($result['snapshot_stale']);
        $this->assertSame('blocked_stale_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['mission_control_snapshot_stale'], $result['blockers']);
    }

    public function testL4WithNumericStringStaleAgeStillFailsCertification(): void
    {
        // A heterogeneous caller may stringify age_seconds; a genuinely stale
        // snapshot must not fail open and slip through the L4+ review gate.
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-string-age',
            'age_seconds' => '90000',
            'reviewed' => true,
        ]);

        $this->assertTrue($result['snapshot_present']);
        $this->assertTrue($result['snapshot_stale']);
        $this->assertSame('blocked_stale_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['mission_control_snapshot_stale'], $result['blockers']);
    }

    public function testL4WithNumericStringFreshAgeIsNotStale(): void
    {
        // The mirror case: a fresh numeric-string age must remain non-stale so
        // the new coercion does not over-block within the threshold.
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-string-fresh',
            'age_seconds' => '600',
            'reviewed' => true,
        ]);

        $this->assertFalse($result['snapshot_stale']);
        $this->assertSame('satisfied', $result['human_review_status']);
        $this->assertTrue($result['certification_allowed']);
    }

    public function testL4WithNonFiniteNanAgeFailsClosedAsStale(): void
    {
        // A NaN age (e.g. from an upstream float duration that divided by zero)
        // is not a valid freshness proof. INF already trips the stale bound, but
        // NaN compares false against every threshold and would otherwise fail
        // open and satisfy the L4+ gate. An uncomputable age must block.
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-nan-age',
            'age_seconds' => NAN,
            'reviewed' => true,
        ]);

        $this->assertTrue($result['snapshot_present']);
        $this->assertTrue($result['snapshot_stale']);
        $this->assertSame('blocked_stale_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['mission_control_snapshot_stale'], $result['blockers']);
    }

    public function testL4WithInfiniteAgeFailsClosedAsStale(): void
    {
        // The mirror non-finite case: an infinite age is unambiguously stale.
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-inf-age',
            'age_seconds' => INF,
            'reviewed' => true,
        ]);

        $this->assertTrue($result['snapshot_stale']);
        $this->assertSame('blocked_stale_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
    }

    public function testL4WithExplicitStaleFlagFailsRegardlessOfAge(): void
    {
        $result = $this->gate->evaluate('L4', [
            'snapshot_ref' => 'mc-snapshot-flagged',
            'stale' => true,
            'reviewed' => true,
        ]);

        $this->assertSame('mc-snapshot-flagged', $result['mission_control_snapshot_ref']);
        $this->assertTrue($result['snapshot_stale']);
        $this->assertSame('blocked_stale_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['mission_control_snapshot_stale'], $result['blockers']);
    }

    public function testL4WithMissingSnapshotIsBlocked(): void
    {
        $result = $this->gate->evaluate('L4', []);

        $this->assertTrue($result['human_review_required']);
        $this->assertFalse($result['snapshot_present']);
        $this->assertSame('', $result['mission_control_snapshot_ref']);
        $this->assertSame('blocked_missing_snapshot', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['mission_control_snapshot_missing'], $result['blockers']);
    }

    public function testL4FreshSnapshotWithoutReviewIsPending(): void
    {
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-fresh',
            'age_seconds' => 1200,
        ]);

        $this->assertTrue($result['human_review_required']);
        $this->assertFalse($result['snapshot_stale']);
        $this->assertSame('pending_review', $result['human_review_status']);
        $this->assertFalse($result['certification_allowed']);
        $this->assertSame(['human_review_not_completed'], $result['blockers']);
    }

    public function testHigherLevelsAlsoRequireHumanOversight(): void
    {
        foreach (['L5', 'L6', 'L7'] as $label) {
            $result = $this->gate->evaluate($label, []);

            $this->assertTrue(
                $result['human_review_required'],
                "Human oversight must be required at {$label}",
            );
            $this->assertSame('blocked_missing_snapshot', $result['human_review_status']);
            $this->assertFalse($result['certification_allowed']);
        }
    }

    public function testLowerLevelsNeverRequireHumanOversight(): void
    {
        foreach ([0, 1, 2, 3] as $tier) {
            $result = $this->gate->evaluate($tier, [
                'ref' => 'mc-snapshot-present',
                'stale' => true,
            ]);

            $this->assertFalse(
                $result['human_review_required'],
                "Level {$tier} must not require human oversight",
            );
            $this->assertSame('not_required', $result['human_review_status']);
            $this->assertTrue($result['certification_allowed']);
            $this->assertSame([], $result['blockers']);
        }
    }

    public function testSnapshotRefIsTrimmedAndCoercedFromString(): void
    {
        $result = $this->gate->evaluate('L4', [
            'mission_control_snapshot_ref' => '  mc-trimmed  ',
            'age_seconds' => 100,
            'review_approved' => true,
        ]);

        $this->assertSame('mc-trimmed', $result['mission_control_snapshot_ref']);
        $this->assertSame('satisfied', $result['human_review_status']);
        $this->assertTrue($result['certification_allowed']);
    }

    public function testEvaluationIsDeterministicForIdenticalInput(): void
    {
        $snapshot = [
            'ref' => 'mc-snapshot-det',
            'age_seconds' => 300,
            'reviewed' => true,
        ];

        $first = $this->gate->evaluate('L4', $snapshot);
        $second = $this->gate->evaluate('L4', $snapshot);

        $this->assertSame($first, $second);
    }

    public function testBlockersContractIsListOfStrings(): void
    {
        $result = $this->gate->evaluate('L4', [
            'ref' => 'mc-snapshot-old',
            'age_seconds' => 200000,
        ]);

        $this->assertArrayHasKey('blockers', $result);
        $this->assertSame(array_values($result['blockers']), $result['blockers']);

        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
    }
}
