<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\LearningTransfer;

use App\Services\Ai\SelfConstruction\LearningTransfer\AtlasLearningTransferGiveBackLessonRouter;
use PHPUnit\Framework\TestCase;

final class AtlasLearningTransferGiveBackLessonRouterTest extends TestCase
{
    private AtlasLearningTransferGiveBackLessonRouter $router;

    protected function setUp(): void
    {
        $this->router = new AtlasLearningTransferGiveBackLessonRouter;
    }

    // ── AC: each give_back reason maps to exactly one repair channel ──

    public function test_scope_gap_maps_to_scope_repair(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'scope_gap', 'reason' => 'allowed_files insufficient', 'packet_id' => 'pkt-1'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_SCOPE, $result['routed_lessons'][0]['channel']);
        $this->assertStringContainsString('Widen allowed_files', $result['routed_lessons'][0]['recommendation']);
    }

    public function test_weak_objective_maps_to_objective_repair(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'weak_objective', 'reason' => 'objective unclear', 'packet_id' => 'pkt-2'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_OBJECTIVE, $result['routed_lessons'][0]['channel']);
    }

    public function test_contradictory_acceptance_maps_to_acceptance_repair(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'contradictory_acceptance', 'reason' => 'acceptance criteria break fixture', 'packet_id' => 'pkt-3'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_ACCEPTANCE, $result['routed_lessons'][0]['channel']);
    }

    public function test_insufficient_evidence_maps_to_evidence_repair(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'insufficient_evidence', 'reason' => 'proof missing', 'packet_id' => 'pkt-4'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_EVIDENCE, $result['routed_lessons'][0]['channel']);
    }

    public function test_duplicate_capability_maps_to_duplicate_prevention(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'duplicate_capability', 'reason' => 'already exists', 'packet_id' => 'pkt-5'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_DUPLICATE_PREVENTION, $result['routed_lessons'][0]['channel']);
    }

    public function test_forbidden_target_maps_to_quarantine(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'forbidden_target', 'reason' => 'pétreo core', 'packet_id' => 'pkt-6'],
            ],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::CHANNEL_QUARANTINE, $result['routed_lessons'][0]['channel']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->router->route([
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [],
        ]);

        $this->assertSame(AtlasLearningTransferGiveBackLessonRouter::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('routed_lessons', $result);
        $this->assertArrayHasKey('routed_count', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $input = [
            'originator_id' => 'orig-1',
            'round_id' => 'round-1',
            'lessons' => [
                ['class' => 'scope_gap', 'reason' => 'allowed_files insufficient', 'packet_id' => 'pkt-1'],
                ['class' => 'insufficient_evidence', 'reason' => 'proof missing', 'packet_id' => 'pkt-2'],
            ],
        ];

        $a = $this->router->route($input);
        $b = $this->router->route($input);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
