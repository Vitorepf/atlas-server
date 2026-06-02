<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9ParallelEngineeringLineageSandboxPlanner;
use PHPUnit\Framework\TestCase;

final class L9ParallelEngineeringLineageSandboxPlannerTest extends TestCase
{
    private L9ParallelEngineeringLineageSandboxPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new L9ParallelEngineeringLineageSandboxPlanner();
    }

    /**
     * A proven Q2 proof boundary (the precondition that makes Q3 sandboxes safe).
     *
     * @return array<string, mixed>
     */
    private function q2Boundary(): array
    {
        return [
            'q2_certified' => true,
            'boundary_id' => 'q2://proven/invariant-set/v1',
        ];
    }

    /**
     * Two distinct in-scope AAEOS engineering lineages, neither asking to bypass
     * the merge gate.
     *
     * @return list<array<string, mixed>>
     */
    private function twoEngineeringLineages(): array
    {
        return [
            ['name' => 'tdd_first_lineage', 'scope' => 'aaeos_engineering'],
            ['name' => 'property_test_lineage', 'scope' => 'software_engineering'],
        ];
    }

    public function testPlanReturnsRequiredPerLineageKeysWithMergeForbiddenAndTransferGated(): void
    {
        $result = $this->planner->plan($this->twoEngineeringLineages(), $this->q2Boundary());

        $this->assertSame(
            'atlas.aaeos.l9.parallel_engineering_lineage_sandbox_plan.v1',
            $result['schema_version'],
        );
        $this->assertSame('L9-Q3', $result['phase']);

        // Top-level shape.
        $this->assertArrayHasKey('lineages', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertTrue($result['planned']);
        $this->assertSame(2, $result['lineage_count']);
        $this->assertSame(2, $result['admitted_count']);
        $this->assertSame(0, $result['rejected_count']);
        $this->assertSame([], $result['blockers']);

        // Every lineage carries the Acceptance-row keys with the mandated values.
        $this->assertCount(2, $result['lineages']);
        foreach ($result['lineages'] as $lineage) {
            $this->assertArrayHasKey('lineage_id', $lineage);
            $this->assertArrayHasKey('sandbox_scope', $lineage);
            $this->assertArrayHasKey('blockers', $lineage);

            $this->assertIsString($lineage['lineage_id']);
            $this->assertNotSame('', $lineage['lineage_id']);
            $this->assertIsString($lineage['sandbox_scope']);
            $this->assertNotSame('', $lineage['sandbox_scope']);

            // merge_allowed is ALWAYS false; transfer_gate_required is ALWAYS true.
            $this->assertFalse($lineage['merge_allowed']);
            $this->assertTrue($lineage['transfer_gate_required']);

            $this->assertSame('admitted_for_sandbox', $lineage['status']);
            $this->assertSame([], $lineage['blockers']);
        }

        // The two sandbox scopes are distinct and namespaced to each lineage.
        $this->assertSame('sandbox/tdd_first_lineage', $result['lineages'][0]['sandbox_scope']);
        $this->assertSame('sandbox/property_test_lineage', $result['lineages'][1]['sandbox_scope']);
    }

    public function testMissingQ2BoundaryBlocksTheWholePlan(): void
    {
        // No Q2 proof boundary => no sandbox is proven safe => the plan blocks
        // and NO lineage is admitted for exploration.
        $result = $this->planner->plan($this->twoEngineeringLineages(), []);

        $this->assertFalse($result['planned']);
        $this->assertFalse($result['q2_boundary_present']);
        $this->assertSame(0, $result['admitted_count']);
        $this->assertContains('q2_boundary_missing', $result['blockers']);

        // Both well-formed lineages are blocked (not admitted) for the Q2 gap.
        $this->assertSame(2, $result['lineage_count']);
        foreach ($result['lineages'] as $lineage) {
            $this->assertSame('blocked', $lineage['status']);
            $this->assertSame(['blocked_q2_missing'], $lineage['blockers']);
            // Even when blocked, no merge authority is granted.
            $this->assertFalse($lineage['merge_allowed']);
            $this->assertTrue($lineage['transfer_gate_required']);
        }
    }

    public function testMissingQ2DoesNotLeakScopeOrMergeBlockersIntoTopLevel(): void
    {
        // When Q2 is missing the per-lineage path short-circuits: every lineage
        // is `blocked` (not rejected) and its scope/merge rules are never
        // evaluated (rejected_count == 0). The top-level `blockers` roll-up must
        // mirror that fail-closed precedence — the ONLY top-level blocker is the
        // Q2 gap. It must NOT leak non_aaeos_lineage_rejected /
        // direct_main_merge_forbidden for lineages that were never rejected.
        $result = $this->planner->plan(
            [
                ['name' => 'eng', 'scope' => 'aaeos_engineering'],
                // Non-AAEOS scope AND a direct main merge request — both rejection
                // categories would fire IF Q2 were present, but Q2 is missing.
                ['name' => 'mkt', 'scope' => 'marketing', 'requests_direct_main_merge' => true],
            ],
            [], // Q2 MISSING
        );

        $this->assertFalse($result['planned']);
        $this->assertFalse($result['q2_boundary_present']);
        $this->assertSame(0, $result['admitted_count']);
        // No lineage is rejected — they are all blocked on the Q2 gap.
        $this->assertSame(0, $result['rejected_count']);

        // The top-level blockers are EXACTLY the Q2 gap, in canonical order.
        $this->assertSame(['q2_boundary_missing'], $result['blockers']);
        $this->assertNotContains('non_aaeos_lineage_rejected', $result['blockers']);
        $this->assertNotContains('direct_main_merge_forbidden', $result['blockers']);

        // Every lineage is blocked with only the Q2-missing marker.
        foreach ($result['lineages'] as $lineage) {
            $this->assertSame('blocked', $lineage['status']);
            $this->assertSame(['blocked_q2_missing'], $lineage['blockers']);
        }
    }

    public function testExplicitlyUncertifiedQ2BoundaryAlsoBlocks(): void
    {
        // A boundary object that is present in shape but explicitly NOT certified
        // must NOT count as a proven boundary (fail-closed).
        $result = $this->planner->plan(
            $this->twoEngineeringLineages(),
            ['q2_certified' => false, 'boundary_id' => 'draft'],
        );

        $this->assertFalse($result['planned']);
        $this->assertFalse($result['q2_boundary_present']);
        $this->assertContains('q2_boundary_missing', $result['blockers']);
        $this->assertSame(0, $result['admitted_count']);
    }

    public function testDirectMainMergeIsForbidden(): void
    {
        // A lineage that explicitly asks to merge straight into main bypasses
        // merge governance and must be rejected.
        $result = $this->planner->plan(
            [
                ['name' => 'good_lineage', 'scope' => 'aaeos_engineering'],
                ['name' => 'bypasser', 'scope' => 'aaeos_engineering', 'requests_direct_main_merge' => true],
            ],
            $this->q2Boundary(),
        );

        $this->assertFalse($result['planned']);
        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertContains('direct_main_merge_forbidden', $result['blockers']);

        $byName = $this->byName($result['lineages']);
        $this->assertSame('admitted_for_sandbox', $byName['good_lineage']['status']);
        $this->assertSame('rejected', $byName['bypasser']['status']);
        $this->assertTrue($byName['bypasser']['requests_direct_main_merge']);
        $this->assertSame(['direct_main_merge_forbidden'], $byName['bypasser']['blockers']);
        // The rejected lineage is still denied merge authority.
        $this->assertFalse($byName['bypasser']['merge_allowed']);
    }

    public function testDirectMainMergeViaMergeTargetIsForbidden(): void
    {
        // Declaring a merge target that resolves to the protected main line is
        // the same forbidden bypass, even without the explicit flag. Proves the
        // rule generalises beyond a single boolean key.
        $result = $this->planner->plan(
            [['name' => 'target_main', 'scope' => 'engineering', 'merge_target' => 'main']],
            $this->q2Boundary(),
        );

        $this->assertFalse($result['planned']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertContains('direct_main_merge_forbidden', $result['blockers']);
        $this->assertSame(
            ['direct_main_merge_forbidden'],
            $result['lineages'][0]['blockers'],
        );
    }

    public function testNonAaeosLineageRejects(): void
    {
        // A lineage whose scope is a non-engineering domain (marketing) is NOT a
        // valid L9 engineering lineage and must be rejected: L9 is sovereign
        // engineering only, never domain expansion.
        $result = $this->planner->plan(
            [
                ['name' => 'eng_lineage', 'scope' => 'aaeos_engineering'],
                ['name' => 'marketing_lineage', 'scope' => 'marketing'],
            ],
            $this->q2Boundary(),
        );

        $this->assertFalse($result['planned']);
        $this->assertSame(1, $result['admitted_count']);
        $this->assertSame(1, $result['rejected_count']);
        $this->assertContains('non_aaeos_lineage_rejected', $result['blockers']);

        $byName = $this->byName($result['lineages']);
        $this->assertSame('admitted_for_sandbox', $byName['eng_lineage']['status']);
        $this->assertTrue($byName['eng_lineage']['is_aaeos_engineering']);

        $this->assertSame('rejected', $byName['marketing_lineage']['status']);
        $this->assertFalse($byName['marketing_lineage']['is_aaeos_engineering']);
        $this->assertSame(['non_aaeos_lineage_rejected'], $byName['marketing_lineage']['blockers']);
    }

    public function testNonAaeosScopeGeneralisesAcrossForbiddenDomains(): void
    {
        // The non-engineering rejection must hold for ANY external domain, not a
        // single hard-coded marketing string. Each of these rejects with the
        // same blocker, and an in-scope engineering lineage still admits.
        foreach (['finance', 'cyber', 'trading', 'external_company', 'domain_generator'] as $domain) {
            $result = $this->planner->plan(
                [['name' => 'x_'.$domain, 'scope' => $domain]],
                $this->q2Boundary(),
            );

            $this->assertFalse(
                $result['planned'],
                "scope {$domain} must not be a valid AAEOS lineage",
            );
            $this->assertSame(1, $result['rejected_count']);
            $this->assertFalse($result['lineages'][0]['is_aaeos_engineering']);
            $this->assertSame(
                ['non_aaeos_lineage_rejected'],
                $result['lineages'][0]['blockers'],
            );
        }
    }

    public function testEmptyScopeIsTreatedAsNonAaeosAndRejects(): void
    {
        // A lineage with no declared scope cannot be asserted to be engineering;
        // fail-closed and reject it as non-AAEOS.
        $result = $this->planner->plan(
            [['name' => 'no_scope_lineage']],
            $this->q2Boundary(),
        );

        $this->assertSame(1, $result['rejected_count']);
        $this->assertFalse($result['lineages'][0]['is_aaeos_engineering']);
        $this->assertSame('', $result['lineages'][0]['scope']);
        $this->assertSame(['non_aaeos_lineage_rejected'], $result['lineages'][0]['blockers']);
    }

    public function testLineageWithBothViolationsCarriesBothBlockersInCanonicalOrder(): void
    {
        // A single lineage that is BOTH out of scope AND asks for a direct main
        // merge must surface both blockers, scope-first (canonical fail order).
        $result = $this->planner->plan(
            [['name' => 'double_bad', 'scope' => 'finance', 'requests_direct_main_merge' => true]],
            $this->q2Boundary(),
        );

        $this->assertSame(1, $result['rejected_count']);
        $this->assertSame(
            ['non_aaeos_lineage_rejected', 'direct_main_merge_forbidden'],
            $result['lineages'][0]['blockers'],
        );
        // Top-level blockers also surface both categories.
        $this->assertContains('non_aaeos_lineage_rejected', $result['blockers']);
        $this->assertContains('direct_main_merge_forbidden', $result['blockers']);
    }

    public function testNamelessLineagesAreDroppedAndEmptyPlanDoesNotExecute(): void
    {
        // A nameless entry is not a real lineage and is dropped; a plan with no
        // real lineages does not execute (nothing to explore), but Q2 being
        // present means there is no Q2 blocker.
        $result = $this->planner->plan(
            [['scope' => 'aaeos_engineering'], 'not-an-array', []],
            $this->q2Boundary(),
        );

        $this->assertSame(0, $result['lineage_count']);
        $this->assertSame([], $result['lineages']);
        $this->assertFalse($result['planned']);
        $this->assertSame([], $result['blockers']);
    }

    public function testMergeIsNeverAllowedEvenWhenEveryLineageIsAdmitted(): void
    {
        // The DoD invariant: parallel lineages explore but NEVER bypass merge
        // governance. No admitted lineage may ever carry merge authority, and a
        // transfer always remains gated.
        $result = $this->planner->plan(
            [
                ['name' => 'a', 'scope' => 'aaeos_engineering'],
                ['name' => 'b', 'scope' => 'engineering'],
                ['name' => 'c', 'scope' => 'software_engineering'],
            ],
            $this->q2Boundary(),
        );

        $this->assertTrue($result['planned']);
        $this->assertSame(3, $result['admitted_count']);
        foreach ($result['lineages'] as $lineage) {
            $this->assertFalse($lineage['merge_allowed']);
            $this->assertTrue($lineage['transfer_gate_required']);
        }
    }

    public function testLineageIdIsDeterministicContentDigestNotCanned(): void
    {
        // Same name+scope => same id across calls; different name => different id.
        $first = $this->planner->plan(
            [['name' => 'alpha', 'scope' => 'aaeos_engineering']],
            $this->q2Boundary(),
        );
        $second = $this->planner->plan(
            [['name' => 'alpha', 'scope' => 'aaeos_engineering']],
            $this->q2Boundary(),
        );
        $other = $this->planner->plan(
            [['name' => 'beta', 'scope' => 'aaeos_engineering']],
            $this->q2Boundary(),
        );

        $this->assertSame(
            $first['lineages'][0]['lineage_id'],
            $second['lineages'][0]['lineage_id'],
        );
        $this->assertNotSame(
            $first['lineages'][0]['lineage_id'],
            $other['lineages'][0]['lineage_id'],
        );
        $this->assertStringStartsWith('lineage_', $first['lineages'][0]['lineage_id']);
    }

    public function testCountsNeverExceedLineageCountAndPartitionCleanly(): void
    {
        // admitted + rejected (when Q2 present) equals the lineage count: counts
        // never overstate reality and the partition is exact. Mix of admit/reject.
        $result = $this->planner->plan(
            [
                ['name' => 'ok1', 'scope' => 'aaeos_engineering'],
                ['name' => 'bad_scope', 'scope' => 'marketing'],
                ['name' => 'bad_merge', 'scope' => 'engineering', 'merge_target' => 'master'],
                ['name' => 'ok2', 'scope' => 'software_engineering'],
            ],
            $this->q2Boundary(),
        );

        $this->assertSame(4, $result['lineage_count']);
        $this->assertSame(2, $result['admitted_count']);
        $this->assertSame(2, $result['rejected_count']);
        $this->assertSame(
            $result['lineage_count'],
            $result['admitted_count'] + $result['rejected_count'],
        );
        $this->assertLessThanOrEqual($result['lineage_count'], $result['admitted_count']);
    }

    public function testResultContractsAreHonoured(): void
    {
        $result = $this->planner->plan($this->twoEngineeringLineages(), $this->q2Boundary());

        $this->assertIsBool($result['planned']);
        $this->assertIsBool($result['q2_boundary_present']);
        $this->assertIsInt($result['lineage_count']);
        $this->assertIsInt($result['admitted_count']);
        $this->assertIsInt($result['rejected_count']);
        $this->assertIsList($result['blockers']);
        $this->assertIsList($result['lineages']);

        foreach ($result['blockers'] as $blocker) {
            $this->assertIsString($blocker);
        }
        foreach ($result['lineages'] as $lineage) {
            $this->assertIsString($lineage['lineage_id']);
            $this->assertIsString($lineage['sandbox_scope']);
            $this->assertIsString($lineage['scope']);
            $this->assertIsString($lineage['status']);
            $this->assertIsBool($lineage['merge_allowed']);
            $this->assertIsBool($lineage['transfer_gate_required']);
            $this->assertIsBool($lineage['requests_direct_main_merge']);
            $this->assertIsBool($lineage['is_aaeos_engineering']);
            $this->assertIsList($lineage['blockers']);
            foreach ($lineage['blockers'] as $blocker) {
                $this->assertIsString($blocker);
            }
        }
    }

    public function testPlanIsDeterministicAndDistinctInputsDiffer(): void
    {
        $lineages = $this->twoEngineeringLineages();

        $first = $this->planner->plan($lineages, $this->q2Boundary());
        $second = $this->planner->plan($lineages, $this->q2Boundary());
        $this->assertSame($first, $second);

        // Removing the Q2 boundary must change the verdict (no canned output).
        $blocked = $this->planner->plan($lineages, []);
        $this->assertNotSame($first['planned'], $blocked['planned']);
        $this->assertNotSame($first['admitted_count'], $blocked['admitted_count']);
    }

    /**
     * Index lineages by their `name` for direct assertions.
     *
     * @param  list<array<string, mixed>>  $lineages
     * @return array<string, array<string, mixed>>
     */
    private function byName(array $lineages): array
    {
        $byName = [];
        foreach ($lineages as $lineage) {
            $byName[$lineage['name']] = $lineage;
        }

        return $byName;
    }
}
