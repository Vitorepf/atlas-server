<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9SovereigntyInvariantRegistry;
use PHPUnit\Framework\TestCase;

final class L9SovereigntyInvariantRegistryTest extends TestCase
{
    private L9SovereigntyInvariantRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new L9SovereigntyInvariantRegistry();
    }

    public function testListReturnsSchemaVersionAndInvariantId(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        $this->assertSame('atlas.aaeos.l9.sovereignty_invariant.v1', $result['schema_version']);
        $this->assertSame('l9.operator_sole_value_source', $result['invariant_id']);
    }

    public function testListReturnsStatementThatOperatorIsSoleSourceOfValuesAndEnds(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        $this->assertSame(
            'The operator is the sole source of engineering values and ends; the system may articulate operator criteria but never chooses engineering ends.',
            $result['statement'],
        );
    }

    public function testListReturnsProtectedDecisions(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        $this->assertSame(
            [
                'engineering_ends',
                'engineering_north_star',
                'engineering_taste',
                'engineering_values',
                'quality_bar',
                'sovereignty_of_engineering',
            ],
            $result['protected_decisions'],
        );
        $this->assertContains('engineering_ends', $result['protected_decisions']);
        $this->assertContains('engineering_values', $result['protected_decisions']);
    }

    public function testListReturnsForbiddenSystemActions(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        $this->assertSame(
            [
                'authorize_own_engineering_values',
                'choose_engineering_ends',
                'override_operator_values',
                'set_engineering_north_star',
                'system_as_value_source',
            ],
            $result['forbidden_system_actions'],
        );
    }

    public function testOverrideRequiredIsAlwaysTrue(): void
    {
        $withAuthority = $this->registry->list(['operator_authority' => true]);
        $withoutAuthority = $this->registry->list([]);

        $this->assertTrue($withAuthority['override_required']);
        $this->assertTrue($withoutAuthority['override_required']);
    }

    public function testSystemAsValueSourceIsForbidden(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        $this->assertContains('system_as_value_source', $result['forbidden_system_actions']);
        $this->assertTrue($result['system_as_value_source_forbidden']);
        $this->assertTrue($this->registry->forbids('system_as_value_source'));
    }

    public function testMissingOperatorAuthorityBlocksCertification(): void
    {
        $result = $this->registry->list([]);

        $this->assertFalse($result['operator_authority_present']);
        $this->assertFalse($result['certifiable']);
        $this->assertSame(['operator_authority_missing'], $result['blockers']);
    }

    public function testExplicitlyFalseOperatorAuthorityBlocksCertification(): void
    {
        $result = $this->registry->list(['operator_authority' => ['present' => false]]);

        $this->assertFalse($result['operator_authority_present']);
        $this->assertFalse($result['certifiable']);
        $this->assertSame(['operator_authority_missing'], $result['blockers']);
    }

    public function testPresentOperatorAuthorityCertifiesWithNoBlockers(): void
    {
        $result = $this->registry->list(['operator_authority' => ['present' => true, 'operator_id' => 'vitor']]);

        $this->assertTrue($result['operator_authority_present']);
        $this->assertTrue($result['certifiable']);
        $this->assertSame([], $result['blockers']);
    }

    public function testOperatorIdAloneAssertsAuthority(): void
    {
        $result = $this->registry->list(['operator_id' => 'vitor']);

        $this->assertTrue($result['operator_authority_present']);
        $this->assertTrue($result['certifiable']);
        $this->assertSame([], $result['blockers']);
    }

    public function testBlankOperatorIdDoesNotAssertAuthorityAndStaysFailClosed(): void
    {
        // A whitespace-only operator id is a blank, meaning-free identity: it must
        // NOT be mistaken for a present sovereign operator. The sovereignty
        // invariant is about WHO is the source of value, so a blank identity has
        // to take the fail-closed path, not an accidental certification.
        foreach (["   ", "\t", "\n", " \t \n "] as $blank) {
            $topLevel = $this->registry->list(['operator_id' => $blank]);
            $this->assertFalse($topLevel['operator_authority_present']);
            $this->assertFalse($topLevel['certifiable']);
            $this->assertSame(['operator_authority_missing'], $topLevel['blockers']);

            $nested = $this->registry->list(['operator_authority' => ['operator_id' => $blank]]);
            $this->assertFalse($nested['operator_authority_present']);
            $this->assertFalse($nested['certifiable']);
            $this->assertSame(['operator_authority_missing'], $nested['blockers']);

            $nestedId = $this->registry->list(['operator_authority' => ['id' => $blank]]);
            $this->assertFalse($nestedId['operator_authority_present']);
            $this->assertFalse($nestedId['certifiable']);
        }
    }

    public function testNumericStringOperatorIdStillAssertsAuthority(): void
    {
        // "0" is a legitimate, non-blank operator identifier; it must still certify
        // (the fix targets blank/whitespace ids, not falsy-looking real ids).
        $result = $this->registry->list(['operator_id' => '0']);

        $this->assertTrue($result['operator_authority_present']);
        $this->assertTrue($result['certifiable']);
        $this->assertSame([], $result['blockers']);
    }

    public function testSystemMayArticulateOperatorCriteriaButNeverChooseEngineeringEnds(): void
    {
        $result = $this->registry->list(['operator_authority' => true]);

        // May articulate operator criteria...
        $this->assertContains('articulate_operator_criteria', $result['permitted_system_actions']);
        $this->assertFalse($this->registry->forbids('articulate_operator_criteria'));

        // ...never choose engineering ends.
        $this->assertContains('choose_engineering_ends', $result['forbidden_system_actions']);
        $this->assertTrue($this->registry->forbids('choose_engineering_ends'));
        $this->assertNotContains('choose_engineering_ends', $result['permitted_system_actions']);
    }

    public function testForbidsGeneralisesToUnknownAction(): void
    {
        $this->assertFalse($this->registry->forbids('run_unit_tests'));
        $this->assertFalse($this->registry->forbids('propose_options_for_operator_decision'));
        $this->assertTrue($this->registry->forbids('override_operator_values'));
    }

    public function testIdenticalContextIsDeterministic(): void
    {
        $context = ['operator_authority' => ['present' => true, 'operator_id' => 'vitor']];

        $first = $this->registry->list($context);
        $second = $this->registry->list($context);

        $this->assertSame($first, $second);
    }
}
