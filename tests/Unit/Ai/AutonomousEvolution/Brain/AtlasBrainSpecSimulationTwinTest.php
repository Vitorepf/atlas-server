<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainSpecSimulationTwin;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use Tests\TestCase;

/**
 * FROZEN proof of the simulation twin — the brain's pre-flight predictor over multiple candidate specs.
 * Proves the deterministic best-first ordering, the 3-class outcome (clean/advisory/blocked), and the
 * winner selection (null when every candidate is blocked).
 */
final class AtlasBrainSpecSimulationTwinTest extends TestCase
{
    private function cleanSpec(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => 'wire AtlasFooService into the php artisan boot kernel so the test passes',
            'allowed_files' => ['app/Services/Ai/SelfConstruction/AtlasFooService.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooServiceTest.php'],
            'scope_in' => ['app/Services/Ai/SelfConstruction/AtlasFooService.php', 'tests/Unit/Ai/SelfConstruction/AtlasFooServiceTest.php'],
            'acceptance_criteria' => ['php artisan test --filter=AtlasFooServiceTest passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    private function blockedSpec(string $id): array
    {
        return [
            'task_packet_id' => $id,
            'objective' => '   ', // missing_objective ⇒ BLOCKING
            'allowed_files' => ['app/Services/Foo.php'],
            'scope_in' => ['app/Services/Foo.php'],
            'acceptance_criteria' => ['php artisan test passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    private function advisorySpec(string $id): array
    {
        // generic acceptance ⇒ acceptance_coverage_mismatch (advisory, NOT blocking).
        return [
            'task_packet_id' => $id,
            'objective' => 'a concrete objective naming app/Services/Foo.php in detail with enough chars',
            'allowed_files' => ['app/Services/Foo.php'],
            'scope_in' => ['app/Services/Foo.php'],
            'acceptance_criteria' => ['phpunit passes'],
            'required_evidence' => ['tests_or_gates_result'],
        ];
    }

    public function test_clean_outranks_advisory_outranks_blocked(): void
    {
        $sim = new AtlasBrainSpecSimulationTwin;
        $r = $sim->simulate(
            [$this->blockedSpec('b'), $this->advisorySpec('a'), $this->cleanSpec('c')],
            new AtlasTaskPacketQualityInspector,
        );

        // verdicts ordered: clean, advisory, blocked.
        self::assertSame('c', $r['verdicts'][0]['task_packet_id']);
        self::assertSame(AtlasBrainSpecSimulationTwin::OUTCOME_PASSES_CLEAN, $r['verdicts'][0]['outcome']);
        self::assertSame('a', $r['verdicts'][1]['task_packet_id']);
        self::assertSame(AtlasBrainSpecSimulationTwin::OUTCOME_ADVISORY_ONLY, $r['verdicts'][1]['outcome']);
        self::assertSame('b', $r['verdicts'][2]['task_packet_id']);
        self::assertSame(AtlasBrainSpecSimulationTwin::OUTCOME_BLOCKED, $r['verdicts'][2]['outcome']);

        self::assertSame('c', $r['winner'], 'cleanest candidate wins');
    }

    public function test_all_blocked_yields_no_winner(): void
    {
        $r = (new AtlasBrainSpecSimulationTwin)->simulate(
            [$this->blockedSpec('x'), $this->blockedSpec('y')],
            new AtlasTaskPacketQualityInspector,
        );

        self::assertNull($r['winner'], 'no non-blocked candidate ⇒ winner is null (re-author, do not pick a blocked one)');
    }

    public function test_advisory_winner_when_no_clean(): void
    {
        $r = (new AtlasBrainSpecSimulationTwin)->simulate(
            [$this->advisorySpec('a'), $this->blockedSpec('b')],
            new AtlasTaskPacketQualityInspector,
        );

        self::assertSame('a', $r['winner']);
    }

    public function test_output_is_byte_stable(): void
    {
        $sim = new AtlasBrainSpecSimulationTwin;
        $inspector = new AtlasTaskPacketQualityInspector;
        $specs = [$this->cleanSpec('z'), $this->advisorySpec('y'), $this->blockedSpec('x')];

        self::assertSame($sim->simulate($specs, $inspector), $sim->simulate($specs, $inspector));
    }

    public function test_simulator_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainSpecSimulationTwin.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
