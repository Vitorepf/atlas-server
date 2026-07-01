<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\KnowledgeSync;

use App\Services\Ai\SelfConstruction\KnowledgeSync\AtlasKnowledgeSyncDocsDriftGate;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasKnowledgeSyncDocsDriftGate: fresh evidence ⇒ conformant=true; missing docs-health ⇒
 * conformant=false with docs_health_missing; failed sync ⇒ sync_result_not_ok; stale observed_at ⇒
 * docs_health_stale; no docs-change bypass (no docs-related artifacts required) ⇒ conformant=true.
 */
final class AtlasKnowledgeSyncDocsDriftGateTest extends TestCase
{
    private function requiredArtifacts(array $ids): array
    {
        return array_map(static fn (string $id): array => ['artifact_id' => $id], $ids);
    }

    private function freshEvidence(int $now): array
    {
        return [
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 60, 'debt_facts' => []],
            'sync_result' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ];
    }

    public function test_fresh_evidence_conformant_true(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate(array_merge($this->freshEvidence($now), [
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
        ]));
        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_missing_docs_health_yields_blocker(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
            // docs_health intentionally missing
            'sync_result' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ]);
        $this->assertFalse($r['conformant']);
        $this->assertContains('docs_health_missing', $r['blockers']);
    }

    public function test_failed_sync_yields_sync_result_not_ok(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'sync_result' => ['ok' => false, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ]);
        $this->assertContains('sync_result_not_ok', $r['blockers']);
    }

    public function test_stale_observed_at_yields_stale_blocker(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check']),
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 99999999], // way stale
            'now_unix' => $now,
        ]);
        $this->assertContains('docs_health_stale', $r['blockers']);
    }

    public function test_no_docs_change_bypass_conformant_true(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['code-intelligence-index']),
            // no docs evidence at all — should still be conformant because no docs artifact required
            'now_unix' => time(),
        ]);
        $this->assertTrue($r['conformant']);
    }

    public function test_debt_facts_are_surfaced_verbatim_no_scalar(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 60, 'debt_facts' => ['baseline_oversized', 'stale_ADR']],
            'sync_result' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ]);
        $this->assertContains('docs_health:baseline_oversized', $r['debt_facts']);
        $this->assertContains('docs_health:stale_ADR', $r['debt_facts']);
        // anti-Goodhart: no score key in envelope.
        $this->assertArrayNotHasKey('score', $r);
    }

    public function test_docs_changed_without_docs_health_required_fails_closed(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['code-intelligence-index']),
            'changed_docs' => ['docs/engineering-knowledge-base/some-doc.md'],
            'now_unix' => time(),
        ]);
        $this->assertFalse($r['conformant']);
        $this->assertContains('docs_changed_but_docs_health_check_not_required', $r['blockers']);
        $this->assertContains('docs_changed_but_knowledge_sync_not_required', $r['blockers']);
    }

    public function test_docs_changed_without_sync_required_fails_closed(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check']),
            'changed_docs' => ['droid-wiki/systems/evolution-loop/index.md'],
            'docs_health' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'now_unix' => $now,
        ]);
        $this->assertFalse($r['conformant']);
        $this->assertContains('docs_changed_but_knowledge_sync_not_required', $r['blockers']);
        $this->assertArrayNotHasKey('score', $r);
    }

    public function test_non_canonical_path_in_changed_docs_does_not_fail_closed(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts([]),
            'changed_docs' => ['app/Console/Commands/SomeCommand.php'],
            'now_unix' => time(),
        ]);
        $this->assertTrue($r['conformant']);
    }

    public function test_empty_changed_docs_bypass_without_docs_artifacts(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts([]),
            'changed_docs' => [],
            'now_unix' => time(),
        ]);
        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
    }

    public function test_blockers_sorted_deterministically(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts([]),
            'changed_docs' => ['docs/some-doc.md'],
            'now_unix' => time(),
        ]);
        $sorted = $r['blockers'];
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $r['blockers'], 'blockers must be sorted deterministically');
    }

    // ── AC1/AC3: capability-changing task requires all 4 sync artifacts ────────────

    public function test_capability_changed_without_any_required_artifacts_fails_closed(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts([]),
            'capability_changed' => true,
            'now_unix' => time(),
        ]);
        $this->assertFalse($r['conformant']);
        $this->assertContains('capability_changed_but_docs_health_check_not_required', $r['blockers']);
        $this->assertContains('capability_changed_but_knowledge_sync_not_required', $r['blockers']);
        $this->assertContains('capability_changed_but_code_index_not_required', $r['blockers']);
        $this->assertContains('capability_changed_but_memory_update_not_required', $r['blockers']);
    }

    public function test_capability_changed_missing_code_index_evidence_is_blocked(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate(array_merge($this->freshEvidence($now), [
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync', 'code-intelligence-index', 'memory-update']),
            'capability_changed' => true,
            'memory_update' => ['ok' => true, 'observed_at_unix' => $now - 60],
            // code_index intentionally missing
        ]));
        $this->assertFalse($r['conformant']);
        $this->assertContains('code_index_missing', $r['blockers']);
        $this->assertContains('run_index_code', $r['next_sync_actions']);
    }

    public function test_capability_changed_missing_memory_update_evidence_is_blocked(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate(array_merge($this->freshEvidence($now), [
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync', 'code-intelligence-index', 'memory-update']),
            'capability_changed' => true,
            'code_index' => ['ok' => true, 'observed_at_unix' => $now - 60],
            // memory_update intentionally missing
        ]));
        $this->assertFalse($r['conformant']);
        $this->assertContains('memory_update_missing', $r['blockers']);
        $this->assertContains('record_memory_update', $r['next_sync_actions']);
    }

    public function test_capability_changed_with_all_fresh_evidence_is_conformant(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate(array_merge($this->freshEvidence($now), [
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync', 'code-intelligence-index', 'memory-update']),
            'capability_changed' => true,
            'code_index' => ['ok' => true, 'observed_at_unix' => $now - 60],
            'memory_update' => ['ok' => true, 'observed_at_unix' => $now - 60],
        ]));
        $this->assertTrue($r['conformant']);
        $this->assertSame([], $r['blockers']);
        $this->assertSame([], $r['next_sync_actions']);
    }

    public function test_non_capability_changing_task_does_not_require_code_index_or_memory(): void
    {
        // code-intelligence-index required but no capability change and no evidence supplied:
        // must NOT be gated — matches the plain "no docs change" bypass behavior.
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['code-intelligence-index']),
            'now_unix' => time(),
        ]);
        $this->assertTrue($r['conformant']);
    }

    // ── AC2: required_artifacts and next_sync_actions always present ───────────────

    public function test_required_artifacts_echoed_in_output(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
            'now_unix' => time(),
        ]);
        $this->assertSame(['docs-health-check', 'engineering-knowledge-sync'], $r['required_artifacts']);
    }

    public function test_next_sync_actions_present_and_empty_when_conformant(): void
    {
        $now = time();
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate(array_merge($this->freshEvidence($now), [
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
        ]));
        $this->assertArrayHasKey('next_sync_actions', $r);
        $this->assertSame([], $r['next_sync_actions']);
    }

    public function test_next_sync_actions_maps_docs_and_sync_blockers_to_remediation(): void
    {
        $r = (new AtlasKnowledgeSyncDocsDriftGate)->evaluate([
            'required_artifacts' => $this->requiredArtifacts(['docs-health-check', 'engineering-knowledge-sync']),
            'now_unix' => time(),
        ]);
        $this->assertContains('run_docs_health_check', $r['next_sync_actions']);
        $this->assertContains('run_engineering_knowledge_sync', $r['next_sync_actions']);
    }
}
