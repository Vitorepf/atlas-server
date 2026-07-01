<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainTaskGraphPrerequisiteUnlockDetectorTest extends TestCase
{
    private function detector(): AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector
    {
        return new AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector;
    }

    private function firstWhereTaskId(array $rows, string $taskId): ?array
    {
        foreach ($rows as $row) {
            if (($row['task_id'] ?? null) === $taskId) {
                return $row;
            }
        }

        return null;
    }

    // ── Schema / envelope ────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $result = $this->detector()->detect(['tasks' => [['task_id' => 'a']]]);

        foreach (['schema', 'prerequisite_candidates', 'downstream_unlock_counts', 'blocked_dependents', 'implementation_order_hints', 'inferred_prerequisites'] as $key) {
            $this->assertArrayHasKey($key, $result, "Missing key: {$key}");
        }
        $this->assertSame(AtlasExternalBrainTaskGraphPrerequisiteUnlockDetector::SCHEMA, $result['schema']);
    }

    public function test_empty_tasks_returns_empty_output(): void
    {
        $result = $this->detector()->detect(['tasks' => []]);

        $this->assertSame([], $result['prerequisite_candidates']);
        $this->assertSame([], $result['blocked_dependents']);
        $this->assertSame([], $result['inferred_prerequisites']);
    }

    // ── Pre-existing explicit depends_on/unlocks behavior (unchanged) ─────────

    public function test_explicit_unlocks_forms_dependent_edge(): void
    {
        $result = $this->detector()->detect(['tasks' => [
            ['task_id' => 'prereq', 'unlocks' => ['dep'], 'status' => 'queued'],
            ['task_id' => 'dep', 'status' => 'queued'],
        ]]);

        $this->assertSame(1, $result['downstream_unlock_counts']['prereq']);
        $this->assertSame('prereq', $result['prerequisite_candidates'][0]['task_id']);
    }

    // ── AC2: task referencing a missing capability gets a prerequisite edge ──

    public function test_task_requiring_a_missing_capability_is_not_treated_as_standalone(): void
    {
        $result = $this->detector()->detect(['tasks' => [
            ['task_id' => 'dependent', 'requires_capabilities' => ['atlas:gate:release_verdict'], 'status' => 'queued'],
        ]]);

        $this->assertCount(1, $result['inferred_prerequisites']);
        $this->assertSame('missing', $result['inferred_prerequisites'][0]['status']);
        $this->assertSame('atlas:gate:release_verdict', $result['inferred_prerequisites'][0]['requires_capability']);
        $this->assertNull($result['inferred_prerequisites'][0]['resolved_task_id']);

        $blocked = $this->firstWhereTaskId($result['blocked_dependents'], 'dependent');
        $this->assertNotNull($blocked, 'a task referencing a missing capability must surface as blocked, not standalone');
        $this->assertContains('missing_capability:atlas:gate:release_verdict', $blocked['blocked_by']);
    }

    // ── AC3: resolved/already-queued prerequisite targets are linked, not duplicated ──

    public function test_required_capability_matching_a_queued_task_is_linked_not_duplicated(): void
    {
        $result = $this->detector()->detect(['tasks' => [
            ['task_id' => 'cli-provider', 'capability' => 'atlas:cli:gate_check', 'status' => 'queued'],
            ['task_id' => 'dependent', 'requires_capabilities' => ['atlas:cli:gate_check'], 'status' => 'queued'],
        ]]);

        $this->assertSame('resolved', $result['inferred_prerequisites'][0]['status']);
        $this->assertSame('cli-provider', $result['inferred_prerequisites'][0]['resolved_task_id']);
        $this->assertSame(1, $result['downstream_unlock_counts']['cli-provider']);
        $this->assertSame('cli-provider', $result['prerequisite_candidates'][0]['task_id']);
        $this->assertSame(['dependent'], $result['prerequisite_candidates'][0]['dependents']);
    }

    public function test_required_capability_already_declared_via_depends_on_is_not_duplicated(): void
    {
        $result = $this->detector()->detect(['tasks' => [
            ['task_id' => 'cli-provider', 'capability' => 'atlas:cli:gate_check', 'status' => 'queued'],
            [
                'task_id' => 'dependent',
                'depends_on' => ['cli-provider'],
                'requires_capabilities' => ['atlas:cli:gate_check'],
                'status' => 'queued',
            ],
        ]]);

        $this->assertSame(1, $result['downstream_unlock_counts']['cli-provider']);
        $this->assertSame(['dependent'], $result['prerequisite_candidates'][0]['dependents']);
    }

    // ── AC4: unlock_notes name which task family becomes safe ────────────────

    public function test_prerequisite_candidate_emits_unlock_notes_grouped_by_task_family(): void
    {
        $result = $this->detector()->detect(['tasks' => [
            ['task_id' => 'prereq', 'capability' => 'atlas:gate:release_verdict', 'unlocks' => ['dep-1', 'dep-2', 'dep-3'], 'status' => 'queued'],
            ['task_id' => 'dep-1', 'task_family' => 'merge_governor', 'status' => 'queued'],
            ['task_id' => 'dep-2', 'task_family' => 'merge_governor', 'status' => 'queued'],
            ['task_id' => 'dep-3', 'status' => 'queued'],
        ]]);

        $candidate = collect($result['prerequisite_candidates'])->firstWhere('task_id', 'prereq');
        $this->assertNotNull($candidate);
        $notes = implode(' | ', $candidate['unlock_notes']);
        $this->assertStringContainsString('atlas:gate:release_verdict', $notes);
        $this->assertStringContainsString('merge_governor', $notes);
        $this->assertStringContainsString('dep-1', $notes);
        $this->assertStringContainsString('dep-2', $notes);
        $this->assertStringContainsString('dep-3', $notes);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $tasks = ['tasks' => [
            ['task_id' => 'cli-provider', 'capability' => 'atlas:cli:gate_check', 'status' => 'queued'],
            ['task_id' => 'dependent', 'requires_capabilities' => ['atlas:cli:gate_check'], 'status' => 'queued'],
        ]];

        $this->assertSame(
            json_encode($this->detector()->detect($tasks)),
            json_encode($this->detector()->detect($tasks)),
        );
    }
}
