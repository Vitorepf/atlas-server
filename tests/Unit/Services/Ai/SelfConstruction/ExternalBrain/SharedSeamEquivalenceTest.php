<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam;
use PHPUnit\Framework\TestCase;

/**
 * Equivalence proof for the extracted runnable-acceptance seam (chain
 * refchain-d831966cf4 s4): the shared implementation preserves the exact
 * behavior both duplicated copies had — happy path per marker, error path,
 * and case-insensitivity.
 */
final class SharedSeamEquivalenceTest extends TestCase
{
    public function test_every_runnable_marker_is_recognized(): void
    {
        foreach (['phpunit', 'artisan test', 'pytest', 'jest', 'rspec'] as $marker) {
            $this->assertTrue(
                SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam::hasRunnableAcceptance(['run '.$marker.' and assert green']),
                $marker,
            );
        }
    }

    public function test_case_insensitive_like_both_legacy_copies(): void
    {
        $this->assertTrue(SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam::hasRunnableAcceptance(['Run PHPUnit suite']));
    }

    public function test_error_path_parity_no_marker_means_false(): void
    {
        $this->assertFalse(SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam::hasRunnableAcceptance(['looks good to me', 'ship it']));
        $this->assertFalse(SharedAtlasExternalBrainRoadmapCoverageGapGovernorSeam::hasRunnableAcceptance([]));
    }
}
