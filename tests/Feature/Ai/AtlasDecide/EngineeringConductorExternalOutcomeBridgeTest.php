<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\AtlasDecide;

use App\Services\Ai\AtlasDecide\AtlasEngineeringRunConductorService;
use App\Services\Ai\Compounding\AtlasCompoundingRuntimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * O-1 single-feeder bridge: the conductor's recordExternalEngineeringOutcome
 * applies the SAME substance gates as maybeRecordCompounding to outcomes
 * executed elsewhere (Forge work-packet cycles). Every rejection leaves the
 * compounding tables untouched; confidence is DERIVED, never caller-supplied;
 * a failed outcome never promotes.
 */
final class EngineeringConductorExternalOutcomeBridgeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->dropSchema();
        (require database_path('migrations/2026_05_17_180000_create_ai_compounding_engineering_intelligence_tables.php'))->up();
        (require database_path('migrations/2026_05_19_030000_strengthen_rag_feedback_and_create_learning_proposals.php'))->up();
        config()->set(AtlasEngineeringRunConductorService::FORGE_BRIDGE_FLAG, true);
    }

    protected function tearDown(): void
    {
        $this->dropSchema();
        parent::tearDown();
    }

    private function dropSchema(): void
    {
        foreach ([
            'ai_learning_proposals',
            'ai_temporal_certifications',
            'ai_benchmark_cases',
            'ai_rag_feedback_events',
            'ai_heuristic_updates',
            'ai_compounding_memories',
            'ai_learning_candidates',
            'ai_run_outcomes',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    private function conductor(): AtlasEngineeringRunConductorService
    {
        return app(AtlasEngineeringRunConductorService::class);
    }

    /** @return array<string,mixed> */
    private function outcome(array $overrides = []): array
    {
        return array_merge([
            'source' => 'forge_work_packet_cycle',
            'flow_id' => 'atlas_forge',
            'run_id' => 'wp-cycle-'.bin2hex(random_bytes(6)),
            'outcome_status' => 'passed',
            'execution_mode' => 'real',
            'evidence_refs' => ['forge_evidence:verification_receipt:gate-run-1'],
            'evidence_kinds' => ['verification_receipt'],
            'gate_result' => ['all_passed' => true, 'gates' => [['name' => 'qa', 'status' => 'passed']]],
            'claim' => 'Forge work packet "harden parser" completed with real evidence (gates: all_passed).',
        ], $overrides);
    }

    public function test_flag_off_rejects_and_writes_nothing(): void
    {
        config()->set(AtlasEngineeringRunConductorService::FORGE_BRIDGE_FLAG, false);

        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome());

        self::assertFalse($r['recorded']);
        self::assertSame('bridge_flag_off', $r['reason']);
        self::assertSame(0, DB::table('ai_run_outcomes')->count());
    }

    public function test_simulation_mode_never_trains(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome(
            $this->outcome(['execution_mode' => 'safe_simulation']),
        );

        self::assertFalse($r['recorded']);
        self::assertSame('not_a_real_execution', $r['reason']);
        self::assertSame(0, DB::table('ai_run_outcomes')->count());
    }

    public function test_simulation_only_evidence_is_not_substantive(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome([
            'evidence_refs' => ['forge_evidence:simulation_log:dry-run'],
            'evidence_kinds' => ['simulation_log'],
        ]));

        self::assertFalse($r['recorded']);
        self::assertSame('no_substantive_evidence', $r['reason']);
    }

    public function test_no_passed_gate_is_rejected_even_if_caller_claims_success(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome([
            'gate_result' => ['all_passed' => false, 'gates' => [['name' => 'qa', 'status' => 'failed']]],
        ]));

        self::assertFalse($r['recorded']);
        self::assertSame('no_passed_gate', $r['reason']);
    }

    public function test_fully_passed_real_outcome_with_verification_receipt_records_promotable_signal(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome());

        self::assertTrue($r['recorded'], json_encode($r));
        self::assertSame(1, DB::table('ai_run_outcomes')->count());
        $candidate = DB::table('ai_learning_candidates')->first();
        self::assertNotNull($candidate);
        // Derived confidence 75 => promotable path (>= distiller floor 70).
        self::assertSame('atlas_forge', DB::table('ai_run_outcomes')->value('flow_id'));
    }

    public function test_partial_gate_pass_without_receipt_is_held_not_promoted(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome([
            'evidence_refs' => ['forge_evidence:diff:patch-1'],
            'evidence_kinds' => ['diff'],
            'gate_result' => ['gates' => [['name' => 'qa', 'status' => 'passed'], ['name' => 'style', 'status' => 'failed']]],
        ]));

        self::assertTrue($r['recorded'], json_encode($r));
        // Confidence 65 < 70 distiller floor => hold, never a memory.
        self::assertSame(0, DB::table('ai_compounding_memories')->count());
    }

    public function test_failed_outcome_records_signal_but_never_promotes(): void
    {
        $r = $this->conductor()->recordExternalEngineeringOutcome($this->outcome([
            'outcome_status' => 'failed',
            'failure_class' => 'test_failure',
            'gate_result' => ['gates' => [['name' => 'partial', 'status' => 'passed']]],
        ]));

        self::assertTrue($r['recorded'], json_encode($r));
        self::assertSame(0, DB::table('ai_compounding_memories')->count());
        self::assertStringContainsString(
            'test_failure',
            (string) DB::table('ai_learning_candidates')->value('claim'),
        );
    }

    /**
     * O-1 feeder-set freeze: the pre-existing legitimate compounding feeders
     * are pinned as an allowlist — a NEW class reaching for recordExecution
     * (instead of going through the conductor bridge) fails this test and
     * must argue its legitimacy explicitly. The Forge bridge deliberately
     * did NOT add a feeder: Forge talks to the conductor.
     */
    public function test_feeder_set_is_frozen_no_new_class_calls_record_execution(): void
    {
        $allowed = [
            'app/Console/Commands/AtlasAiCompoundingCommand.php',
            'app/Http/Controllers/AtlasDev/RunController.php',
            'app/Services/Ai/AtlasDecide/AtlasEngineeringRunConductorService.php',
            'app/Services/Ai/AutonomousEngineering/AtlasAutonomousEngineeringService.php',
            'app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php',
            'app/Services/Ai/Compounding/AtlasCompoundingEngineeringIntelligenceService.php',
            'app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php',
        ];

        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path(), \FilesystemIterator::SKIP_DOTS),
        );
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = (string) $file->getPathname();
            $relative = ltrim(str_replace(base_path(), '', $path), '/');
            if (in_array($relative, $allowed, true)) {
                continue;
            }
            $contents = (string) file_get_contents($path);
            if (str_contains($contents, 'recordExecution(') && str_contains($contents, 'CompoundingRuntime')) {
                $offenders[] = $relative;
            }
        }

        self::assertSame([], $offenders, 'O-1: a new compounding feeder appeared — route it through the conductor bridge instead');
    }
}
