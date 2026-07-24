<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Console\Commands\AtlasReviewDeepCommand;
use App\Models\AtlasTask;
use App\Services\Ai\EngineeringKernel\AgentQosExcellenceLaw;
use App\Services\Engineering\CodeGraph\CodeGraphReviewContextAssembler;
use ReflectionMethod;
use Tests\TestCase;

/**
 * P2g-QOS / R94: review:deep is surface audit only — never eng gate / EngineeringOutcome pass.
 */
final class AaeosAgentQosReviewDeepNonGatingTest extends TestCase
{
    public function test_law_blocks_review_deep_as_eng_gate(): void
    {
        $blockers = AgentQosExcellenceLaw::blockers([
            'review_deep_as_eng_gate' => true,
        ]);

        $this->assertContains('review_deep_non_gating', $blockers);
    }

    public function test_deep_packet_schema_is_explicitly_non_gating(): void
    {
        $command = new AtlasReviewDeepCommand;
        $method = new ReflectionMethod(AtlasReviewDeepCommand::class, 'buildPacket');
        $method->setAccessible(true);

        $task = new AtlasTask;
        $task->forceFill(['id' => 'task-p2g-review-deep']);

        $assembler = $this->createMock(CodeGraphReviewContextAssembler::class);
        $assembler->method('assemble')->willReturn([
            'schema_version' => CodeGraphReviewContextAssembler::SCHEMA,
            'changed' => [],
            'blast_radius' => [],
            'pack' => [],
            'stats' => ['changed' => 0, 'blast_radius' => 0, 'candidates' => 0, 'included' => 0, 'depth' => 1],
        ]);

        $packet = $method->invoke(
            $command,
            $task,
            [
                'run_id' => 'run-p2g-review',
                'summary' => ['blocking_findings' => []],
                'recorded_findings' => [],
            ],
            $assembler,
        );

        $this->assertSame('atlas.review.deep_packet.v1', $packet['schema_version']);
        $this->assertFalse($packet['eng_gate']);
        $this->assertTrue($packet['non_gating_surface']);
        $this->assertFalse($packet['mints_engineering_outcome']);
        $this->assertArrayNotHasKey('engineering_outcome', $packet);
        $this->assertArrayNotHasKey('gate_verdict', $packet);
        $this->assertArrayNotHasKey('cert_verdict', $packet);
    }
}
