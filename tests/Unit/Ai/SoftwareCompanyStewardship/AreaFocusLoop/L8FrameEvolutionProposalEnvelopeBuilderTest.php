<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L8FrameEvolutionProposalEnvelopeBuilder;
use PHPUnit\Framework\TestCase;

final class L8FrameEvolutionProposalEnvelopeBuilderTest extends TestCase
{
    private L8FrameEvolutionProposalEnvelopeBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L8FrameEvolutionProposalEnvelopeBuilder();
    }

    /**
     * @return array<string, mixed>
     */
    private function wellFormedInput(): array
    {
        return [
            'title' => 'Add P17 forecasting phase to runbook',
            'structural_changes' => [
                [
                    'target_doc' => 'atlas-agentic-engineering-os',
                    'proposed_state_description' => 'Insert a forecasting phase after P16',
                    'schema_changes' => ['atlas.aaeos.phase.v1 -> atlas.aaeos.phase.v2'],
                    'layer_count_before' => 16,
                    'layer_count_after' => 17,
                ],
            ],
            'motivating_evidence' => [
                ['obra_id' => 'obra-001', 'limitation_observed' => 'no forecasting', 'frequency' => 4],
                ['obra_id' => 'obra-002', 'limitation_observed' => 'no forecasting', 'frequency' => 3],
                ['obra_id' => 'obra-003', 'limitation_observed' => 'no forecasting', 'frequency' => 2],
                ['obra_id' => 'obra-004', 'limitation_observed' => 'no forecasting', 'frequency' => 5],
                ['obra_id' => 'obra-005', 'limitation_observed' => 'no forecasting', 'frequency' => 1],
            ],
            'expected_metrics_delta' => [
                'throughput' => '+12%',
                'reliability' => 'flat',
                'operator_friction' => '-5%',
            ],
            'proposed_by_actor' => [
                'kind' => 'agent',
                'id' => 'aaeos-loop',
                'autonomy_level' => 'L8',
            ],
        ];
    }

    public function testBuildReturnsCanonicalSchemaAndAllRequiredEnvelopeFields(): void
    {
        $envelope = $this->builder->build($this->wellFormedInput());

        // schema_version byte-for-byte canonical.
        $this->assertSame('atlas.architecture.redesign_proposal.v1', $envelope['schema_version']);

        // Acceptance: build(input) returns proposal_id, structural_changes,
        // motivating_evidence, expected_metrics_delta, touches_sovereignty_layer
        // and proposed_by_actor — every field present and correctly computed.
        $this->assertSame('arp_', substr($envelope['proposal_id'], 0, 4));
        $this->assertSame(20, strlen($envelope['proposal_id']));

        $this->assertCount(1, $envelope['structural_changes']);
        $this->assertSame('atlas-agentic-engineering-os', $envelope['structural_changes'][0]['target_doc']);
        $this->assertSame(16, $envelope['structural_changes'][0]['layer_count_before']);
        $this->assertSame(17, $envelope['structural_changes'][0]['layer_count_after']);
        $this->assertSame(
            ['atlas.aaeos.phase.v1 -> atlas.aaeos.phase.v2'],
            $envelope['structural_changes'][0]['schema_changes'],
        );

        $this->assertCount(5, $envelope['motivating_evidence']);
        $this->assertSame(5, $envelope['motivating_evidence_count']);
        $this->assertSame('obra-001', $envelope['motivating_evidence'][0]['obra_id']);

        $this->assertSame(
            ['throughput' => '+12%', 'reliability' => 'flat', 'operator_friction' => '-5%'],
            $envelope['expected_metrics_delta'],
        );

        $this->assertFalse($envelope['touches_sovereignty_layer']);

        $this->assertSame(
            ['kind' => 'agent', 'id' => 'aaeos-loop', 'autonomy_level' => 'L8'],
            $envelope['proposed_by_actor'],
        );

        // Well-formed proposal is ready for review with no blockers.
        $this->assertSame('ready_for_review', $envelope['status']);
        $this->assertSame([], $envelope['blockers']);
    }

    public function testMissingMotivatingEvidenceBlocks(): void
    {
        $input = $this->wellFormedInput();
        // Four obra-grounded items is below the canonical minimum of five.
        $input['motivating_evidence'] = [
            ['obra_id' => 'obra-001', 'limitation_observed' => 'x', 'frequency' => 2],
            ['obra_id' => 'obra-002', 'limitation_observed' => 'x', 'frequency' => 2],
            ['obra_id' => 'obra-003', 'limitation_observed' => 'x', 'frequency' => 2],
            ['obra_id' => 'obra-004', 'limitation_observed' => 'x', 'frequency' => 2],
        ];

        $envelope = $this->builder->build($input);

        $this->assertSame('blocked', $envelope['status']);
        $this->assertContains('missing_motivating_evidence', $envelope['blockers']);
        $this->assertSame(4, $envelope['motivating_evidence_count']);
    }

    public function testEvidenceItemsWithoutObraAnchorAreNotCountedAndBlock(): void
    {
        $input = $this->wellFormedInput();
        // Five entries but only three are obra-grounded — anchorless ones are dropped,
        // so the proposal still blocks on insufficient motivating evidence.
        $input['motivating_evidence'] = [
            ['obra_id' => 'obra-001', 'limitation_observed' => 'x', 'frequency' => 1],
            ['limitation_observed' => 'no obra anchor'],
            ['obra_id' => 'obra-002', 'limitation_observed' => 'x', 'frequency' => 1],
            ['obra_id' => '', 'limitation_observed' => 'blank obra'],
            ['obra_id' => 'obra-003', 'limitation_observed' => 'x', 'frequency' => 1],
        ];

        $envelope = $this->builder->build($input);

        $this->assertSame(3, $envelope['motivating_evidence_count']);
        $this->assertSame('blocked', $envelope['status']);
        $this->assertContains('missing_motivating_evidence', $envelope['blockers']);
    }

    public function testDuplicateObraIdsCountAsOneWorkAndBlockBreadthGate(): void
    {
        // The canonical gate is `motivating_evidence_min_obras: 5` — five DISTINCT
        // obra-grounded works, with each item carrying its own `frequency`. A
        // proposal motivated by a single Obra repeated five times has breadth 1,
        // not 5, and must block: counting duplicate obra_id rows as separate Obras
        // would fail-open the breadth gate.
        $input = $this->wellFormedInput();
        $input['motivating_evidence'] = [
            ['obra_id' => 'obra-001', 'limitation_observed' => 'x', 'frequency' => 3],
            ['obra_id' => 'obra-001', 'limitation_observed' => 'y', 'frequency' => 2],
            ['obra_id' => 'obra-001', 'limitation_observed' => 'z', 'frequency' => 1],
            ['obra_id' => 'obra-001', 'limitation_observed' => 'w', 'frequency' => 4],
            ['obra_id' => 'obra-001', 'limitation_observed' => 'v', 'frequency' => 1],
        ];

        $envelope = $this->builder->build($input);

        // One distinct Obra -> below the breadth floor of five.
        $this->assertSame(1, $envelope['motivating_evidence_count']);
        $this->assertSame('blocked', $envelope['status']);
        $this->assertContains('missing_motivating_evidence', $envelope['blockers']);

        // Five DISTINCT Obras across the same number of rows clears the gate.
        $input['motivating_evidence'] = [
            ['obra_id' => 'obra-001', 'limitation_observed' => 'x', 'frequency' => 1],
            ['obra_id' => 'obra-002', 'limitation_observed' => 'x', 'frequency' => 1],
            ['obra_id' => 'obra-003', 'limitation_observed' => 'x', 'frequency' => 1],
            ['obra_id' => 'obra-004', 'limitation_observed' => 'x', 'frequency' => 1],
            ['obra_id' => 'obra-005', 'limitation_observed' => 'x', 'frequency' => 1],
        ];

        $cleared = $this->builder->build($input);

        $this->assertSame(5, $cleared['motivating_evidence_count']);
        $this->assertSame('ready_for_review', $cleared['status']);
        $this->assertSame([], $cleared['blockers']);
    }

    public function testProposalHasNoWriteOrApplyAuthorityEvenWhenWellFormed(): void
    {
        $wellFormed = $this->builder->build($this->wellFormedInput());

        $blockedInput = $this->wellFormedInput();
        $blockedInput['motivating_evidence'] = [];
        $blocked = $this->builder->build($blockedInput);

        // DoD: "proposal has no write/apply authority" — true for every status.
        $this->assertFalse($wellFormed['apply_authority']);
        $this->assertFalse($wellFormed['write_authority']);
        $this->assertFalse($blocked['apply_authority']);
        $this->assertFalse($blocked['write_authority']);
    }

    public function testTouchesSovereigntyLayerWhenStructuralTargetIsCanonicalSovereigntyDoc(): void
    {
        $input = $this->wellFormedInput();
        // Target the Trust Ledger canonical doc — a sovereignty layer the test
        // never hard-codes into the class output; the class must detect it from
        // its canonical lexicon (case/.md-suffix insensitive).
        $input['structural_changes'] = [
            [
                'target_doc' => 'ATLAS-TRUST-LEDGER-CANONICAL.md',
                'proposed_state_description' => 'change trust scoring',
                'layer_count_before' => 1,
                'layer_count_after' => 1,
            ],
            [
                'target_doc' => 'atlas-agentic-engineering-os',
                'proposed_state_description' => 'unrelated structural edit',
            ],
        ];

        $envelope = $this->builder->build($input);

        $this->assertTrue($envelope['touches_sovereignty_layer']);
        $this->assertSame(['atlas-trust-ledger-canonical'], $envelope['sovereignty_layers_touched']);
        $this->assertTrue($envelope['structural_changes'][0]['touches_sovereignty_layer']);
        $this->assertFalse($envelope['structural_changes'][1]['touches_sovereignty_layer']);
    }

    public function testMissingStructuralChangeBlocks(): void
    {
        $input = $this->wellFormedInput();
        $input['structural_changes'] = [];

        $envelope = $this->builder->build($input);

        $this->assertSame('blocked', $envelope['status']);
        $this->assertContains('missing_structural_change', $envelope['blockers']);
        $this->assertSame([], $envelope['structural_changes']);
        $this->assertSame([], $envelope['sovereignty_layers_touched']);
        $this->assertFalse($envelope['touches_sovereignty_layer']);
    }

    public function testProposalIdIsDeterministicAndContentAddressedNotCanned(): void
    {
        $input = $this->wellFormedInput();

        $first = $this->builder->build($input);
        $second = $this->builder->build($input);

        // Same input -> same id (pure, content-addressed).
        $this->assertSame($first['proposal_id'], $second['proposal_id']);

        // A different title -> a different id (generalises; not a canned constant).
        $other = $input;
        $other['title'] = 'Add a brand new governance dimension';
        $otherEnvelope = $this->builder->build($other);

        $this->assertNotSame($first['proposal_id'], $otherEnvelope['proposal_id']);
        $this->assertSame('arp_', substr($otherEnvelope['proposal_id'], 0, 4));
    }

    public function testActorAndMetricsDeltaAreNormalizedFromPartialInput(): void
    {
        $input = $this->wellFormedInput();
        // Operator-kind actor given as numeric level; metrics delta only partly filled.
        $input['proposed_by_actor'] = ['kind' => 'operator', 'id' => 'vitor', 'autonomy_level' => 8];
        $input['expected_metrics_delta'] = ['throughput' => '+3%'];

        $envelope = $this->builder->build($input);

        $this->assertSame('operator', $envelope['proposed_by_actor']['kind']);
        $this->assertSame('vitor', $envelope['proposed_by_actor']['id']);
        $this->assertSame('L8', $envelope['proposed_by_actor']['autonomy_level']);

        // Unspecified metric dimensions are filled deterministically, never dropped.
        $this->assertSame('+3%', $envelope['expected_metrics_delta']['throughput']);
        $this->assertSame('unspecified', $envelope['expected_metrics_delta']['reliability']);
        $this->assertSame('unspecified', $envelope['expected_metrics_delta']['operator_friction']);
    }

    public function testNonRepresentableFloatCountsEmitNoWarningAndFallBackToDefaults(): void
    {
        // Purity guard: the builder documents itself as a pure function with no
        // side-effects. A non-finite (NAN / +-INF) or out-of-int-range finite float
        // in a numeric field is malformed; a bare `(int) $float` cast would emit a
        // PHP "not representable as int" warning (an observable side-effect that
        // breaks purity and fails under failOnWarning / strict error handlers). Such
        // values must fall back to the declared default deterministically and silently.
        $input = $this->wellFormedInput();
        $input['structural_changes'][0]['layer_count_before'] = NAN;
        $input['structural_changes'][0]['layer_count_after'] = 1e30;
        $input['motivating_evidence'][0]['frequency'] = INF;
        $input['motivating_evidence'][1]['frequency'] = -1e30;

        $captured = [];
        set_error_handler(static function (int $errno, string $errstr) use (&$captured): bool {
            $captured[] = $errstr;

            return true;
        });

        try {
            $envelope = $this->builder->build($input);
        } finally {
            restore_error_handler();
        }

        $this->assertSame([], $captured, 'build() must not emit any PHP warning/notice for non-representable float counts');

        // layer_count defaults are 0; frequency default is 1 (then clamped to >= 0).
        $this->assertSame(0, $envelope['structural_changes'][0]['layer_count_before']);
        $this->assertSame(0, $envelope['structural_changes'][0]['layer_count_after']);
        $this->assertSame(1, $envelope['motivating_evidence'][0]['frequency']);
        $this->assertSame(1, $envelope['motivating_evidence'][1]['frequency']);

        // The proposal is otherwise well-formed, so the malformed numerics do not
        // change the gate outcome.
        $this->assertSame('ready_for_review', $envelope['status']);
        $this->assertSame([], $envelope['blockers']);
    }

    public function testFrequencyIsClampedAndStructuralTargetsWithoutDocAreDropped(): void
    {
        $input = $this->wellFormedInput();
        $input['structural_changes'] = [
            ['proposed_state_description' => 'no target doc here'],
            [
                'target_doc' => 'atlas-agentic-engineering-os',
                'proposed_state_description' => 'real edit',
                'layer_count_before' => 16,
                'layer_count_after' => 17,
            ],
        ];
        $input['motivating_evidence'][0]['frequency'] = -9;

        $envelope = $this->builder->build($input);

        // Only the targeted structural change survives.
        $this->assertCount(1, $envelope['structural_changes']);
        $this->assertSame('atlas-agentic-engineering-os', $envelope['structural_changes'][0]['target_doc']);

        // Negative frequency is clamped to 0 (never below bound).
        $this->assertSame(0, $envelope['motivating_evidence'][0]['frequency']);

        // Still well-formed: five evidence items + one structural change.
        $this->assertSame('ready_for_review', $envelope['status']);
        $this->assertSame([], $envelope['blockers']);
    }
}
