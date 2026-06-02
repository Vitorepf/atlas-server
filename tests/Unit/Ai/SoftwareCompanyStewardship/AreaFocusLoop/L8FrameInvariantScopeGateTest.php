<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8FrameInvariantScopeGate;
use PHPUnit\Framework\TestCase;

final class L8FrameInvariantScopeGateTest extends TestCase
{
    private L8FrameInvariantScopeGate $gate;

    protected function setUp(): void
    {
        $this->gate = new L8FrameInvariantScopeGate();
    }

    public function testEvaluateReturnsScopeReviewAndBlockerFieldsWithSchema(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-l7-l10-governed-ladder-backlog'],
                ],
            ],
            ['registry' => []],
        );

        $this->assertSame('atlas.aaeos.l8.frame_invariant_scope_gate.v1', $result['schema_version']);
        $this->assertArrayHasKey('invariant_scope', $result);
        $this->assertArrayHasKey('sovereignty_layer_review_required', $result);
        $this->assertArrayHasKey('blockers', $result);
        $this->assertSame('feature_local', $result['invariant_scope']);
        $this->assertFalse($result['sovereignty_layer_review_required']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['cleared']);
    }

    public function testNonSovereigntyFrameChangeIsClearedSoL8CanChangeFrame(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    [
                        'target_doc' => 'atlas-aaeos-runbook',
                        'proposed_state_description' => 'add a new phase P17 to the runbook',
                    ],
                ],
            ],
            ['registry' => [
                ['invariant_id' => 'inv.local_first', 'immutable' => true],
            ]],
        );

        $this->assertSame('feature_local', $result['invariant_scope']);
        $this->assertFalse($result['sovereignty_layer_review_required']);
        $this->assertFalse($result['independent_human_reviewer_required']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['cleared']);
    }

    public function testTrustLedgerTouchWithoutIndependentReviewerBlocks(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-trust-ledger-canonical'],
                ],
            ],
            ['registry' => []],
        );

        $this->assertSame('sovereignty_layer', $result['invariant_scope']);
        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertTrue($result['independent_human_reviewer_required']);
        $this->assertSame(['atlas-trust-ledger-canonical'], $result['sovereignty_layers_touched']);
        $this->assertSame(['trust_governance_requires_independent_human_reviewer'], $result['blockers']);
        $this->assertFalse($result['cleared']);
    }

    public function testTrustLedgerTouchWithIndependentReviewerClears(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-trust-ledger-canonical'],
                ],
                'independent_human_reviewer' => [
                    'id' => 'reviewer-77',
                    'independent_from_implementation' => true,
                ],
            ],
            ['registry' => []],
        );

        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertTrue($result['independent_human_reviewer_required']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['cleared']);
    }

    public function testReviewerNotIndependentFromImplementationStillBlocks(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-sovereign-operating-system'],
                ],
                'independent_human_reviewer' => [
                    'id' => 'reviewer-88',
                    'independent_from_implementation' => false,
                ],
            ],
            ['registry' => []],
        );

        $this->assertSame(['trust_governance_requires_independent_human_reviewer'], $result['blockers']);
        $this->assertFalse($result['cleared']);
    }

    public function testProposalWeakeningImmutableInvariantBlocks(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'invariant_operations' => [
                    ['invariant_id' => 'inv.no_silent_promotion', 'effect' => 'relax'],
                ],
            ],
            ['registry' => [
                ['invariant_id' => 'inv.no_silent_promotion', 'immutable' => true],
            ]],
        );

        $this->assertContains('weakens_immutable_invariant', $result['blockers']);
        $this->assertSame(['inv.no_silent_promotion'], $result['weakened_invariants']);
        $this->assertSame('invariant_only', $result['invariant_scope']);
        $this->assertFalse($result['cleared']);
    }

    public function testWeakenedInvariantsStayStringListSortedLexicographically(): void
    {
        // Adversarial: invariant ids are integer-like strings (e.g. numeric
        // registry codes). PHP coerces such array keys back to int and a default
        // sort would order them numerically ([2, 10]); weakened_invariants must
        // stay a lexicographically-sorted list<string> (["10", "2", "inv.a"]).
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'invariant_operations' => [
                    ['invariant_id' => '2', 'effect' => 'disable'],
                    ['invariant_id' => 'inv.a', 'effect' => 'relax'],
                    ['invariant_id' => '10', 'effect' => 'remove'],
                ],
            ],
            ['registry' => [
                ['invariant_id' => '2', 'immutable' => true],
                ['invariant_id' => 'inv.a', 'immutable' => true],
                ['invariant_id' => '10', 'immutable' => true],
            ]],
        );

        $weakened = $result['weakened_invariants'];

        $this->assertSame(['10', '2', 'inv.a'], $weakened);
        $this->assertSame(array_values($weakened), $weakened);
        $this->assertSame(range(0, count($weakened) - 1), array_keys($weakened));
        foreach ($weakened as $id) {
            $this->assertIsString($id);
        }
        $this->assertContains('weakens_immutable_invariant', $result['blockers']);
    }

    public function testWeakeningEffectPaddedWithWhitespaceStillBlocks(): void
    {
        // Fail-closed DoD: "never weaken a sacred invariant silently." A gaming
        // attempt that declares the weakening verb with surrounding whitespace
        // (e.g. " relax ", "remove\n") must NOT defeat the weakening detector and
        // slip an immutable-invariant change through as cleared.
        $variants = [
            'surrounding spaces' => '  relax  ',
            'leading newline' => "\nremove",
            'trailing tab' => "disable\t",
            'mixed case + space' => ' Loosen ',
        ];

        foreach ($variants as $label => $effect) {
            $result = $this->gate->evaluate(
                [
                    'structural_changes' => [
                        ['target_doc' => 'atlas-aaeos-runbook'],
                    ],
                    'invariant_operations' => [
                        ['invariant_id' => 'inv.no_silent_promotion', 'effect' => $effect],
                    ],
                ],
                ['registry' => [
                    ['invariant_id' => 'inv.no_silent_promotion', 'immutable' => true],
                ]],
            );

            $this->assertSame(
                ['inv.no_silent_promotion'],
                $result['weakened_invariants'],
                "weakening must be detected for padded effect variant: {$label}",
            );
            $this->assertContains(
                'weakens_immutable_invariant',
                $result['blockers'],
                "blocker must fire for padded effect variant: {$label}",
            );
            $this->assertFalse($result['cleared'], "must not clear for padded effect variant: {$label}");
        }
    }

    public function testMutatingNonImmutableInvariantDoesNotBlock(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'invariant_operations' => [
                    ['invariant_id' => 'inv.tunable_threshold', 'effect' => 'relax'],
                ],
            ],
            ['registry' => [
                ['invariant_id' => 'inv.tunable_threshold', 'immutable' => false],
            ]],
        );

        $this->assertSame([], $result['weakened_invariants']);
        $this->assertSame('invariant_only', $result['invariant_scope']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['cleared']);
    }

    public function testSovereigntyTouchWeakeningInvariantYieldsCombinedScopeAndBothFlags(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    [
                        'target_doc' => 'atlas-epistemic-operating-system',
                        'invariant_op' => ['invariant_id' => 'inv.evidence_required', 'effect' => 'disable'],
                    ],
                ],
            ],
            ['registry' => [
                ['invariant_id' => 'inv.evidence_required', 'immutable' => true],
            ]],
        );

        $this->assertSame('sovereignty_and_invariant', $result['invariant_scope']);
        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertTrue($result['independent_human_reviewer_required']);
        $this->assertSame(['atlas-epistemic-operating-system'], $result['sovereignty_layers_touched']);
        $this->assertSame(['inv.evidence_required'], $result['weakened_invariants']);
        $this->assertSame(
            ['weakens_immutable_invariant', 'sovereignty_layer_requires_independent_human_reviewer'],
            $result['blockers'],
        );
        $this->assertFalse($result['cleared']);
    }

    public function testNonTrustSovereigntyLayerUsesSovereigntyReviewerBlocker(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-canonical-glossary-and-naming'],
                ],
            ],
            ['registry' => []],
        );

        $this->assertSame(['sovereignty_layer_requires_independent_human_reviewer'], $result['blockers']);
        $this->assertTrue($result['sovereignty_layer_review_required']);
    }

    public function testMissingStructuralChangesBlocks(): void
    {
        $result = $this->gate->evaluate([], ['registry' => []]);

        $this->assertSame(['proposal_missing_structural_changes'], $result['blockers']);
        $this->assertSame('feature_local', $result['invariant_scope']);
        $this->assertFalse($result['cleared']);
    }

    public function testSovereigntyLayersTouchedIsSortedDedupedListOfStrings(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-trust-ledger-canonical'],
                    ['target_doc' => 'atlas-cognition-operating-system'],
                    ['target_doc' => 'atlas-trust-ledger-canonical'],
                    ['target_doc' => 'atlas-some-feature-doc'],
                ],
                'independent_human_reviewer' => 'operator-and-architect',
            ],
            ['registry' => []],
        );

        $this->assertSame(
            ['atlas-cognition-operating-system', 'atlas-trust-ledger-canonical'],
            $result['sovereignty_layers_touched'],
        );
        $this->assertSame(array_values($result['sovereignty_layers_touched']), $result['sovereignty_layers_touched']);
        foreach ($result['sovereignty_layers_touched'] as $layer) {
            $this->assertIsString($layer);
        }
    }

    public function testTouchesSovereigntyFlagAloneRequiresReview(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'touches_sovereignty_layer' => true,
            ],
            ['registry' => []],
        );

        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertSame('sovereignty_layer', $result['invariant_scope']);
        $this->assertSame([], $result['sovereignty_layers_touched']);
        $this->assertSame(['sovereignty_layer_requires_independent_human_reviewer'], $result['blockers']);
    }

    public function testImmutableIdsListFromRegistryContextDetectsWeakening(): void
    {
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'invariant_operations' => [
                    ['invariant_id' => 'inv.sacred_gate', 'weakens_invariant' => true],
                ],
            ],
            ['immutable_ids' => ['inv.sacred_gate']],
        );

        $this->assertSame(['inv.sacred_gate'], $result['weakened_invariants']);
        $this->assertContains('weakens_immutable_invariant', $result['blockers']);
    }

    public function testTrustLedgerFlagWithoutNamedDocStillRequiresSovereigntyReview(): void
    {
        // DoD guard: trust-ledger / governance is the most sacred gate, so a
        // proposal that declares it via flag (without naming a registry doc)
        // must still surface sovereignty_layer_review_required — it can never
        // demand an independent reviewer while reporting no sovereignty review,
        // which would let a sacred gate change pass silently.
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'touches_trust_ledger' => true,
            ],
            ['registry' => []],
        );

        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertTrue($result['independent_human_reviewer_required']);
        $this->assertSame('sovereignty_layer', $result['invariant_scope']);
        $this->assertSame([], $result['sovereignty_layers_touched']);
        $this->assertSame(['trust_governance_requires_independent_human_reviewer'], $result['blockers']);
        $this->assertFalse($result['cleared']);
    }

    public function testGovernanceFlagWithReviewerClearsAndKeepsSovereigntyReview(): void
    {
        // Counterpart to the blocked case: once an independent reviewer is
        // present the same governance touch clears, while sovereignty review
        // stays required (the gate fired, it was satisfied, not bypassed).
        $result = $this->gate->evaluate(
            [
                'structural_changes' => [
                    ['target_doc' => 'atlas-aaeos-runbook'],
                ],
                'touches_governance' => true,
                'independent_human_reviewer' => [
                    'id' => 'reviewer-99',
                    'independent_from_implementation' => true,
                ],
            ],
            ['registry' => []],
        );

        $this->assertTrue($result['sovereignty_layer_review_required']);
        $this->assertTrue($result['independent_human_reviewer_required']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['cleared']);
    }

    public function testSacredLayerTouchIsNeverMissedBehindCaseWhitespaceOrMdSuffix(): void
    {
        // Fail-closed DoD: "L8 can change frame, never sovereignty or sacred gates
        // silently." A non-canonical casing / `.md` suffix / surrounding whitespace
        // on a sovereignty target_doc must NOT let the sacred-layer touch slip past
        // the gate as feature_local with zero blockers.
        $variants = [
            'uppercase' => 'ATLAS-TRUST-LEDGER-CANONICAL',
            'dot-md suffix' => 'atlas-trust-ledger-canonical.md',
            'trailing whitespace' => 'atlas-trust-ledger-canonical ',
            'leading whitespace' => ' atlas-trust-ledger-canonical',
            'mixed case + md' => 'Atlas-Trust-Ledger-Canonical.md',
        ];

        foreach ($variants as $label => $targetDoc) {
            $result = $this->gate->evaluate(
                ['structural_changes' => [['target_doc' => $targetDoc]]],
                ['registry' => []],
            );

            $this->assertTrue(
                $result['sovereignty_layer_review_required'],
                "sovereignty review must be required for variant: {$label}",
            );
            $this->assertSame('sovereignty_layer', $result['invariant_scope'], "scope for variant: {$label}");
            $this->assertSame(
                ['atlas-trust-ledger-canonical'],
                $result['sovereignty_layers_touched'],
                "normalized layer id for variant: {$label}",
            );
            $this->assertContains(
                'trust_governance_requires_independent_human_reviewer',
                $result['blockers'],
                "blocker for variant: {$label}",
            );
            $this->assertFalse($result['cleared'], "must not clear for variant: {$label}");
        }
    }

    public function testWhitespaceOnlyReviewerDoesNotSatisfyTheSacredGate(): void
    {
        // A blank / whitespace-only reviewer id is not a named independent human
        // reviewer; it must NOT clear a trust-governance touch (fail-closed).
        $arrayReviewer = $this->gate->evaluate(
            [
                'structural_changes' => [['target_doc' => 'atlas-trust-ledger-canonical']],
                'independent_human_reviewer' => ['id' => '   ', 'independent_from_implementation' => true],
            ],
            ['registry' => []],
        );

        $this->assertFalse($arrayReviewer['cleared']);
        $this->assertContains('trust_governance_requires_independent_human_reviewer', $arrayReviewer['blockers']);

        // Same when the reviewer is given as a bare whitespace string.
        $stringReviewer = $this->gate->evaluate(
            [
                'structural_changes' => [['target_doc' => 'atlas-trust-ledger-canonical']],
                'independent_human_reviewer' => '   ',
            ],
            ['registry' => []],
        );

        $this->assertFalse($stringReviewer['cleared']);
        $this->assertContains('trust_governance_requires_independent_human_reviewer', $stringReviewer['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $proposal = [
            'structural_changes' => [
                [
                    'target_doc' => 'atlas-trust-ledger-canonical',
                    'invariant_op' => ['invariant_id' => 'inv.no_silent_promotion', 'effect' => 'remove'],
                ],
            ],
        ];
        $invariants = ['registry' => [
            ['invariant_id' => 'inv.no_silent_promotion', 'immutable' => true],
        ]];

        $first = $this->gate->evaluate($proposal, $invariants);
        $second = $this->gate->evaluate($proposal, $invariants);

        $this->assertSame($first, $second);
    }
}
