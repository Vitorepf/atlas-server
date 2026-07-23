<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainConsumerEdgeValidator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainConsumerEdgeValidatorTest extends TestCase
{
    private function validator(): AtlasExternalBrainConsumerEdgeValidator
    {
        return new AtlasExternalBrainConsumerEdgeValidator;
    }

    public function test_rejects_task_with_no_downstream_consumer_path(): void
    {
        $r = $this->validator()->validate([]);

        $this->assertFalse($r['consumer_edge_valid']);
        $this->assertSame('detached_task', $r['rejection_reason']);
        $this->assertNull($r['consuming_block']);
    }

    public function test_rejects_task_with_empty_downstream_consumer_path(): void
    {
        $r = $this->validator()->validate(['downstream_consumer_path' => []]);

        $this->assertFalse($r['consumer_edge_valid']);
        $this->assertSame('detached_task', $r['rejection_reason']);
    }

    public function test_rejects_task_whose_path_ends_in_a_report_only_sink(): void
    {
        $r = $this->validator()->validate([
            'downstream_consumer_path' => ['proposal', 'evaluation', 'summary_dashboard'],
        ]);

        $this->assertFalse($r['consumer_edge_valid']);
        $this->assertSame('report_only_sink', $r['rejection_reason']);
        $this->assertNull($r['consuming_block']);
    }

    public function test_rejects_task_whose_path_ends_in_an_unknown_block(): void
    {
        $r = $this->validator()->validate([
            'downstream_consumer_path' => ['proposal', 'some_untracked_experiment'],
        ]);

        $this->assertFalse($r['consumer_edge_valid']);
        $this->assertSame('detached_task', $r['rejection_reason']);
    }

    public function test_valid_task_returns_consumer_edge_valid_and_the_consuming_block(): void
    {
        $liveBlocks = ['task_fabric', 'queue', 'maestro', 'outcome_learning', 'control_plane', 'prompt_contract'];

        foreach ($liveBlocks as $block) {
            $r = $this->validator()->validate([
                'downstream_consumer_path' => ['proposal', 'seed_gate', $block],
            ]);

            $this->assertTrue($r['consumer_edge_valid'], "expected {$block} to be a valid consumer edge");
            $this->assertNull($r['rejection_reason']);
            $this->assertSame($block, $r['consuming_block']);
        }
    }

    public function test_schema_is_present_on_every_response(): void
    {
        $r = $this->validator()->validate(['downstream_consumer_path' => ['maestro']]);

        $this->assertSame(AtlasExternalBrainConsumerEdgeValidator::SCHEMA, $r['schema']);
    }
}
