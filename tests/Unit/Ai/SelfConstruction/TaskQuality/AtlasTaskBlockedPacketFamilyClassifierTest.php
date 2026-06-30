<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use Tests\TestCase;

final class AtlasTaskBlockedPacketFamilyClassifierTest extends TestCase
{
    // ── AC1: every listed family maps deterministically ──────────────────────

    public function test_forbidden_target_classified_with_retire_action(): void
    {
        $r = $this->classify(['blocking_deficiencies' => ['forbidden_self_target']]);

        $this->assertSame('forbidden_target', $r['family']);
        $this->assertSame('retire', $r['recommended_action']);
        $this->assertSame('unrepairable', $r['repairability']);
        $this->assertSame('high', $r['confidence']);
    }

    public function test_contradictory_acceptance_classified_with_retire(): void
    {
        $r = $this->classify(['blocking_deficiencies' => ['contradictory_acceptance']]);

        $this->assertSame('contradictory_acceptance', $r['family']);
        $this->assertSame('retire', $r['recommended_action']);
    }

    public function test_schema_mismatch_classified_as_repairable(): void
    {
        $r = $this->classify(['blocking_deficiencies' => ['schema mismatch']]);

        $this->assertSame('schema_mismatch', $r['family']);
        $this->assertSame('respec', $r['recommended_action']);
        $this->assertSame('repairable', $r['repairability']);
    }

    public function test_missing_impl_file_classified_as_repairable(): void
    {
        $r = $this->classify(['quality_facts' => ['impl_file_missing' => true]]);

        $this->assertSame('missing_impl_file', $r['family']);
        $this->assertSame('respec', $r['recommended_action']);
    }

    public function test_duplicate_already_done_classified_with_retire(): void
    {
        $r = $this->classify(['quality_facts' => ['already_done' => true]]);

        $this->assertSame('duplicate_already_done', $r['family']);
        $this->assertSame('retire', $r['recommended_action']);
    }

    public function test_dormant_cli_arm_proxy_classified(): void
    {
        $r = $this->classify(['quality_facts' => ['dormant_cli_arm_proxy' => true]]);

        $this->assertSame('dormant_cli_arm_proxy', $r['family']);
    }

    public function test_unknown_classified_as_manual_review_with_low_confidence(): void
    {
        $r = $this->classify([]);

        $this->assertSame('unknown', $r['family']);
        $this->assertSame('manual_review', $r['recommended_action']);
        $this->assertSame('low', $r['confidence']);
    }

    // ── AC2: repeated give_back with vs without prior success ────────────────

    public function test_repeated_give_back_with_prior_success_is_respec_candidate(): void
    {
        $r = $this->classify(['give_back_count' => 10, 'has_prior_success' => true]);

        $this->assertSame('repeated_give_back', $r['family']);
        $this->assertSame('respec', $r['recommended_action']);
        $this->assertSame('repairable', $r['repairability']);
    }

    public function test_repeated_give_back_without_prior_success_is_retire(): void
    {
        $r = $this->classify(['give_back_count' => 10, 'has_prior_success' => false]);

        $this->assertSame('repeated_give_back', $r['family']);
        $this->assertSame('retire', $r['recommended_action']);
        $this->assertSame('unrepairable', $r['repairability']);
    }

    // ── AC3: unknown stays manual_review with low confidence ─────────────────

    public function test_unknown_has_low_confidence_and_unknown_repairability(): void
    {
        $r = $this->classify(['status' => 'blocked']);

        $this->assertSame('unknown', $r['family']);
        $this->assertSame('low', $r['confidence']);
        $this->assertSame('unknown', $r['repairability']);
    }

    // ── output shape ─────────────────────────────────────────────────────────

    public function test_output_has_all_required_fields(): void
    {
        $r = $this->classify([]);

        foreach (['schema_version', 'task_packet_id', 'family', 'recommended_action', 'repairability', 'rationale', 'required_respec_fields', 'confidence'] as $key) {
            $this->assertArrayHasKey($key, $r, "missing key: {$key}");
        }
    }

    public function test_classify_batch_returns_list(): void
    {
        $classifier = new AtlasTaskBlockedPacketFamilyClassifier;
        $results = $classifier->classifyBatch([
            ['task_packet_id' => 'a', 'blocking_deficiencies' => ['forbidden_self_target']],
            ['task_packet_id' => 'b', 'quality_facts' => ['already_done' => true]],
        ]);

        $this->assertCount(2, $results);
        $this->assertSame('a', $results[0]['task_packet_id']);
        $this->assertSame('forbidden_target', $results[0]['family']);
        $this->assertSame('b', $results[1]['task_packet_id']);
        $this->assertSame('duplicate_already_done', $results[1]['family']);
    }

    private function classify(array $packet): array
    {
        $packet['task_packet_id'] = $packet['task_packet_id'] ?? 'test-packet';

        return (new AtlasTaskBlockedPacketFamilyClassifier)->classifyOne($packet);
    }
}
