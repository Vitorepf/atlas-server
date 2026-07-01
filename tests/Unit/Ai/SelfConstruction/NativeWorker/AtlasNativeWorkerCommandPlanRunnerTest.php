<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerCommandPlanRunner;
use PHPUnit\Framework\TestCase;

/**
 * Proves the AtlasNativeWorkerCommandPlanRunner: dry_run validates without executing; an allowlisted
 * harmless PHP command runs and returns exit_code=0; a non-allowlisted command is denied; a forbidden
 * label (network/external_provider) is denied_label; secret-like env values are redacted to '***';
 * identical input ⇒ deterministic result shape.
 */
final class AtlasNativeWorkerCommandPlanRunnerTest extends TestCase
{
    private function envelope(): array
    {
        return [
            'runtime_owner' => 'atlas_native',
            'command_allowlist' => ['version_probe'],
            'gates' => ['phpunit'],
        ];
    }

    public function test_dry_run_returns_would_run_entries_and_invokes_no_process(): void
    {
        $plan = [['name' => 'version_probe', 'argv' => ['/opt/homebrew/bin/php', '-r', 'echo "ok";'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);

        $this->assertTrue($out['dry_run']);
        $this->assertCount(1, $out['results']);
        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_DRY_RUN, $out['results'][0]['status']);
        $this->assertArrayHasKey('would_run', $out['results'][0]);
        $this->assertSame(5, $out['results'][0]['would_run']['timeout_seconds']);
    }

    public function test_allowlisted_harmless_php_command_runs_and_reports_exit_code_zero(): void
    {
        $phpBin = '/opt/homebrew/bin/php';
        if (! is_file($phpBin) || ! is_executable($phpBin)) {
            $this->markTestSkipped('homebrew php missing on this host');
        }
        $plan = [['name' => 'version_probe', 'argv' => [$phpBin, '-r', 'echo "ok";'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: false);

        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_OK, $out['results'][0]['status']);
        $this->assertSame(0, $out['results'][0]['exit_code']);
    }

    public function test_command_not_in_allowlist_is_denied(): void
    {
        $plan = [['name' => 'forbidden_thing', 'argv' => ['/bin/echo', 'hi']]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);
        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_DENIED, $out['results'][0]['status']);
        $this->assertStringContainsString('not_in_envelope_allowlist', $out['results'][0]['reason']);
    }

    public function test_forbidden_label_network_or_external_provider_is_denied_label(): void
    {
        $plan = [['name' => 'version_probe', 'argv' => ['/bin/true'], 'labels' => ['network']]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);
        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_DENIED_LABEL, $out['results'][0]['status']);
        $this->assertStringContainsString('network', $out['results'][0]['reason']);

        $plan2 = [['name' => 'version_probe', 'argv' => ['/bin/true'], 'labels' => ['external_provider']]];
        $out2 = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan2, dryRun: true);
        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_DENIED_LABEL, $out2['results'][0]['status']);
    }

    public function test_secret_like_env_keys_are_redacted_to_three_stars(): void
    {
        $plan = [['name' => 'version_probe', 'argv' => ['/bin/true'], 'env' => ['ATLAS_SECRET' => 'xyz', 'SOME_TOKEN' => 'abc', 'OPENAI_API_KEY' => 'sk-1', 'PUBLIC_VAR' => 'visible']]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);
        $env = $out['results'][0]['would_run']['env_redacted'];
        $this->assertSame('***', $env['ATLAS_SECRET']);
        $this->assertSame('***', $env['SOME_TOKEN']);
        $this->assertSame('***', $env['OPENAI_API_KEY']);
        $this->assertSame('visible', $env['PUBLIC_VAR']);
    }

    public function test_timeout_metadata_is_recorded_on_each_result(): void
    {
        $plan = [['name' => 'version_probe', 'argv' => ['/bin/true'], 'timeout_seconds' => 7]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);
        $this->assertSame(7, $out['results'][0]['would_run']['timeout_seconds']);
    }

    public function test_result_shape_is_deterministic_across_two_dry_runs(): void
    {
        $plan = [['name' => 'version_probe', 'argv' => ['/bin/true'], 'timeout_seconds' => 5]];
        $runner = new AtlasNativeWorkerCommandPlanRunner;
        $a = json_encode($runner->execute($this->envelope(), $plan, true));
        $b = json_encode($runner->execute($this->envelope(), $plan, true));
        $this->assertSame($a, $b);
    }

    public function test_malformed_command_missing_argv_is_denied(): void
    {
        $plan = [['name' => 'version_probe']]; // no argv
        $out = (new AtlasNativeWorkerCommandPlanRunner)->execute($this->envelope(), $plan, dryRun: true);
        $this->assertSame(AtlasNativeWorkerCommandPlanRunner::STATUS_DENIED, $out['results'][0]['status']);
        $this->assertSame('malformed_command', $out['results'][0]['reason']);
    }

    // --- validate() — facts-only plan validator ----------------------

    private function validateEnvelope(): array
    {
        return array_merge($this->envelope(), ['acceptance_commands' => ['run_tests']]);
    }

    public function test_git_commit_command_is_rejected_by_validate(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['git', 'commit', '-m', 'msg'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertFalse($out['passed']);
        $this->assertSame('git_mutation_command', $out['rejections'][0]['reason']);
    }

    public function test_git_push_command_is_rejected_by_validate(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['git', 'push'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertFalse($out['passed']);
        $this->assertSame('git_mutation_command', $out['rejections'][0]['reason']);
    }

    public function test_provider_command_is_rejected_by_validate(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['claude', '--print', 'hello'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertFalse($out['passed']);
        $this->assertSame('provider_command_detected', $out['rejections'][0]['reason']);
    }

    public function test_missing_timeout_is_rejected_by_validate(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test']]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertFalse($out['passed']);
        $this->assertSame('missing_timeout', $out['rejections'][0]['reason']);
    }

    public function test_non_acceptance_command_is_rejected_when_acceptance_list_set(): void
    {
        $plan = [['name' => 'extra_script', 'argv' => ['/bin/echo', 'hi'], 'timeout_seconds' => 5]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertFalse($out['passed']);
        $this->assertSame('not_acceptance_command', $out['rejections'][0]['reason']);
    }

    public function test_php_artisan_test_with_timeout_is_accepted_by_validate(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60]];
        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);
        $this->assertTrue($out['passed']);
        $this->assertSame([], $out['rejections']);
        $this->assertCount(1, $out['accepted']);
    }

    // ── AC: scope safety, timeout safety, dry-run behavior, command family, evidence capture ──

    public function test_safe_plan_reports_command_family_and_evidence_capture_requirement(): void
    {
        $envelope = array_merge($this->validateEnvelope(), ['allowed_command_families' => ['test_runner']]);
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60, 'family' => 'test_runner']];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($envelope, $plan);

        $this->assertTrue($out['passed']);
        $this->assertSame('test_runner', $out['accepted'][0]['command_family']);
        $this->assertTrue($out['accepted'][0]['requires_evidence_capture'], 'run_tests is a declared acceptance command');
    }

    public function test_forbidden_path_outside_allowed_scope_roots_is_rejected(): void
    {
        $envelope = array_merge($this->validateEnvelope(), ['allowed_scope_roots' => ['/repo/atlas-server']]);
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60, 'cwd' => '/etc']];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($envelope, $plan);

        $this->assertFalse($out['passed']);
        $this->assertSame('scope_outside_allowed_roots', $out['rejections'][0]['reason']);
    }

    public function test_cwd_within_allowed_scope_root_is_accepted(): void
    {
        $envelope = array_merge($this->validateEnvelope(), ['allowed_scope_roots' => ['/repo/atlas-server']]);
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60, 'cwd' => '/repo/atlas-server/app']];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($envelope, $plan);

        $this->assertTrue($out['passed']);
    }

    public function test_timeout_breach_exceeding_declared_ceiling_is_rejected(): void
    {
        $envelope = array_merge($this->validateEnvelope(), ['max_timeout_seconds' => 30]);
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 300]];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($envelope, $plan);

        $this->assertFalse($out['passed']);
        $this->assertSame('timeout_exceeds_ceiling', $out['rejections'][0]['reason']);
    }

    public function test_unsafe_command_family_outside_allowlist_is_rejected(): void
    {
        $envelope = array_merge($this->validateEnvelope(), ['allowed_command_families' => ['test_runner']]);
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60, 'family' => 'shell_admin']];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($envelope, $plan);

        $this->assertFalse($out['passed']);
        $this->assertSame('command_family_not_allowed', $out['rejections'][0]['reason']);
    }

    public function test_validate_reports_dry_run_no_execution_result(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 60]];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);

        $this->assertTrue($out['dry_run'], 'validate() never executes — always reports dry_run true');
    }

    public function test_opt_in_fields_absent_from_envelope_are_no_ops(): void
    {
        $plan = [['name' => 'run_tests', 'argv' => ['/opt/homebrew/bin/php', 'artisan', 'test'], 'timeout_seconds' => 300, 'cwd' => '/anywhere']];

        $out = (new AtlasNativeWorkerCommandPlanRunner)->validate($this->validateEnvelope(), $plan);

        $this->assertTrue($out['passed'], 'no allowed_scope_roots/max_timeout_seconds/allowed_command_families declared => new gates are no-ops');
    }
}
