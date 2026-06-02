<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8FrameChangeClassifier;
use PHPUnit\Framework\TestCase;

final class L8FrameChangeClassifierTest extends TestCase
{
    private L8FrameChangeClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new L8FrameChangeClassifier();
    }

    public function testClassifyReturnsCanonicalSchemaVersion(): void
    {
        $result = $this->classifier->classify(['internal_refactor' => true]);

        $this->assertSame('atlas.aaeos.l8.frame_change_classification.v1', $result['schema_version']);
    }

    public function testChangingPhasesReturnsStructuralFrameChange(): void
    {
        $result = $this->classifier->classify([
            'phase_count_before' => 16,
            'phase_count_after' => 17,
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertSame('architecture_evolution_proposal_runtime', $result['route']);
        $this->assertTrue($result['requires_structural_runbook']);
        $this->assertContains('alters_phase_count_or_identity', $result['structural_signals']);
    }

    public function testChangingDepartmentsReturnsStructuralFrameChange(): void
    {
        $result = $this->classifier->classify([
            'added_departments' => ['marketing', 'finance'],
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertContains('alters_department_count_or_identity', $result['structural_signals']);
    }

    public function testChangingRootCanonicalSchemaReturnsStructuralFrameChange(): void
    {
        $result = $this->classifier->classify([
            'root_schema_changes' => ['atlas.aaeos.phase.v1 -> atlas.aaeos.phase.v2'],
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertContains('alters_root_canonical_schema', $result['structural_signals']);
    }

    public function testInternalRefactorReturnsFeatureChange(): void
    {
        $result = $this->classifier->classify([
            'internal_refactor' => true,
        ]);

        $this->assertSame('feature_change', $result['classification']);
        $this->assertSame('self_construction_os_feature_path', $result['route']);
        $this->assertTrue($result['feature_path_allowed']);
        $this->assertContains('internal_refactor_without_contract_change', $result['feature_signals']);
    }

    public function testFeatureWithinExistingBoundaryReturnsFeatureChange(): void
    {
        $result = $this->classifier->classify([
            'adds_feature_in_existing_boundary' => true,
        ]);

        $this->assertSame('feature_change', $result['classification']);
        $this->assertContains('adds_feature_in_existing_boundary', $result['feature_signals']);
    }

    public function testAmbiguousInputReturnsUnknownBlocked(): void
    {
        // A recognised signal field is present but no structural and no feature
        // signal actually fires (every flag is false) — undecidable, not a feature.
        $result = $this->classifier->classify([
            'internal_refactor' => false,
            'adds_feature_in_existing_boundary' => false,
        ]);

        $this->assertSame('unknown_blocked', $result['classification']);
        $this->assertSame('none', $result['route']);
        $this->assertFalse($result['requires_structural_runbook']);
        $this->assertFalse($result['feature_path_allowed']);
        $this->assertSame(['ambiguous_no_classifiable_signal'], $result['blockers']);
    }

    public function testEmptyChangeReturnsUnknownBlockedAsMalformed(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('unknown_blocked', $result['classification']);
        $this->assertSame('none', $result['route']);
        $this->assertSame(['malformed_change_input'], $result['blockers']);
    }

    public function testStructuralRedesignCannotTravelTheNormalFeaturePath(): void
    {
        // DoD: structural redesign cannot go through the normal feature path. Even a
        // change that ALSO carries feature evidence is forced to the gated runbook
        // when any structural signal is present — structural wins, fail-closed.
        $result = $this->classifier->classify([
            'changes_departments' => true,
            'internal_refactor' => true,
            'adds_feature_in_existing_boundary' => true,
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertTrue($result['requires_structural_runbook']);
        $this->assertFalse($result['feature_path_allowed']);
        $this->assertNotSame('self_construction_os_feature_path', $result['route']);
        $this->assertSame([], $result['feature_signals']);
    }

    public function testAutonomyPromotionContractChangeIsStructural(): void
    {
        $result = $this->classifier->classify([
            'alters_autonomy_promotion_contract' => true,
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertContains('alters_autonomy_promotion_contract', $result['structural_signals']);
    }

    public function testOsMaeAuthorityChangeIsStructural(): void
    {
        $result = $this->classifier->classify([
            'alters_os_mae_authority' => true,
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertContains('alters_os_mae_authority', $result['structural_signals']);
    }

    public function testEqualPhaseCountBeforeAndAfterIsNotStructural(): void
    {
        // Generalisation guard: a declared before/after pair that is EQUAL is not a
        // count/identity change, so this stays a feature when paired with a feature
        // signal — canned-output keyed to the test inputs would fail this.
        $result = $this->classifier->classify([
            'phase_count_before' => 16,
            'phase_count_after' => 16,
            'optimizes_performance' => true,
        ]);

        $this->assertSame('feature_change', $result['classification']);
        $this->assertSame([], $result['structural_signals']);
        $this->assertContains('optimizes_without_contract_change', $result['feature_signals']);
    }

    public function testContractChangingOptimizationIsNotADisguisedFeature(): void
    {
        // An "optimization" that admits it changes a contract loses its feature
        // signal; with no structural signal either, it is undecidable, not a feature.
        $result = $this->classifier->classify([
            'optimizes_performance' => true,
            'changes_contract' => true,
        ]);

        $this->assertSame('unknown_blocked', $result['classification']);
        $this->assertSame([], $result['feature_signals']);
        $this->assertSame(['ambiguous_no_classifiable_signal'], $result['blockers']);
    }

    public function testStructuralSignalsAreAStrictStringListWithNoIntKeys(): void
    {
        $result = $this->classifier->classify([
            'phase_count_before' => 16,
            'phase_count_after' => 18,
            'added_departments' => ['growth'],
        ]);

        $signals = $result['structural_signals'];
        $this->assertSame(array_values($signals), $signals);
        foreach (array_keys($signals) as $key) {
            $this->assertIsInt($key);
        }
        foreach ($signals as $signal) {
            $this->assertIsString($signal);
        }
        // Two distinct structural rows fired for this change.
        $this->assertSame(2, count($signals));
    }

    public function testDeclaredStructuralWithoutConcreteFieldStillRoutesToRunbook(): void
    {
        // A change that only declares itself structural (no enumerated field) is
        // still fail-closed to the gated runbook.
        $result = $this->classifier->classify([
            'change_class' => 'structural',
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertTrue($result['requires_structural_runbook']);
    }

    public function testEmptyChangeListDoesNotShadowPopulatedSiblingList(): void
    {
        // Adversarial: a producer emits every list key, leaving the unused one as
        // an empty array. A naive `??` chain stops at the present-but-empty
        // `phase_changes` and never consults the populated `added_phases`, missing
        // the structural signal and fail-OPENing a frame change to unknown_blocked.
        // The structural signal MUST still fire (structural redesign cannot slip
        // through as undecidable).
        $result = $this->classifier->classify([
            'phase_changes' => [],
            'added_phases' => ['growth-phase'],
        ]);

        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertSame('architecture_evolution_proposal_runtime', $result['route']);
        $this->assertContains('alters_phase_count_or_identity', $result['structural_signals']);

        // Same shadowing risk on the root-schema dimension.
        $schemaResult = $this->classifier->classify([
            'root_schema_changes' => [],
            'canonical_schema_changes' => ['atlas.aaeos.phase.v1 -> atlas.aaeos.phase.v2'],
        ]);

        $this->assertSame('structural_frame_change', $schemaResult['classification']);
        $this->assertContains('alters_root_canonical_schema', $schemaResult['structural_signals']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $change = [
            'phase_count_before' => 16,
            'phase_count_after' => 17,
            'internal_refactor' => true,
        ];

        $first = $this->classifier->classify($change);
        $second = $this->classifier->classify($change);

        $this->assertSame($first, $second);
    }

    public function testNonStringDeclaredClassEmitsNoWarningAndIsTreatedAsAbsent(): void
    {
        // Purity guard: a producer can emit a malformed `change_class` as a non-string
        // (e.g. a decoded JSON array/object). The declared class is read on the normal
        // structural path too, so casting an array with (string) would emit an
        // "Array to string conversion" warning — an observable side-effect that breaks
        // this kernel's documented purity contract. A non-string declared class must be
        // treated as absent, silently and deterministically.
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true;
        });

        try {
            // (1) malformed array change_class with NO other signal -> unknown_blocked, no warning.
            $ambiguous = $this->classifier->classify(['change_class' => ['structural']]);
            // (2) malformed array change_class alongside a REAL structural delta -> the
            //     declared class is consulted on the happy structural path; it must still
            //     classify structurally without leaking a warning.
            $structural = $this->classifier->classify([
                'change_class' => ['structural'],
                'phase_count_before' => 16,
                'phase_count_after' => 17,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $captured, 'classify() must not emit any PHP warning for a non-string change_class');

        // A non-string declared class carries no usable label: with no other signal the
        // change is undecidable (fail-closed), never silently a feature.
        $this->assertSame('unknown_blocked', $ambiguous['classification']);

        // A real structural signal still routes to the gated runbook regardless of the
        // unusable declared-class field.
        $this->assertSame('structural_frame_change', $structural['classification']);
        $this->assertContains('alters_phase_count_or_identity', $structural['structural_signals']);
    }

    /**
     * @return list<array{0: mixed, 1: mixed}>
     */
    public static function nonRepresentableCountProvider(): array
    {
        return [
            'nan before' => [NAN, 5],
            'positive infinity before' => [INF, 5],
            'negative infinity before' => [-INF, 5],
            'overflowing finite float before' => [1e30, 5],
            'overflowing finite float after' => [16, -1e30],
        ];
    }

    /**
     * Purity guard: a count field carrying a non-finite (NAN / +-INF) or
     * out-of-int-range finite float is malformed numeric input. The kernel
     * documents itself as pure with no side-effects, so it must coerce such a
     * value deterministically WITHOUT emitting a PHP "not representable as int"
     * warning. A bare `(int) $float` cast would emit that warning (an observable
     * side-effect that breaks purity and fails under failOnWarning / strict error
     * handlers). The non-finite count collapses to 0, which differs from the real
     * sibling count and so still fails CLOSED to the gated structural runbook.
     *
     * @param  mixed  $before
     * @param  mixed  $after
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonRepresentableCountProvider')]
    public function testNonRepresentableFloatCountEmitsNoWarningAndFailsClosed($before, $after): void
    {
        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true;
        });

        try {
            $result = $this->classifier->classify([
                'phase_count_before' => $before,
                'phase_count_after' => $after,
            ]);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $captured, 'classify() must not emit any PHP warning/notice for non-representable float counts');
        // 0 vs a real sibling count differs -> structural; structural is the safe route.
        $this->assertSame('structural_frame_change', $result['classification']);
        $this->assertSame('architecture_evolution_proposal_runtime', $result['route']);
        $this->assertContains('alters_phase_count_or_identity', $result['structural_signals']);
    }
}
