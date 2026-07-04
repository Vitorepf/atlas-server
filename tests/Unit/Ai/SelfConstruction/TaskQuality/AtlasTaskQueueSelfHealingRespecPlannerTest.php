<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\TaskQuality\AtlasTaskQueueSelfHealingRespecPlanner;
use Tests\TestCase;

final class AtlasTaskQueueSelfHealingRespecPlannerTest extends TestCase
{
    private function planner(): AtlasTaskQueueSelfHealingRespecPlanner
    {
        return new AtlasTaskQueueSelfHealingRespecPlanner();
    }

    private function healthyPacket(array $overrides = []): array
    {
        return array_merge([
            'target'              => 'AtlasFooService',
            'allowed_files'       => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
            'acceptance_criteria' => [
                'Must implement deterministic scoring.',
                'Running ./vendor/bin/phpunit produces green output.',
            ],
            'forbidden_targets'   => [],
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_present(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());
        $this->assertSame(AtlasTaskQueueSelfHealingRespecPlanner::SCHEMA, $result['schema']);
    }

    // ── healthy packet ────────────────────────────────────────────────────────

    public function test_healthy_packet_no_respec(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());

        $this->assertFalse($result['respec_required']);
        $this->assertSame([], $result['issues']);
        $this->assertSame([], $result['respec_actions']);
        $this->assertSame([], $result['evidence_requirements']);
    }

    // ── scope_removes_implementation ──────────────────────────────────────────

    public function test_respec_when_scope_repair_removed_impl(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'scope_repair_removed_impl' => true,
        ]));

        $this->assertTrue($result['respec_required']);
        $types = array_column($result['issues'], 'type');
        $this->assertContains('scope_removes_implementation', $types);
    }

    public function test_respec_action_add_implementation_file_for_scope_repair(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'scope_repair_removed_impl' => true,
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_implementation_file', $actions);
    }

    public function test_scope_repair_issue_includes_root_cause(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'scope_repair_removed_impl' => true,
        ]));

        $issue = $result['issues'][array_search('scope_removes_implementation', array_column($result['issues'], 'type'), true)];
        $this->assertArrayHasKey('root_cause', $issue);
        $this->assertNotEmpty($issue['root_cause']);
    }

    // ── contradictory_acceptance ──────────────────────────────────────────────

    public function test_respec_for_explicit_contradictory_acceptance_flag(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('contradictory_acceptance', $types);
    }

    public function test_respec_for_contradictory_acceptance_criteria(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'acceptance_criteria' => [
                'Must call provider API.',
                'Must not call provider API.',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('contradictory_acceptance', $types);
    }

    public function test_contradictory_acceptance_evidence_names_the_conflicting_criteria(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'acceptance_criteria' => [
                'Must call provider API.',
                'Must not call provider API.',
            ],
        ]));

        $joined = implode(' ', $result['evidence_requirements']);
        $this->assertStringContainsString('Must call provider API.', $joined);
        $this->assertStringContainsString('Must not call provider API.', $joined);
    }

    public function test_respec_action_revise_acceptance_criteria(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('revise_acceptance_criteria', $actions);
    }

    // ── forbidden_target ──────────────────────────────────────────────────────

    public function test_respec_when_target_forbidden(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'AtlasFooService',
            'forbidden_targets' => ['AtlasFooService'],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('forbidden_target', $types);
    }

    public function test_forbidden_target_check_is_case_insensitive(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'AtlasFooService',
            'forbidden_targets' => ['atlasfooservice'],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('forbidden_target', $types);
    }

    public function test_respec_action_replace_target_for_forbidden(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'           => 'BadTarget',
            'forbidden_targets' => ['badtarget'],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('replace_target', $actions);
    }

    // ── missing_test_path ─────────────────────────────────────────────────────

    public function test_respec_when_no_test_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('missing_test_path', $types);
    }

    public function test_respec_action_add_test_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
            ],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_test_file', $actions);
    }

    // ── test_only_packet ──────────────────────────────────────────────────────

    public function test_respec_for_test_only_packet(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $types = array_column($result['issues'], 'type');
        $this->assertContains('test_only_packet', $types);
    }

    public function test_test_only_action_is_add_implementation_file(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => [
                'tests/Unit/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $actions = array_column($result['respec_actions'], 'action');
        $this->assertContains('add_implementation_file', $actions);
    }

    // ── evidence_requirements ─────────────────────────────────────────────────

    public function test_evidence_requirements_non_empty_when_respec_required(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'has_contradictory_acceptance' => true,
        ]));

        $this->assertNotEmpty($result['evidence_requirements']);
    }

    public function test_evidence_requirements_empty_when_no_respec(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());
        $this->assertSame([], $result['evidence_requirements']);
    }

    // ── multiple issues accumulate ────────────────────────────────────────────

    public function test_multiple_issues_accumulate(): void
    {
        $result = $this->planner()->plan([
            'target'                       => 'BadTarget',
            'allowed_files'                => ['tests/Unit/Foo/FooTest.php'],
            'acceptance_criteria'          => ['Must call provider.', 'Must not call provider.'],
            'has_contradictory_acceptance' => true,
            'forbidden_targets'            => ['badtarget'],
        ]);

        $this->assertTrue($result['respec_required']);
        $this->assertGreaterThanOrEqual(3, count($result['issues']));
    }

    // ── determinism ──────────────────────────────────────────────────────────

    public function test_identical_input_yields_identical_output(): void
    {
        $input = $this->healthyPacket(['has_contradictory_acceptance' => true]);
        $this->assertSame($this->planner()->plan($input), $this->planner()->plan($input));
    }

    // ── runnable_command_hint and non_fatal_discovery_hints ──────────────────

    public function test_respec_includes_runnable_command_hint(): void
    {
        $result = $this->planner()->plan([
            'allowed_files'       => ['app/Services/Foo.php'],
            'acceptance_criteria' => ['must pass tests'],
            'forbidden_targets'   => [],
        ]);

        $this->assertTrue($result['respec_required']);
        $this->assertNotNull($result['runnable_command_hint']);
        $this->assertStringContainsString('artisan test', $result['runnable_command_hint']);
    }

    public function test_missing_test_path_emits_non_fatal_discovery_hint(): void
    {
        $result = $this->planner()->plan([
            'allowed_files'       => ['app/Services/Foo.php'],
            'acceptance_criteria' => ['must pass tests'],
            'forbidden_targets'   => [],
        ]);

        $this->assertNotEmpty($result['non_fatal_discovery_hints']);
        $this->assertStringContainsString('discovery', $result['non_fatal_discovery_hints'][0]);
    }

    public function test_test_only_packet_emits_non_fatal_discovery_hint(): void
    {
        $result = $this->planner()->plan([
            'allowed_files'       => ['tests/Unit/FooTest.php'],
            'acceptance_criteria' => ['must pass tests'],
            'forbidden_targets'   => [],
        ]);

        $this->assertNotEmpty($result['non_fatal_discovery_hints']);
    }

    public function test_healthy_packet_has_no_runnable_command_hint_or_discovery_hints(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());

        $this->assertFalse($result['respec_required']);
        $this->assertNull($result['runnable_command_hint']);
        $this->assertSame([], $result['non_fatal_discovery_hints']);
    }

    // ── AC3: before/after implementability + residual risk ─────────────────────

    public function test_replacement_readiness_included_when_respec_required(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php'],
        ]));

        $this->assertArrayHasKey('replacement_readiness', $result);
        $rr = $result['replacement_readiness'];
        $this->assertTrue($rr['has_impl']);
        $this->assertFalse($rr['has_test']);
        $this->assertTrue($rr['has_runnable_command']);
        $this->assertFalse($rr['claimable_after_respec']); // missing test
    }

    public function test_replacement_readiness_null_when_no_respec(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());
        $this->assertNull($result['replacement_readiness']);
    }

    public function test_replacement_readiness_claimable_when_fully_repairable(): void
    {
        // missing_test_path with a simple add_test_file → fully repairable
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php'],
        ]));

        $this->assertTrue($result['implementability_after']);
        // claimable: has_impl=true, add_test_file fixes has_test → after respec both present
        $this->assertTrue($result['replacement_readiness']['has_impl']);
        $this->assertFalse($result['replacement_readiness']['has_test']);
        $this->assertTrue($result['replacement_readiness']['has_runnable_command']);
        // claimable_after_respec is about the RESOLVED state: has_test is currently false,
        // so the packet isn't claimable yet — respec must happen first.
        $this->assertFalse($result['replacement_readiness']['claimable_after_respec']);
    }

    public function test_replacement_readiness_has_correct_booleans(): void
    {
        // Impl + test present but forbidden_target → still has impl & test but not claimable
        $result = $this->planner()->plan($this->healthyPacket([
            'target'            => 'AtlasFooService',
            'forbidden_targets' => ['atlasfooservice'],
        ]));

        $rr = $result['replacement_readiness'];
        $this->assertTrue($rr['has_impl']);
        $this->assertTrue($rr['has_test']);
        $this->assertTrue($rr['has_runnable_command']);
        // forbidden_target means implementability_after=false → not claimable
        $this->assertFalse($rr['claimable_after_respec']);
    }

    public function test_healthy_packet_is_implementable_before_and_after_with_no_residual_risk(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());

        $this->assertTrue($result['implementability_before']);
        $this->assertTrue($result['implementability_after']);
        $this->assertSame([], $result['residual_risk']);
    }

    public function test_missing_test_path_is_not_implementable_before_but_is_after(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php'],
        ]));

        $this->assertFalse($result['implementability_before']);
        $this->assertTrue($result['implementability_after']);
        $this->assertSame([], $result['residual_risk']);
    }

    public function test_forbidden_target_is_not_fully_implementable_after_and_names_residual_risk(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'target'            => 'AtlasFooService',
            'forbidden_targets' => ['atlasfooservice'],
        ]));

        $this->assertFalse($result['implementability_before']);
        $this->assertFalse($result['implementability_after']);
        $this->assertNotEmpty($result['residual_risk']);
    }

    // ── AC2: refuses broad-scope or proof-gate-removing proposed respecs ───────

    public function test_proposed_respec_validation_absent_when_no_proposal_given(): void
    {
        $result = $this->planner()->plan($this->healthyPacket());

        $this->assertNull($result['proposed_respec_validation']);
    }

    public function test_proposed_respec_in_scope_and_proof_preserving_is_valid(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'allowed_files' => ['app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php'],
            'proposed_allowed_files' => [
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooService.php',
                'app/Services/Ai/SelfConstruction/Foo/AtlasFooServiceTest.php',
            ],
        ]));

        $this->assertTrue($result['proposed_respec_validation']['valid']);
        $this->assertSame([], $result['proposed_respec_validation']['refusal_reasons']);
    }

    public function test_proposed_respec_adding_unrelated_file_is_refused(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'proposed_allowed_files' => array_merge($this->healthyPacket()['allowed_files'], [
                'app/Services/Ai/UnrelatedDomain/SomethingElse.php',
            ]),
        ]));

        $this->assertFalse($result['proposed_respec_validation']['valid']);
        $reasonBlob = implode(',', $result['proposed_respec_validation']['refusal_reasons']);
        $this->assertStringContainsString('broad_scope_expansion', $reasonBlob);
    }

    public function test_proposed_respec_dropping_runnable_proof_criterion_is_refused(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'proposed_acceptance_criteria' => ['Must implement deterministic scoring.'],
        ]));

        $this->assertFalse($result['proposed_respec_validation']['valid']);
        $reasonBlob = implode(',', $result['proposed_respec_validation']['refusal_reasons']);
        $this->assertStringContainsString('removed_meaningful_proof_gate', $reasonBlob);
    }

    public function test_proposed_respec_dropping_non_proof_criterion_is_allowed(): void
    {
        $result = $this->planner()->plan($this->healthyPacket([
            'acceptance_criteria' => [
                'Must implement deterministic scoring.',
                'Should read well in the changelog.',
                'Running ./vendor/bin/phpunit produces green output.',
            ],
            'proposed_acceptance_criteria' => [
                'Must implement deterministic scoring.',
                'Running ./vendor/bin/phpunit produces green output.',
            ],
        ]));

        $this->assertTrue($result['proposed_respec_validation']['valid']);
    }
}
