<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeSchedulerManifest;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimeSchedulerManifestTest extends TestCase
{
    public function test_schema_constant_and_default_shape(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();

        $this->assertSame(AtlasSelfConstructionRuntimeSchedulerManifest::SCHEMA, $m['schema_version']);
        $this->assertSame('atlas_native', $m['final_runtime_owner']);
        $this->assertSame('atlas_server', $m['steady_state_runtime_owner']);
        $this->assertFalse($m['operator_dependency_allowed']);
        $this->assertFalse($m['human_dependency_allowed']);
        $this->assertFalse($m['external_provider_dependency_allowed']);
    }

    public function test_tick_command_contains_atlas_native_daemon_tick_with_apply_and_json(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();

        $this->assertStringContainsString('atlas:self-construction:runtime-daemon', $m['tick_command']);
        $this->assertStringContainsString('tick', $m['tick_command']);
        $this->assertStringContainsString('--apply', $m['tick_command']);
        $this->assertStringContainsString('--json', $m['tick_command']);
        $this->assertStringContainsString('/opt/homebrew/bin/php', $m['tick_command']);
        $this->assertStringContainsString('atlas:self-construction:runtime-daemon status', $m['status_command']);
        $this->assertStringContainsString('atlas:self-construction:runtime-daemon plan', $m['plan_command']);
    }

    public function test_php_bin_and_facts_path_are_configurable(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest([
            'php_bin' => '/usr/bin/php',
            'facts_path' => '/var/atlas/facts.json',
        ]);

        $this->assertStringContainsString('/usr/bin/php', $m['tick_command']);
        $this->assertStringContainsString('--facts=/var/atlas/facts.json', $m['tick_command']);
        $this->assertStringNotContainsString('/opt/homebrew', $m['tick_command']);
    }

    public function test_safety_stop_conditions_and_evidence_obligations_present(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();

        foreach (['master_switch_off', 'safety_stop_event', 'pause_requested', 'stop_requested', 'unattended_supervisor_critical_blocker', 'heartbeat_stale_beyond_policy', 'non_atlas_dependency_detected'] as $s) {
            $this->assertContains($s, $m['safety_stop_conditions']);
        }
        foreach (['daemon_cycle_hash', 'cycle_receipt_hash', 'state_hash', 'supervisor_hash'] as $e) {
            $this->assertContains($e, $m['evidence_obligations']);
        }
        $this->assertSame('exponential_with_jitter', $m['backoff_policy']['kind']);
    }

    public function test_no_provider_operator_or_git_command_appears_in_executable_fields(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();
        $executableFields = [$m['tick_command'], $m['status_command'], $m['plan_command']];
        $haystack = implode("\n", $executableFields);

        foreach (['curl', 'wget', 'ssh', 'git ', 'docker', 'claude', 'codex', 'cursor', 'http', 'aws', 'sudo', 'sh -c', 'operator'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $haystack, "executable field must not contain {$forbidden}");
        }
    }

    public function test_manifest_is_deterministic_for_identical_options(): void
    {
        $a = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest(['cadence_seconds' => 90]);
        $b = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest(['cadence_seconds' => 90]);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_manifest_is_pure_self_install_is_false(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();

        $this->assertFalse($m['self_install']);
        $this->assertSame('atlas_existing_scheduler_or_launchd_bridge', $m['expected_consumer']);
    }

    // ---------- lanes, queue thresholds, cadence, manifest hash ----------

    public function test_enabled_lanes_defaults_contain_all_seven_final_brain_lanes(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();
        $this->assertContains('self-recovery',           $m['enabled_lanes']);
        $this->assertContains('compounding',             $m['enabled_lanes']);
        $this->assertContains('completion-certification',$m['enabled_lanes']);
        $this->assertCount(7, $m['enabled_lanes']);
    }

    public function test_disabled_lanes_are_excluded_from_enabled_lanes(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest([
            'disabled_lanes' => ['compounding', 'frontier-import'],
        ]);
        $this->assertNotContains('compounding',     $m['enabled_lanes']);
        $this->assertNotContains('frontier-import', $m['enabled_lanes']);
        $this->assertContains('compounding',     $m['disabled_lanes']);
        $this->assertContains('frontier-import', $m['disabled_lanes']);
        $this->assertCount(5, $m['enabled_lanes']);
    }

    public function test_queue_thresholds_defaults_are_present(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();
        $this->assertSame(500, $m['queue_thresholds']['max_queued']);
        $this->assertSame(20,  $m['queue_thresholds']['max_claimed']);
        $this->assertFalse($m['queue_thresholds']['drain_before_stop']);
    }

    public function test_queue_thresholds_can_be_overridden(): void
    {
        $m = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest([
            'queue_thresholds' => ['max_queued' => 100],
        ]);
        $this->assertSame(100, $m['queue_thresholds']['max_queued']);
        $this->assertSame(20,  $m['queue_thresholds']['max_claimed']); // default preserved
    }

    public function test_cadence_is_configurable_and_bounded_to_at_least_one(): void
    {
        $m1 = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest(['cadence_seconds' => 30]);
        $this->assertSame(30, $m1['cadence_seconds']);

        $m2 = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest(['cadence_seconds' => 0]);
        $this->assertSame(1, $m2['cadence_seconds']); // clamped
    }

    public function test_manifest_hash_is_64_char_sha256_and_changes_with_options(): void
    {
        $m1 = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest();
        $m2 = (new AtlasSelfConstructionRuntimeSchedulerManifest)->manifest(['cadence_seconds' => 30]);

        $this->assertSame(64, strlen($m1['manifest_hash']));
        $this->assertNotSame($m1['manifest_hash'], $m2['manifest_hash']);
    }
}
