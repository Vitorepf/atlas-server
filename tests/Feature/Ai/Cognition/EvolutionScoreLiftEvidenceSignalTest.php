<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition;

use App\Models\AiCompoundingMemory;
use App\Models\AiRagFeedbackEvent;
use App\Models\AiRunOutcome;
use App\Services\Ai\Cognition\AtlasAcosEvolutionScoreService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BootsCompoundingSchema;
use Tests\TestCase;

final class EvolutionScoreLiftEvidenceSignalTest extends TestCase
{
    use BootsCompoundingSchema;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootCompoundingSchema();
        config([
            'atlas.ai.loop.learning_recall_use_lift.enabled' => true,
            'atlas.ai.context_feedback.global_hints_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        $this->dropCompoundingSchema();
        parent::tearDown();
    }

    public function test_feedback_loop_vivo_does_not_score_maximum_when_lift_is_insufficient(): void
    {
        $this->seedFeedback('baseline-only', 'failed', ['docs/a.md' => 'useful']);

        $report = app(AtlasAcosEvolutionScoreService::class)->build();
        $signal = collect($report['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal')['feedback_loop_vivo'];

        $this->assertLessThan(2.5, (float) $signal['points']);
        $this->assertStringContainsString('dual_read old_feedback=', (string) $signal['evidence']);
        $this->assertStringContainsString('lift_status=insufficient_live_ab_evidence', (string) $signal['evidence']);
    }

    public function test_feedback_loop_vivo_scores_maximum_when_measurement_ready_with_ten_cases_per_arm(): void
    {
        $memory = $this->activeMemory();
        for ($i = 0; $i < 10; $i++) {
            $this->seedFeedback('with-'.$i, 'passed', ['compounding_memory:'.$memory->memory_hash => 'used']);
            $this->seedFeedback('without-'.$i, 'failed', ['docs/b'.$i.'.md' => 'useful']);
        }

        $report = app(AtlasAcosEvolutionScoreService::class)->build();
        $signal = collect($report['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal')['feedback_loop_vivo'];

        $this->assertSame(2.5, (float) $signal['points']);
        $this->assertStringContainsString('measurement_ready=true', (string) $signal['evidence']);
        $this->assertStringContainsString('with_cases=10', (string) $signal['evidence']);
        $this->assertStringContainsString('without_cases=10', (string) $signal['evidence']);
    }

    public function test_feedback_loop_vivo_partial_scoring_follows_case_count_not_blockers(): void
    {
        $memory = $this->activeMemory();
        for ($i = 0; $i < 5; $i++) {
            $this->seedFeedback('partial-with-'.$i, 'passed', ['compounding_memory:'.$memory->memory_hash => 'used']);
            $this->seedFeedback('partial-without-'.$i, 'failed', ['docs/c'.$i.'.md' => 'useful']);
        }

        $report = app(AtlasAcosEvolutionScoreService::class)->build();
        $signal = collect($report['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal')['feedback_loop_vivo'];

        $this->assertSame(1.25, (float) $signal['points']);
        $this->assertStringContainsString('with_cases=5', (string) $signal['evidence']);
    }

    public function test_licoes_geridas_requires_active_compounding_memory_served_by_recall(): void
    {
        $memory = $this->activeMemory();
        $this->seedFeedback('served-compounding', 'passed', ['compounding_memory:'.$memory->memory_hash => 'included']);

        $report = app(AtlasAcosEvolutionScoreService::class)->build();
        $signal = collect($report['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal')['licoes_geridas'];

        $this->assertSame(2.5, (float) $signal['points']);
        $this->assertStringContainsString('active_compounding_served=yes', (string) $signal['evidence']);
        $this->assertStringContainsString('dual_read old_licoes=', (string) $signal['evidence']);
    }

    public function test_evolution_score_command_reflects_honest_lift_state(): void
    {
        $this->seedFeedback('cmd-baseline', 'failed', ['docs/cmd.md' => 'useful']);

        Artisan::call('atlas:cognition:evolution-score', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);
        $signal = collect($payload['dimensions']['inteligencia_entregue']['signals'])->keyBy('signal')['feedback_loop_vivo'];

        $this->assertLessThan(2.5, (float) $signal['points']);
        $this->assertStringContainsString('dual_read', (string) $signal['evidence']);
    }

    /**
     * @param  array<string,string>  $sourceUtility
     */
    private function seedFeedback(string $runId, string $status, array $sourceUtility): void
    {
        AiRunOutcome::query()->create([
            'schema_version' => 'atlas.ai.compounding.outcome.v1',
            'run_id' => $runId,
            'trace_id' => null,
            'flow_id' => 'atlas_dev',
            'outcome_status' => $status,
            'flow_quality' => 80,
            'retrieval_quality' => 80,
            'execution_quality' => 80,
            'evidence_quality' => 80,
            'human_override' => false,
            'learning_required' => true,
            'missed_signals' => [],
            'evidence_refs' => ['receipt:'.$runId],
            'payload' => [],
            'outcome_hash' => hash('sha256', 'outcome-'.$runId),
            'evaluated_at' => now(),
        ]);

        AiRagFeedbackEvent::query()->create([
            'schema_version' => 'atlas.ai.rag.feedback.v1',
            'retrieval_receipt_id' => 'receipt-'.$runId,
            'flow_id' => 'atlas_dev',
            'query_plan_hash' => hash('sha256', $runId),
            'included_sources' => count($sourceUtility),
            'used_sources' => count($sourceUtility),
            'noise_sources' => 0,
            'missed_required_sources' => [],
            'context_sufficiency' => 80,
            'post_execution_utility' => 80,
            'source_utility' => $sourceUtility,
            'outcome_status' => $status,
            'failure_reason' => null,
            'next_retrieval_hint' => null,
            'payload' => [],
            'feedback_hash' => hash('sha256', 'feedback-'.$runId),
        ]);
    }

    private function activeMemory(): AiCompoundingMemory
    {
        return AiCompoundingMemory::query()->create([
            'schema_version' => 'atlas.ai.compounding.memory.v1',
            'learning_candidate_id' => null,
            'memory_type' => 'routing_memory',
            'scope' => 'atlas-server',
            'flow_id' => 'atlas_dev',
            'status' => 'active',
            'claim' => 'Evolution score lift evidence memory.',
            'confidence' => 90,
            'evidence_refs' => ['receipt:ope06'],
            'revalidation_policy' => 'revalidate_on_failure_or_expiry',
            'valid_until' => now()->addDays(7),
            'last_revalidated_at' => now(),
            'payload' => [],
            'memory_hash' => hash('sha256', 'ope06-memory'),
        ]);
    }
}
