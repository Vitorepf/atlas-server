<?php

namespace Tests\Feature\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Repair\DevRepairLoopService;
use App\Services\Ai\Programming\AtlasDev\Schemas\EscalationPacket;
use Tests\TestCase;

class DevRepairLoopServiceTest extends TestCase
{
    public function test_failure_is_captured_and_classified_with_failing_test_pointer(): void
    {
        $receipt = $this->service()->evaluate($this->baseInput());

        $this->assertSame(DevRepairLoopService::RECEIPT_SCHEMA_VERSION, $receipt['schema_version']);
        $this->assertSame(DevRepairLoopService::OUTCOME_PLANNED, $receipt['outcome']);

        $this->assertArrayHasKey('failure_capture', $receipt);
        $this->assertSame('verification_gate', $receipt['failure_capture']['gate']);
        $this->assertSame('tests/Feature/Provider/RouterTest.php::test_fallback', $receipt['failure_capture']['failing_test']);
        $this->assertNotEmpty($receipt['failure_capture']['failure_signature']);

        $this->assertSame('atlas.programming.failure_classification.v1', $receipt['failure_classification']['schema_version']);
        // Classifier returns canonical FastPath failure modes; for a verification gate
        // with a failing_test the canonical mode is `missed_test`.
        $this->assertSame('missed_test', $receipt['failure_classification']['mode']);
    }

    public function test_repair_candidate_emitted_with_failure_taxonomy(): void
    {
        $receipt = $this->service()->evaluate($this->baseInput());

        $candidate = $receipt['repair_candidate'];
        $this->assertSame('atlas.programming.repair_attempt.plan.v1', $candidate['schema_version']);
        $this->assertSame('planned', $candidate['status']);
        $this->assertGreaterThanOrEqual(1, $candidate['attempt']);

        $capsule = $candidate['repair_capsule'];
        $this->assertSame('atlas.programming.repair_capsule.v1', $capsule['schema_version']);
        $this->assertSame('test_failure', $capsule['failure_taxonomy']['category']);
        $this->assertSame('fix_smallest_behavioral_cause_then_rerun_failed_tests', $capsule['failure_taxonomy']['repair_strategy']);
        $this->assertTrue($capsule['verification_plan']['patch_verifier_required']);
    }

    public function test_focused_retest_command_prefers_failing_test_from_capsule(): void
    {
        $receipt = $this->service()->evaluate($this->baseInput());

        $retest = $receipt['focused_retest_command'];
        $this->assertSame('atlas.programming.focused_retest.v1', $retest['schema_version']);
        $this->assertStringContainsString(
            'tests/Feature/Provider/RouterTest.php::test_fallback',
            (string) $retest['primary_command'],
        );
        $this->assertSame('rerun_failing_test_from_capsule', $retest['selection_reason']);
    }

    public function test_attempt_outcome_passed_yields_recovered_with_no_blocker(): void
    {
        $input = $this->baseInput();
        $input['attempt_outcome'] = 'passed';
        $input['attempt_count'] = 1;

        $receipt = $this->service()->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_RECOVERED, $receipt['outcome']);
        $this->assertNull($receipt['blocker']);
        $this->assertNull($receipt['escalate_to_forge']);
    }

    public function test_max_attempts_reached_yields_blocked_outcome(): void
    {
        $input = $this->baseInput();
        $input['attempt_count'] = 2;
        $input['max_attempts'] = 2;

        $receipt = $this->service()->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_BLOCKED, $receipt['outcome']);
        $this->assertNotNull($receipt['blocker']);
        $this->assertSame('atlas.programming.dev_repair_blocker.v1', $receipt['blocker']['schema_version']);
        $stopConditionNames = array_column($receipt['stop_conditions'], 'condition');
        $this->assertContains('max_attempts_reached', $stopConditionNames);
    }

    public function test_same_signature_twice_escalates_to_forge(): void
    {
        $input = $this->baseInput();
        $input['attempt_count'] = 1;
        $input['max_attempts'] = 2;
        $input['previous_capsule'] = [
            'run_id' => 'run-test',
            'task_contract_hash' => 'contract-test',
            'attempt_index' => 0,
            'gate' => 'verification_gate',
            'primary_error_excerpt' => 'Failed asserting that null is true',
            'failure_signature' => $this->expectedSignature(),
            'changed_files' => ['app/Services/Provider/Router.php'],
            'decision' => 'retry',
            'escalation_signal_delta' => [],
        ];

        $receipt = $this->service()->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_ESCALATED, $receipt['outcome']);
        $this->assertNotNull($receipt['escalate_to_forge']);
        $stopConditionNames = array_column($receipt['stop_conditions'], 'condition');
        $this->assertContains('same_signature_twice', $stopConditionNames);

        $packet = $receipt['escalate_to_forge'];
        $this->assertSame(EscalationPacket::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertContains(EscalationPacket::TRIGGER_EVIDENCE_INSUFFICIENT, $packet['promotion_triggers']);
    }

    public function test_diff_growth_escalates_to_forge_with_scope_too_large_trigger(): void
    {
        $input = $this->baseInput();
        $input['attempt_count'] = 1;
        $input['changed_files'] = [
            'app/Services/Provider/Router.php',
            'app/Services/Provider/Adapter.php',
            'app/Services/Provider/Fallback.php',
        ];
        $input['previous_changed_files'] = ['app/Services/Provider/Router.php'];

        $receipt = $this->service()->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_ESCALATED, $receipt['outcome']);
        $stopConditionNames = array_column($receipt['stop_conditions'], 'condition');
        $this->assertContains('diff_growth', $stopConditionNames);

        $packet = $receipt['escalate_to_forge'];
        $this->assertContains(EscalationPacket::TRIGGER_SCOPE_TOO_LARGE, $packet['promotion_triggers']);
    }

    public function test_r4_risk_escalates_immediately_without_consuming_attempts(): void
    {
        $input = $this->baseInput();
        $input['risk_level'] = 'R4';
        $input['attempt_count'] = 0;

        $receipt = $this->service()->evaluate($input);

        $this->assertSame(DevRepairLoopService::OUTCOME_ESCALATED, $receipt['outcome']);
        $this->assertSame(0, $receipt['attempts_allowed']);
        $stopConditionNames = array_column($receipt['stop_conditions'], 'condition');
        $this->assertContains('risk_level_forbids_dev_repair', $stopConditionNames);

        $packet = $receipt['escalate_to_forge'];
        $this->assertContains(EscalationPacket::TRIGGER_HIGH_RISK, $packet['promotion_triggers']);
    }

    public function test_context_retrieval_needed_when_retrieval_plan_absent(): void
    {
        $receipt = $this->service()->evaluate($this->baseInput());

        $this->assertTrue($receipt['context_retrieval_needed']['required']);
        $this->assertContains('retrieval_plan_absent', $receipt['context_retrieval_needed']['reasons']);
    }

    public function test_receipt_hash_is_stable_for_equivalent_inputs(): void
    {
        // generated_at is excluded from the hash so identical inputs produce
        // identical receipt_hash even when the timestamp diverges.
        $service = $this->service();
        $receipt1 = $service->evaluate($this->baseInput());
        $receipt2 = $service->evaluate($this->baseInput());

        $this->assertSame(64, strlen($receipt1['receipt_hash']));
        $this->assertSame($receipt1['receipt_hash'], $receipt2['receipt_hash']);

        $json = json_encode($receipt1, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $this->assertNotFalse($json);
        $decoded = json_decode((string) $json, true);
        $this->assertSame(DevRepairLoopService::RECEIPT_SCHEMA_VERSION, $decoded['schema_version']);
        $this->assertArrayHasKey('repair_candidate', $decoded);
        $this->assertArrayHasKey('focused_retest_command', $decoded);
        $this->assertArrayHasKey('stop_conditions', $decoded);
    }

    private function service(): DevRepairLoopService
    {
        return app(DevRepairLoopService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function baseInput(): array
    {
        return [
            'run_id' => 'run-test',
            'task_contract_hash' => 'contract-test',
            'risk_level' => 'R2',
            'attempt_count' => 0,
            'max_attempts' => 2,
            'failure_packet' => [
                'gate' => 'verification_gate',
                'command' => '/opt/homebrew/bin/php artisan test tests/Feature/Provider/RouterTest.php',
                'exit_code' => 1,
                'primary_error_excerpt' => 'Failed asserting that null is true',
                'failing_test' => 'tests/Feature/Provider/RouterTest.php::test_fallback',
                'full_error_log_path' => 'storage/logs/test-output.log',
            ],
            'changed_files' => ['app/Services/Provider/Router.php'],
            'previous_changed_files' => [],
            'original_user_intent' => 'corrigir test fallback no provider router',
        ];
    }

    private function expectedSignature(): string
    {
        // Recompute the canonical signature used by FailureCapsule from the
        // baseInput's gate + error excerpt so the test stays in lock-step with
        // the hasher even if the normalization rules evolve.
        $service = $this->service();
        $receipt = $service->evaluate($this->baseInput());

        return (string) $receipt['failure_capture']['failure_signature'];
    }
}
