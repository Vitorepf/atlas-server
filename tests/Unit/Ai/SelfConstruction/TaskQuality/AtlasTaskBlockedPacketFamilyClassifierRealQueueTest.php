<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskBlockedPacketFamilyClassifier;
use Tests\TestCase;

/**
 * Pins the real-queue families AtlasTaskBlockedPacketFamilyClassifier must classify instead of
 * collapsing into unknown: missing_scope_fields, forbidden_target_suspect,
 * duplicate_or_stale_brain_packet, and respec_candidate (already existed) — proven against
 * fixtures modeled on real blocked-queue ids (brain:cerebro4:char:*, codex-meta-*).
 */
final class AtlasTaskBlockedPacketFamilyClassifierRealQueueTest extends TestCase
{
    private function classify(array $packet): array
    {
        $packet['task_packet_id'] = $packet['task_packet_id'] ?? 'test-packet';

        return (new AtlasTaskBlockedPacketFamilyClassifier)->classifyOne($packet);
    }

    // ── missing_scope_fields ────────────────────────────────────────────────────

    public function test_missing_fields_list_classifies_as_missing_scope_fields(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-some-task-20260630-001',
            'objective' => 'Upgrade something',
            'missing_fields' => ['scope_in', 'allowed_files'],
        ]);

        $this->assertSame('missing_scope_fields', $r['family']);
        $this->assertSame('respec', $r['recommended_action']);
        $this->assertSame('repairable', $r['repairability']);
    }

    public function test_blocking_deficiency_mentioning_scope_in_classifies_as_missing_scope_fields(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-another-task-20260630-002',
            'blocking_deficiencies' => ['missing_scope_in_entries'],
        ]);

        $this->assertSame('missing_scope_fields', $r['family']);
    }

    // ── forbidden_target_suspect ─────────────────────────────────────────────────

    public function test_objective_mentioning_forbidden_axis_path_classifies_as_suspect(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-risky-task-20260630-003',
            'objective' => 'Touch app/Services/Ai/SelfImprovement/Foo.php to fix a bug',
        ]);

        $this->assertSame('forbidden_target_suspect', $r['family']);
        $this->assertSame('manual_review', $r['recommended_action']);
    }

    public function test_objective_mentioning_forge_path_classifies_as_suspect(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-forge-touch-20260630-004',
            'objective' => 'Touch forge/orchestrator/Runner.php to fix a config issue',
        ]);

        $this->assertSame('forbidden_target_suspect', $r['family']);
    }

    // ── duplicate_or_stale_brain_packet ──────────────────────────────────────────

    public function test_brain_seed_enumeration_id_classifies_as_duplicate_or_stale(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'brain:cerebro4:char:142',
            'objective' => 'Investigate character trait drift',
        ]);

        $this->assertSame('duplicate_or_stale_brain_packet', $r['family']);
        $this->assertSame('retire', $r['recommended_action']);
        $this->assertSame('unrepairable', $r['repairability']);
    }

    public function test_codex_meta_id_with_duplicate_objective_signal_classifies_as_stale(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-stale-work-20260630-005',
            'objective' => 'This objective is a duplicate of work already implemented by a prior task.',
        ]);

        $this->assertSame('duplicate_or_stale_brain_packet', $r['family']);
    }

    public function test_codex_meta_id_without_stale_signal_does_not_classify_as_duplicate(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-fresh-real-task-20260630-006',
            'objective' => 'Strengthen a service so it handles a real edge case.',
        ]);

        $this->assertNotSame('duplicate_or_stale_brain_packet', $r['family']);
    }

    // ── respec_candidate still reachable ─────────────────────────────────────────

    public function test_generic_blocking_deficiency_still_classifies_as_respec_candidate(): void
    {
        $r = $this->classify([
            'task_packet_id' => 'codex-meta-generic-blocked-20260630-007',
            'blocking_deficiencies' => ['vague_objective'],
        ]);

        $this->assertSame('respec_candidate', $r['family']);
    }

    // ── truly empty packet stays unknown with explicit insufficient_signal reason ──

    public function test_truly_empty_packet_remains_unknown_with_insufficient_signal_reason(): void
    {
        $r = $this->classify([]);

        $this->assertSame('unknown', $r['family']);
        $this->assertSame('manual_review', $r['recommended_action']);
        $this->assertStringContainsString('insufficient_signal', $r['rationale']);
    }

    public function test_classifier_is_deterministic_for_real_queue_fixtures(): void
    {
        $classifier = new AtlasTaskBlockedPacketFamilyClassifier;
        $packet = [
            'task_packet_id' => 'brain:cerebro4:char:99',
            'objective' => 'Investigate',
        ];

        $this->assertSame($classifier->classifyOne($packet), $classifier->classifyOne($packet));
    }
}
