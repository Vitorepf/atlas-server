<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCritiqueQuorumReducer;
use Tests\TestCase;

final class AtlasExternalBrainCritiqueQuorumReducerTest extends TestCase
{
    private function svc(): AtlasExternalBrainCritiqueQuorumReducer
    {
        return new AtlasExternalBrainCritiqueQuorumReducer;
    }

    private function finding(
        string $type,
        string $severity,
        string $evidence = '',
        string $repair = '',
        bool $accepted = false,
        bool $resolvesConflict = false,
        float $evidenceStrength = 0.0,
        string $blockerClass = '',
    ): array {
        return array_filter([
            'type'              => $type,
            'severity'          => $severity,
            'evidence'          => $evidence,
            'repair_action'     => $repair,
            'accepted'          => $accepted ?: null,
            'resolves_conflict' => $resolvesConflict ?: null,
            'evidence_strength' => $evidenceStrength > 0.0 ? $evidenceStrength : null,
            'blocker_class'     => $blockerClass !== '' ? $blockerClass : null,
        ], static fn ($v) => $v !== null && $v !== '' && $v !== false);
    }

    private function reduce(array ...$outputs): array
    {
        return $this->svc()->reduce([
            'critique_outputs' => array_map(static fn (array $findings) => ['findings' => $findings], $outputs),
        ]);
    }

    // ── blocking findings ─────────────────────────────────────────────────────

    public function test_high_severity_unique_finding_is_blocking(): void
    {
        $r = $this->reduce([
            $this->finding('proxy_risk', 'high', 'objective contains cleanup keyword'),
        ]);

        $types = array_column($r['blocking_findings'], 'type');
        $this->assertContains('proxy_risk', $types);
    }

    public function test_low_severity_finding_is_accepted_tradeoff_not_blocking(): void
    {
        $r = $this->reduce([
            $this->finding('low_leverage', 'low', 'single file scope'),
        ]);

        $this->assertSame([], $r['blocking_findings']);
        $types = array_column($r['accepted_tradeoffs'], 'type');
        $this->assertContains('low_leverage', $types);
    }

    // ── dedup / merge ─────────────────────────────────────────────────────────

    public function test_duplicate_same_type_across_outputs_merged(): void
    {
        $r = $this->reduce(
            [$this->finding('proxy_risk', 'high', 'short evidence')],
            [$this->finding('proxy_risk', 'high', 'longer and more detailed evidence string here')],
        );

        $mergedTypes = array_column($r['merged_duplicates'], 'type');
        $this->assertContains('proxy_risk', $mergedTypes);

        // Only one blocking finding per type
        $blockingTypes = array_column($r['blocking_findings'], 'type');
        $this->assertSame(1, count(array_filter($blockingTypes, static fn (string $t): bool => $t === 'proxy_risk')));
    }

    public function test_merge_picks_longest_evidence(): void
    {
        $r = $this->reduce(
            [$this->finding('op_dep', 'high', 'short')],
            [$this->finding('op_dep', 'high', 'much longer and more informative evidence for the finding')],
        );

        $bf = array_filter($r['blocking_findings'], static fn ($f) => $f['type'] === 'op_dep');
        $this->assertStringContains('much longer', array_values($bf)[0]['evidence']);
    }

    public function test_merged_duplicates_records_correct_count(): void
    {
        $r = $this->reduce(
            [$this->finding('proxy_risk', 'high', 'e1')],
            [$this->finding('proxy_risk', 'high', 'e2')],
            [$this->finding('proxy_risk', 'high', 'e3')],
        );

        $entry = array_filter($r['merged_duplicates'], static fn ($d) => $d['type'] === 'proxy_risk');
        $this->assertSame(3, array_values($entry)[0]['merged_count']);
    }

    // ── unresolved conflicts ──────────────────────────────────────────────────

    public function test_severity_disagreement_without_resolution_is_conflict(): void
    {
        $r = $this->reduce(
            [$this->finding('low_leverage', 'high', 'one critique says high')],
            [$this->finding('low_leverage', 'low', 'another says low')],
        );

        $conflictTypes = array_column($r['unresolved_conflicts'], 'type');
        $this->assertContains('low_leverage', $conflictTypes);
        // Should NOT appear in blocking or tradeoffs
        $this->assertNotContains('low_leverage', array_column($r['blocking_findings'], 'type'));
        $this->assertNotContains('low_leverage', array_column($r['accepted_tradeoffs'], 'type'));
    }

    public function test_severity_disagreement_with_resolution_evidence_not_conflict(): void
    {
        $r = $this->reduce(
            [$this->finding('low_leverage', 'high', 'strong evidence', '', false, true)], // resolves_conflict=true
            [$this->finding('low_leverage', 'low', 'weak evidence')],
        );

        $conflictTypes = array_column($r['unresolved_conflicts'], 'type');
        $this->assertNotContains('low_leverage', $conflictTypes);
    }

    // ── repair actions ────────────────────────────────────────────────────────

    public function test_repair_actions_collected_from_blocking_findings(): void
    {
        $r = $this->reduce([
            $this->finding('proxy_risk', 'high', 'evidence', 'remove cleanup objective'),
        ]);

        $actions = array_column($r['repair_actions'], 'action');
        $this->assertContains('remove cleanup objective', $actions);
    }

    public function test_repair_actions_deduplicated_across_findings(): void
    {
        $r = $this->reduce(
            [$this->finding('proxy_risk', 'high', 'e1', 'same repair')],
            [$this->finding('proxy_risk', 'high', 'e2', 'same repair')],
        );

        $sameRepair = array_filter($r['repair_actions'], static fn ($a) => $a['action'] === 'same repair');
        $this->assertCount(1, $sameRepair);
    }

    // ── quorum decision ───────────────────────────────────────────────────────

    public function test_output_has_decision_and_reason_keys(): void
    {
        $r = $this->svc()->reduce([]);

        $this->assertArrayHasKey('decision', $r);
        $this->assertArrayHasKey('decision_reason', $r);
    }

    public function test_unanimous_approve_when_no_blockers(): void
    {
        $r = $this->reduce([
            $this->finding('style', 'low', 'minor style nit'),
            $this->finding('naming', 'low', 'variable name could be clearer'),
        ]);

        $this->assertSame('approve', $r['decision']);
    }

    public function test_high_severity_strong_evidence_and_agreement_rejects(): void
    {
        // 2 critics agree, evidence_strength=0.80, neutral blocker_class → rejects via agreement path
        $r = $this->reduce(
            [$this->finding('proxy_risk', 'high', 'objective is cleanup', '', false, false, 0.80, 'performance')],
            [$this->finding('proxy_risk', 'high', 'confirmed proxy pattern', '', false, false, 0.80, 'performance')],
        );

        $this->assertSame('reject', $r['decision']);
    }

    public function test_high_severity_weak_evidence_repairs_not_rejects(): void
    {
        // Single critic, evidence_strength=0.40 (below threshold) → repair, not reject
        $r = $this->reduce([
            $this->finding('proxy_risk', 'high', 'looks like cleanup', '', false, false, 0.40),
        ]);

        $this->assertSame('repair', $r['decision']);
    }

    public function test_unresolved_disagreement_escalates(): void
    {
        $r = $this->reduce(
            [$this->finding('low_leverage', 'high', 'one critic says high')],
            [$this->finding('low_leverage', 'low', 'another critic says low')],
        );

        $this->assertSame('escalate', $r['decision']);
    }

    public function test_safety_blocker_class_forces_reject_with_single_critic_strong_evidence(): void
    {
        // Safety class + strong evidence overrides the agreement minimum → reject
        $r = $this->reduce([
            $this->finding('data_corruption', 'high', 'irreversible write detected', '', false, false, 0.85, 'safety'),
        ]);

        $this->assertSame('reject', $r['decision']);
    }

    public function test_empty_input_approves(): void
    {
        $r = $this->svc()->reduce([]);

        $this->assertSame('approve', $r['decision']);
    }

    // ── schema + empty ────────────────────────────────────────────────────────

    public function test_empty_critiques_returns_empty_output(): void
    {
        $r = $this->svc()->reduce([]);

        $this->assertSame([], $r['blocking_findings']);
        $this->assertSame([], $r['merged_duplicates']);
        $this->assertSame([], $r['unresolved_conflicts']);
        $this->assertSame([], $r['repair_actions']);
        $this->assertSame([], $r['accepted_tradeoffs']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->reduce([]);

        $this->assertSame(AtlasExternalBrainCritiqueQuorumReducer::SCHEMA, $r['schema_version']);
    }

    // ── helper ────────────────────────────────────────────────────────────────

    private function assertStringContains(string $needle, string $haystack): void
    {
        $this->assertTrue(str_contains($haystack, $needle), "Expected '{$haystack}' to contain '{$needle}'");
    }
}
