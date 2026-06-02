<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L10SovereigntyAndConvergencePreconditionRegistry;
use PHPUnit\Framework\TestCase;

final class L10SovereigntyAndConvergencePreconditionRegistryTest extends TestCase
{
    private L10SovereigntyAndConvergencePreconditionRegistry $registry;

    /** Context asserting both load-bearing preconditions satisfied. */
    private const BOTH_SATISFIED = [
        'operator_sovereignty' => true,
        'convergence_bound' => ['proven' => true],
    ];

    protected function setUp(): void
    {
        $this->registry = new L10SovereigntyAndConvergencePreconditionRegistry();
    }

    public function testListReturnsSchemaVersion(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertSame(
            'atlas.aaeos.l10.sovereignty_and_convergence_precondition.v1',
            $result['schema_version'],
        );
        $this->assertSame('precondition', $result['phase']);
    }

    public function testListReturnsTheTwoLoadBearingPreconditionIds(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertSame(
            [
                'l10.operator_sovereignty',
                'l10.proven_convergence_bound',
            ],
            $result['precondition_ids'],
        );
        $this->assertContains('l10.operator_sovereignty', $result['precondition_ids']);
        $this->assertContains('l10.proven_convergence_bound', $result['precondition_ids']);
        $this->assertCount(2, $result['precondition_ids']);
    }

    public function testListReturnsStatementThatBoundAndSovereigntyPrecedeEveryPillar(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertSame(
            'A proven convergence bound and operator sovereignty are the two load-bearing preconditions for L10; bound and sovereignty precede every L10 pillar and can never be transcended.',
            $result['statement'],
        );
    }

    public function testListReturnsProtectedPillars(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertSame(
            [
                'r1_generative_engineering',
                'r2_long_horizon_strategy',
                'r3_bounded_recursive_self_improvement',
                'r4_operator_atlas_fusion',
            ],
            $result['protected_pillars'],
        );
    }

    public function testBoundAndSovereigntyPrecedeEveryL10Pillar(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        // DoD: bound and sovereignty precede EVERY L10 pillar (R1-R4) — each one
        // is gated by these two preconditions.
        $this->assertContains('r1_generative_engineering', $result['protected_pillars']);
        $this->assertContains('r2_long_horizon_strategy', $result['protected_pillars']);
        $this->assertContains('r3_bounded_recursive_self_improvement', $result['protected_pillars']);
        $this->assertContains('r4_operator_atlas_fusion', $result['protected_pillars']);
        $this->assertCount(4, $result['protected_pillars']);
    }

    public function testListReturnsRequiredLowerEvidence(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertSame(
            [
                'l8_p5_self_deception_immunity',
                'l8_transcendence_certification',
                'l9_q2_proven_invariants',
                'l9_sovereign_engineering_certification',
            ],
            $result['required_lower_evidence'],
        );
        // The proven bound rests on P5 (L8) and Q2 (L9).
        $this->assertContains('l8_p5_self_deception_immunity', $result['required_lower_evidence']);
        $this->assertContains('l9_q2_proven_invariants', $result['required_lower_evidence']);
    }

    public function testImmovableIsAlwaysTrue(): void
    {
        $withBoth = $this->registry->list(self::BOTH_SATISFIED);
        $withNeither = $this->registry->list([]);

        $this->assertTrue($withBoth['immovable']);
        $this->assertTrue($withNeither['immovable']);
        $this->assertTrue($withBoth['override_required']);
    }

    public function testSystemAsSourceOfEndsIsForbidden(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        $this->assertContains('system_as_source_of_ends', $result['forbidden_system_actions']);
        $this->assertTrue($result['system_as_source_of_ends_forbidden']);
        $this->assertTrue($this->registry->forbids('system_as_source_of_ends'));
    }

    public function testMissingSovereigntyPreconditionBlocks(): void
    {
        // Convergence bound proven, but sovereignty absent.
        $result = $this->registry->list(['convergence_bound' => ['proven' => true]]);

        $this->assertFalse($result['sovereignty_present']);
        $this->assertTrue($result['convergence_bound_proven']);
        $this->assertFalse($result['certifiable']);
        $this->assertContains('sovereignty_precondition_missing', $result['blockers']);
        $this->assertNotContains('convergence_bound_precondition_missing', $result['blockers']);
    }

    public function testMissingConvergenceBoundPreconditionBlocks(): void
    {
        // Sovereignty present, but convergence bound absent.
        $result = $this->registry->list(['operator_sovereignty' => true]);

        $this->assertTrue($result['sovereignty_present']);
        $this->assertFalse($result['convergence_bound_proven']);
        $this->assertFalse($result['certifiable']);
        $this->assertContains('convergence_bound_precondition_missing', $result['blockers']);
        $this->assertNotContains('sovereignty_precondition_missing', $result['blockers']);
    }

    public function testExplicitlyUnprovenConvergenceBoundBlocks(): void
    {
        // proven=false must override the array being present — fail-closed.
        $result = $this->registry->list([
            'operator_sovereignty' => true,
            'convergence_bound' => ['present' => true, 'proven' => false],
        ]);

        $this->assertFalse($result['convergence_bound_proven']);
        $this->assertFalse($result['certifiable']);
        $this->assertSame(['convergence_bound_precondition_missing'], $result['blockers']);
    }

    public function testConvergenceBoundPresentButUnprovenDoesNotCertify(): void
    {
        // A bound that is merely PRESENT (or satisfied) but not PROVEN must not
        // satisfy `l10.proven_convergence_bound` — presence never proves a bound,
        // and recursion under an unproven bound is the definition of runaway.
        $presentOnly = $this->registry->list([
            'operator_sovereignty' => true,
            'convergence_bound' => ['present' => true],
        ]);

        $this->assertFalse($presentOnly['convergence_bound_proven']);
        $this->assertFalse($presentOnly['certifiable']);
        $this->assertSame(['convergence_bound_precondition_missing'], $presentOnly['blockers']);

        $satisfiedOnly = $this->registry->list([
            'operator_sovereignty' => true,
            'convergence_bound' => ['satisfied' => true],
        ]);

        $this->assertFalse($satisfiedOnly['convergence_bound_proven']);
        $this->assertFalse($satisfiedOnly['certifiable']);
        $this->assertSame(['convergence_bound_precondition_missing'], $satisfiedOnly['blockers']);
    }

    public function testExplicitlyUnprovenBoundDominatesAContradictoryTruthySignal(): void
    {
        // Fail-closed must win: an explicit proven=false anywhere blocks even when
        // another location asserts the bound proven.
        $topLevelFalseNestedTrue = $this->registry->list([
            'operator_sovereignty' => true,
            'convergence_bound' => ['proven' => false],
            'preconditions' => ['convergence_bound' => ['proven' => true]],
        ]);

        $this->assertFalse($topLevelFalseNestedTrue['convergence_bound_proven']);
        $this->assertFalse($topLevelFalseNestedTrue['certifiable']);
        $this->assertContains(
            'convergence_bound_precondition_missing',
            $topLevelFalseNestedTrue['blockers'],
        );

        $nestedFalseTopLevelTrue = $this->registry->list([
            'operator_sovereignty' => true,
            'convergence_bound' => ['proven' => true],
            'preconditions' => ['convergence_bound' => ['proven' => false]],
        ]);

        $this->assertFalse($nestedFalseTopLevelTrue['convergence_bound_proven']);
        $this->assertFalse($nestedFalseTopLevelTrue['certifiable']);
    }

    public function testNonBooleanTruthySignalsDoNotAssertPreconditions(): void
    {
        // Only strict booleans/`=== true` flags count; truthy scalars must not.
        $result = $this->registry->list([
            'operator_sovereignty' => 1,
            'convergence_bound' => 'proven',
        ]);

        $this->assertFalse($result['sovereignty_present']);
        $this->assertFalse($result['convergence_bound_proven']);
        $this->assertSame(
            [
                'sovereignty_precondition_missing',
                'convergence_bound_precondition_missing',
            ],
            $result['blockers'],
        );
    }

    public function testReturnedListFieldsAreWellTypedStringLists(): void
    {
        $result = $this->registry->list(self::BOTH_SATISFIED);

        foreach ([
            'precondition_ids',
            'protected_pillars',
            'required_lower_evidence',
            'forbidden_system_actions',
            'blockers',
        ] as $field) {
            $value = $result[$field];
            $this->assertIsArray($value);
            $this->assertTrue(array_is_list($value), "{$field} must be a list");
            foreach ($value as $item) {
                $this->assertIsString($item, "{$field} entries must be strings");
            }
        }

        $this->assertIsBool($result['immovable']);
        $this->assertIsBool($result['override_required']);
        $this->assertIsBool($result['system_as_source_of_ends_forbidden']);
        $this->assertIsBool($result['sovereignty_present']);
        $this->assertIsBool($result['convergence_bound_proven']);
        $this->assertIsBool($result['certifiable']);
    }

    public function testBothPreconditionsMissingProducesBothBlockers(): void
    {
        $result = $this->registry->list([]);

        $this->assertFalse($result['sovereignty_present']);
        $this->assertFalse($result['convergence_bound_proven']);
        $this->assertFalse($result['certifiable']);
        $this->assertSame(
            [
                'sovereignty_precondition_missing',
                'convergence_bound_precondition_missing',
            ],
            $result['blockers'],
        );
    }

    public function testBothPreconditionsSatisfiedCertifiesWithNoBlockers(): void
    {
        $result = $this->registry->list([
            'operator_sovereignty' => ['present' => true],
            'convergence_bound' => ['proven' => true],
        ]);

        $this->assertTrue($result['sovereignty_present']);
        $this->assertTrue($result['convergence_bound_proven']);
        $this->assertTrue($result['certifiable']);
        $this->assertSame([], $result['blockers']);
    }

    public function testPreconditionsAcceptedViaNestedPreconditionsArray(): void
    {
        $result = $this->registry->list([
            'preconditions' => [
                'operator_sovereignty' => ['satisfied' => true],
                'convergence_bound' => ['proven' => true],
            ],
        ]);

        $this->assertTrue($result['sovereignty_present']);
        $this->assertTrue($result['convergence_bound_proven']);
        $this->assertTrue($result['certifiable']);
        $this->assertSame([], $result['blockers']);
    }

    public function testForbidsGeneralisesAcrossActions(): void
    {
        // Real membership: every forbidden action is rejected, anything else is not.
        $this->assertTrue($this->registry->forbids('choose_engineering_telos'));
        $this->assertTrue($this->registry->forbids('own_engineering_values'));
        $this->assertTrue($this->registry->forbids('authorize_own_engineering_ends'));
        $this->assertFalse($this->registry->forbids('articulate_operator_intent'));
        $this->assertFalse($this->registry->forbids('propose_engineering_strategy'));
        $this->assertFalse($this->registry->forbids('run_unit_tests'));
    }

    public function testIdenticalContextIsDeterministic(): void
    {
        $context = [
            'operator_sovereignty' => ['present' => true],
            'convergence_bound' => ['proven' => true],
        ];

        $first = $this->registry->list($context);
        $second = $this->registry->list($context);

        $this->assertSame($first, $second);
    }
}
