<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasProgrammingRivalsForgeDryRunCommand;
use App\Console\Commands\AtlasProgrammingRivalsForgePreflightCommand;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsProtocolService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Tests\TestCase;

class AtlasForgeNativeRivalsTest extends TestCase
{
    /**
     * Canary used by AtlasRivalsCommand --quick. Must remain fast (no DB,
     * no subprocess, no provider) so the quick preset stays quick.
     */
    public function test_quick_canary_fixture_passes_under_three_seconds(): void
    {
        $started = microtime(true);
        $this->assertTrue(true, 'canary always-true assertion');
        $elapsed = microtime(true) - $started;
        $this->assertLessThan(3.0, $elapsed, 'Quick canary must finish in well under 3 seconds.');
    }

    public function test_case_manifest_publishes_quick_and_full_test_commands(): void
    {
        $manifest = app(AtlasForgeNativeRivalsCaseManifestService::class)->manifest(null);

        $case = (array) ($manifest['case'] ?? []);
        $this->assertIsString($case['quick_test_command'] ?? null);
        $this->assertIsString($case['full_test_command'] ?? null);
        $this->assertNotSame(
            $case['quick_test_command'],
            $case['full_test_command'],
            'Quick must not equal full — otherwise quick is not actually quick.',
        );
        $this->assertStringContainsString('test_quick_canary_fixture_passes_under_three_seconds', $case['quick_test_command']);
    }

    public function test_protocol_declares_atlas_arm_must_be_forge(): void
    {
        $protocol = app(AtlasForgeNativeRivalsProtocolService::class)->protocol();

        $this->assertSame('atlas.programming.forge_native_rivals_protocol.v1', $protocol['schema_version']);
        $this->assertSame('forge', $protocol['atlas_arm']['runtime']);
        $this->assertTrue($protocol['atlas_arm']['atlas_side_must_use_forge']);
        $this->assertTrue($protocol['atlas_arm']['invalid_if_not_forge']);
        $this->assertContains('atlas:code:forge-fast-path', $protocol['atlas_arm']['required_commands']);
        $this->assertContains('atlas:code:forge-fast-path-status', $protocol['atlas_arm']['required_commands']);
        $this->assertContains('atlas:code:forge-review', $protocol['atlas_arm']['required_commands']);
        $this->assertFalse($protocol['invariants']['synthetic_scores_allowed']);
        $this->assertTrue($protocol['invariants']['atlas_side_must_use_forge']);
        $this->assertTrue($protocol['invariants']['clean_workspace_required']);
        $this->assertTrue($protocol['invariants']['replay_manifest_required']);
        $this->assertTrue($protocol['invariants']['provider_cost_approval_required']);
        $this->assertContains('atlas_not_forge', $protocol['invalid_if']);
        $this->assertContains('dirty_workspace', $protocol['invalid_if']);
        $this->assertContains('synthetic_score_admitted', $protocol['invalid_if']);
        $this->assertFalse($protocol['safety']['protocol_call_dispatches_provider']);
        $this->assertFalse($protocol['safety']['protocol_call_spends_tokens']);
    }

    public function test_case_manifest_marks_atlas_arm_as_forge_and_valid(): void
    {
        $manifest = app(AtlasForgeNativeRivalsCaseManifestService::class)->manifest(null);

        $this->assertSame('atlas.programming.forge_native_rivals_case_manifest.v1', $manifest['schema_version']);
        $this->assertTrue($manifest['valid']);
        $this->assertSame([], $manifest['invalid_reasons']);
        $this->assertSame('forge', $manifest['case']['atlas_arm']['runtime']);
        $this->assertTrue($manifest['case']['atlas_arm']['atlas_side_must_use_forge']);
        $this->assertStringContainsString('atlas:code:forge-fast-path', (string) $manifest['case']['atlas_arm']['command_template']);
        $this->assertTrue($manifest['case']['replay_requirements']['replay_manifest_required']);
        $this->assertTrue($manifest['atlas_side_must_use_forge']);
        $this->assertFalse($manifest['external_provider_call']);
    }

    public function test_case_manifest_returns_invalid_for_unknown_case(): void
    {
        $manifest = app(AtlasForgeNativeRivalsCaseManifestService::class)->manifest('does-not-exist');

        $this->assertFalse($manifest['valid']);
        $this->assertContains('case_not_found', $manifest['invalid_reasons']);
    }

    public function test_preflight_blocks_dirty_workspace(): void
    {
        $dirty = $this->makeDirtyNonGitWorkspaceWithDocs();
        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $dirty,
            'intends_provider_battery' => false,
        ]);

        $this->assertSame('atlas.programming.forge_native_rivals_preflight.v1', $packet['schema_version']);
        $this->assertSame('blocked_dirty_workspace', $packet['status']);
        $this->assertFalse($packet['ready_for_dry_run']);
        $this->assertFalse($packet['ready_for_provider_battery']);
        $this->assertContains('workspace_dirty_or_not_git', $packet['blocking_reasons']);
        $this->assertFalse($packet['external_provider_call']);
    }

    public function test_preflight_blocks_provider_dispatch_without_approval(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());
        $separateBaseline = $this->makeCleanGitWorkspace();

        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $cleanGitWorkspace,
            'baseline_workspace' => $separateBaseline,
            'intends_provider_battery' => true,
            'provider_cost_approved' => false,
            'runbook_reviewed' => false,
        ]);

        $this->assertSame('blocked_requires_operator_approval', $packet['status']);
        $this->assertFalse($packet['ready_for_provider_battery']);
        $this->assertContains('provider_cost_not_approved', $packet['blocking_reasons']);
        $this->assertFalse($packet['external_provider_call']);
    }

    public function test_preflight_blocks_tracked_python_bytecode(): void
    {
        $workspace = $this->makeCleanGitWorkspaceWithTrackedPythonBytecode();

        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $workspace,
            'intends_provider_battery' => false,
        ]);

        $this->assertSame('blocked_tracked_python_bytecode', $packet['status']);
        $this->assertFalse($packet['ready_for_dry_run']);
        $this->assertFalse($packet['ready_for_provider_battery']);
        $this->assertContains('tracked_python_bytecode_in_workspace', $packet['blocking_reasons']);
        $this->assertFalse($packet['external_provider_call']);

        $workspaceCheck = $packet['checks']['workspace'] ?? [];
        $this->assertTrue((bool) ($workspaceCheck['tracked_python_bytecode_present'] ?? false));
        $this->assertGreaterThanOrEqual(
            1,
            (int) data_get($workspaceCheck, 'tracked_python_bytecode.tracked_count', 0),
        );
        $sample = (array) data_get($workspaceCheck, 'tracked_python_bytecode.tracked_sample', []);
        $this->assertNotSame([], $sample);
        $resolution = (string) data_get($workspaceCheck, 'tracked_python_bytecode.resolution_command', '');
        $this->assertNotSame('', $resolution);
        $this->assertStringContainsString('git', $resolution);
        $this->assertStringContainsString('rm --cached', $resolution);
        $this->assertStringContainsString('pyc', $resolution);
        $this->assertStringContainsString('tracked_python_bytecode', (string) $packet['next_action']);
    }

    public function test_preflight_passes_for_dry_run_when_workspace_and_artifacts_clean(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());

        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $cleanGitWorkspace,
            'intends_provider_battery' => false,
        ]);

        $this->assertSame('ready_for_dry_run', $packet['status']);
        $this->assertTrue($packet['ready_for_dry_run']);
        $this->assertFalse($packet['external_provider_call']);
        $this->assertTrue((bool) data_get($packet, 'checks.case_manifest.atlas_arm_is_forge'));
        $this->assertSame([], $packet['blocking_reasons']);
    }

    public function test_preflight_exposes_readiness_fingerprint(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());

        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $cleanGitWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'gate_profile' => 'strict',
            'intends_provider_battery' => false,
        ]);

        $fp = (array) ($packet['readiness_fingerprint'] ?? []);
        $this->assertSame(
            'atlas.programming.rivals_forge_readiness_fingerprint.v1',
            $fp['schema_version'] ?? null,
        );
        $this->assertSame(64, strlen((string) ($fp['value'] ?? '')));
        $this->assertSame('quick', data_get($fp, 'components.preset'));
        $this->assertSame('sonnet', data_get($fp, 'components.atlas_model'));
        $this->assertSame('sonnet', data_get($fp, 'components.baseline_model'));
    }

    public function test_dry_run_fingerprint_matches_preflight_for_same_intent(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());

        $intent = [
            'workspace' => $cleanGitWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'gate_profile' => 'strict',
        ];

        $preflight = app(AtlasForgeNativeRivalsPreflightService::class)->preflight($intent + [
            'intends_provider_battery' => false,
        ]);
        $dryRun = app(AtlasForgeNativeRivalsDryRunService::class)->dryRun($intent);

        $this->assertSame(
            data_get($preflight, 'readiness_fingerprint.value'),
            data_get($dryRun, 'readiness_fingerprint.value'),
        );
    }

    public function test_changing_model_between_preflight_invocations_breaks_fingerprint(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());

        $opus = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $cleanGitWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'opus',
            'baseline_model' => 'opus',
            'intends_provider_battery' => false,
        ]);
        $sonnet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $cleanGitWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'intends_provider_battery' => false,
        ]);

        $this->assertNotSame(
            data_get($opus, 'readiness_fingerprint.value'),
            data_get($sonnet, 'readiness_fingerprint.value'),
        );
    }

    public function test_dry_run_does_not_dispatch_provider_and_passes_when_workspace_clean(): void
    {
        $cleanGitWorkspace = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());

        $report = app(AtlasForgeNativeRivalsDryRunService::class)->dryRun([
            'workspace' => $cleanGitWorkspace,
        ]);

        $this->assertSame('atlas.programming.forge_native_rivals_dry_run.v1', $report['schema_version']);
        $this->assertSame('dry_run_passed', $report['status']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertFalse($report['provider_dispatched']);
        $this->assertFalse($report['provider_tokens_spent']);
        $this->assertTrue($report['atlas_side_must_use_forge']);
        $this->assertFalse($report['synthetic_scores_allowed']);
        $this->assertSame(
            'atlas.programming.forge_native_rivals_replay_manifest.v1',
            data_get($report, 'planned.replay_manifest.schema_version'),
        );
        $this->assertTrue((bool) data_get($report, 'planned.replay_manifest.valid'));
        $this->assertSame(
            data_get($report, 'planned.replay_manifest'),
            data_get($report, 'replay_manifest'),
        );
        $this->assertTrue((bool) data_get($report, 'replay_manifest.valid'));
        $this->assertSame([], $report['blocking_reasons']);
    }

    public function test_dry_run_blocks_when_workspace_is_dirty(): void
    {
        $dirty = $this->makeDirtyNonGitWorkspace();

        $report = app(AtlasForgeNativeRivalsDryRunService::class)->dryRun([
            'workspace' => $dirty,
        ]);

        $this->assertSame('dry_run_blocked', $report['status']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertContains('canonical_docs_missing', $report['blocking_reasons']);
    }

    public function test_completion_audit_exposes_forge_native_rivals_certification_separately(): void
    {
        /** @var ProgrammingProfessionalCompletionAuditService $audit */
        $audit = app(ProgrammingProfessionalCompletionAuditService::class);
        $report = $audit->report(base_path(), refreshLocalBenchmarks: false);

        $this->assertArrayHasKey('forge_native_rivals_certification', $report);
        $this->assertArrayHasKey('external_rivals_certification', $report);
        $this->assertNotSame($report['forge_native_rivals_certification'], $report['external_rivals_certification']);

        $forge = $report['forge_native_rivals_certification'];
        $this->assertSame('atlas.programming.forge_native_rivals_certification.v1', $forge['schema_version']);
        $this->assertTrue($forge['atlas_side_must_use_forge']);
        $this->assertTrue($forge['protocol_available']);
        $this->assertTrue($forge['protocol_doc_available']);
        $this->assertTrue($forge['preflight_command_available']);
        $this->assertTrue($forge['dry_run_command_available']);
        $this->assertTrue($forge['case_manifest_available']);
        $this->assertTrue($forge['case_manifest_atlas_arm_is_forge']);
        $this->assertTrue($forge['provider_dispatch_blocked_without_approval']);
        $this->assertFalse($forge['synthetic_scores_allowed']);
        $this->assertTrue($forge['separated_from_external_rivals_certification']);
        $this->assertFalse($forge['promotes_completion_claim']);

        $external = $report['external_rivals_certification'];
        $this->assertSame('atlas.programming.rivals_readiness.v1', $external['schema_version']);
        $this->assertNotSame('passed', $external['status']);
    }

    public function test_external_rivals_certification_remains_blocked_even_with_forge_native_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);

        $external = $report['external_rivals_certification'];
        $this->assertContains(
            $external['status'],
            ['blocked', 'blocked_requires_operator_approval'],
            'external_rivals_certification must remain blocked until a real comparable battery is approved.',
        );
        $this->assertFalse((bool) ($external['claim_ready'] ?? true));
    }

    public function test_preflight_command_registered_and_returns_json(): void
    {
        $this->assertTrue(class_exists(AtlasProgrammingRivalsForgePreflightCommand::class));

        $exitCode = $this->artisan('atlas:programming:rivals-forge-preflight', ['--json' => true])
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_dry_run_command_registered_and_returns_json(): void
    {
        $this->assertTrue(class_exists(AtlasProgrammingRivalsForgeDryRunCommand::class));

        $exitCode = $this->artisan('atlas:programming:rivals-forge-dry-run', ['--json' => true])
            ->run();

        $this->assertSame(0, $exitCode);
    }

    public function test_strict_preflight_fails_when_workspace_is_not_git(): void
    {
        $dirty = $this->makeDirtyNonGitWorkspace();

        $exitCode = $this->artisan('atlas:programming:rivals-forge-preflight', [
            '--workspace' => $dirty,
            '--json' => true,
            '--strict' => true,
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    private function makeDirtyNonGitWorkspace(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_forge_native_rivals_'.bin2hex(random_bytes(8));
        @mkdir($path, 0o755, true);
        file_put_contents($path.'/note.txt', 'not a git workspace');

        return $path;
    }

    private function makeDirtyNonGitWorkspaceWithDocs(): string
    {
        $path = $this->makeDirtyNonGitWorkspace();
        $seed = $this->copyCanonicalDocs();
        $this->mirrorDirectory($seed, $path);

        return $path;
    }

    private function makeCleanGitWorkspace(?string $seedPath = null): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_forge_native_rivals_clean_'.bin2hex(random_bytes(8));
        @mkdir($path, 0o755, true);

        if ($seedPath !== null && is_dir($seedPath)) {
            $this->mirrorDirectory($seedPath, $path);
        }

        $this->runGit($path, ['init', '--quiet']);
        $this->runGit($path, ['config', 'user.email', 'tests@atlas.local']);
        $this->runGit($path, ['config', 'user.name', 'Atlas Tests']);
        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'seed']);

        return $path;
    }

    private function makeCleanGitWorkspaceWithTrackedPythonBytecode(): string
    {
        $path = $this->makeCleanGitWorkspace($this->copyCanonicalDocs());
        $pycDir = $path.'/runtimes/python/atlas_module/__pycache__';
        @mkdir($pycDir, 0o755, true);
        file_put_contents($pycDir.'/module.cpython-312.pyc', "tracked-bytecode-fixture\n");

        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'seed tracked python bytecode']);

        return $path;
    }

    private function copyCanonicalDocs(): string
    {
        $stage = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_forge_native_rivals_docs_'.bin2hex(random_bytes(8));
        @mkdir($stage.'/docs/engineering-knowledge-base/domains', 0o755, true);

        $sources = [
            'docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md',
        ];

        foreach ($sources as $rel) {
            $src = base_path($rel);
            $dst = $stage.DIRECTORY_SEPARATOR.$rel;
            @mkdir(dirname($dst), 0o755, true);
            if (is_file($src)) {
                copy($src, $dst);
            }
        }

        return $stage;
    }

    private function mirrorDirectory(string $source, string $destination): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($source, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            $target = $destination.DIRECTORY_SEPARATOR.$iterator->getSubPathname();
            if ($item->isDir()) {
                @mkdir($target, 0o755, true);
            } else {
                @mkdir(dirname($target), 0o755, true);
                copy($item->getPathname(), $target);
            }
        }
    }

    /**
     * @param  array<int,string>  $args
     */
    private function runGit(string $cwd, array $args): void
    {
        $process = new \Symfony\Component\Process\Process(array_merge(['git'], $args), $cwd);
        $process->setTimeout(10);
        $process->run();
    }
}
