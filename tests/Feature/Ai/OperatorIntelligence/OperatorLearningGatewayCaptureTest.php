<?php

namespace Tests\Feature\Ai\OperatorIntelligence;

use App\Models\OperatorLearningCandidate;
use App\Models\OperatorLearningSignal;
use App\Services\Ai\AiGatewayService;
use Illuminate\Support\Facades\File;
use Tests\Concerns\CreatesAiJobChoiceTables;
use Tests\Concerns\CreatesOperatorIntelligenceTables;
use Tests\TestCase;

final class OperatorLearningGatewayCaptureTest extends TestCase
{
    use CreatesAiJobChoiceTables;
    use CreatesOperatorIntelligenceTables;

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAiJobChoiceTables();
        $this->createOperatorIntelligenceTables();

        config([
            'atlas.ai.enabled' => true,
            'atlas_operator_intelligence.default_operator_id' => 'vitor',
            'atlas_operator_intelligence.chat_capture_enabled' => true,
            'atlas_operator_intelligence.auto_apply_enabled' => false,
            'atlas_operator_intelligence.shadow_mode' => true,
            'atlas_operator_intelligence.projection_path' => storage_path('framework/testing/operator-intelligence'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('framework/testing/operator-intelligence'));
        $this->dropOperatorIntelligenceTables();
        $this->dropAiJobChoiceTables();

        parent::tearDown();
    }

    public function test_gateway_captures_explicit_operator_learning_signal_from_human_chat(): void
    {
        $trace = app(AiGatewayService::class)->enqueueInteraction(
            'Da próxima vez, prefiro respostas curtas quando eu pedir status.',
            [
                'source_type' => 'manual',
                'provider' => 'codex_cli',
                'include_semantic_context' => false,
                'payload' => [
                    'app_surface' => 'atlas_cli',
                    'atlas_workflow_mode' => 'dev',
                    'operator_id' => 'vitor',
                ],
            ],
        );

        $signal = OperatorLearningSignal::query()->sole();
        $candidate = OperatorLearningCandidate::query()->sole();

        $this->assertSame('chat_explicit_operator_signal', $signal->source_type);
        $this->assertSame('ai_trace', $signal->source_ref_type);
        $this->assertSame($trace->id, $signal->source_ref_id);
        $this->assertSame($trace->id, $signal->trace_id);
        $this->assertSame('COL-156', $signal->taxonomy_item_id);
        $this->assertSame('vitor', $signal->operator_id);
        $this->assertSame($signal->id, $candidate->signal_id);
        $this->assertSame('candidate', $candidate->status);
        $this->assertSame($candidate->id, data_get($trace->refresh()->metadata, 'operator_learning_capture.candidate_id'));
        $this->assertSame('captured', data_get($trace->metadata, 'operator_learning_capture.status'));
    }

    public function test_gateway_does_not_capture_system_or_internal_sources(): void
    {
        app(AiGatewayService::class)->enqueueInteraction(
            'Prefiro respostas curtas quando eu pedir status.',
            [
                'source_type' => 'system',
                'provider' => 'codex_cli',
                'include_semantic_context' => false,
                'payload' => [
                    'app_surface' => 'atlas_quality_loop',
                    'atlas_workflow_mode' => 'quality_repair',
                ],
            ],
        );

        $this->assertSame(0, OperatorLearningSignal::query()->count());
        $this->assertSame(0, OperatorLearningCandidate::query()->count());
    }
}
