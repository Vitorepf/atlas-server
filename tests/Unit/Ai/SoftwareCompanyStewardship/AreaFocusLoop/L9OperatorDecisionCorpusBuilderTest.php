<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\L9OperatorDecisionCorpusBuilder;
use PHPUnit\Framework\TestCase;

final class L9OperatorDecisionCorpusBuilderTest extends TestCase
{
    private L9OperatorDecisionCorpusBuilder $builder;

    protected function setUp(): void
    {
        $this->builder = new L9OperatorDecisionCorpusBuilder();
    }

    public function testBuildReturnsCorpusFieldsForOperatorDecisions(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'review_gate_1',
                'source' => 'review_gate',
                'author' => 'operator',
                'decision' => 'block',
                'rationale_ref' => 'rationale_1',
            ],
            [
                'id' => 'cockpit_1',
                'source' => 'cockpit_decision',
                'author' => 'operator',
                'label' => 'approve',
                'rationale_ref' => 'rationale_2',
            ],
            [
                'id' => 'override_1',
                'source' => 'override_receipt',
                'author' => 'operator',
                'decision' => 'revert',
                'rationale_ref' => 'rationale_3',
                'override_ref' => 'override_receipt_9',
            ],
        ]);

        $this->assertSame('atlas.loop.l9.operator_decision_corpus.v1', $result['schema_version']);
        $this->assertSame('corpus_ready', $result['status']);
        $this->assertSame(3, $result['decision_count']);
        $this->assertSame(['review_gate_1', 'cockpit_1', 'override_1'], $result['labeled_examples']);
        $this->assertSame(['rationale_1', 'rationale_2', 'rationale_3'], $result['rationale_refs']);
        $this->assertSame(['override_receipt_9'], $result['override_refs']);
        $this->assertSame([], $result['rejected_examples']);
    }

    public function testUnlabeledProviderOutputIsIgnored(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'operator_call',
                'author' => 'operator',
                'decision' => 'allow',
            ],
            [
                'id' => 'provider_taste',
                'author' => 'provider',
                'source' => 'aemor_outcome',
            ],
        ]);

        $this->assertSame(1, $result['decision_count']);
        $this->assertSame(['operator_call'], $result['labeled_examples']);
        $this->assertNotContains('provider_taste', $result['labeled_examples']);
        $this->assertSame(
            [['example_id' => 'provider_taste', 'reason' => 'unlabeled_provider_output']],
            $result['rejected_examples'],
        );
    }

    public function testNonOperatorDecisionIsRejected(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'operator_call',
                'author' => 'operator',
                'decision' => 'block',
            ],
            [
                'id' => 'provider_label',
                'author' => 'provider',
                'source' => 'cockpit_decision',
                'decision' => 'approve',
            ],
        ]);

        $this->assertSame(1, $result['decision_count']);
        $this->assertSame(['operator_call'], $result['labeled_examples']);
        $this->assertSame(
            [['example_id' => 'provider_label', 'reason' => 'non_operator_decision']],
            $result['rejected_examples'],
        );
    }

    public function testEmptyCorpusReturnsInsufficientEvidence(): void
    {
        $result = $this->builder->build([]);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertSame(0, $result['decision_count']);
        $this->assertSame([], $result['labeled_examples']);
        $this->assertSame([], $result['rationale_refs']);
        $this->assertSame([], $result['override_refs']);
        $this->assertSame([], $result['rejected_examples']);
    }

    public function testCorpusWithOnlyProviderEventsIsInsufficientEvidence(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'provider_a',
                'author' => 'provider',
            ],
            [
                'id' => 'provider_b',
                'author' => 'model',
                'decision' => 'approve',
            ],
        ]);

        $this->assertSame('insufficient_evidence', $result['status']);
        $this->assertSame(0, $result['decision_count']);
        $this->assertSame([], $result['labeled_examples']);
        $this->assertSame(
            [
                ['example_id' => 'provider_a', 'reason' => 'unlabeled_provider_output'],
                ['example_id' => 'provider_b', 'reason' => 'non_operator_decision'],
            ],
            $result['rejected_examples'],
        );
    }

    public function testListContractsAreStringListsNotIntKeyedMaps(): void
    {
        $result = $this->builder->build([
            [
                'id' => '100',
                'author' => 'operator',
                'decision' => 'allow',
                'rationale_ref' => '200',
                'override_ref' => '300',
            ],
            [
                'id' => '101',
                'author' => 'operator',
                'decision' => 'block',
                'rationale_ref' => '201',
            ],
        ]);

        $this->assertSame(['100', '101'], $result['labeled_examples']);
        $this->assertSame(['200', '201'], $result['rationale_refs']);
        $this->assertSame(['300'], $result['override_refs']);
        $this->assertSame([0, 1], array_keys($result['labeled_examples']));
        $this->assertSame([0, 1], array_keys($result['rationale_refs']));
        $this->assertContainsOnlyString($result['labeled_examples']);
        $this->assertContainsOnlyString($result['rationale_refs']);
        $this->assertContainsOnlyString($result['override_refs']);
    }

    public function testOperatorEventWithoutLabelIsRejectedAsUnlabeled(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'operator_silent',
                'author' => 'operator',
            ],
        ]);

        $this->assertSame(0, $result['decision_count']);
        $this->assertSame([], $result['labeled_examples']);
        $this->assertSame(
            [['example_id' => 'operator_silent', 'reason' => 'unlabeled_operator_decision']],
            $result['rejected_examples'],
        );
    }

    public function testOverrideRefFallsBackToIdWhenFlagged(): void
    {
        $result = $this->builder->build([
            [
                'id' => 'override_flagged',
                'author' => 'operator',
                'decision' => 'revert',
                'is_override' => true,
            ],
        ]);

        $this->assertSame(['override_flagged'], $result['override_refs']);
        $this->assertSame(['override_flagged'], $result['labeled_examples']);
    }

    public function testMissingIdsFallBackToPositionalExampleIds(): void
    {
        $result = $this->builder->build([
            [
                'author' => 'operator',
                'decision' => 'allow',
            ],
            [
                'author' => 'provider',
            ],
        ]);

        $this->assertSame(['event_0'], $result['labeled_examples']);
        $this->assertSame(
            [['example_id' => 'event_1', 'reason' => 'unlabeled_provider_output']],
            $result['rejected_examples'],
        );
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $events = [
            [
                'id' => 'operator_call',
                'author' => 'operator',
                'decision' => 'block',
                'rationale_ref' => 'rationale_1',
            ],
            [
                'id' => 'provider_taste',
                'author' => 'provider',
            ],
        ];

        $first = $this->builder->build($events);
        $second = $this->builder->build($events);

        $this->assertSame($first, $second);
    }
}
