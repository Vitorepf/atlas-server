<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerOutcomeMapper;
use Tests\TestCase;

class AtlasNativeWorkerOutcomeMapperTest extends TestCase
{
    private function envelope(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'p1',
            'required_evidence' => ['tests_or_gates_result'],
        ], $override);
    }

    private function execution(array $override = []): array
    {
        return array_replace([
            'command_status' => 'green',
            'patch_status' => 'green',
            'results' => [],
            'evidence_refs' => ['tests_or_gates_result'],
            'blockers' => [],
        ], $override);
    }

    private function verification(array $override = []): array
    {
        return array_replace([
            'passed' => true,
            'blockers' => [],
            'evidence_refs' => [],
        ], $override);
    }

    public function test_success_when_verification_green_evidence_complete_results_green(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map($this->envelope(), $this->execution(), $this->verification());

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_SUCCESS, $verdict['report_outcome']);
        self::assertSame('all_green', $verdict['report_reason']);
        self::assertSame([], $verdict['blocking_deficiencies']);
    }

    public function test_give_back_when_envelope_marks_impossible_scope(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(['impossible_scope' => true]),
            $this->execution(),
            $this->verification(['passed' => false]),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
        self::assertContains('impossible_scope', $verdict['blocking_deficiencies']);
    }

    public function test_give_back_when_execution_reports_missing_implementation_path(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['missing_implementation_path' => true]),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
        self::assertContains('missing_implementation_path', $verdict['blocking_deficiencies']);
    }

    public function test_give_back_when_non_atlas_native_dependency_required(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['non_atlas_native_dependency' => true]),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
    }

    public function test_failed_when_verification_red(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(),
            $this->verification(['passed' => false, 'blockers' => ['test_red_x']]),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
        self::assertContains('test_red_x', $verdict['blocking_deficiencies']);
    }

    public function test_red_gates_never_become_success(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['command_status' => 'red']),
            $this->verification(),
        );

        self::assertNotSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_SUCCESS, $verdict['report_outcome']);
        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
    }

    public function test_failed_when_evidence_incomplete(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(['required_evidence' => ['tests_or_gates_result', 'extra_artifact']]),
            $this->execution(),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
        self::assertSame('evidence_incomplete', $verdict['report_reason']);
    }

    public function test_failed_when_unresolved_blockers_present(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['blockers' => ['rollback_plan_missing']]),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
        self::assertContains('rollback_plan_missing', $verdict['blocking_deficiencies']);
    }

    public function test_outcome_hash_is_deterministic(): void
    {
        $mapper = new AtlasNativeWorkerOutcomeMapper();
        $a = $mapper->map($this->envelope(), $this->execution(), $this->verification());
        $b = $mapper->map($this->envelope(), $this->execution(), $this->verification());

        self::assertSame($a['outcome_hash'], $b['outcome_hash']);
        self::assertStringStartsWith('outcome_', $a['outcome_hash']);
    }

    public function test_denied_command_status_maps_to_give_back(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['command_status' => 'denied']),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
        self::assertContains('command_execution_denied', $verdict['blocking_deficiencies']);
    }

    public function test_timeout_command_status_maps_to_give_back(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['command_status' => 'timeout']),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
        self::assertContains('command_execution_timeout', $verdict['blocking_deficiencies']);
    }

    public function test_empty_results_flag_maps_to_give_back(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['empty_results' => true]),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_GIVE_BACK, $verdict['report_outcome']);
        self::assertContains('empty_results', $verdict['blocking_deficiencies']);
    }

    public function test_missing_evidence_maps_to_failed(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(['required_evidence' => ['tests_or_gates_result', 'coverage_report']]),
            $this->execution(['evidence_refs' => []]),
            $this->verification(['evidence_refs' => []]),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
        self::assertSame('evidence_incomplete', $verdict['report_reason']);
    }

    public function test_failed_gate_in_results_maps_to_failed(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['results' => [['name' => 'phpunit', 'status' => 'red']]]),
            $this->verification(),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_FAILED, $verdict['report_outcome']);
    }

    public function test_unknown_command_status_does_not_become_success(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(),
            $this->execution(['command_status' => '']),
            $this->verification(),
        );

        self::assertNotSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_SUCCESS, $verdict['report_outcome']);
    }

    public function test_complete_green_evidence_maps_to_success(): void
    {
        $verdict = (new AtlasNativeWorkerOutcomeMapper)->map(
            $this->envelope(['required_evidence' => ['tests_or_gates_result', 'implementation_notes']]),
            $this->execution([
                'command_status' => 'green',
                'patch_status' => 'green',
                'results' => [['name' => 'phpunit', 'status' => 'green']],
                'evidence_refs' => ['tests_or_gates_result', 'implementation_notes'],
                'blockers' => [],
            ]),
            $this->verification(['passed' => true, 'blockers' => [], 'evidence_refs' => []]),
        );

        self::assertSame(AtlasNativeWorkerOutcomeMapper::OUTCOME_SUCCESS, $verdict['report_outcome']);
        self::assertSame('all_green', $verdict['report_reason']);
        self::assertSame([], $verdict['blocking_deficiencies']);
    }

    public function test_mapper_source_does_not_call_providers_or_processes_or_io(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerOutcomeMapper.php'));
        foreach (['shell_exec', 'exec(', 'system(', 'proc_open', '`git ', 'Http::', 'curl_', 'DB::', 'Storage::', 'file_put_contents'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "outcome mapper must not contain {$forbidden}");
        }
    }
}
