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
}
