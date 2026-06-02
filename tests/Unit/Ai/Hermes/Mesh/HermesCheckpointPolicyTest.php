<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesCheckpointPolicy;
use Tests\TestCase;

class HermesCheckpointPolicyTest extends TestCase
{
    private const POLICY_KEY = 'atlas.ai.providers.hermes_cli.mesh.checkpoint_policy';

    /**
     * @return array<string,mixed>
     */
    private function mission(): array
    {
        return [
            'schema_version' => 'atlas.hermes.executive_mission.v1',
            'mission_id' => 'mission-123',
            'mission_hash' => 'abc123def456',
            'objective' => 'mutate some code',
            'scope' => ['permission_mode' => 'write'],
        ];
    }

    public function test_engages_checkpoints_on_approved_write_path(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'write');

        $this->assertSame('atlas.hermes.checkpoint_policy.v1', $receipt['schema_version']);
        $this->assertTrue($receipt['enabled']);
        $this->assertTrue($receipt['checkpoint_before']);
        $this->assertTrue($receipt['rollback_on_failed_verifier']);
        $this->assertTrue($receipt['checkpoint_allowed_now']);
        $this->assertSame('write', $receipt['permission_mode']);
        $this->assertSame('checkpoint_engaged', $receipt['status']);
        $this->assertNull($receipt['reason']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_checkpoint_can_decide']);
        $this->assertSame('mission-123', $receipt['mission_id']);
        $this->assertSame('abc123def456', $receipt['mission_hash']);
    }

    public function test_engages_checkpoints_on_approved_danger_path(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'danger');

        $this->assertTrue($receipt['enabled']);
        $this->assertTrue($receipt['checkpoint_before']);
        $this->assertTrue($receipt['rollback_on_failed_verifier']);
        $this->assertTrue($receipt['checkpoint_allowed_now']);
        $this->assertSame('danger', $receipt['permission_mode']);
        $this->assertSame('checkpoint_engaged', $receipt['status']);
    }

    public function test_fails_closed_when_policy_off_by_default(): void
    {
        // No config set -> safe default 'off'.
        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'write');

        $this->assertFalse($receipt['enabled']);
        $this->assertFalse($receipt['checkpoint_before']);
        $this->assertFalse($receipt['rollback_on_failed_verifier']);
        $this->assertFalse($receipt['checkpoint_allowed_now']);
        $this->assertSame('checkpoint_disabled_by_policy', $receipt['status']);
        $this->assertSame('checkpoint_policy_not_atlas_adapter', $receipt['reason']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_checkpoint_can_decide']);
    }

    public function test_fails_closed_when_policy_explicitly_off(): void
    {
        config([self::POLICY_KEY => 'off']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'danger');

        $this->assertFalse($receipt['enabled']);
        $this->assertFalse($receipt['checkpoint_allowed_now']);
        $this->assertSame('checkpoint_policy_not_atlas_adapter', $receipt['reason']);
    }

    public function test_never_engages_for_read_mode_even_with_atlas_adapter(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'read');

        $this->assertFalse($receipt['enabled']);
        $this->assertFalse($receipt['checkpoint_before']);
        $this->assertFalse($receipt['rollback_on_failed_verifier']);
        $this->assertFalse($receipt['checkpoint_allowed_now']);
        $this->assertSame('read', $receipt['permission_mode']);
        $this->assertSame('permission_mode_read', $receipt['reason']);
        $this->assertSame('checkpoint_blocked', $receipt['status']);
    }

    public function test_normalizes_unknown_permission_mode_to_read_and_blocks(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'GARBAGE-mode');

        $this->assertSame('read', $receipt['permission_mode']);
        $this->assertFalse($receipt['checkpoint_allowed_now']);
        $this->assertFalse($receipt['enabled']);
        // Normalized to 'read', so the read short-circuit fires.
        $this->assertSame('permission_mode_read', $receipt['reason']);
        $this->assertSame('checkpoint_blocked', $receipt['status']);
    }

    public function test_normalizes_case_and_whitespace_on_write_mode(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), '  WRITE  ');

        $this->assertSame('write', $receipt['permission_mode']);
        $this->assertTrue($receipt['checkpoint_allowed_now']);
    }

    public function test_handles_missing_mission_identity_fields(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide([], 'write');

        $this->assertNull($receipt['mission_id']);
        $this->assertNull($receipt['mission_hash']);
        $this->assertTrue($receipt['checkpoint_allowed_now']);
    }

    public function test_receipt_hash_present_last_and_deterministic(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $policy = new HermesCheckpointPolicy;
        $a = $policy->decide($this->mission(), 'write');
        $b = $policy->decide($this->mission(), 'write');

        $this->assertArrayHasKey('receipt_hash', $a);
        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);

        // receipt_hash must be the LAST key.
        $keys = array_keys($a);
        $this->assertSame('receipt_hash', end($keys));

        // Hash must be a sha256 hex digest.
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $a['receipt_hash']);

        // Different policy state must yield a different hash.
        config([self::POLICY_KEY => 'off']);
        $c = $policy->decide($this->mission(), 'write');
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
    }

    public function test_first_key_is_schema_version(): void
    {
        config([self::POLICY_KEY => 'atlas_adapter']);

        $receipt = (new HermesCheckpointPolicy)->decide($this->mission(), 'write');
        $keys = array_keys($receipt);

        $this->assertSame('schema_version', $keys[0]);
    }
}
