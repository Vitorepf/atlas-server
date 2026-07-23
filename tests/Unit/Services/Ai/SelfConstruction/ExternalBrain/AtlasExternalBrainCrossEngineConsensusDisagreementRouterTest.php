<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCrossEngineConsensusDisagreementRouter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCrossEngineConsensusDisagreementRouterTest extends TestCase
{
    private AtlasExternalBrainCrossEngineConsensusDisagreementRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasExternalBrainCrossEngineConsensusDisagreementRouter;
    }

    // ── AC: conflicting recommendations emit verification tasks ──

    public function test_conflicting_recommendations_emit_verification_tasks(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'topic' => 'next_leverage',
            'recommendations' => [
                ['engine' => 'claude', 'answer' => 'repair'],
                ['engine' => 'codex', 'answer' => 'expansion'],
                ['engine' => 'cursor', 'answer' => 'repair'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainCrossEngineConsensusDisagreementRouter::VERDICT_DISAGREEMENT, $result['verdict']);
        $this->assertTrue($result['disagreement']);
        $this->assertSame(2, $result['verification_task_count']);

        $tasks = $result['verification_tasks'];
        $this->assertSame('repair', $tasks[0]['answer_under_test']);
        $this->assertSame(['claude', 'cursor'], $tasks[0]['supporting_engines']);
        $this->assertSame(['codex'], $tasks[0]['opposing_engines']);

        $this->assertSame('expansion', $tasks[1]['answer_under_test']);
        $this->assertSame(['codex'], $tasks[1]['supporting_engines']);
        $this->assertSame(['claude', 'cursor'], $tasks[1]['opposing_engines']);
    }

    // ── AC: aligned recommendations pass with confidence ──

    public function test_aligned_recommendations_pass_with_confidence(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'topic' => 'next_leverage',
            'recommendations' => [
                ['engine' => 'claude', 'answer' => 'repair'],
                ['engine' => 'codex', 'answer' => 'repair'],
                ['engine' => 'cursor', 'answer' => 'repair'],
            ],
        ]);

        $this->assertSame(AtlasExternalBrainCrossEngineConsensusDisagreementRouter::VERDICT_ALIGNED, $result['verdict']);
        $this->assertFalse($result['disagreement']);
        $this->assertSame(0, $result['verification_task_count']);
        $this->assertSame(1, $result['unique_answer_count']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'topic' => 'next_leverage',
            'recommendations' => [],
        ]);

        $this->assertSame(AtlasExternalBrainCrossEngineConsensusDisagreementRouter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('verification_tasks', $result);
        $this->assertArrayHasKey('disagreement', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'topic' => 'next_leverage',
            'recommendations' => [
                ['engine' => 'claude', 'answer' => 'repair'],
                ['engine' => 'codex', 'answer' => 'expansion'],
            ],
        ];

        $a = $this->router->route($input);
        $b = $this->router->route($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
