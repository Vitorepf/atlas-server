<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProposalReplayCourt;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainProposalReplayCourtTest extends TestCase
{
    private function court(): AtlasExternalBrainProposalReplayCourt
    {
        return new AtlasExternalBrainProposalReplayCourt;
    }

    private function good(array $overrides = []): array
    {
        return array_merge([
            'id'             => 'p1',
            'target_file'    => 'app/Services/Foo.php',
            'objective'      => 'Implement FooService',
            'evidence'       => ['phpunit:FooTest'],
            'scaffold_score' => 0.80,
            'blast_radius'   => 0.30,
        ], $overrides);
    }

    // ── AC4: output shape ─────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->court()->adjudicate([]);
        $this->assertSame(AtlasExternalBrainProposalReplayCourt::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('accepted_proposals',  $r);
        $this->assertArrayHasKey('rejected_proposals',  $r);
        $this->assertArrayHasKey('replay_checks',       $r);
        $this->assertArrayHasKey('repairable_proposals', $r);
        $this->assertArrayHasKey('court_verdict',       $r);
    }

    // ── AC3: accepted path ────────────────────────────────────────────────────

    public function test_valid_proposal_accepted(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good()]]);
        $this->assertContains('p1', $r['accepted_proposals']);
        $this->assertEmpty($r['rejected_proposals']);
        $this->assertSame('all_accepted', $r['court_verdict']);
    }

    // ── AC2: duplicate target rejection ───────────────────────────────────────

    public function test_duplicate_target_rejected(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'              => [$this->good()],
            'existing_queue_targets' => ['app/Services/Foo.php'],
        ]);
        $reasons = $r['rejected_proposals'][0]['rejection_reasons'];
        $this->assertContains('duplicate_target', $reasons);
        $this->assertEmpty($r['accepted_proposals']);
    }

    public function test_duplicate_target_not_repairable(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'              => [$this->good()],
            'existing_queue_targets' => ['app/Services/Foo.php'],
        ]);
        $this->assertEmpty($r['repairable_proposals']);
    }

    // ── AC2: scaffold compliance rejection ───────────────────────────────────

    public function test_low_scaffold_score_rejected(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'         => [$this->good(['id' => 'bad', 'scaffold_score' => 0.40])],
            'min_scaffold_score' => 0.60,
        ]);
        $reasons = $r['rejected_proposals'][0]['rejection_reasons'];
        $this->assertContains('scaffold_compliance', $reasons);
    }

    public function test_low_scaffold_only_is_repairable(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'         => [$this->good(['scaffold_score' => 0.40])],
            'min_scaffold_score' => 0.60,
        ]);
        $this->assertNotEmpty($r['repairable_proposals']);
        $this->assertStringContainsString('scaffold', $r['repairable_proposals'][0]['repair_hint']);
    }

    // ── AC2: task-fabric check ────────────────────────────────────────────────

    public function test_empty_objective_fails_task_fabric(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good(['objective' => ''])]]);
        $reasons = $r['rejected_proposals'][0]['rejection_reasons'];
        $this->assertContains('task_fabric_check', $reasons);
    }

    public function test_blast_radius_too_high_fails_task_fabric(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'       => [$this->good(['blast_radius' => 0.95])],
            'max_blast_radius' => 0.90,
        ]);
        $reasons = $r['rejected_proposals'][0]['rejection_reasons'];
        $this->assertContains('task_fabric_check', $reasons);
    }

    public function test_task_fabric_failure_not_repairable(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good(['objective' => ''])]]);
        $this->assertEmpty($r['repairable_proposals']);
    }

    // ── AC2: evidence check ───────────────────────────────────────────────────

    public function test_missing_evidence_rejected_when_required(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'        => [$this->good(['evidence' => []])],
            'require_evidence' => true,
        ]);
        $reasons = $r['rejected_proposals'][0]['rejection_reasons'];
        $this->assertContains('evidence_check', $reasons);
    }

    public function test_missing_evidence_accepted_when_not_required(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'        => [$this->good(['evidence' => []])],
            'require_evidence' => false,
        ]);
        $this->assertContains('p1', $r['accepted_proposals']);
    }

    public function test_evidence_only_failure_is_repairable(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'        => [$this->good(['evidence' => []])],
            'require_evidence' => true,
        ]);
        $this->assertNotEmpty($r['repairable_proposals']);
        $this->assertStringContainsString('evidence', $r['repairable_proposals'][0]['repair_hint']);
    }

    // ── court_verdict ─────────────────────────────────────────────────────────

    public function test_verdict_empty_when_no_proposals(): void
    {
        $r = $this->court()->adjudicate([]);
        $this->assertSame('empty', $r['court_verdict']);
    }

    public function test_verdict_all_rejected(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good(['objective' => ''])]]);
        $this->assertSame('all_rejected', $r['court_verdict']);
    }

    public function test_verdict_partial_acceptance(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [
            $this->good(['id' => 'ok']),
            $this->good(['id' => 'bad', 'objective' => '']),
        ]]);
        $this->assertSame('partial_acceptance', $r['court_verdict']);
    }

    // ── replay_checks stats ───────────────────────────────────────────────────

    public function test_replay_checks_tracks_per_check_counts(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [
            $this->good(['id' => 'a']),
            $this->good(['id' => 'b', 'scaffold_score' => 0.1]),
        ]]);
        $sc = $r['replay_checks']['scaffold_compliance'];
        $this->assertSame(2, $sc['checked']);
        $this->assertSame(1, $sc['passed']);
        $this->assertSame(1, $sc['failed']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['proposals' => [
            $this->good(['id' => 'a']),
            $this->good(['id' => 'b', 'scaffold_score' => 0.3]),
        ]];
        $a = $this->court()->adjudicate($facts);
        $b = $this->court()->adjudicate($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC4: arena_ranking always present ─────────────────────────────────────

    public function test_arena_ranking_key_always_present(): void
    {
        $r = $this->court()->adjudicate([]);

        $this->assertArrayHasKey('arena_ranking', $r);
        $this->assertSame([], $r['arena_ranking']);
    }

    public function test_arena_ranking_empty_when_all_rejected(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [
            ['id' => 'p1', 'objective' => '', 'scaffold_score' => 0.9, 'evidence' => ['e']],
        ]]);

        $this->assertSame([], $r['arena_ranking']);
    }

    // ── AC3: arena_score and ranking order ────────────────────────────────────

    public function test_accepted_proposal_appears_in_arena_ranking(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good(['id' => 'p1'])]]);

        $this->assertCount(1, $r['arena_ranking']);
        $this->assertSame('p1', $r['arena_ranking'][0]['id']);
        $this->assertArrayHasKey('arena_score', $r['arena_ranking'][0]);
        $this->assertSame(1, $r['arena_ranking'][0]['rank']);
    }

    public function test_arena_ranking_sorted_by_score_descending(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [
            array_merge($this->good(['id' => 'low']),  ['leverage' => 0.1, 'implementability' => 0.1, 'risk' => 0.9]),
            array_merge($this->good(['id' => 'high']), ['leverage' => 0.9, 'implementability' => 0.9, 'risk' => 0.1]),
        ]]);

        $this->assertSame('high', $r['arena_ranking'][0]['id']);
        $this->assertSame('low',  $r['arena_ranking'][1]['id']);
        $this->assertGreaterThan($r['arena_ranking'][1]['arena_score'], $r['arena_ranking'][0]['arena_score']);
    }

    public function test_arena_score_uses_evidence_count(): void
    {
        $r1 = $this->court()->adjudicate(['proposals' => [
            $this->good(['id' => 'no-ev',   'evidence' => []]),
        ], 'require_evidence' => false]);
        $r2 = $this->court()->adjudicate(['proposals' => [
            $this->good(['id' => 'with-ev', 'evidence' => ['e1', 'e2', 'e3', 'e4', 'e5']]),
        ]]);

        $this->assertGreaterThan(
            $r1['arena_ranking'][0]['arena_score'],
            $r2['arena_ranking'][0]['arena_score'],
        );
    }

    // ── replay() ─────────────────────────────────────────────────────────────

    public function test_evidence_check_resolved_by_new_evidence_replays(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            ['id' => 'p1', 'original_rejection_reasons' => ['evidence_check'], 'new_evidence' => ['fresh evidence']],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_REPLAY, $result['decisions'][0]['decision']);
    }

    public function test_duplicate_target_still_in_queue_keeps_rejected(): void
    {
        $result = $this->court()->replay([
            'rejected_proposals' => [
                ['id' => 'p1', 'target_file' => 'app/Foo.php', 'original_rejection_reasons' => ['duplicate_target']],
            ],
            'current_queue_targets' => ['app/Foo.php'],
        ]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_KEEP_REJECTED, $result['decisions'][0]['decision']);
        $this->assertStringContainsString('duplicate_target', $result['decisions'][0]['reason']);
    }

    public function test_duplicate_target_no_longer_in_queue_replays(): void
    {
        $result = $this->court()->replay([
            'rejected_proposals' => [
                ['id' => 'p1', 'target_file' => 'app/Foo.php', 'original_rejection_reasons' => ['duplicate_target']],
            ],
            'current_queue_targets' => [],
        ]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_REPLAY, $result['decisions'][0]['decision']);
    }

    public function test_scope_changed_yields_rewrite_not_blind_replay(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            ['id' => 'p1', 'original_rejection_reasons' => ['task_fabric_check'], 'scope_changed' => true],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_REWRITE, $result['decisions'][0]['decision']);
    }

    public function test_unresolved_scaffold_compliance_keeps_rejected(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            ['id' => 'p1', 'original_rejection_reasons' => ['scaffold_compliance']],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_KEEP_REJECTED, $result['decisions'][0]['decision']);
    }

    public function test_dependency_fixed_resolves_scaffold_compliance_and_replays(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            ['id' => 'p1', 'original_rejection_reasons' => ['scaffold_compliance'], 'dependency_fixed' => true],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_REPLAY, $result['decisions'][0]['decision']);
    }

    public function test_unknown_rejection_reason_fails_closed_to_keep_rejected(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            ['id' => 'p1', 'original_rejection_reasons' => ['some_unknown_future_reason']],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_KEEP_REJECTED, $result['decisions'][0]['decision']);
    }

    public function test_multiple_reasons_all_must_resolve_to_replay(): void
    {
        $result = $this->court()->replay(['rejected_proposals' => [
            [
                'id' => 'p1',
                'original_rejection_reasons' => ['evidence_check', 'scaffold_compliance'],
                'new_evidence' => ['e1'],
                // dependency_fixed/scope_changed both false: scaffold_compliance still present
            ],
        ]]);

        $this->assertSame(AtlasExternalBrainProposalReplayCourt::DECISION_KEEP_REJECTED, $result['decisions'][0]['decision']);
    }

    // ── repair_synthesis ─────────────────────────────────────────────────────

    public function test_evidence_only_failure_repair_synthesis_has_required_fields(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'        => [$this->good(['evidence' => []])],
            'require_evidence' => true,
        ]);

        $synthesis = $r['repairable_proposals'][0]['repair_synthesis'];
        foreach (['missing_evidence', 'scaffold_repair_steps', 'acceptance_rewrite_hint', 'safe_to_resubmit'] as $k) {
            $this->assertArrayHasKey($k, $synthesis, "Missing key: {$k}");
        }
        $this->assertNotEmpty($synthesis['missing_evidence']);
        $this->assertSame([], $synthesis['scaffold_repair_steps']);
        $this->assertTrue($synthesis['safe_to_resubmit']);
    }

    public function test_low_scaffold_only_repair_synthesis_has_scaffold_steps(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'         => [$this->good(['scaffold_score' => 0.40])],
            'min_scaffold_score' => 0.60,
        ]);

        $synthesis = $r['repairable_proposals'][0]['repair_synthesis'];
        $this->assertNotEmpty($synthesis['scaffold_repair_steps']);
        $this->assertSame([], $synthesis['missing_evidence']);
        $this->assertTrue($synthesis['safe_to_resubmit']);
    }

    public function test_rejected_proposal_includes_safe_to_resubmit_true_when_repairable(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'        => [$this->good(['evidence' => []])],
            'require_evidence' => true,
        ]);

        $this->assertTrue($r['rejected_proposals'][0]['safe_to_resubmit']);
    }

    public function test_duplicate_target_rejected_proposal_safe_to_resubmit_false(): void
    {
        $r = $this->court()->adjudicate([
            'proposals'              => [$this->good()],
            'existing_queue_targets' => ['app/Services/Foo.php'],
        ]);

        $this->assertFalse($r['rejected_proposals'][0]['safe_to_resubmit']);
    }

    public function test_task_fabric_failure_rejected_proposal_safe_to_resubmit_false(): void
    {
        $r = $this->court()->adjudicate(['proposals' => [$this->good(['objective' => ''])]]);

        $this->assertFalse($r['rejected_proposals'][0]['safe_to_resubmit']);
    }
}
