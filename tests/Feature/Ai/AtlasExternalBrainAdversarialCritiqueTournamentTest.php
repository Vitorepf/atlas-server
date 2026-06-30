<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAdversarialCritiqueTournament;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAdversarialCritiqueTournamentTest extends TestCase
{
    private AtlasExternalBrainAdversarialCritiqueTournament $tournament;

    protected function setUp(): void
    {
        $this->tournament = new AtlasExternalBrainAdversarialCritiqueTournament;
    }

    private function judge(array $packets): array
    {
        return $this->tournament->run(['packets' => $packets]);
    }

    private function cleanPacket(array $overrides = []): array
    {
        return array_merge([
            'objective'          => 'Harden the evidence pipeline so re-prove loops close faster',
            'allowed_files'      => ['app/Services/Ai/Foo.php', 'tests/Feature/Ai/FooTest.php'],
            'acceptance_criteria' => [
                'Runnable /opt/homebrew/bin/php test proves the pipeline closes the loop.',
            ],
        ], $overrides);
    }

    // ── AC1: rhetoric-only proposals (no evidence/implementability/runnable AC) are blocked ─

    public function test_proxy_keyword_in_objective_blocks_packet(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'cleanup unused imports in the service layer']),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertSame('proxy_risk', $r['winning_attack']);
    }

    public function test_reformat_keyword_blocks_packet(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'reformat all config files for consistency']),
        ]);

        $this->assertTrue($r['blocking']);
    }

    public function test_all_exit_code_only_criteria_is_flagged_as_false_green(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['acceptance_criteria' => [
                'php artisan test exits 0',
                'composer exits 0',
            ]]),
        ]);

        $lenses = array_column($r['allowed_tradeoffs'], 'lens');
        $this->assertContains('false_green_acceptance', $lenses);
    }

    public function test_short_objective_is_flagged_as_low_leverage(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'Fix a bug']),  // <50 chars
        ]);

        $lenses = array_merge(
            array_column($r['blocking_findings'], 'lens'),
            array_column($r['allowed_tradeoffs'], 'lens'),
        );
        $this->assertContains('low_leverage', $lenses);
    }

    public function test_operator_dependency_in_criteria_blocks_packet(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['acceptance_criteria' => [
                'Operator confirms the migration was successful.',
            ]]),
        ]);

        $this->assertTrue($r['blocking']);
    }

    // ── AC2: result contains dissenting critiques and required repairs ─────────

    public function test_clean_batch_is_not_blocking(): void
    {
        $r = $this->judge([$this->cleanPacket()]);

        $this->assertFalse($r['blocking']);
        $this->assertNull($r['winning_attack']);
    }

    public function test_blocking_result_includes_findings_with_evidence(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'cleanup dead code everywhere']),
        ]);

        $this->assertNotEmpty($r['blocking_findings']);
        foreach ($r['blocking_findings'] as $f) {
            $this->assertArrayHasKey('evidence',  $f);
            $this->assertArrayHasKey('lens',      $f);
            $this->assertArrayHasKey('severity',  $f);
        }
    }

    public function test_blocking_result_includes_revised_batch_constraints(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'remove unused imports across all files']),
        ]);

        $this->assertNotEmpty($r['revised_batch_constraints']);
        $this->assertTrue($r['revised_batch_constraints'][0]['resolution_required']);
    }

    public function test_non_blocking_result_exposes_tradeoffs(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['acceptance_criteria' => ['phpunit exits 0']]),
        ]);

        // false_green_acceptance is low-severity → allowed_tradeoff, not a blocker
        $this->assertFalse($r['blocking']);
        $this->assertNotEmpty($r['allowed_tradeoffs']);
    }

    public function test_schema_is_present(): void
    {
        $r = $this->judge([]);

        $this->assertSame(AtlasExternalBrainAdversarialCritiqueTournament::SCHEMA, $r['schema_version']);
    }

    // ── AC3: no winner when all proposals are proxy/template-farm variants ────

    public function test_all_proxy_packets_produce_blocking_result(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['objective' => 'cleanup service class formatting']),
            $this->cleanPacket(['objective' => 'remove whitespace in config files']),
        ]);

        $this->assertTrue($r['blocking']);
        $this->assertNotNull($r['winning_attack']);
    }

    public function test_template_farm_shape_blocks_structurally_identical_packets(): void
    {
        // 3+ packets all sharing the same PHP suffix fingerprint → template_farm_shape
        $makePacket = fn (string $obj, string $file): array => [
            'objective'          => $obj,
            'allowed_files'      => [$file, str_replace('app/', 'tests/', $file)],
            'acceptance_criteria' => ['Runnable test passes.'],
        ];

        $r = $this->judge([
            $makePacket('Harden Foo so the contract holds under load', 'app/Services/Ai/FooService.php'),
            $makePacket('Harden Bar so the contract holds under load', 'app/Services/Ai/BarService.php'),
            $makePacket('Harden Baz so the contract holds under load', 'app/Services/Ai/BazService.php'),
        ]);

        $this->assertTrue($r['blocking']);
        $lenses = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('template_farm_shape', $lenses);
    }

    public function test_duplicate_target_file_blocks_batch(): void
    {
        $r = $this->judge([
            $this->cleanPacket(['allowed_files' => ['app/Services/Shared.php']]),
            $this->cleanPacket(['objective' => 'Improve Shared to cover edge cases', 'allowed_files' => ['app/Services/Shared.php']]),
        ]);

        $this->assertTrue($r['blocking']);
        $lenses = array_column($r['blocking_findings'], 'lens');
        $this->assertContains('duplicate_target', $lenses);
    }

    // ── AC4: pure and deterministic ───────────────────────────────────────────

    public function test_run_is_deterministic(): void
    {
        $packets = [$this->cleanPacket()];

        $this->assertSame(json_encode($this->judge($packets)), json_encode($this->judge($packets)));
    }

    public function test_empty_packet_list_produces_clean_result(): void
    {
        $r = $this->judge([]);

        $this->assertFalse($r['blocking']);
        $this->assertNull($r['winning_attack']);
        $this->assertSame([], $r['blocking_findings']);
    }
}
