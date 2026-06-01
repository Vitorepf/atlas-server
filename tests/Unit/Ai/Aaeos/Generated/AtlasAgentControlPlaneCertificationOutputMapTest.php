<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneCertificationOutputMapService;
use Tests\TestCase;

/**
 * Pins the "Padrao tudo verde" rule set and the "Regras para IA" from the doc.
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/evidence-index/atlas-agent-control-plane-certification-output-map-v1.md
 */
class AtlasAgentControlPlaneCertificationOutputMapTest extends TestCase
{
    private function service(): AtlasAgentControlPlaneCertificationOutputMapService
    {
        return new AtlasAgentControlPlaneCertificationOutputMapService;
    }

    public function test_canonical_all_green_sample_is_evaluated_green_for_each_command(): void
    {
        $service = $this->service();

        foreach ([
            AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_PROJECTION,
            AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY,
            AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_REPLAY,
        ] as $command) {
            $result = $service->evaluate($command, $service->allGreenSample($command));

            $this->assertTrue($result['all_green'], "{$command} canonical sample must be all-green");
            $this->assertSame([], $result['deviations']);
            // Regras para IA: all-green still never authorizes runtime.
            $this->assertFalse($result['runtime_authorized']);
            $this->assertSame('all_green_read_only_safe_may_proceed', $result['human_label']);
        }
    }

    public function test_any_allowed_flag_true_breaks_all_green(): void
    {
        $service = $this->service();
        $fields = $service->allGreenSample(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY);
        // execution_allowed=true means the projection started executing an adapter.
        $fields['execution_allowed'] = true;

        $result = $service->evaluate(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY, $fields);

        $this->assertFalse($result['all_green']);
        $this->assertContains('flag_must_be_false:execution_allowed', $result['deviations']);
        $this->assertSame('deviation_detected_diagnose_before_advancing', $result['human_label']);
    }

    public function test_mode_not_starting_with_read_only_is_a_writer_regression(): void
    {
        $service = $this->service();
        $fields = $service->allGreenSample(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_REPLAY);
        $fields['mode'] = 'writer_agent_control_plane_deterministic_chain_replay';

        $result = $service->evaluate(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_REPLAY, $fields);

        $this->assertFalse($result['all_green']);
        $this->assertFalse($result['checks']['mode_read_only']);
        $this->assertContains(
            'mode_regressed_to_writer:writer_agent_control_plane_deterministic_chain_replay',
            $result['deviations']
        );
    }

    public function test_violation_count_above_zero_blocks_but_warning_count_does_not(): void
    {
        $service = $this->service();
        $command = AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY;

        // A single violation blocks promotion.
        $violating = $service->allGreenSample($command);
        $violating['violation_count'] = 1;
        $blocked = $service->evaluate($command, $violating);
        $this->assertFalse($blocked['all_green']);
        $this->assertContains('violation_count_blocks_promotion:1', $blocked['deviations']);

        // Warnings are recorded but do NOT break all-green (doc: non-blocking).
        $warned = $service->allGreenSample($command);
        $warned['warning_count'] = 3;
        $stillGreen = $service->evaluate($command, $warned);
        $this->assertTrue($stillGreen['all_green']);
        $this->assertFalse($stillGreen['checks']['no_warnings']);
    }

    public function test_pointer_mismatch_is_reported_as_regression_never_silenced(): void
    {
        $service = $this->service();
        $fields = $service->allGreenSample(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY);
        $fields['expected_next_required_slice'] = 'some_other_slice';

        $result = $service->evaluate(AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_CHAIN_INTEGRITY, $fields);

        $this->assertFalse($result['all_green']);
        $this->assertFalse($result['checks']['pointer_aligned:next_required_slice']);
        $this->assertContains(
            'pointer_regression:next_required_slice:current=activate_signed_one_shot_scheduler_tick:expected=some_other_slice',
            $result['deviations']
        );
    }

    public function test_runtime_safety_all_false_false_breaks_green_and_is_never_runtime_authorization(): void
    {
        $service = $this->service();
        $command = AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_REPLAY;

        // When runtime_safety_all_false is FALSE, that is unsafe -> not green.
        $fields = $service->allGreenSample($command);
        $fields['runtime_safety_all_false'] = false;
        $unsafe = $service->evaluate($command, $fields);
        $this->assertFalse($unsafe['all_green']);
        $this->assertContains('runtime_safety_not_all_false', $unsafe['deviations']);

        // And even when it is TRUE (safe), runtime is still NOT authorized.
        $safe = $service->evaluate($command, $service->allGreenSample($command));
        $this->assertTrue($safe['all_green']);
        $this->assertFalse($safe['runtime_authorized']);
    }

    public function test_hash_drift_without_code_change_is_a_bug_but_acknowledged_when_code_changed(): void
    {
        $service = $this->service();
        $baseline = ['replay_hash' => 'aaaa', 'deterministic_replay_hash' => 'bbbb', 'proof_bundle_hash' => 'cccc'];

        // Stable: identical readings.
        $stable = $service->compareHashes($baseline, $baseline);
        $this->assertTrue($stable['stable']);
        $this->assertSame('hashes_stable_deterministic', $stable['verdict']);

        // Drift with NO code change -> determinism bug, never "expected".
        $driftNoCode = $service->compareHashes($baseline, ['replay_hash' => 'zzzz', 'deterministic_replay_hash' => 'bbbb', 'proof_bundle_hash' => 'cccc']);
        $this->assertFalse($driftNoCode['stable']);
        $this->assertSame(['replay_hash'], $driftNoCode['drifted_fields']);
        $this->assertSame('determinism_bug_hash_drift_without_code_change', $driftNoCode['verdict']);

        // Same drift WITH a code change -> acknowledged, record release note.
        $driftWithCode = $service->compareHashes($baseline, ['replay_hash' => 'zzzz', 'deterministic_replay_hash' => 'bbbb', 'proof_bundle_hash' => 'cccc'], true);
        $this->assertSame('hash_drift_acknowledged_code_changed_record_release_note', $driftWithCode['verdict']);
    }

    public function test_corridor_is_green_only_when_all_three_commands_are_green(): void
    {
        $service = $this->service();

        $greenAll = $service->corridorVerdict([
            ['command' => 'a', 'all_green' => true, 'deviations' => []],
            ['command' => 'b', 'all_green' => true, 'deviations' => []],
            ['command' => 'c', 'all_green' => true, 'deviations' => []],
        ]);
        $this->assertTrue($greenAll['corridor_all_green']);
        $this->assertSame(3, $greenAll['all_green_command_count']);
        $this->assertFalse($greenAll['runtime_authorized']);

        $oneBad = $service->corridorVerdict([
            ['command' => 'a', 'all_green' => true, 'deviations' => []],
            ['command' => 'b', 'all_green' => false, 'deviations' => ['x']],
            ['command' => 'c', 'all_green' => true, 'deviations' => []],
        ]);
        $this->assertFalse($oneBad['corridor_all_green']);
        $this->assertSame(['b'], $oneBad['blocking_commands']);
    }

    public function test_schema_version_mismatch_is_flagged(): void
    {
        $service = $this->service();
        $command = AtlasAgentControlPlaneCertificationOutputMapService::COMMAND_PROJECTION;

        $ok = $service->verifySchemaVersion($command, 'atlas.self_construction_agent_control_plane.v1');
        $this->assertTrue($ok['matches']);

        $bad = $service->verifySchemaVersion($command, 'atlas.self_construction_agent_control_plane.v2');
        $this->assertFalse($bad['matches']);
        $this->assertSame('atlas.self_construction_agent_control_plane.v1', $bad['expected']);
    }
}
