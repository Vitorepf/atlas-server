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
        // write_evidence is honestly blocked (no real commands ran → empty commands_run → writer
        // rejects). The old fabrication branch synthesized a fake command with hardcoded exit_code=0
        // to bypass the writer guard; that has been removed. The honest verification outcome is
        // carried by tests_or_gates_result.passed.
        self::assertNotContains('write_evidence', $verdict['applied_steps']);
        self::assertContains('map_outcome', $verdict['applied_steps']);
        self::assertContains('report', $verdict['applied_steps']);
        // The blocked write is recorded under blocked_actions.
        $writeBlockReasons = array_column($verdict['blocked_actions'], 'reason');
        self::assertNotEmpty(array_filter($writeBlockReasons, static fn (string $r): bool => str_contains($r, 'evidence writer: commands_run must not be empty')));

        // map_outcome must still precede report.
        $mapIdx = array_search('map_outcome', $verdict['applied_steps'], true);
        $reportIdx = array_search('report', $verdict['applied_steps'], true);
        self::assertLessThan($reportIdx, $mapIdx, 'map_outcome must run before report');

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

    // ── step_retry_contract ───────────────────────────────────────────────────

    public function test_dry_run_emits_step_retry_contract_with_dry_run_reason(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([]);

        self::assertArrayHasKey('step_retry_contract', $r);
        $src = $r['step_retry_contract'];
        self::assertSame('dry_run_planned_steps_only', $src['reportable_outcome_reason']);
        self::assertTrue($src['retryable']);
        self::assertNull($src['required_callback']);
        self::assertSame([], $src['evidence_needed']);
    }

    public function test_no_claim_emits_retryable_contract_with_queue_reason(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => null,
        ]);

        $src = $r['step_retry_contract'];
        self::assertSame('no_task_available_in_queue', $src['reportable_outcome_reason']);
        self::assertTrue($src['retryable']);
        self::assertSame('claim', $src['failed_or_pending_step']);
    }

    public function test_no_claim_callback_emits_claim_as_required_callback(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            // no claim_callback
        ]);

        $src = $r['step_retry_contract'];
        self::assertSame('claim', $src['required_callback']);
        self::assertTrue($src['retryable']);
    }

    public function test_adapter_refused_emits_retryable_contract_with_adapter_reason(): void
    {
        $badClaim = $this->validClaim();
        $badClaim['task_packet']['allowed_files'] = [];

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => $badClaim,
        ]);

        $src = $r['step_retry_contract'];
        self::assertSame('task_packet_rejected_by_adapter', $src['reportable_outcome_reason']);
        self::assertTrue($src['retryable']);
    }

    public function test_forbidden_label_emits_non_retryable_contract(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'action_labels' => ['git'],
            'claim_callback' => fn () => null,
        ]);

        $src = $r['step_retry_contract'];
        self::assertSame('forbidden_action_label_in_spec', $src['reportable_outcome_reason']);
        self::assertFalse($src['retryable']);
    }

    public function test_successful_apply_emits_success_outcome_reason_and_not_retryable(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-src-test-'.bin2hex(random_bytes(4)).'.jsonl';

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true],
        ]);

        $src = $r['step_retry_contract'];
        self::assertStringContainsString('success', $src['reportable_outcome_reason']);
        self::assertFalse($src['retryable']);
        self::assertContains('tests_or_gates_result', $src['evidence_needed']);

        @unlink($ledger);
    }

    // ── AC: cycle result distinguishes no_claimable/command_failed/verification_failed/success/give_back ──

    public function test_no_claimable_outcome_class_is_no_claimable(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => null,
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_NO_CLAIMABLE, $r['outcome_class']);
    }

    public function test_success_outcome_class_is_success_with_evidence_summary(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-cycle-outcome-class-'.bin2hex(random_bytes(4)).'.jsonl';

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true, 'evidence_refs' => ['tests_or_gates_result', 'implementation_notes']],
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_SUCCESS, $r['outcome_class']);
        self::assertSame([], $r['evidence_summary']['missing']);

        @unlink($ledger);
    }

    public function test_missing_verification_never_normalizes_into_success(): void
    {
        // The AC bug: verification omitted entirely must NEVER be silently treated as passed.
        $ledger = sys_get_temp_dir().'/atlas-cycle-no-verify-'.bin2hex(random_bytes(4)).'.jsonl';

        $reportPayload = null;
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => function (array $payload) use (&$reportPayload): void {
                $reportPayload = $payload;
            },
            // no 'verification' key supplied at all
        ]);

        self::assertNotSame('success', $reportPayload['outcome']);
        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_VERIFICATION_FAILED, $r['outcome_class']);

        @unlink($ledger);
    }

    public function test_verification_failed_outcome_class(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-cycle-verify-failed-'.bin2hex(random_bytes(4)).'.jsonl';

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => false],
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_VERIFICATION_FAILED, $r['outcome_class']);
        self::assertArrayHasKey('missing', $r['evidence_summary']);

        @unlink($ledger);
    }

    public function test_command_failed_outcome_class(): void
    {
        // An allowlisted command that only ever dry-runs (never reaches 'green') is a genuine
        // command_failed — distinct from a 'denied' (not-in-allowlist) command, which is give_back.
        $ledger = sys_get_temp_dir().'/atlas-cycle-command-failed-'.bin2hex(random_bytes(4)).'.jsonl';
        $claim = $this->validClaim();
        $claim['task_packet']['gates'] = ['allowed_gate'];

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $claim,
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true],
            'command_plan' => [
                ['name' => 'allowed_gate', 'argv' => ['true']],
            ],
        ]);

        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_COMMAND_FAILED, $r['outcome_class']);

        @unlink($ledger);
    }

    public function test_evidence_summary_is_null_before_outcome_mapping(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([]);

        self::assertNull($r['evidence_summary']);
        self::assertNull($r['outcome_class']);
    }

    public function test_adapter_refused_outcome_class_is_give_back(): void
    {
        $badClaim = $this->validClaim();
        $badClaim['task_packet']['allowed_files'] = [];

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => $badClaim,
            'report_callback' => fn (array $p) => $p,
        ]);

        self::assertSame(
            AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_GIVE_BACK,
            $r['outcome_class'],
            'adapter refusal must produce give_back outcome_class',
        );
    }

    public function test_denied_command_outcome_class_is_give_back(): void
    {
        $r = (new AtlasNativeWorkerClaimExecuteReportCycle)->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true],
            'command_plan' => [
                ['name' => 'not-in-allowlist', 'command' => 'echo hi'],
            ],
        ]);

        self::assertNotSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_SUCCESS, $r['outcome_class']);
        // Forbidden/denied commands surface as OUTCOME_CLASS_GIVE_BACK or OUTCOME_CLASS_COMMAND_FAILED
        self::assertContains($r['outcome_class'], [
            AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_GIVE_BACK,
            AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_COMMAND_FAILED,
        ]);
    }

    public function test_verification_failed_does_not_produce_success_outcome_class(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-cycle-ac3-'.bin2hex(random_bytes(4)).'.jsonl';

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => false],
        ]);

        self::assertNotSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_SUCCESS, $r['outcome_class']);
        self::assertNotSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_GIVE_BACK, $r['outcome_class']);
        self::assertSame(AtlasNativeWorkerClaimExecuteReportCycle::OUTCOME_CLASS_VERIFICATION_FAILED, $r['outcome_class']);

        @unlink($ledger);
    }

    public function test_lease_and_task_ids_match_claimed_task_on_success(): void
    {
        $ledger = sys_get_temp_dir().'/atlas-cycle-ids-'.bin2hex(random_bytes(4)).'.jsonl';

        $claim = $this->validClaim();
        $claim['lease_id'] = 'lease-abc';
        $claim['task_packet']['task_packet_id'] = 'task-xyz';

        $verdict = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $claim,
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true],
        ]);

        self::assertSame('task-xyz', $verdict['task_packet_id']);
        self::assertSame('lease-abc', $verdict['lease_id']);

        @unlink($ledger);
    }

    public function test_step_retry_contract_required_keys_present_for_every_status(): void
    {
        $keys = ['failed_or_pending_step', 'retryable', 'required_callback', 'evidence_needed', 'reportable_outcome_reason'];

        $fixtures = [
            // dry_run
            (new AtlasNativeWorkerClaimExecuteReportCycle)->run([]),
            // no_claim
            (new AtlasNativeWorkerClaimExecuteReportCycle)->run(['dry_run' => false, 'claim_callback' => fn () => null]),
            // refused_label
            (new AtlasNativeWorkerClaimExecuteReportCycle)->run(['dry_run' => false, 'action_labels' => ['git'], 'claim_callback' => fn () => null]),
        ];

        foreach ($fixtures as $i => $r) {
            self::assertArrayHasKey('step_retry_contract', $r, "fixture {$i} missing step_retry_contract");
            foreach ($keys as $k) {
                self::assertArrayHasKey($k, $r['step_retry_contract'], "fixture {$i} step_retry_contract missing {$k}");
            }
        }
    }

    public function test_no_fabricated_command_when_no_real_command_ran(): void
    {
        // RED against current (pre-fix) code: the fabrication branch synthesizes
        // {command:'/opt/homebrew/bin/php artisan test', exit_code:0} when
        // verification.passed=true and commandsForEvidence is empty. After the
        // fix, commands_run must contain NO synthesized entry.
        $ledger = sys_get_temp_dir().'/atlas-cycle-no-fabrication-'.bin2hex(random_bytes(4)).'.jsonl';

        $r = (new AtlasNativeWorkerClaimExecuteReportCycle(
            evidenceWriter: new AtlasNativeWorkerEvidenceWriter($ledger),
        ))->run([
            'dry_run' => false,
            'claim_callback' => fn () => $this->validClaim(),
            'report_callback' => fn (array $p) => $p,
            'verification' => ['passed' => true],
            // NO command_plan supplied → no real command runner results.
        ]);

        // The cycle must NOT have written evidence (writer rejects empty commands_run).
        self::assertNotContains('write_evidence', $r['applied_steps']);

        // The blocked action must be the honest write_evidence rejection.
        $writeBlocks = array_values(array_filter(
            $r['blocked_actions'],
            static fn (array $b): bool => $b['action'] === 'write_evidence',
        ));
        self::assertNotEmpty($writeBlocks, 'write_evidence must be blocked when no real command ran');
        self::assertStringContainsString('commands_run must not be empty', $writeBlocks[0]['reason']);

        // The ledger file must NOT exist (no row was written).
        self::assertFileDoesNotExist($ledger);

        @unlink($ledger);
    }
}