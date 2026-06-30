<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialCritiqueTournament;
use Tests\TestCase;

final class AtlasExternalBrainAdversarialCritiqueTournamentTest extends TestCase
{
    private function svc(): AtlasExternalBrainAdversarialCritiqueTournament
    {
        return new AtlasExternalBrainAdversarialCritiqueTournament;
    }

    private function packet(string $objective, array $acceptance = [], array $files = []): array
    {
        return ['objective' => $objective, 'acceptance_criteria' => $acceptance, 'allowed_files' => $files];
    }

    private function tournament(array $packets): array
    {
        return $this->svc()->run(['packets' => $packets]);
    }

    // ── clean batch ───────────────────────────────────────────────────────────

    public function test_clean_batch_not_blocked(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to detect capability stalls and emit a retirement recommendation',
                ['given a stalled capability the system must emit retire with evidence'],
                ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
        $this->assertNull($r['winning_attack']);
        $this->assertSame([], $r['blocking_findings']);
    }

    // ── lens 1: proxy_risk ────────────────────────────────────────────────────

    public function test_proxy_keyword_in_objective_triggers_blocking(): void
    {
        $r = $this->tournament([
            $this->packet('Cleanup unused imports and remove dead code from the pipeline'),
        ]);

        $this->assertTrue($r['blocking']);
        $lenses = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('proxy_risk', $lenses);
    }

    public function test_rename_keyword_triggers_proxy_risk(): void
    {
        $r = $this->tournament([
            $this->packet('Rename the old handler to conform to naming convention'),
        ]);

        $blocking = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('proxy_risk', $blocking);
    }

    // ── lens 2: operator_dependency ───────────────────────────────────────────

    public function test_manually_in_acceptance_criteria_triggers_blocking(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to produce a deployment report',
                ['the operator must manually verify each output before proceeding'],
            ),
        ]);

        $this->assertTrue($r['blocking']);
        $lenses = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('operator_dependency', $lenses);
    }

    // ── lens 3: duplicate_target ──────────────────────────────────────────────

    public function test_duplicate_allowed_file_triggers_blocking(): void
    {
        $sharedFile = 'app/Services/AtlasCore.php';
        $r = $this->tournament([
            $this->packet('Implement AtlasFoo to score items', [], [$sharedFile]),
            $this->packet('Implement AtlasBar to rank items', [], [$sharedFile]),
        ]);

        $this->assertTrue($r['blocking']);
        $lenses = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('duplicate_target', $lenses);
    }

    // ── lens 4: false_green_acceptance (low severity) ─────────────────────────

    public function test_all_exit_code_criteria_is_allowed_tradeoff_not_blocking(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to export a report',
                ['the command exits 0 on success', 'the process exits 0 with no error output'],
            ),
        ]);

        $this->assertFalse($r['blocking']);
        $lenses = array_column($r['allowed_tradeoffs'], 'lens');
        $this->assertContains('false_green_acceptance', $lenses);
    }

    // ── lens 5: low_leverage (low severity) ───────────────────────────────────

    public function test_short_objective_is_allowed_tradeoff_not_blocking(): void
    {
        $r = $this->tournament([
            $this->packet('Add a logger', ['it logs'], ['app/Foo.php', 'tests/FooTest.php']),
        ]);

        $this->assertFalse($r['blocking']);
        $lenses = array_column($r['allowed_tradeoffs'], 'lens');
        $this->assertContains('low_leverage', $lenses);
    }

    public function test_single_file_scope_is_allowed_tradeoff(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to validate the certification evidence chain and emit a structured verdict',
                ['given a valid chain the verdict must be accepted'],
                ['app/Services/AtlasFoo.php'],
            ),
        ]);

        $lenses = array_column($r['allowed_tradeoffs'], 'lens');
        $this->assertContains('low_leverage', $lenses);
    }

    // ── winning_attack / constraints ──────────────────────────────────────────

    public function test_winning_attack_is_first_blocking_lens(): void
    {
        // proxy_risk fires first (lens 1) before duplicate_target (lens 3)
        $r = $this->tournament([
            $this->packet('Cleanup and rename everything', [], ['shared.php']),
            $this->packet('Normal packet', [], ['shared.php']),
        ]);

        $this->assertSame('proxy_risk', $r['winning_attack']);
    }

    public function test_revised_batch_constraints_deduplicated(): void
    {
        // Two packets both have proxy_risk — constraint must appear once
        $r = $this->tournament([
            $this->packet('Cleanup old files', [], ['a.php']),
            $this->packet('Cleanup orphan classes', [], ['b.php']),
        ]);

        $types = array_column($r['revised_batch_constraints'], 'constraint_type');
        $this->assertSame(array_unique($types), $types);
        $this->assertContains('proxy_risk', $types);
    }

    // ── schema + structure ────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->run([]);

        $this->assertSame(AtlasExternalBrainAdversarialCritiqueTournament::SCHEMA, $r['schema_version']);
    }

    public function test_result_has_all_required_keys(): void
    {
        $r = $this->svc()->run([]);

        foreach (['schema_version', 'blocking', 'winning_attack', 'blocking_findings', 'allowed_tradeoffs', 'revised_batch_constraints'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    // ── lens 6: proxy_work ────────────────────────────────────────────────────

    public function test_proxy_work_fires_for_proxy_objective(): void
    {
        $r = $this->tournament([
            $this->packet('Cleanup unused imports and remove dead code'),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertContains('proxy_work', array_column($r['blocking_findings'], 'lens'));
    }

    // ── lens 7: weak_runnable_proof ───────────────────────────────────────────

    public function test_weak_runnable_proof_fires_for_empty_acceptance_criteria(): void
    {
        $r = $this->tournament([
            $this->packet('Implement AtlasFoo to process routing events', []),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertContains('weak_runnable_proof', array_column($r['blocking_findings'], 'lens'));
    }

    public function test_weak_runnable_proof_does_not_fire_when_criteria_present(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to detect capability stalls and emit a retirement recommendation',
                ['given a stalled capability the system must emit retire with evidence'],
                ['app/Services/Foo.php', 'tests/Unit/FooTest.php'],
            ),
        ]);

        $this->assertNotContains('weak_runnable_proof', array_column($r['blocking_findings'], 'lens'));
    }

    // ── lens 8: template_farm_shape ───────────────────────────────────────────

    public function test_template_farm_shape_fires_for_structurally_identical_packets(): void
    {
        $batch = array_map(static fn (int $i): array => [
            'objective'           => "Implement AtlasAdapter{$i} to extend capacity",
            'acceptance_criteria' => ['tests pass'],
            'allowed_files'       => [
                "app/Services/AtlasAdapter{$i}Service.php",
                "tests/Unit/AtlasAdapter{$i}ServiceTest.php",
            ],
        ], range(1, 4));

        $r = $this->tournament($batch);

        $this->assertTrue($r['blocking']);
        $this->assertContains('template_farm_shape', array_column($r['blocking_findings'], 'lens'));
    }

    public function test_template_farm_shape_does_not_fire_for_small_batches(): void
    {
        $r = $this->tournament([
            $this->packet('Implement AtlasAlpha to process events', [], ['app/Alpha.php']),
            $this->packet('Implement AtlasBeta to process events', [], ['app/Beta.php']),
        ]);

        $this->assertNotContains('template_farm_shape', array_column($r['blocking_findings'], 'lens'));
    }

    // ── lens 9: hidden_human_dependency ──────────────────────────────────────

    public function test_hidden_human_dependency_fires_for_manual_confirmation(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to export daily stats',
                ['operator must manually review the output before next step'],
            ),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertContains('hidden_human_dependency', array_column($r['blocking_findings'], 'lens'));
    }

    // ── lens 10: overwide_allowed_files ───────────────────────────────────────

    public function test_overwide_allowed_files_fires_when_packet_has_too_many_files(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to cover all edge cases across the system',
                ['all tests pass'],
                ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php', 'g.php'],
            ),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertContains('overwide_allowed_files', array_column($r['blocking_findings'], 'lens'));
    }

    public function test_overwide_allowed_files_does_not_fire_at_threshold(): void
    {
        $r = $this->tournament([
            $this->packet(
                'Implement AtlasFoo to cover all edge cases across the system',
                ['all tests pass'],
                ['a.php', 'b.php', 'c.php', 'd.php', 'e.php', 'f.php'],
            ),
        ]);

        $this->assertNotContains('overwide_allowed_files', array_column($r['blocking_findings'], 'lens'));
    }

    // ── lens 11: duplicate_scope ──────────────────────────────────────────────

    public function test_duplicate_scope_fires_for_identical_objectives(): void
    {
        $r = $this->tournament([
            $this->packet('Implement AtlasFoo to process routing events', [], ['app/A.php']),
            $this->packet('Implement AtlasFoo to process routing events', [], ['app/B.php']),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertContains('duplicate_scope', array_column($r['blocking_findings'], 'lens'));
    }

    public function test_duplicate_scope_is_case_insensitive(): void
    {
        $r = $this->tournament([
            $this->packet('Implement AtlasFoo To Process Routing Events', [], ['app/A.php']),
            $this->packet('implement atlasfoo to process routing events', [], ['app/B.php']),
        ]);

        $this->assertContains('duplicate_scope', array_column($r['blocking_findings'], 'lens'));
    }
}
