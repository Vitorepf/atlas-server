<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Cognition\AcosProgram;

use App\Models\AiLearningProposal;
use App\Models\AiRunOutcome;
use App\Services\Ai\Cognition\AcosProgram\AcosMaxObraRetroService;
use App\Services\Ai\AtlasDecide\AtlasDecideLiveOutcomeFeedbackService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\CreatesAemorTables;
use Tests\TestCase;

/**
 * TETO-05 — Obra-Retro at lote close: obra outcomes feed OUTC-01 and lessons enter
 * the normal gated proposal queue, with no special promotion path.
 */
final class Teto05ObraRetroLoteCloseTest extends TestCase
{
    use CreatesAemorTables;

    private string $originalStoragePath;

    private string $tmpStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalStoragePath = storage_path();
        $this->tmpStorage = sys_get_temp_dir().'/atlas-teto05-'.bin2hex(random_bytes(4));
        mkdir($this->tmpStorage, 0o755, true);
        $this->app->useStoragePath($this->tmpStorage);

        $this->createAemorTables();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();

        config()->set('atlas.aemor.engineering_outcome_enabled', true);
        config()->set('atlas.aemor.engineering_outcome_mode', 'default');
        config()->set('atlas.ai.capture_quality_gate.mode', 'enforce');
        config()->set('atlas.memory_admission.mode', 'observe');

        $feedback = new AtlasDecideLiveOutcomeFeedbackService;
        $feedback->setLogPathForTesting($this->tmpStorage.'/live_outcomes.jsonl');
        app()->instance(AtlasDecideLiveOutcomeFeedbackService::class, $feedback);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('ai_learning_proposals');
        Schema::dropIfExists('ai_rag_feedback_events');
        Schema::dropIfExists('ai_benchmark_cases');
        Schema::dropIfExists('ai_temporal_certifications');
        Schema::dropIfExists('ai_heuristic_updates');
        Schema::dropIfExists('ai_compounding_memories');
        Schema::dropIfExists('ai_learning_candidates');
        Schema::dropIfExists('ai_run_outcomes');
        $this->dropAemorTables();

        app()->forgetInstance(AtlasDecideLiveOutcomeFeedbackService::class);
        $this->app->useStoragePath($this->originalStoragePath);

        parent::tearDown();
    }

    public function test_lote_zero_close_records_tagged_obra_outcomes_and_normal_lesson_candidates(): void
    {
        Artisan::call('atlas:acos:obra-retro', ['--lote' => 0, '--json' => true]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('recorded', $payload['status']);
        $this->assertSame(0, $payload['lote']);
        $this->assertGreaterThanOrEqual(1, $payload['outcomes']['recorded']);
        $this->assertGreaterThanOrEqual(1, $payload['lesson_candidates']['queued']);
        $this->assertSame('obra:acos-max', $payload['series_tag']);
        $this->assertSame('normal_capture_quality_gate_and_asi_02', $payload['lesson_candidates']['path']);

        $outcomes = AiRunOutcome::query()->get();
        $this->assertGreaterThanOrEqual(1, $outcomes->count());
        $this->assertTrue($outcomes->contains(
            fn (AiRunOutcome $outcome): bool => $outcome->flow_id === 'obra:acos-max'
                && data_get($outcome->payload, 'actor_tag') === 'obra:acos-max'
                && data_get($outcome->payload, 'lote') === 0
        ), 'obra outcomes must be separable from product series by flow/tag');

        $proposal = AiLearningProposal::query()->first();
        $this->assertNotNull($proposal);
        $this->assertSame('proposed', $proposal->status);
        $this->assertSame('obra:acos-max', $proposal->flow_id);
        $this->assertSame('ok', data_get($proposal->payload, 'quality.reason'));
        $this->assertSame('ASI-02', data_get($payload, 'lesson_candidates.items.0.memory_admission.slice'));
        $this->assertFalse((bool) data_get($payload, 'lesson_candidates.items.0.auto_promoted'));
    }

    public function test_boilerplate_obra_candidate_is_rejected_by_the_normal_quality_gate(): void
    {
        $result = app(AcosMaxObraRetroService::class)->proposeLessonCandidate([
            'kind' => 'failure_pattern',
            'summary' => 'produced passed outcome and should inform future routing',
            'evidence_refs' => ['scoreboard:lote-0:TETO-05'],
            'proposed_state' => ['should_repromote_sources' => [], 'should_demote_noise_count' => 0, 'target_context_sufficiency_min' => 70],
        ]);

        $this->assertSame('rejected_by_quality_gate', $result['status']);
        $this->assertSame('meta_stub', $result['reason']);
        $this->assertSame(0, AiLearningProposal::query()->count());
        $this->assertSame('ASI-02', data_get($result, 'memory_admission.slice'));
        $this->assertFalse((bool) ($result['auto_promoted'] ?? true));
    }
}
