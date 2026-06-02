<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10KnownSpaceNoveltyDiscriminator;
use PHPUnit\Framework\TestCase;

final class L10KnownSpaceNoveltyDiscriminatorTest extends TestCase
{
    private L10KnownSpaceNoveltyDiscriminator $discriminator;

    protected function setUp(): void
    {
        $this->discriminator = new L10KnownSpaceNoveltyDiscriminator();
    }

    /**
     * @return list<array{id:string,signature:string,mechanisms:list<string>}>
     */
    private function knownCorpus(): array
    {
        return [
            ['id' => 'event_sourcing', 'signature' => 'sig_event_sourcing', 'mechanisms' => ['append_log', 'replay', 'projection']],
            ['id' => 'cqrs', 'signature' => 'sig_cqrs', 'mechanisms' => ['command_bus', 'read_model', 'projection']],
            ['id' => 'actor_model', 'signature' => 'sig_actor_model', 'mechanisms' => ['mailbox', 'supervision', 'message_passing']],
            ['id' => 'dataflow', 'signature' => 'sig_dataflow', 'mechanisms' => ['pipeline', 'backpressure', 'operator']],
        ];
    }

    public function testReturnsAllFourComputedKeys(): void
    {
        $result = $this->discriminator->discriminate(
            ['signature' => 'sig_brand_new', 'mechanisms' => ['holographic_state'], 'novelty_evidence' => ['ev:outcome-1']],
            $this->knownCorpus(),
        );

        $this->assertArrayHasKey('novelty_status', $result);
        $this->assertArrayHasKey('nearest_known_patterns', $result);
        $this->assertArrayHasKey('novelty_score', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertSame('atlas.loop.l10_known_space_novelty.v1', $result['schema_version']);
    }

    public function testGenuinelyOutsideKnownSpaceWithEvidenceIsNovel(): void
    {
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_holographic_compute',
                'mechanisms' => ['holographic_state', 'phase_addressing', 'interference_routing'],
                'novelty_evidence' => ['ev:outcome-delta-1', 'ev:twin-safe-2'],
            ],
            $this->knownCorpus(),
        );

        $this->assertSame('novel_outside_known_space', $result['novelty_status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['nearest_known_patterns']);
        $this->assertSame(1.0, $result['novelty_score']);
    }

    public function testExactKnownPatternRejectsNovelty(): void
    {
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_event_sourcing',
                'mechanisms' => ['append_log', 'replay', 'projection'],
                'novelty_evidence' => ['ev:claimed-1'],
            ],
            $this->knownCorpus(),
        );

        $this->assertSame('known_existing', $result['novelty_status']);
        $this->assertSame(['exact_known_pattern_match'], $result['blockers']);
        $this->assertSame('event_sourcing', $result['nearest_known_patterns'][0]);
        $this->assertSame(0.0, $result['novelty_score']);
    }

    public function testInsufficientCorpusReturnsUnknownNotNew(): void
    {
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_holographic_compute',
                'mechanisms' => ['holographic_state', 'phase_addressing'],
                'novelty_evidence' => ['ev:outcome-1'],
            ],
            [
                ['id' => 'event_sourcing', 'signature' => 'sig_event_sourcing', 'mechanisms' => ['append_log', 'replay']],
                ['id' => 'cqrs', 'signature' => 'sig_cqrs', 'mechanisms' => ['command_bus', 'read_model']],
            ],
        );

        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertSame(['insufficient_known_corpus'], $result['blockers']);
    }

    public function testEmptyCorpusReturnsUnknownNotNew(): void
    {
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_holographic_compute',
                'mechanisms' => ['holographic_state'],
                'novelty_evidence' => ['ev:outcome-1'],
            ],
            [],
        );

        $this->assertSame('unknown_not_new', $result['novelty_status']);
        $this->assertSame(['insufficient_known_corpus'], $result['blockers']);
        $this->assertSame([], $result['nearest_known_patterns']);
    }

    public function testNoveltyScoreBelowThresholdBlocks(): void
    {
        // Shares 3 of 4 mechanisms with cqrs: Jaccard = 3/4 = 0.75 ->
        // novelty distance = 0.25, which is below the 0.6 threshold.
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_renamed_cqrs',
                'mechanisms' => ['command_bus', 'read_model', 'projection', 'audit_tap'],
                'novelty_evidence' => ['ev:claimed-1'],
            ],
            $this->knownCorpus(),
        );

        $this->assertSame('known_existing', $result['novelty_status']);
        $this->assertSame(['novelty_score_below_threshold'], $result['blockers']);
        $this->assertEqualsWithDelta(0.25, $result['novelty_score'], 0.0001);
        $this->assertSame('cqrs', $result['nearest_known_patterns'][0]);
    }

    public function testRenamedExistingWorkWithoutEvidenceIsDampenedBelowThreshold(): void
    {
        // Distinct mechanisms (Jaccard with every known pattern = 0) would score
        // 1.0 with evidence, but with NO novelty evidence the distance is dampened
        // by the 0.5 penalty to 0.5, falling below the 0.6 threshold: renamed work
        // outside known space but without evidence is not admitted as novel.
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_unproven',
                'mechanisms' => ['holographic_state', 'phase_addressing'],
            ],
            $this->knownCorpus(),
        );

        $this->assertSame('known_existing', $result['novelty_status']);
        $this->assertSame(['novelty_score_below_threshold'], $result['blockers']);
        $this->assertEqualsWithDelta(0.5, $result['novelty_score'], 0.0001);
    }

    public function testEvidencePresenceRaisesNoveltyScoreForIdenticalMechanisms(): void
    {
        $candidate = [
            'signature' => 'sig_partial_overlap',
            'mechanisms' => ['append_log', 'phase_addressing'],
        ];

        $withEvidence = $this->discriminator->discriminate(
            $candidate + ['novelty_evidence' => ['ev:outcome-1']],
            $this->knownCorpus(),
        );
        $withoutEvidence = $this->discriminator->discriminate(
            $candidate,
            $this->knownCorpus(),
        );

        // Same mechanisms, only evidence differs -> evidenced score is strictly
        // higher, and exactly double the dampened score (penalty 0.5).
        $this->assertGreaterThan($withoutEvidence['novelty_score'], $withEvidence['novelty_score']);
        $this->assertEqualsWithDelta(
            $withoutEvidence['novelty_score'] * 2,
            $withEvidence['novelty_score'],
            0.0001,
        );
    }

    public function testNearestKnownPatternsAreOrderedBySimilarityAndAreStrings(): void
    {
        // mechanisms overlap most with cqrs (2/3) then event_sourcing/dataflow.
        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_probe',
                'mechanisms' => ['command_bus', 'read_model', 'append_log', 'pipeline'],
                'novelty_evidence' => ['ev:outcome-1'],
            ],
            $this->knownCorpus(),
        );

        $this->assertContainsOnlyString($result['nearest_known_patterns']);
        $this->assertSame('cqrs', $result['nearest_known_patterns'][0]);
        $this->assertLessThanOrEqual(
            L10KnownSpaceNoveltyDiscriminator::NEAREST_LIMIT,
            count($result['nearest_known_patterns']),
        );
        $this->assertNotContains('actor_model', $result['nearest_known_patterns']);
    }

    public function testNoveltyScoreNeverExceedsUnitBoundForExtremeInput(): void
    {
        $duplicatedMechanisms = array_fill(0, 50, 'append_log');

        $result = $this->discriminator->discriminate(
            [
                'signature' => 'sig_extreme',
                'mechanisms' => $duplicatedMechanisms,
                'novelty_evidence' => array_fill(0, 25, 'ev:flood'),
            ],
            $this->knownCorpus(),
        );

        $this->assertGreaterThanOrEqual(0.0, $result['novelty_score']);
        $this->assertLessThanOrEqual(1.0, $result['novelty_score']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $candidate = [
            'signature' => 'sig_holographic_compute',
            'mechanisms' => ['holographic_state', 'phase_addressing'],
            'novelty_evidence' => ['ev:outcome-1'],
        ];
        $known = $this->knownCorpus();

        $first = $this->discriminator->discriminate($candidate, $known);
        $second = $this->discriminator->discriminate($candidate, $known);

        $this->assertSame($first, $second);
    }

    public function testExactMatchTakesPrecedenceOverInsufficientCorpus(): void
    {
        // Only two known patterns (below MIN_KNOWN_CORPUS) but the candidate is an
        // exact rename of one: the exact-match rule fires first.
        $result = $this->discriminator->discriminate(
            ['signature' => 'sig_cqrs', 'mechanisms' => ['command_bus', 'read_model']],
            [
                ['id' => 'event_sourcing', 'signature' => 'sig_event_sourcing', 'mechanisms' => ['append_log']],
                ['id' => 'cqrs', 'signature' => 'sig_cqrs', 'mechanisms' => ['command_bus', 'read_model']],
            ],
        );

        $this->assertSame('known_existing', $result['novelty_status']);
        $this->assertSame(['exact_known_pattern_match'], $result['blockers']);
    }
}
