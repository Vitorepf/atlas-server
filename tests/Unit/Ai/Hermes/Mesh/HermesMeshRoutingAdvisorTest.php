<?php

namespace Tests\Unit\Ai\Hermes\Mesh;

use App\Services\Ai\Hermes\Mesh\HermesMeshRoutingAdvisor;
use Tests\TestCase;

class HermesMeshRoutingAdvisorTest extends TestCase
{
    private function advisor(): HermesMeshRoutingAdvisor
    {
        return new HermesMeshRoutingAdvisor;
    }

    private function enablePolicy(): void
    {
        config(['atlas.ai.providers.hermes_cli.mesh.policy' => 'atlas_adapter']);
    }

    private function enableAutoRoute(): void
    {
        config(['atlas.ai.providers.hermes_cli.mesh.auto_route' => true]);
    }

    public function test_execution_route_stays_single_when_advised_but_auto_route_off(): void
    {
        // policy + signal => mesh_advised, but the dedicated auto_route switch is OFF
        // (default), so Atlas Decide must NOT auto-route — execution_route='single'.
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a', 'b']]]],
        ]);

        $this->assertTrue($receipt['mesh_advised'], 'advisable on the approved path');
        $this->assertFalse($receipt['auto_route_enabled']);
        $this->assertSame('single', $receipt['execution_route'], 'advice without the auto_route consent never auto-routes');
    }

    public function test_execution_route_mesh_only_when_advised_and_auto_route_on(): void
    {
        $this->enablePolicy();
        $this->enableAutoRoute();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a', 'b', 'c']]]],
        ]);

        $this->assertTrue($receipt['mesh_advised']);
        $this->assertTrue($receipt['auto_route_enabled']);
        $this->assertSame('mesh', $receipt['execution_route']);
    }

    public function test_auto_route_on_does_not_override_policy_off(): void
    {
        // auto_route on but mesh policy off => still fail-closed: not advised, single.
        $this->enableAutoRoute();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a']]]],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('mesh_policy_off', $receipt['blocked_reason']);
        $this->assertSame('single', $receipt['execution_route'], 'auto_route alone cannot route without policy + signal + privacy');
    }

    public function test_auto_route_on_still_blocked_by_privacy(): void
    {
        $this->enablePolicy();
        $this->enableAutoRoute();

        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['subtasks' => ['a', 'b']]],
                'privacy' => ['sensitivity' => 'secret'],
            ],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('privacy_blocks_external_runtime', $receipt['blocked_reason']);
        $this->assertSame('single', $receipt['execution_route'], 'sensitive/secret never auto-routes even with both switches on');
    }

    public function test_advises_mesh_on_fully_approved_path_with_subtasks(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['subtasks' => ['a', 'b', 'c']]],
            ],
        ]);

        $this->assertSame('schema_version', array_key_first($receipt));
        $this->assertSame('atlas.hermes.mesh_routing.v1', $receipt['schema_version']);
        $this->assertTrue($receipt['mesh_advised']);
        $this->assertNull($receipt['blocked_reason']);
        $this->assertSame(3, $receipt['subtask_count']);
        $this->assertSame('atlas', $receipt['authority']);
        $this->assertFalse($receipt['hermes_mesh_can_decide']);
        $this->assertSame('mesh_advised', $receipt['status']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }

    public function test_advises_mesh_when_requested_flag_present(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['requested' => true]],
            ],
        ]);

        $this->assertTrue($receipt['mesh_advised']);
        $this->assertNull($receipt['blocked_reason']);
        $this->assertSame(0, $receipt['subtask_count']);
    }

    public function test_default_off_fail_closed_when_policy_missing(): void
    {
        // No policy config set => default 'off'.
        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['subtasks' => ['a']]],
            ],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('mesh_policy_off', $receipt['blocked_reason']);
        $this->assertSame('mesh_disabled_by_policy', $receipt['status']);
        $this->assertArrayHasKey('receipt_hash', $receipt);
    }

    public function test_no_decomposition_signal_blocks(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => []]],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('no_decomposition_signal', $receipt['blocked_reason']);
        $this->assertSame(0, $receipt['subtask_count']);
        $this->assertSame('mesh_not_advised', $receipt['status']);
    }

    public function test_empty_subtasks_array_is_no_signal(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => []]]],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('no_decomposition_signal', $receipt['blocked_reason']);
    }

    public function test_privacy_sensitive_blocks_external_runtime(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['subtasks' => ['a', 'b']]],
                'privacy' => ['sensitivity' => 'sensitive'],
            ],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('privacy_blocks_external_runtime', $receipt['blocked_reason']);
        $this->assertSame('mesh_blocked', $receipt['status']);
        $this->assertSame(2, $receipt['subtask_count']);
    }

    public function test_privacy_secret_blocks_external_runtime(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => [
                'hermes' => ['mesh' => ['requested' => true]],
                'privacy' => ['sensitivity' => 'secret'],
            ],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('privacy_blocks_external_runtime', $receipt['blocked_reason']);
    }

    public function test_invalid_input_empty_options_fails_closed(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('no_decomposition_signal', $receipt['blocked_reason']);
        $this->assertSame(0, $receipt['subtask_count']);
    }

    public function test_invalid_subtasks_scalar_is_ignored(): void
    {
        $this->enablePolicy();

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => 'not-an-array']]],
        ]);

        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('no_decomposition_signal', $receipt['blocked_reason']);
        $this->assertSame(0, $receipt['subtask_count']);
    }

    public function test_invalid_policy_value_falls_back_to_off(): void
    {
        config(['atlas.ai.providers.hermes_cli.mesh.policy' => ['not', 'a', 'string']]);

        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['requested' => true]]],
        ]);

        $this->assertSame('off', $receipt['mesh_policy']);
        $this->assertFalse($receipt['mesh_advised']);
        $this->assertSame('mesh_policy_off', $receipt['blocked_reason']);
    }

    public function test_receipt_hash_present_and_deterministic(): void
    {
        $this->enablePolicy();

        $options = [
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a', 'b']]]],
        ];

        $first = $this->advisor()->advise($options);
        $second = $this->advisor()->advise($options);

        $this->assertArrayHasKey('receipt_hash', $first);
        $this->assertSame(64, strlen($first['receipt_hash']));
        $this->assertSame($first['receipt_hash'], $second['receipt_hash']);
    }

    public function test_receipt_hash_present_and_allowed_now_false_when_off(): void
    {
        // Default off.
        $receipt = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a']]]],
        ]);

        $this->assertArrayHasKey('receipt_hash', $receipt);
        // mesh_advised is the *_allowed_now-equivalent.
        $this->assertFalse($receipt['mesh_advised']);

        // Deterministic when off too.
        $again = $this->advisor()->advise([
            'payload' => ['hermes' => ['mesh' => ['subtasks' => ['a']]]],
        ]);
        $this->assertSame($receipt['receipt_hash'], $again['receipt_hash']);
    }
}
