<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AaeosL8TranscendenceCertificationService;
use PHPUnit\Framework\TestCase;

final class AaeosL8TranscendenceCertificationServiceTest extends TestCase
{
    private AaeosL8TranscendenceCertificationService $service;

    protected function setUp(): void
    {
        $this->service = new AaeosL8TranscendenceCertificationService();
    }

    /**
     * Every pillar (P5, P1, P2, P3, P4) certified with an explicit honest-merge
     * evidence ref. Inputs are NOT the bool fast-path so the array branch is
     * exercised end to end.
     *
     * @return array<string,array<string,mixed>>
     */
    private function allPillarsCertified(): array
    {
        return [
            'p5' => ['certified' => true, 'honest_merge' => true, 'evidence_ref' => 'evidence://l8/transcendence/p5/s106'],
            'p1' => ['certified' => true, 'honest_merge' => true, 'evidence_ref' => 'evidence://l8/transcendence/p1/s112'],
            'p2' => ['certified' => true, 'honest_merge' => true, 'evidence_ref' => 'evidence://l8/transcendence/p2/s117'],
            'p3' => ['certified' => true, 'honest_merge' => true, 'evidence_ref' => 'evidence://l8/transcendence/p3/s121'],
            'p4' => ['certified' => true, 'honest_merge' => true, 'evidence_ref' => 'evidence://l8/transcendence/p4/s124'],
        ];
    }

    public function testCertifiesWhenAllFivePillarsPassWithHonestMerge(): void
    {
        $result = $this->service->certify($this->allPillarsCertified());

        // Acceptance: schema + the five pillar flags + certified + blockers + evidence_refs.
        $this->assertSame('atlas.aaeos.l8.transcendence_certification.v1', $result['schema_version']);
        $this->assertTrue($result['p5']);
        $this->assertTrue($result['p1']);
        $this->assertTrue($result['p2']);
        $this->assertTrue($result['p3']);
        $this->assertTrue($result['p4']);
        $this->assertTrue($result['certified']);
        $this->assertSame('l8_transcended', $result['status']);
        $this->assertSame([], $result['blockers']);
        $this->assertSame(5, $result['pillars_certified_count']);
        $this->assertSame(5, $result['pillar_total']);
        $this->assertSame(
            [
                'evidence://l8/transcendence/p5/s106',
                'evidence://l8/transcendence/p1/s112',
                'evidence://l8/transcendence/p2/s117',
                'evidence://l8/transcendence/p3/s121',
                'evidence://l8/transcendence/p4/s124',
            ],
            $result['evidence_refs'],
        );
    }

    public function testResultExposesEveryRequiredSchemaKey(): void
    {
        $result = $this->service->certify($this->allPillarsCertified());

        foreach (['schema_version', 'p5', 'p1', 'p2', 'p3', 'p4', 'certified', 'blockers', 'evidence_refs'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function testMissingP5BlocksEvenWhenP1ThroughP4Pass(): void
    {
        $inputs = $this->allPillarsCertified();
        // P5 not certified; P1-P4 remain fully green and honestly merged.
        $inputs['p5'] = ['certified' => false];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['p5']);
        $this->assertTrue($result['p1']);
        $this->assertTrue($result['p2']);
        $this->assertTrue($result['p3']);
        $this->assertTrue($result['p4']);
        // certified=false: P5 is the hard precondition even with every capability pillar green.
        $this->assertFalse($result['certified']);
        $this->assertSame('blocked_not_l8', $result['status']);
        $this->assertSame(['p5_not_certified'], $result['blockers']);
        $this->assertSame(4, $result['pillars_certified_count']);
    }

    public function testAbsentP5InputBlocksFailClosed(): void
    {
        $inputs = $this->allPillarsCertified();
        unset($inputs['p5']);

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['p5']);
        $this->assertFalse($result['certified']);
        // P5-first ordering: the missing precondition leads the blocker list.
        $this->assertSame('p5_not_certified', $result['blockers'][0]);
    }

    public function testPassingPillarWithDishonestMergeIsNotCertified(): void
    {
        $inputs = $this->allPillarsCertified();
        // P2 passed but was auto/faked-merged: capability bought without honest merge.
        $inputs['p2'] = ['certified' => true, 'honest_merge' => false];

        $result = $this->service->certify($inputs);

        $this->assertTrue($result['p5']);
        $this->assertTrue($result['p1']);
        $this->assertFalse($result['p2']);
        $this->assertTrue($result['p3']);
        $this->assertTrue($result['p4']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['p2_merge_not_honest'], $result['blockers']);
        $this->assertSame(4, $result['pillars_certified_count']);
    }

    public function testFakedMergeFlagAloneBlocksPillar(): void
    {
        $inputs = $this->allPillarsCertified();
        $inputs['p4'] = ['passed' => true, 'faked_merge' => true];

        $result = $this->service->certify($inputs);

        $this->assertFalse($result['p4']);
        $this->assertFalse($result['certified']);
        $this->assertSame(['p4_merge_not_honest'], $result['blockers']);
    }

    public function testBlockersAreOrderedP5FirstAcrossMultipleGaps(): void
    {
        // P5 and P3 fail their pass; P1 passes but merge is dishonest; P2/P4 green.
        $result = $this->service->certify([
            'p5' => ['certified' => false],
            'p1' => ['certified' => true, 'honest_merge' => false],
            'p2' => true,
            'p3' => false,
            'p4' => true,
        ]);

        $this->assertSame(
            ['p5_not_certified', 'p1_merge_not_honest', 'p3_not_certified'],
            $result['blockers'],
        );
        $this->assertFalse($result['certified']);
        $this->assertSame(2, $result['pillars_certified_count']);
        $this->assertTrue($result['p2']);
        $this->assertTrue($result['p4']);
    }

    public function testBoolPillarsAreAcceptedAndAssumeHonestMerge(): void
    {
        $result = $this->service->certify([
            'p5' => true,
            'p1' => true,
            'p2' => true,
            'p3' => true,
            'p4' => true,
        ]);

        $this->assertTrue($result['certified']);
        $this->assertSame('l8_transcended', $result['status']);
        $this->assertSame(5, $result['pillars_certified_count']);
        // A certified bool pillar with no explicit ref gets a deterministic met marker.
        $this->assertSame(
            [
                'evidence://l8/transcendence/p5/met',
                'evidence://l8/transcendence/p1/met',
                'evidence://l8/transcendence/p2/met',
                'evidence://l8/transcendence/p3/met',
                'evidence://l8/transcendence/p4/met',
            ],
            $result['evidence_refs'],
        );
    }

    public function testUncertifiedPillarCarriesBlockedEvidenceMarkerAndIsAbsentFromEvidenceRefs(): void
    {
        $inputs = $this->allPillarsCertified();
        $inputs['p3'] = false;

        $result = $this->service->certify($inputs);

        $pillarsByKey = [];
        foreach ($result['pillars'] as $pillar) {
            $pillarsByKey[$pillar['key']] = $pillar;
        }

        $this->assertFalse($pillarsByKey['p3']['certified']);
        $this->assertSame('evidence://l8/transcendence/p3/blocked', $pillarsByKey['p3']['evidence_ref']);
        // The blocked pillar's marker is never advertised as proof.
        $this->assertNotContains('evidence://l8/transcendence/p3/blocked', $result['evidence_refs']);
        // Each pillar reports its originating slice.
        $this->assertSame('S121', $pillarsByKey['p3']['slice']);
        $this->assertSame('S106', $pillarsByKey['p5']['slice']);
    }

    public function testEmptyInputsBlockAllFivePillars(): void
    {
        $result = $this->service->certify([]);

        $this->assertFalse($result['p5']);
        $this->assertFalse($result['p1']);
        $this->assertFalse($result['p2']);
        $this->assertFalse($result['p3']);
        $this->assertFalse($result['p4']);
        $this->assertFalse($result['certified']);
        $this->assertSame(0, $result['pillars_certified_count']);
        $this->assertSame(
            [
                'p5_not_certified',
                'p1_not_certified',
                'p2_not_certified',
                'p3_not_certified',
                'p4_not_certified',
            ],
            $result['blockers'],
        );
        $this->assertSame([], $result['evidence_refs']);
    }

    public function testIdenticalInputsAreDeterministic(): void
    {
        $inputs = $this->allPillarsCertified();
        $inputs['p2'] = ['certified' => true, 'honest_merge' => false];

        $first = $this->service->certify($inputs);
        $second = $this->service->certify($inputs);

        $this->assertSame($first, $second);
    }

    public function testBlockersListIsAlwaysAListEvenWithGaps(): void
    {
        $result = $this->service->certify([
            'p5' => true,
            'p1' => false,
            'p2' => true,
            'p3' => false,
            'p4' => true,
        ]);

        $this->assertSame(array_values($result['blockers']), $result['blockers']);
        $this->assertSame(['p1_not_certified', 'p3_not_certified'], $result['blockers']);
    }
}
