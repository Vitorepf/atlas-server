<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerClaimExecuteReportCycle;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerEvidenceWriter;
use Tests\TestCase;

class AtlasNativeWorkerClaimExecuteReportCycleTest extends TestCase
{
    private function validClaim(): array
    {
        return [
            'lease_id' => 'l-1',
            'task_packet' => [
                'task_packet_id' => 'pk-1',
                'objective' => 'noop',
                'allowed_files' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'scope_in' => ['app/Foo.php', 'tests/Unit/FooTest.php'],
                'acceptance_criteria' => ['/opt/homebrew/bin/php artisan test tests/Unit/FooTest.php'],
                'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
                'simplicity_contract' => [
                    'final_runtime_owner' => 'atlas_native',
                    'steady_state_runtime_owner' => 'atlas_server',
                ],
            ],
        ];
    }

    public function test_default_dry_run_invokes_zero_callbacks_and_returns_planned_steps(): void
    {
        $claimCalled = 0;
        $reportCalled = 0;
        $materializerCalled = 0;

        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'claim_callback' => function () use (&$claimCalled) { $claimCalled++; return null; },
            'report_callback' => function () use (&$reportCalled) { $reportCalled++; },
            'patch_materializer' => function () use (&$materializerCalled) { $materializerCalled++; },
        ]);

        self::assertTrue($verdict['dry_run']);
        self::assertSame(0, $claimCalled);
        self::assertSame(0, $reportCalled);
        self::assertSame(0, $materializerCalled);
        self::assertNotEmpty($verdict['planned_steps']);
        self::assertSame([], $verdict['applied_steps']);
        self::assertSame([], $verdict['blocked_actions']);
    }

    public function test_apply_mode_reports_only_mapper_outcome_after_evidence_write(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-cycle-evidence-'.bin2hex(random_bytes(4)).'.jsonl';

        $reportPayload = null;
        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => function (array $payload) use (&$reportPayload): void {
                $reportPayload = $payload;
            },
            'verification' => ['passed' => true, 'evidence_refs' => []],
        ]);

        self::assertFalse($verdict['dry_run']);
        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::STATUS_OK, $verdict['status']);
        self::assertSame('pk-1', $verdict['task_packet_id']);
        self::assertSame('l-1', $verdict['lease_id']);
        self::assertNotNull($reportPayload);
        self::assertSame('success', $reportPayload['outcome']);
        self::assertContains('write_evidence', $verdict['applied_steps']);
        self::assertContains('map_outcome', $verdict['applied_steps']);
        self::assertContains('report', $verdict['applied_steps']);

        // Evidence MUST be written before report — so evidence file must exist now.
        self::assertFileExists($ledger);

        @unlink($ledger);
    }

    public function test_forbidden_action_labels_are_refused_before_any_step(): void
    {
        $claimed = false;
        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'action_labels' => ['git', 'external_provider', 'operator_handoff'],
            'claim_callback' => function () use (&$claimed) { $claimed = true; return null; },
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::STATUS_REFUSED_LABEL, $verdict['status']);
        self::assertFalse($claimed, 'claim must not run when forbidden labels are present');
        $reasons = array_column($verdict['blocked_actions'], 'reason');
        self::assertContains('forbidden_action_label', $reasons);
    }

    public function test_no_claim_returns_no_claim_status(): void
    {
        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => null,
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::STATUS_NO_CLAIM, $verdict['status']);
        self::assertSame([], $verdict['applied_steps']);
    }

    public function test_adapter_refused_surfaces_reason(): void
    {
        $badClaim = $this->validClaim();
        $badClaim['task_packet']['allowed_files'] = []; // adapter will refuse

        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => $badClaim,
            'report_callback' => fn () => null,
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::STATUS_ADAPTER_REFUSED, $verdict['status']);
        $reasons = array_column($verdict['blocked_actions'], 'reason');
        self::assertContains('allowed_files_empty', $reasons);
    }

    public function test_denied_command_blocks_success_outcome(): void
    {
        $reportPayload = null;
        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => function (array $payload) use (&$reportPayload): void {
                $reportPayload = $payload;
            },
            'verification' => ['passed' => true],
            'command_plan' => [
                ['name' => 'not-in-allowlist', 'command' => 'echo hi'],
            ],
        ]);

        self::assertNotNull($reportPayload);
        self::assertNotSame('success', $reportPayload['outcome'], 'a denied command must not produce a success outcome');
    }

    public function test_cycle_hash_is_deterministic_for_identical_dry_run(): void
    {
        $cycle = new AtlasNativeWorkerClaimExecuteReportCycle();
        $a = $cycle->run([]);
        $b = $cycle->run([]);

        self::assertSame($a['cycle_hash'], $b['cycle_hash']);
        self::assertStringStartsWith('worker_cycle_', $a['cycle_hash']);
    }

    public function test_cycle_source_does_not_call_provider_or_git_or_unrestricted_shell(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/NativeWorker/AtlasNativeWorkerClaimExecuteReportCycle.php'));
        foreach (['shell_exec', 'system(', 'proc_open', '`git ', 'Http::', 'curl_'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "cycle must not contain {$forbidden}");
        }
    }
}
