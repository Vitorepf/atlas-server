<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\V4;

use App\Services\Ai\AutonomousEvolution\V4\AtlasLoopV4MetaObjectiveOriginator;
use PHPUnit\Framework\TestCase;

final class AtlasLoopV4MetaObjectiveOriginatorTest extends TestCase
{
    public function test_fail_closed_without_writer(): void
    {
        $result = (new AtlasLoopV4MetaObjectiveOriginator)->originate(
            $this->origination(),
            $this->delivery(),
            $this->capability(),
        );

        $this->assertFalse($result['originated']);
        $this->assertSame('no_writer', $result['reason']);
    }

    public function test_fail_closed_when_writer_returns_non_array(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(static fn (): string => 'not-a-proposal');

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('no_writer', $result['reason']);
    }

    public function test_refuses_ungrounded_proposal(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal(['cited_facts' => []]));

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('ungrounded_proposal', $result['reason']);
    }

    public function test_refuses_proxy_vocabulary_objective(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal([
            'objective' => 'Cleanup the rejection dimensions with a tidy refactor.',
        ]));

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('proxy_vocabulary_refused', $result['reason']);
    }

    public function test_refuses_fact_citation_that_does_not_match_input(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal([
            'cited_facts' => ['origination.refuted_inventory=99'],
        ]));

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('citations_refuted_by_fact_judge', $result['reason']);
    }

    public function test_refuses_target_metric_outside_allowed_buckets(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal([
            'target_metric' => 'latency',
        ]));

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('target_metric_out_of_scope', $result['reason']);
    }

    public function test_refuses_target_delta_above_reachability_ceiling(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal([
            'target_delta' => 1.0,
        ]));

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertFalse($result['originated']);
        $this->assertSame('target_delta_exceeds_reachability_ceiling', $result['reason']);
    }

    public function test_happy_path_returns_normalized_originated_objective(): void
    {
        $originator = new AtlasLoopV4MetaObjectiveOriginator(fn (): array => $this->proposal());

        $result = $originator->originate($this->origination(), $this->delivery(), $this->capability());

        $this->assertTrue($result['originated']);
        $this->assertSame('Raise grounded recall by closing the refuted-inventory bottleneck.', $result['objective']);
        $this->assertSame('origination', $result['target_metric']);
        $this->assertSame(0.5, $result['target_delta']);
        $this->assertSame([
            'origination.refuted_inventory=4',
            'delivery.rejected_contract=3',
            'capability.grounded_recall=0.4',
        ], $result['cited_facts']);
        $this->assertNull($result['reason']);
    }

    /** @return array<string,mixed> */
    private function proposal(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Raise grounded recall by closing the refuted-inventory bottleneck.',
            'target_metric' => 'origination',
            'target_delta' => 0.5,
            'cited_facts' => [
                'origination.refuted_inventory=4',
                'delivery.rejected_contract=3',
                'capability.grounded_recall=0.4',
            ],
        ], $overrides);
    }

    /** @return array<string,mixed> */
    private function origination(): array
    {
        return [
            'originated' => 7,
            'refuted_inventory' => 4,
        ];
    }

    /** @return array<string,mixed> */
    private function delivery(): array
    {
        return [
            'certified' => 5,
            'rejected_contract' => 3,
        ];
    }

    /** @return array<string,mixed> */
    private function capability(): array
    {
        return [
            'grounded_recall' => 0.4,
            'cycle_liveness' => -0.25,
        ];
    }
}
