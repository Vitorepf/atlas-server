<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesMeshCheckpointExecutor;
use Tests\TestCase;

class HermesMeshCheckpointExecutorTest extends TestCase
{
    /**
     * An engaged (fully-approved) checkpoint policy decision.
     *
     * @return array<string,mixed>
     */
    private function engagedDecision(string $permissionMode = 'write'): array
    {
        return [
            'schema_version' => 'atlas.hermes.checkpoint_policy.v1',
            'enabled' => true,
            'checkpoint_before' => true,
            'rollback_on_failed_verifier' => true,
            'checkpoint_allowed_now' => true,
            'permission_mode' => $permissionMode,
        ];
    }

    /**
     * A disabled (fail-closed) checkpoint policy decision.
     *
     * @return array<string,mixed>
     */
    private function disabledDecision(): array
    {
        return [
            'schema_version' => 'atlas.hermes.checkpoint_policy.v1',
            'enabled' => false,
            'checkpoint_before' => false,
            'rollback_on_failed_verifier' => false,
            'checkpoint_allowed_now' => false,
            'permission_mode' => 'read',
        ];
    }

    public function test_rolls_back_on_approved_path_failed_verifier(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->engagedDecision('write'),
            ['passed' => false, 'detail' => 'tests failed']
        );

        $this->assertSame('atlas.hermes.checkpoint_action.v1', $receipt['schema_version']);
        $this->assertTrue($receipt['rollback_now']);
        $this->assertTrue($receipt['action_allowed_now']);
        $this->assertFalse($receipt['verifier_passed']);
        $this->assertSame('hermes_checkpoints_restore', $receipt['rollback_strategy']);
        $this->assertSame('write', $receipt['permission_mode']);
        $this->assertSame('rollback_required', $receipt['status']);
        $this->assertNull($receipt['reason']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_checkpoint_can_decide']);
        $this->assertSame(['checkpoints'], $receipt['rollback_args']);
    }

    public function test_no_rollback_when_verifier_passed(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->engagedDecision('danger'),
            ['passed' => true]
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertTrue($receipt['verifier_passed']);
        $this->assertSame('verifier_passed', $receipt['reason']);
        $this->assertSame('rollback_not_required', $receipt['status']);
        $this->assertSame('danger', $receipt['permission_mode']);
    }

    public function test_fails_closed_when_policy_disabled(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->disabledDecision(),
            ['passed' => false]
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertSame('checkpoint_policy_not_enabled', $receipt['reason']);
        $this->assertSame('rollback_blocked', $receipt['status']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_checkpoint_can_decide']);
    }

    public function test_fails_closed_when_rollback_not_armed_even_if_enabled(): void
    {
        $decision = $this->engagedDecision('write');
        $decision['rollback_on_failed_verifier'] = false;

        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $decision,
            ['passed' => false]
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertSame('rollback_on_failed_verifier_not_engaged', $receipt['reason']);
        $this->assertSame('rollback_blocked', $receipt['status']);
    }

    public function test_fails_closed_on_empty_decision(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate([], ['passed' => false]);

        $this->assertFalse($receipt['rollback_now']);
        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertSame('checkpoint_policy_not_enabled', $receipt['reason']);
        $this->assertSame('read', $receipt['permission_mode']);
    }

    public function test_fails_closed_when_verifier_missing_passed(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->engagedDecision('write'),
            ['detail' => 'no passed key']
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertSame('verifier_result_missing_passed', $receipt['reason']);
        $this->assertSame('rollback_blocked', $receipt['status']);
    }

    public function test_fails_closed_when_passed_is_not_a_bool(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->engagedDecision('write'),
            ['passed' => 'false']
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertSame('verifier_result_missing_passed', $receipt['reason']);
    }

    public function test_fails_closed_when_enabled_is_truthy_but_not_bool(): void
    {
        $decision = $this->engagedDecision('write');
        $decision['enabled'] = 1; // truthy but not a real bool

        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $decision,
            ['passed' => false]
        );

        $this->assertFalse($receipt['rollback_now']);
        $this->assertSame('checkpoint_policy_not_enabled', $receipt['reason']);
    }

    public function test_normalizes_invalid_permission_mode_to_read(): void
    {
        $decision = $this->engagedDecision('GARBAGE');

        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $decision,
            ['passed' => false]
        );

        $this->assertSame('read', $receipt['permission_mode']);
        // Mode is passthrough/advisory only; the rollback still fires on the
        // fully-approved policy + failed verifier path.
        $this->assertTrue($receipt['rollback_now']);
    }

    public function test_rollback_args_helper_is_pure_advisory(): void
    {
        $this->assertSame(['checkpoints'], (new HermesMeshCheckpointExecutor)->rollbackArgs());
    }

    public function test_receipt_hash_present_last_and_deterministic(): void
    {
        $executor = new HermesMeshCheckpointExecutor;
        $a = $executor->evaluate($this->engagedDecision('write'), ['passed' => false]);
        $b = $executor->evaluate($this->engagedDecision('write'), ['passed' => false]);

        $this->assertArrayHasKey('receipt_hash', $a);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);

        $keys = array_keys($a);
        $this->assertSame('receipt_hash', end($keys));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['receipt_hash']);

        // Different outcome must yield a different hash.
        $c = $executor->evaluate($this->engagedDecision('write'), ['passed' => true]);
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
    }

    public function test_first_key_is_schema_version(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->engagedDecision('write'),
            ['passed' => false]
        );

        $keys = array_keys($receipt);
        $this->assertSame('schema_version', $keys[0]);
    }

    public function test_action_allowed_now_false_when_off(): void
    {
        $receipt = (new HermesMeshCheckpointExecutor)->evaluate(
            $this->disabledDecision(),
            ['passed' => false]
        );

        $this->assertFalse($receipt['action_allowed_now']);
        $this->assertFalse($receipt['rollback_now']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }
}
