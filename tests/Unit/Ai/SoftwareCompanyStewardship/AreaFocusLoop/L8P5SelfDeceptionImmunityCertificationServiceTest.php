<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8P5SelfDeceptionImmunityCertificationService;
use PHPUnit\Framework\TestCase;

final class L8P5SelfDeceptionImmunityCertificationServiceTest extends TestCase
{
    private L8P5SelfDeceptionImmunityCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new L8P5SelfDeceptionImmunityCertificationService();
    }

    /**
     * Every S101-S105 pillar green, each with its own evidence ref.
     *
     * @return array<string,mixed>
     */
    private function allPillarsPassing(): array
    {
        return [
            'admission' => ['passed' => true, 'evidence_ref' => 'evidence://l8/p5/admission/s101'],
            'anchors' => ['passed' => true, 'evidence_ref' => 'evidence://l8/p5/anchors/s102'],
            'divergence' => ['passed' => true, 'evidence_ref' => 'evidence://l8/p5/divergence/s103'],
            'adversarial' => ['passed' => true, 'evidence_ref' => 'evidence://l8/p5/adversarial/s104'],
            'trust_penalty' => ['passed' => true, 'evidence_ref' => 'evidence://l8/p5/trust_penalty/s105'],
        ];
    }

    /**
     * The five canonical ground-truth anchors, each independent + immutable + not
     * self-reported (mirrors S102 L8GroundTruthAnchorRegistry).
     *
     * @return list<array<string,mixed>>
     */
    private function allFiveAnchors(): array
    {
        $metrics = [
            'useful_cycle_rate',
            'trust_ledger_score',
            'dm_dt',
            'retained_evolution_rate',
            'provider_honesty_rate',
        ];

        return array_map(
            static fn (string $metric): array => [
                'optimized_metric' => $metric,
                'independent_source' => 'external_ledger:'.$metric,
                'immutable' => true,
                'cannot_be_self_reported' => true,
            ],
            $metrics,
        );
    }

    /**
     * One adversarial case where the reported metric rises but the anchor is flat,
     * detected and blocked (mirrors S104 self-deception probe output).
     *
     * @return list<array<string,mixed>>
     */
    private function blockedGamingCase(): array
    {
        return [
            ['metric_up_anchor_flat' => true, 'blocked' => true, 'metric' => 'useful_cycle_rate'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fullyImmuneInputs(): array
    {
        return [
            'gates' => $this->allPillarsPassing(),
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => $this->blockedGamingCase(),
        ];
    }

    public function testCertifyReturnsP5CertifiedBlockersAnchorCountAdversarialCaseCountAndEvidenceRefs(): void
    {
        $result = $this->service->certify($this->fullyImmuneInputs());

        // Every key named by the Acceptance row is present.
        $this->assertArrayHasKey('p5_certified', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertArrayHasKey('anchor_count', $result);
        $this->assertArrayHasKey('adversarial_case_count', $result);
        $this->assertArrayHasKey('evidence_refs', $result);

        $this->assertSame(
            'atlas.aaeos.l8.p5_self_deception_immunity_certification.v1',
            $result['schema_version'],
        );
        $this->assertSame('L8-P5', $result['phase']);

        // The named fields carry their real computed types/values.
        $this->assertIsBool($result['p5_certified']);
        $this->assertTrue($result['p5_certified']);
        $this->assertSame(5, $result['anchor_count']);
        $this->assertSame(1, $result['adversarial_case_count']);
        $this->assertSame([], $result['blockers']);
    }

    public function testP5CertifiedTrueOnlyWithAllPillarsPassAndSimulatedGamingBlocked(): void
    {
        $result = $this->service->certify($this->fullyImmuneInputs());

        $this->assertTrue($result['p5_certified']);
        $this->assertSame('p5_certified', $result['status']);

        // All five S101-S105 pillars passed.
        $this->assertSame(5, $result['pillar_total']);
        $this->assertSame(5, $result['pillars_passed_count']);
        $pillarSlices = array_map(static fn (array $row): string => $row['slice'], $result['pillars']);
        $this->assertSame(['S101', 'S102', 'S103', 'S104', 'S105'], $pillarSlices);

        // Simulated gaming was detected and blocked.
        $this->assertTrue($result['simulated_gaming_blocked']);

        // Five pillar refs plus the simulated-gaming-blocked proof flow through.
        $this->assertSame(
            [
                'evidence://l8/p5/admission/s101',
                'evidence://l8/p5/anchors/s102',
                'evidence://l8/p5/divergence/s103',
                'evidence://l8/p5/adversarial/s104',
                'evidence://l8/p5/trust_penalty/s105',
                'evidence://l8/p5/simulated_gaming_blocked/met',
            ],
            $result['evidence_refs'],
        );
    }

    public function testGamingNotBlockedFailsP5EvenWhenEveryPillarAndAnchorIsPresent(): void
    {
        // All five pillars pass and all anchors present, but the only adversarial
        // case was NOT blocked: the system has not refuted its own gain, so P5
        // must NOT certify. p5_certified=true REQUIRES simulated gaming blocked.
        $result = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => [
                ['metric_up_anchor_flat' => true, 'blocked' => false],
            ],
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame('blocked_not_p5', $result['status']);
        $this->assertFalse($result['simulated_gaming_blocked']);
        $this->assertSame(5, $result['pillars_passed_count']);
        $this->assertSame(5, $result['anchor_count']);
        $this->assertSame(['simulated_gaming_not_blocked'], $result['blockers']);
        // The simulated-gaming-blocked proof is never emitted on a blocked verdict.
        $this->assertNotContains(
            'evidence://l8/p5/simulated_gaming_blocked/met',
            $result['evidence_refs'],
        );
    }

    public function testMissingOneAnchorBlocksP5(): void
    {
        // Drop a single canonical anchor (dm_dt). Everything else is green.
        $anchors = $this->allFiveAnchors();
        unset($anchors[2]); // dm_dt is the third canonical anchor
        $anchors = array_values($anchors);

        $result = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $anchors,
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame('blocked_not_p5', $result['status']);
        // Four of five anchors present — the count never overstates reality.
        $this->assertSame(4, $result['anchor_count']);
        $this->assertSame(['dm_dt'], $result['missing_anchors']);
        $this->assertSame(['ground_truth_anchor_missing'], $result['blockers']);
    }

    public function testOnePillarGapBlocksP5WithNamedBlocker(): void
    {
        // S103 divergence detector not wired; all else green.
        $gates = $this->allPillarsPassing();
        $gates['divergence'] = ['passed' => false, 'evidence_ref' => 'evidence://l8/p5/divergence/draft'];

        $result = $this->service->certify([
            'gates' => $gates,
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame('blocked_not_p5', $result['status']);
        $this->assertSame(4, $result['pillars_passed_count']);
        $this->assertSame(['divergence_detector_not_wired'], $result['blockers']);
        // An unmet pillar never leaks its evidence ref into the proof list.
        $this->assertNotContains('evidence://l8/p5/divergence/draft', $result['evidence_refs']);
    }

    public function testEmptyInputBlocksWithEveryBlockerInCanonicalOrder(): void
    {
        // No L8 capability before P5 is alive: a bare/empty snapshot must
        // fail-closed across every pillar, the anchor gap and the gaming gap,
        // in canonical safety-first order. This proves the rules generalise.
        $result = $this->service->certify([]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame('blocked_not_p5', $result['status']);
        $this->assertSame(0, $result['pillars_passed_count']);
        $this->assertSame(0, $result['anchor_count']);
        $this->assertSame(0, $result['adversarial_case_count']);
        $this->assertFalse($result['simulated_gaming_blocked']);
        $this->assertSame([], $result['evidence_refs']);
        $this->assertSame(
            [
                'l8_admission_not_granted',
                'ground_truth_anchors_not_registered',
                'divergence_detector_not_wired',
                'adversarial_probe_not_wired',
                'trust_penalty_policy_not_wired',
                'ground_truth_anchor_missing',
                'simulated_gaming_not_blocked',
            ],
            $result['blockers'],
        );
        $this->assertSame(
            [
                'useful_cycle_rate',
                'trust_ledger_score',
                'dm_dt',
                'retained_evolution_rate',
                'provider_honesty_rate',
            ],
            $result['missing_anchors'],
        );
    }

    public function testAnchorCountNeverExceedsFiveEvenWithDuplicatesAndNonCanonicalEntries(): void
    {
        // Duplicate canonical anchors and irrelevant extras must not inflate the
        // count: anchor_count is bounded by the five canonical metrics.
        $anchors = $this->allFiveAnchors();
        $anchors[] = ['optimized_metric' => 'useful_cycle_rate', 'immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'dup'];
        $anchors[] = ['optimized_metric' => 'some_unrelated_metric', 'immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'x'];

        $result = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $anchors,
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertSame(5, $result['anchor_count']);
        $this->assertSame(5, $result['anchor_required_count']);
        $this->assertLessThanOrEqual(5, $result['anchor_count']);
        $this->assertSame([], $result['missing_anchors']);
        $this->assertTrue($result['p5_certified']);
    }

    public function testSelfReportedAnchorDoesNotCountAsIndependent(): void
    {
        // An anchor explicitly marked self-reported / mutable cannot anchor a
        // metric: it must be excluded from the count and reported missing.
        $anchors = $this->allFiveAnchors();
        // Corrupt the trust_ledger_score anchor: self-reported + mutable.
        $anchors[1] = [
            'optimized_metric' => 'trust_ledger_score',
            'independent_source' => 'self',
            'immutable' => false,
            'cannot_be_self_reported' => false,
        ];

        $result = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $anchors,
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame(4, $result['anchor_count']);
        $this->assertSame(['trust_ledger_score'], $result['missing_anchors']);
        $this->assertContains('ground_truth_anchor_missing', $result['blockers']);
    }

    public function testPillarGapAnchorGapAndGamingGapAllSurfaceInCanonicalOrder(): void
    {
        // A pillar gap, a missing anchor AND no blocked gaming case at once: all
        // three categories surface, in canonical order, none hidden.
        $gates = $this->allPillarsPassing();
        $gates['trust_penalty'] = false; // S105 pillar gap

        $anchors = $this->allFiveAnchors();
        unset($anchors[4]); // drop provider_honesty_rate
        $anchors = array_values($anchors);

        $result = $this->service->certify([
            'gates' => $gates,
            'anchors' => $anchors,
            'adversarial_cases' => [], // no gaming case proven blocked
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertSame(4, $result['pillars_passed_count']);
        $this->assertSame(4, $result['anchor_count']);
        $this->assertSame(['provider_honesty_rate'], $result['missing_anchors']);
        $this->assertSame(
            [
                'trust_penalty_policy_not_wired',
                'ground_truth_anchor_missing',
                'simulated_gaming_not_blocked',
            ],
            $result['blockers'],
        );
    }

    public function testBarePillarBooleanAndAlternatePassKeysAreHonoured(): void
    {
        // Pillars expressed as a bare bool and via met/pass aliases all certify,
        // anchors supplied as a metric-keyed map, and a bare-true pillar derives a
        // deterministic met ref. Proves generalisation beyond the canned shape.
        $result = $this->service->certify([
            'gates' => [
                'admission' => true,
                'anchors' => ['met' => true],
                'divergence' => ['pass' => true],
                'adversarial' => true,
                'trust_penalty' => true,
            ],
            'anchors' => [
                'useful_cycle_rate' => ['immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'ext'],
                'trust_ledger_score' => ['immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'ext'],
                'dm_dt' => ['immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'ext'],
                'retained_evolution_rate' => ['immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'ext'],
                'provider_honesty_rate' => ['immutable' => true, 'cannot_be_self_reported' => true, 'independent_source' => 'ext'],
            ],
            'adversarial_cases' => [
                ['gaming' => true, 'detected' => true],
            ],
        ]);

        $this->assertTrue($result['p5_certified']);
        $this->assertSame(5, $result['anchor_count']);
        // Bare-true admission pillar derives the prefixed met ref.
        $this->assertContains('evidence://l8/p5/admission/met', $result['evidence_refs']);
    }

    public function testDetectedButExplicitlyNotBlockedGamingCaseDoesNotProveImmunity(): void
    {
        // A gaming attempt that was DETECTED but explicitly NOT blocked is the
        // exact self-deception P5 exists to refute: detecting one's own gaming
        // without stopping it must NOT certify immunity. The `detected` alias
        // never overrides an explicit `blocked === false` (fail-closed).
        $result = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => [
                ['gaming' => true, 'detected' => true, 'blocked' => false],
            ],
        ]);

        $this->assertFalse($result['simulated_gaming_blocked']);
        $this->assertFalse($result['p5_certified']);
        $this->assertSame('blocked_not_p5', $result['status']);
        $this->assertSame(['simulated_gaming_not_blocked'], $result['blockers']);

        // A second case in the SAME batch that IS genuinely blocked still rescues
        // immunity — one honest blocked refutation is sufficient.
        $rescued = $this->service->certify([
            'gates' => $this->allPillarsPassing(),
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => [
                ['gaming' => true, 'detected' => true, 'blocked' => false],
                ['metric_up_anchor_flat' => true, 'blocked' => true],
            ],
        ]);
        $this->assertTrue($rescued['simulated_gaming_blocked']);
        $this->assertTrue($rescued['p5_certified']);
    }

    public function testUnknownPillarEvidenceFailsClosedAndNeverFakesCertification(): void
    {
        // Garbage / flagless pillar evidence must fail-closed, not silently pass.
        $gates = $this->allPillarsPassing();
        $gates['admission'] = ['note' => 'pending review']; // no pass flag
        $gates['anchors'] = 'maybe';                        // non-bool, non-array

        $result = $this->service->certify([
            'gates' => $gates,
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertFalse($result['p5_certified']);
        $this->assertContains('l8_admission_not_granted', $result['blockers']);
        $this->assertContains('ground_truth_anchors_not_registered', $result['blockers']);
        // Blockers preserve canonical pillar order (admission before anchors).
        $this->assertSame(
            ['l8_admission_not_granted', 'ground_truth_anchors_not_registered'],
            $result['blockers'],
        );
    }

    public function testListAndScalarContractsAreHonoured(): void
    {
        $gates = $this->allPillarsPassing();
        unset($gates['adversarial']);

        $result = $this->service->certify([
            'gates' => $gates,
            'anchors' => $this->allFiveAnchors(),
            'adversarial_cases' => $this->blockedGamingCase(),
        ]);

        $this->assertIsList($result['blockers']);
        $this->assertIsList($result['evidence_refs']);
        $this->assertIsList($result['missing_anchors']);
        $this->assertIsList($result['pillars']);
        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        foreach ($result['evidence_refs'] as $ref) {
            $this->assertIsString($ref);
        }
        foreach ($result['missing_anchors'] as $anchor) {
            $this->assertIsString($anchor);
        }
        $this->assertIsInt($result['anchor_count']);
        $this->assertIsInt($result['adversarial_case_count']);
        $this->assertIsInt($result['pillars_passed_count']);
        $this->assertIsBool($result['p5_certified']);
        $this->assertIsBool($result['simulated_gaming_blocked']);
    }

    public function testCertificationIsDeterministicAndDistinctSnapshotsDiffer(): void
    {
        $inputs = $this->fullyImmuneInputs();

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);
        $this->assertSame($first, $second);

        // A different snapshot must produce a different verdict (no canned output).
        $blockedInputs = $inputs;
        unset($blockedInputs['adversarial_cases']);
        $blocked = $this->service->certify($blockedInputs);

        $this->assertNotSame($first['status'], $blocked['status']);
        $this->assertNotSame($first['p5_certified'], $blocked['p5_certified']);
        $this->assertNotSame($first['simulated_gaming_blocked'], $blocked['simulated_gaming_blocked']);
    }
}
