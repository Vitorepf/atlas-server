<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\SelfExpanding;

use App\Services\Ai\SoftwareCompanyStewardship\SelfExpanding\SelfConstructionBuildVsReuseDecider;
use PHPUnit\Framework\TestCase;

final class SelfConstructionBuildVsReuseDeciderTest extends TestCase
{
    private SelfConstructionBuildVsReuseDecider $decider;

    protected function setUp(): void
    {
        $this->decider = new SelfConstructionBuildVsReuseDecider();
    }

    /**
     * @return list<array{id: string, capability: string, owner_doc: string}>
     */
    private function primitives(): array
    {
        return [
            [
                'id' => 'prim_evidence_ledger',
                'capability' => 'append evidence ledger receipts',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-evidence-ledger.md',
            ],
            [
                'id' => 'prim_provider_topology',
                'capability' => 'route provider topology selection',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-forge-provider-topology.md',
            ],
        ];
    }

    public function testReturnShapeMatchesSchema(): void
    {
        $result = $this->decider->decide(
            ['capability' => 'append evidence receipts', 'new_surface' => null, 'consumed_primitives' => []],
            $this->primitives(),
        );

        $this->assertSame('atlas.self_construction.build_vs_reuse.v1', $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('matched_primitive', $result);
        $this->assertArrayHasKey('reuse_owner_doc', $result);
        $this->assertArrayHasKey('overlap_keywords', $result);
        $this->assertArrayHasKey('reason', $result);
    }

    public function testRuleOneCapabilityOverlapWithoutNewSurfaceReuses(): void
    {
        $result = $this->decider->decide(
            ['capability' => 'append evidence receipts', 'new_surface' => null, 'consumed_primitives' => []],
            $this->primitives(),
        );

        $this->assertSame('reuse', $result['verdict']);
        $this->assertSame('prim_evidence_ledger', $result['matched_primitive']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-evidence-ledger.md', $result['reuse_owner_doc']);
        $this->assertSame(['append', 'evidence', 'receipts'], $result['overlap_keywords']);
    }

    public function testReuseOwnerDocIsEchoedExactlyNotFabricated(): void
    {
        $primitives = [
            [
                'id' => 'prim_xyz',
                'capability' => 'schedule mission follow through cadence',
                'owner_doc' => 'docs/engineering-knowledge-base/atlas-mission-follow-through-CUSTOM-42.md',
            ],
        ];

        $result = $this->decider->decide(
            ['capability' => 'schedule mission cadence', 'new_surface' => null, 'consumed_primitives' => []],
            $primitives,
        );

        $this->assertSame('reuse', $result['verdict']);
        $this->assertSame('prim_xyz', $result['matched_primitive']);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-mission-follow-through-CUSTOM-42.md',
            $result['reuse_owner_doc'],
        );
    }

    public function testRuleTwoOverlapWithUncoveredNewSurfaceRequiresAdapter(): void
    {
        $result = $this->decider->decide(
            [
                'capability' => 'route provider topology',
                'new_surface' => 'streaming',
                'consumed_primitives' => [],
            ],
            $this->primitives(),
        );

        $this->assertSame('adapter_required', $result['verdict']);
        $this->assertSame('prim_provider_topology', $result['matched_primitive']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-forge-provider-topology.md', $result['reuse_owner_doc']);
        $this->assertStringContainsString('streaming', $result['reason']);
    }

    public function testNewSurfaceAlreadyCoveredFallsBackToReuse(): void
    {
        $result = $this->decider->decide(
            [
                'capability' => 'route provider topology',
                'new_surface' => 'selection',
                'consumed_primitives' => [],
            ],
            $this->primitives(),
        );

        $this->assertSame('reuse', $result['verdict']);
        $this->assertSame('prim_provider_topology', $result['matched_primitive']);
    }

    public function testRuleThreeZeroOverlapAndNoConsumptionBuildsNew(): void
    {
        $result = $this->decider->decide(
            [
                'capability' => 'render quarterly marketing dashboard',
                'new_surface' => null,
                'consumed_primitives' => [],
            ],
            $this->primitives(),
        );

        $this->assertSame('build_new', $result['verdict']);
        $this->assertNull($result['matched_primitive']);
        $this->assertNull($result['reuse_owner_doc']);
        $this->assertSame([], $result['overlap_keywords']);
    }

    public function testRuleFourPhantomConsumptionCannotUnlockBuildNew(): void
    {
        $result = $this->decider->decide(
            [
                'capability' => 'render quarterly marketing dashboard',
                'new_surface' => null,
                'consumed_primitives' => ['prim_does_not_exist'],
            ],
            $this->primitives(),
        );

        $this->assertNotSame('build_new', $result['verdict']);
        $this->assertSame('adapter_required', $result['verdict']);
        $this->assertNull($result['matched_primitive']);
    }

    public function testRuleFourNamedConsumedPrimitiveThatExistsReuses(): void
    {
        $result = $this->decider->decide(
            [
                'capability' => 'render quarterly marketing dashboard',
                'new_surface' => null,
                'consumed_primitives' => ['prim_provider_topology'],
            ],
            $this->primitives(),
        );

        $this->assertSame('reuse', $result['verdict']);
        $this->assertSame('prim_provider_topology', $result['matched_primitive']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-forge-provider-topology.md', $result['reuse_owner_doc']);
    }

    public function testRuleFiveMoreSpecificMatchWinsOnTwoPrimitiveFixture(): void
    {
        $primitives = [
            [
                'id' => 'prim_loose',
                'capability' => 'evidence audit',
                'owner_doc' => 'docs/loose.md',
            ],
            [
                'id' => 'prim_specific',
                'capability' => 'evidence audit ledger receipts append',
                'owner_doc' => 'docs/specific.md',
            ],
        ];

        $result = $this->decider->decide(
            [
                'capability' => 'evidence audit ledger receipts',
                'new_surface' => null,
                'consumed_primitives' => [],
            ],
            $primitives,
        );

        $this->assertSame('reuse', $result['verdict']);
        $this->assertSame('prim_specific', $result['matched_primitive']);
        $this->assertSame('docs/specific.md', $result['reuse_owner_doc']);
        $this->assertSame(['evidence', 'audit', 'ledger', 'receipts'], $result['overlap_keywords']);
    }

    public function testDecisionIsDeterministicForIdenticalInput(): void
    {
        $request = [
            'capability' => 'route provider topology',
            'new_surface' => 'streaming',
            'consumed_primitives' => [],
        ];
        $primitives = $this->primitives();

        $first = $this->decider->decide($request, $primitives);
        $second = $this->decider->decide($request, $primitives);

        $this->assertSame($first, $second);
    }
}
