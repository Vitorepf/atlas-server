<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostCommitNoGapRunner;
use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPostMergeCompressionAuditor;
use PHPUnit\Framework\TestCase;

/**
 * Proves the PostCommitNoGapRunner composes wave resequencer, compression auditor,
 * originator gate, and end-to-end proof into one gap-free verdict.
 *
 * AC1: a wave with remaining obsolete tasks, unmet compression, blocked originator gate,
 *      or incomplete E2E proof → no_gap=false, reasons list each blocker.
 * AC2: a fully clean wave where all four checks pass → no_gap=true, reasons=[], and
 *      each sub-result is present in the output.
 */
final class AtlasExternalBrainPostCommitNoGapRunnerTest extends TestCase
{
    private AtlasExternalBrainPostCommitNoGapRunner $runner;

    protected function setUp(): void
    {
        $this->runner = new AtlasExternalBrainPostCommitNoGapRunner;
    }

    public function test_wave_with_remaining_gap_blocks_stop(): void
    {
        // Feed facts that trigger failures in all four sub-organs.
        $result = $this->runner->run([
            'resequence_facts' => [
                'previous_wave' => ['task_ids' => ['task-1']],
                'queue_tasks' => [
                    ['task_id' => 'task-1', 'superseded_by_commit' => true],
                ],
            ],
            'compression_facts' => [
                'promised' => ['fitness_score' => 0.9],
                'actual'   => ['fitness_score' => 0.5],
            ],
            'originator_proposal' => [
                'organ_id' => 'test-organ',
                'organ_kind' => 'detector',
                'gap_closed' => '',
                'downstream_consumer' => '',
                'proof_gate' => '',
                'compounding_effect' => '',
            ],
            'proof_facts' => [
                'dossier' => [],
            ],
        ]);

        // no_gap must be false because every check fails.
        $this->assertFalse($result['no_gap']);

        // Reasons must list each blocker.
        $this->assertContains('wave_has_obsolete_tasks', $result['reasons']);
        $this->assertContains('compression_unmet', $result['reasons']);
        $this->assertContains('originator_gate_blocked', $result['reasons']);
        $this->assertContains('e2e_proof_incomplete', $result['reasons']);

        // All four sub-results are present.
        $this->assertArrayHasKey('resequenced_wave', $result);
        $this->assertArrayHasKey('compression_audit', $result);
        $this->assertArrayHasKey('originator_gate', $result);
        $this->assertArrayHasKey('e2e_proof', $result);

        // Verify sub-result details.
        $this->assertNotEmpty($result['resequenced_wave']['removed_obsolete_task_ids']);
        $this->assertSame(
            AtlasExternalBrainPostMergeCompressionAuditor::STATUS_REPAIR_REQUIRED,
            $result['compression_audit']['status'],
        );
        $this->assertFalse($result['originator_gate']['seedable']);
        $this->assertFalse($result['e2e_proof']['complete']);
    }

    public function test_proven_gap_free_wave_allows_stop(): void
    {
        // Feed facts that satisfy all four sub-organs.
        $result = $this->runner->run([
            'resequence_facts' => [
                'previous_wave' => ['task_ids' => ['task-1']],
                'queue_tasks' => [
                    ['task_id' => 'task-1'],
                ],
            ],
            'compression_facts' => [
                'promised' => ['fitness_score' => 0.8],
                'actual'   => ['fitness_score' => 0.9],
            ],
            'originator_proposal' => [
                'organ_id' => 'valid-organ',
                'organ_kind' => 'builder',
                'gap_closed' => 'close-autonomy-circuit',
                'downstream_consumer' => 'originator-planner',
                'proof_gate' => 'AtlasExternalBrainAutonomyClaimAuditor',
                'compounding_effect' => 'self-sustaining-loop',
            ],
            'proof_facts' => [
                'dossier' => [
                    'discovery'       => ['evidence' => 'discovery done', 'hash' => hash('sha256', 'discovery done')],
                    'task_spec'       => ['evidence' => 'task spec written', 'hash' => hash('sha256', 'task spec written')],
                    'serving'         => ['evidence' => 'serving result', 'hash' => hash('sha256', 'serving result')],
                    'muscle_outcome'  => ['evidence' => 'outcome recorded', 'hash' => hash('sha256', 'outcome recorded')],
                    'learning_update' => ['evidence' => 'learning stored', 'hash' => hash('sha256', 'learning stored')],
                    'next_decision'   => ['evidence' => 'next decision made', 'hash' => hash('sha256', 'next decision made')],
                ],
            ],
        ]);

        // no_gap must be true — all checks pass.
        $this->assertTrue($result['no_gap']);
        $this->assertSame([], $result['reasons']);

        // All four sub-results are present.
        $this->assertArrayHasKey('resequenced_wave', $result);
        $this->assertArrayHasKey('compression_audit', $result);
        $this->assertArrayHasKey('originator_gate', $result);
        $this->assertArrayHasKey('e2e_proof', $result);

        // Verify sub-result details.
        $this->assertSame([], $result['resequenced_wave']['removed_obsolete_task_ids']);
        $this->assertSame(
            AtlasExternalBrainPostMergeCompressionAuditor::STATUS_PROMISE_MET,
            $result['compression_audit']['status'],
        );
        $this->assertTrue($result['originator_gate']['seedable']);
        $this->assertTrue($result['e2e_proof']['complete']);

        // Schema is present.
        $this->assertSame(
            AtlasExternalBrainPostCommitNoGapRunner::SCHEMA,
            $result['schema'],
        );
    }

    public function test_empty_facts_defaults_to_gap_detected(): void
    {
        // Zero facts should mean no wave, no promises, no proposal, no dossier → gap.
        $result = $this->runner->run([]);

        $this->assertFalse($result['no_gap']);
        $this->assertNotEmpty($result['reasons']);
        $this->assertSame([], $result['resequenced_wave']['removed_obsolete_task_ids']);
        // Empty promised=0, actual=0 → no metric where actual<0, so promise_met
        $this->assertSame(
            AtlasExternalBrainPostMergeCompressionAuditor::STATUS_PROMISE_MET,
            $result['compression_audit']['status'],
        );
        $this->assertFalse($result['originator_gate']['seedable']);
        $this->assertFalse($result['e2e_proof']['complete']);
    }
}
