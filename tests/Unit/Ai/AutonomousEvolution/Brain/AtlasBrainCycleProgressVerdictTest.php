<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainCycleProgressVerdict;
use Tests\TestCase;

/**
 * S7 keystone: a cycle counts as progress ONLY when ALL five conditions hold (doc written, packet enqueued,
 * classifier not 'rejected_proxy', seed gate admitted, at least one grounded citation). Drop any one → zero.
 */
final class AtlasBrainCycleProgressVerdictTest extends TestCase
{
    private function fullCtx(): array
    {
        return [
            'doc_written' => true,
            'enqueued' => true,
            'classifier_class' => 'evolucao',
            'seed_gate_admit' => true,
            'grounded_citations' => ['app/Services/Ai/Foo/Bar.php:42', 'docs/loop-canonical-definition.md'],
        ];
    }

    public function test_counts_as_progress_when_all_conditions_hold(): void
    {
        $result = (new AtlasBrainCycleProgressVerdict)->verdict($this->fullCtx());

        self::assertTrue($result['counts_as_progress']);
        self::assertSame([], $result['reasons']);
    }

    public function test_zero_progress_when_a_condition_is_missing(): void
    {
        // [mutateKey, value, expectedReason] — drop/break each condition in turn; each must zero the verdict.
        $cases = [
            ['doc_written', false, 'no_doc_section_written'],
            ['enqueued', false, 'no_packet_enqueued'],
            ['classifier_class', 'rejected_proxy', 'classifier_rejected_proxy'],
            ['seed_gate_admit', false, 'seed_gate_blocked'],
            ['grounded_citations', [], 'no_grounded_citation'],
            ['grounded_citations', ['', '  '], 'no_grounded_citation'],
            ['grounded_citations', null, 'no_grounded_citation'],
        ];

        foreach ($cases as [$mutateKey, $value, $expectedReason]) {
            $ctx = $this->fullCtx();
            $ctx[$mutateKey] = $value;

            $result = (new AtlasBrainCycleProgressVerdict)->verdict($ctx);

            self::assertFalse($result['counts_as_progress'], "{$mutateKey} missing must zero the verdict");
            self::assertContains($expectedReason, $result['reasons']);
        }
    }

    public function test_empty_ctx_reports_every_missing_reason(): void
    {
        $result = (new AtlasBrainCycleProgressVerdict)->verdict([]);

        self::assertFalse($result['counts_as_progress']);
        self::assertEqualsCanonicalizing([
            'no_doc_section_written',
            'no_packet_enqueued',
            'seed_gate_blocked',
            'no_grounded_citation',
        ], $result['reasons']);
        // classifier_class absent (not 'rejected_proxy') is NOT a reason — only an explicit reject is.
        self::assertNotContains('classifier_rejected_proxy', $result['reasons']);
    }
}
