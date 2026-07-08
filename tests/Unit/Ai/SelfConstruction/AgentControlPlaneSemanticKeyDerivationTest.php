<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneTaskPacketBuilder;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSemanticDuplicateIndex;
use Tests\TestCase;

/**
 * Proves AgentControlPlaneTaskPacketBuilder derives capability_key/target_family/acceptance_intent
 * deterministically onto packet.metadata whenever the caller omits them, that a caller-supplied
 * value always wins, that derivation is stable across identical inputs, and that two same-
 * capability keyless candidates now collide through AtlasTaskFabricSemanticDuplicateIndex where
 * before they slid past on empty keys.
 */
final class AgentControlPlaneSemanticKeyDerivationTest extends TestCase
{
    private function builder(): AgentControlPlaneTaskPacketBuilder
    {
        return new AgentControlPlaneTaskPacketBuilder;
    }

    private function keylessInput(array $overrides = []): array
    {
        return array_merge([
            'objective' => 'Fix AtlasFooBar leak',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFooBar.php', 'tests/Unit/Ai/Foo/AtlasFooBarTest.php'],
            'acceptance_criteria' => ['leak no longer reproduces under load, php artisan test --filter=AtlasFooBarTest exits 0'],
            'required_evidence' => ['tests_or_gates_result'],
        ], $overrides);
    }

    // ── derives the three keys from a keyless packet input ──────────────────

    public function test_derives_capability_key_target_family_and_acceptance_intent_when_omitted(): void
    {
        $packet = $this->builder()->build($this->keylessInput());

        $this->assertArrayHasKey('metadata', $packet);
        $this->assertNotSame('', $packet['metadata']['capability_key']);
        $this->assertNotSame('', $packet['metadata']['target_family']);
        $this->assertNotSame('', $packet['metadata']['acceptance_intent']);

        $this->assertSame('fix_atlasfoobar', $packet['metadata']['capability_key']);
        $this->assertSame('app/Services/Ai/Foo', $packet['metadata']['target_family']);
        $this->assertStringNotContainsString('AtlasFooBar', $packet['metadata']['acceptance_intent']);
        $this->assertStringNotContainsString('php artisan test', $packet['metadata']['acceptance_intent']);
    }

    // ── caller-supplied keys win over derivation ─────────────────────────────

    public function test_caller_supplied_keys_win_over_derivation(): void
    {
        $packet = $this->builder()->build($this->keylessInput([
            'capability_key' => 'explicit_capability',
            'target_family' => 'explicit/family',
            'acceptance_intent' => 'explicit intent',
        ]));

        $this->assertSame('explicit_capability', $packet['metadata']['capability_key']);
        $this->assertSame('explicit/family', $packet['metadata']['target_family']);
        $this->assertSame('explicit intent', $packet['metadata']['acceptance_intent']);
    }

    // ── stable derivation for identical inputs ───────────────────────────────

    public function test_derivation_is_stable_for_identical_inputs(): void
    {
        $a = $this->builder()->build($this->keylessInput());
        $b = $this->builder()->build($this->keylessInput());

        $this->assertSame($a['metadata'], $b['metadata']);
    }

    // ── target_family from deepest shared directory of non-test allowed_files ──

    public function test_target_family_is_deepest_shared_directory_across_multiple_files(): void
    {
        $packet = $this->builder()->build($this->keylessInput([
            'allowed_files' => [
                'app/Services/Ai/Foo/AtlasFooBar.php',
                'app/Services/Ai/Foo/Sub/AtlasFooBarHelper.php',
            ],
        ]));

        $this->assertSame('app/Services/Ai/Foo', $packet['metadata']['target_family']);
    }

    // ── two same-capability keyless candidates now collide at admission ──────

    public function test_two_same_capability_keyless_candidates_collide_through_duplicate_index(): void
    {
        $packetA = $this->builder()->build($this->keylessInput([
            'task_packet_id' => 'pkt-a',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFooBar.php'],
        ]));
        $packetB = $this->builder()->build($this->keylessInput([
            'task_packet_id' => 'pkt-b',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFooBar.php'],
        ]));

        $existing = [[
            'task_packet_id' => $packetA['task_packet_id'],
            'capability_key' => $packetA['metadata']['capability_key'],
            'target_family' => $packetA['metadata']['target_family'],
            'allowed_files' => $packetA['normalized_scope']['allowed_files'],
            'acceptance_intent' => $packetA['metadata']['acceptance_intent'],
        ]];

        $candidate = [
            'task_packet_id' => $packetB['task_packet_id'],
            'capability_key' => $packetB['metadata']['capability_key'],
            'target_family' => $packetB['metadata']['target_family'],
            'allowed_files' => $packetB['normalized_scope']['allowed_files'],
            'acceptance_intent' => $packetB['metadata']['acceptance_intent'],
        ];

        $result = (new AtlasTaskFabricSemanticDuplicateIndex)->check($candidate, $existing);

        $this->assertSame(AtlasTaskFabricSemanticDuplicateIndex::DUPLICATE_SEMANTIC, $result['status']);
        $this->assertSame('pkt-a', $result['matched_packet_id']);
    }

    public function test_before_derivation_keyless_candidates_would_not_have_collided(): void
    {
        // Regression proof: with blank capability_key/target_family (the pre-derivation
        // behavior), the index never matches even for genuinely identical work.
        $existing = [[
            'task_packet_id' => 'pkt-a',
            'capability_key' => '',
            'target_family' => '',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFooBar.php'],
            'acceptance_intent' => '',
        ]];
        $candidate = [
            'task_packet_id' => 'pkt-b',
            'capability_key' => '',
            'target_family' => '',
            'allowed_files' => ['app/Services/Ai/Foo/AtlasFooBar.php'],
            'acceptance_intent' => '',
        ];

        $result = (new AtlasTaskFabricSemanticDuplicateIndex)->check($candidate, $existing);

        $this->assertSame(AtlasTaskFabricSemanticDuplicateIndex::DUPLICATE_NONE, $result['status']);
    }
}
