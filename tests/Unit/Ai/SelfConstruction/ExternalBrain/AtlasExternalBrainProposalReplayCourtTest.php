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
}
