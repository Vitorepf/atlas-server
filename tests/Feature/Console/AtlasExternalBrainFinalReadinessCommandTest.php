<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasExternalBrainFinalReadinessCommandTest extends TestCase
{
    private string $inputPath;

    protected function tearDown(): void
    {
        if (isset($this->inputPath) && is_file($this->inputPath)) {
            unlink($this->inputPath);
        }
        parent::tearDown();
    }

    private function writeInput(array $payload): string
    {
        $this->inputPath = tempnam(sys_get_temp_dir(), 'final_readiness_input_').'.json';
        file_put_contents($this->inputPath, (string) json_encode($payload));

        return $this->inputPath;
    }

    private function callCommand(array $payload): array
    {
        $path = $this->writeInput($payload);
        Artisan::call('atlas:external-brain:final-readiness', ['--input' => $path]);
        $raw = trim(Artisan::output());
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Command output is not valid JSON:\n{$raw}");

        return $decoded;
    }

    private function fullyReadyAreaEvidence(): array
    {
        $areas = [
            'originator', 'task_fabric', 'maestro', 'learning',
            'anti_goodhart', 'runtime', 'consolidation',
            'workers', 'gates', 'receipts', 'memory_docs_sync', 'model_amplifier',
        ];

        $evidence = [];
        foreach ($areas as $area) {
            $evidence[$area] = [
                'status' => 'proven',
                'unresolved_count' => 0,
                'has_runnable_proof' => true,
                'knowledge_sync_current' => true,
                'operator_independence' => true,
                'poison_blocker_open' => false,
            ];
        }

        return $evidence;
    }

    private function fullyPassingCertificationEvidence(): array
    {
        return [
            'live_cycle_evidence' => ['cycle_count' => 3, 'resolved_task_count' => 10, 'evidence_refs' => ['cycle_run_receipt'], 'evidence_age_hours' => 1.0],
            'anti_goodhart' => ['verdict' => 'pass', 'evidence_refs' => ['audit_report'], 'evidence_age_hours' => 1.0],
            'self_improvement_cycle' => ['has_output' => true, 'recommendation_count' => 2, 'evidence_refs' => ['recommendation_receipt'], 'evidence_age_hours' => 1.0],
            'muscle_learning' => ['outcome_count' => 5, 'success_rate' => 0.8, 'evidence_refs' => ['outcome_ledger_ref'], 'evidence_age_hours' => 1.0],
            'property_gated_path' => ['ready' => true, 'blocking_gates' => [], 'evidence_refs' => ['gate_readiness_cert'], 'evidence_age_hours' => 1.0],
            'doc_proposal' => ['drafted' => true, 'certification_blocked' => false, 'evidence_refs' => ['doc_draft_ref'], 'evidence_age_hours' => 1.0],
            'autonomy' => ['human_dependency_in_loop' => false, 'provider_dependency_in_steady_state' => false, 'evidence_refs' => ['autonomy_assessment_ref'], 'evidence_age_hours' => 1.0],
        ];
    }

    public function test_missing_input_option_fails(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:final-readiness');
        $this->assertNotSame(0, $exitCode);
    }

    public function test_fully_ready_scenario_yields_final_95_ready_verdict_and_zero_exit_code(): void
    {
        $exitCode = Artisan::call('atlas:external-brain:final-readiness', ['--input' => $this->writeInput([
            'area_evidence' => $this->fullyReadyAreaEvidence(),
            'gaps' => [],
            'certification_evidence' => $this->fullyPassingCertificationEvidence(),
        ])]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame('final_95_ready', $result['overall_verdict']);
        $this->assertSame([], $result['blocker_ranked_closure_steps']);
        $this->assertSame(0, $exitCode);
    }

    public function test_incomplete_area_evidence_blocks_overall_readiness(): void
    {
        $areaEvidence = $this->fullyReadyAreaEvidence();
        $areaEvidence['gates']['status'] = 'missing';

        $exitCode = Artisan::call('atlas:external-brain:final-readiness', ['--input' => $this->writeInput([
            'area_evidence' => $areaEvidence,
            'gaps' => [],
            'certification_evidence' => $this->fullyPassingCertificationEvidence(),
        ])]);
        $result = json_decode(trim(Artisan::output()), true);

        $this->assertSame('not_ready', $result['overall_verdict']);
        $this->assertContains('gates', $result['readiness_map']['blocking_areas']);
        $this->assertNotSame(0, $exitCode);
    }

    public function test_open_gap_surfaces_a_blocker_ranked_closure_step(): void
    {
        $result = $this->callCommand([
            'area_evidence' => $this->fullyReadyAreaEvidence(),
            'gaps' => [[
                'organ_id' => 'atlas_brain_seed_gate',
                'gap_type' => 'missing',
                'owner_subsystem' => 'external_brain',
            ]],
            'certification_evidence' => $this->fullyPassingCertificationEvidence(),
        ]);

        $this->assertSame('not_ready', $result['overall_verdict']);
        $this->assertSame(1, $result['gap_burn_down']['total_gaps']);
        $gapStep = array_values(array_filter($result['blocker_ranked_closure_steps'], static fn (array $s): bool => $s['source'] === 'gap_burn_down'));
        $this->assertNotEmpty($gapStep);
        $this->assertSame('atlas_brain_seed_gate', $gapStep[0]['target']);
    }

    public function test_failing_certification_dimension_surfaces_a_blocker_ranked_closure_step(): void
    {
        $certification = $this->fullyPassingCertificationEvidence();
        $certification['anti_goodhart'] = ['verdict' => 'reject'];

        $result = $this->callCommand([
            'area_evidence' => $this->fullyReadyAreaEvidence(),
            'gaps' => [],
            'certification_evidence' => $certification,
        ]);

        $this->assertSame('not_ready', $result['overall_verdict']);
        $this->assertNotSame('final_95_candidate', $result['certification']['verdict']);
        $certStep = array_values(array_filter($result['blocker_ranked_closure_steps'], static fn (array $s): bool => $s['source'] === 'certification_gate'));
        $this->assertNotEmpty($certStep);
        $this->assertSame('anti_goodhart_pass', $certStep[0]['target']);
    }
}
