<?php

namespace Tests\Unit\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Cognitive\PersonalWorkedExample\WorkedExampleSerializer;
use Tests\TestCase;

class WorkedExampleSerializerTest extends TestCase
{
    public function test_serializes_candidate_into_personal_ledger_worked_example_shape(): void
    {
        $serialized = (new WorkedExampleSerializer)->serialize([
            'topic' => 'queue-batching',
            'domain' => 'programming',
            'title' => 'Queue batching lock fix',
            'problem_context' => 'Latency increased after batching.',
            'raw_steps' => [
                ['action' => 'Measure lock contention.', 'reasoning' => 'CPU was idle.', 'why_works' => 'Targets the real bottleneck.'],
            ],
            'redaction_applied' => ['provider_safe' => true],
        ]);

        $this->assertSame('personal_ledger', $serialized['source']);
        $this->assertSame('queue-batching', $serialized['topic']);
        $this->assertSame('programming', $serialized['domain']);
        $this->assertSame('Measure lock contention.', $serialized['solution_full'][0]['action']);
        $this->assertSame([1, 3, 5], $serialized['fading_levels']['2']);
    }

    public function test_generates_default_transfer_steps_when_source_has_no_steps(): void
    {
        $serialized = (new WorkedExampleSerializer)->serialize([
            'topic' => 'strategy-review',
            'domain' => 'strategic_decision',
        ]);

        $this->assertCount(5, $serialized['solution_full']);
        $this->assertStringContainsString('Compare resultado', $serialized['solution_full'][3]['action']);
    }
}
