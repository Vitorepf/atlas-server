<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\FairClaudePolicy;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsDryRunService;
use App\Services\Ai\Programming\AtlasForgeNativeRivalsPreflightService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsInvalidBatteryTriageRegistry;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseRubricService;
use App\Services\Ai\Programming\AtlasRivalsRunOrchestrator;
use App\Services\Ai\Programming\RivalsForgeReadinessFingerprintService;
use App\Services\Ai\Programming\RivalsForgeRunLogStreamService;
use App\Services\Ai\Programming\WorkspaceHygieneService;
use Tests\TestCase;

/**
 * End-to-end integration covering slices A → J of the Atlas Forge Rivals
 * Reliability Lockdown v1. Exercises preflight, dry-run, orchestrator (fake
 * provider), evidence pack real-run, verifier, evaluator hard fails,
 * fingerprint reuse, triage registry, replay stream. NEVER calls a real
 * provider.
 */
class AtlasForgeRivalsReliabilityLockdownIntegrationTest extends TestCase
{
    /** @var list<string> */
    private array $createdWorkspaces = [];

    /** @var list<string> */
    private array $createdRunIds = [];

    /** @var list<string> */
    private array $createdFingerprints = [];

    protected function tearDown(): void
    {
        foreach ($this->createdWorkspaces as $w) {
            $this->purge($w);
        }
        $this->createdWorkspaces = [];

        $stream = app(RivalsForgeRunLogStreamService::class);
        foreach ($this->createdRunIds as $runId) {
            $this->purge($stream->runDirectory($runId));
        }
        $this->createdRunIds = [];

        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        foreach ($this->createdFingerprints as $fp) {
            $prefix = substr($fp, 0, 16);
            @unlink($registry->rootDirectory().DIRECTORY_SEPARATOR.$prefix.'.json');
        }
        $this->createdFingerprints = [];
        parent::tearDown();
    }

    public function test_external_rivals_certification_remains_blocked_after_full_lockdown(): void
    {
        // Auditability invariant: nothing in the lockdown unblocks external_rivals_certification.
        $rubric = app(AtlasRivalsOneShotEnterpriseRubricService::class)->rubric();
        $this->assertTrue((bool) $rubric['separated_from_external_rivals_certification']);
        $this->assertFalse((bool) $rubric['promotes_external_rivals_claim']);

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $this->assertTrue((bool) $pack['separated_from_external_rivals_certification']);
        $this->assertFalse((bool) $pack['promotes_external_rivals_claim']);
        $this->assertFalse((bool) $pack['claim_ready']);
    }

    public function test_full_clean_lifecycle_with_fake_provider_emits_canon_events_and_passes(): void
    {
        $atlasWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $baselineWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId('clean');

        $intent = [
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $atlasWorkspace,
            'baseline_workspace' => $baselineWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => 'php -r \'fwrite(STDOUT, "fake-provider-ok"); usleep(150000);\'',
        ];
        $result = app(AtlasRivalsRunOrchestrator::class)->run($intent);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_PASSED, $result['verdict']);
        $this->assertNull($result['score']);
        $this->assertFalse($result['claim_ready']);
        $this->assertFalse($result['external_provider_call']);

        $kinds = $this->eventKinds($runId);
        foreach (['run_started', 'preflight', 'dry_run', 'provider_start', 'provider_done',
            'after_clean_check', 'evidence_pack', 'final_report'] as $expected) {
            $this->assertContains($expected, $kinds, "lifecycle event '$expected' must appear");
        }
    }

    public function test_workspace_dirty_after_fake_provider_returns_invalid_dirty_after_run(): void
    {
        $atlasWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId('dirty');

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $atlasWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => "touch integration_dirty.tmp && echo ok",
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_INVALID_DIRTY_AFTER_RUN, $result['verdict']);
        $this->assertNull($result['score']);
    }

    public function test_stalled_fake_provider_returns_stalled_runner_verdict(): void
    {
        $atlasWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $runId = $this->newRunId('stalled');

        $result = app(AtlasRivalsRunOrchestrator::class)->run([
            'run_id' => $runId,
            'mode' => AtlasRivalsRunOrchestrator::MODE_FAKE_PROVIDER,
            'atlas_workspace' => $atlasWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'fake_provider_command' => "php -r 'sleep(4);'",
            'fake_provider_timeout_seconds' => 6,
            'stall_budget_seconds' => 1,
        ]);

        $this->assertSame(AtlasRivalsRunOrchestrator::VERDICT_STALLED_RUNNER, $result['verdict']);
        $this->assertNull($result['score']);
    }

    public function test_evaluator_real_run_with_dirty_after_check_returns_grade_invalid(): void
    {
        $atlasWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $packBase = app(AtlasRivalsEvidencePackService::class)->generateForRealRun([
            'atlas_workspace' => $atlasWorkspace,
            'preset' => 'quick',
            'provider_receipt' => [
                'exit_code' => 0,
                'stdout_hash' => str_repeat('a', 64),
                'stderr_hash' => str_repeat('b', 64),
                'model' => 'sonnet',
                'binary_resolved' => '/opt/claude-cli',
            ],
            'timeline_events' => [['ts' => '2026-05-14T00:00:00Z', 'kind' => 'provider_start']],
            'human_intervention' => ['count' => 0, 'source' => 'orchestrator_runtime'],
            'final_gates' => ['release_gate_status' => 'passed'],
            'workspace_before' => ['status_hash' => str_repeat('1', 64), 'head_sha' => 'abcd', 'is_git' => true],
            'workspace_after' => [
                'ran' => true,
                'clean' => false,
                'hash_before' => str_repeat('1', 64),
                'hash_after' => str_repeat('2', 64),
                'dirty_files' => ['integration_dirty.tmp'],
                'dirty_files_truncated' => false,
                'head_changed' => false,
            ],
        ]);

        $evidenceInput = app(AtlasRivalsEvidencePackService::class)->toEvaluationEvidenceInput($packBase);
        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => [
                'schema_version' => 'atlas.programming.forge_native_rivals_replay_manifest.v1',
                'valid' => true,
                'state' => 'executed',
                'atlas_arm' => ['runtime' => 'forge'],
                'rival_arm' => ['runtime' => 'claude_code_baseline'],
                'acceptance_gates' => ['forge_runtime_certified'],
            ],
            'case_manifest' => ['case' => ['objective' => 'real-run integration']],
            'evidence_pack' => $evidenceInput,
            'evaluation_mode' => 'real_run_evaluation',
        ]);

        $this->assertSame(AtlasRivalsOneShotEnterpriseEvaluationService::GRADE_INVALID, $report['grade']);
        $this->assertContains('dirty_workspace_after_run', $report['hard_fails']);
        $this->assertNull($report['diagnostic_score']);
    }

    public function test_triage_registry_distinguishes_opus_and_sonnet_quick_fingerprints(): void
    {
        $opus = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'opus']);
        $sonnet = $this->fingerprintFor(['preset' => 'quick', 'atlas_model' => 'sonnet']);
        $this->assertNotSame($opus, $sonnet);

        $registry = app(AtlasRivalsInvalidBatteryTriageRegistry::class);
        $registry->recordInvalidBattery($opus, ['suite' => 'atlas-fair-claude-v1']);
        $registry->triage($opus, 'historical opus invalid quarantined');

        $registry->recordInvalidBattery($sonnet);

        $this->assertFalse($registry->requiresTriage($opus));
        $this->assertTrue($registry->requiresTriage($sonnet));
    }

    public function test_sonnet_model_lock_propagates_through_fingerprint(): void
    {
        $atlasWorkspace = $this->makeCleanGitWorkspaceWithDocs();
        $packet = app(AtlasForgeNativeRivalsPreflightService::class)->preflight([
            'workspace' => $atlasWorkspace,
            'preset' => 'quick',
            'atlas_model' => 'sonnet',
            'baseline_model' => 'sonnet',
            'intends_provider_battery' => false,
        ]);

        $this->assertSame('sonnet', data_get($packet, 'readiness_fingerprint.components.atlas_model'));
        $this->assertSame('sonnet', data_get($packet, 'readiness_fingerprint.components.baseline_model'));
        $this->assertContains('sonnet', FairClaudePolicy::MODEL_LOCK_ALLOWLIST);
    }

    public function test_evidence_pack_default_diagnostic_local_does_not_dispatch_provider(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $this->makeCleanGitWorkspaceWithDocs(),
        ]);
        $this->assertFalse((bool) $pack['external_provider_call']);
        $this->assertFalse((bool) $pack['provider_dispatched']);
        $this->assertFalse((bool) $pack['provider_tokens_spent']);
    }

    public function test_hygiene_service_disables_pythondontwritebytecode_for_python_runs(): void
    {
        $env = app(WorkspaceHygieneService::class)->forceBytecodeDisabledEnv();
        $this->assertSame('1', $env['PYTHONDONTWRITEBYTECODE']);
        $this->assertNotEmpty($env['PYTHONPYCACHEPREFIX']);
        $this->assertStringContainsString('atlas-rivals-pycache', $env['PYTHONPYCACHEPREFIX']);
    }

    private function fingerprintFor(array $intent): string
    {
        $fingerprint = app(RivalsForgeReadinessFingerprintService::class)->compute($intent);
        $value = (string) $fingerprint['value'];
        $this->createdFingerprints[] = $value;

        return $value;
    }

    private function eventKinds(string $runId): array
    {
        return array_map(
            static fn (array $e): string => (string) ($e['kind'] ?? ''),
            app(RivalsForgeRunLogStreamService::class)->tail($runId),
        );
    }

    private function newRunId(string $suffix): string
    {
        $id = 'rivals_orchestrator_test_lockdown_'.bin2hex(random_bytes(6)).'-'.$suffix;
        $this->createdRunIds[] = $id;

        return $id;
    }

    private function makeCleanGitWorkspaceWithDocs(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_lockdown_integration_'.bin2hex(random_bytes(8));
        $this->createdWorkspaces[] = $path;
        @mkdir($path, 0o755, true);
        foreach (['docs/engineering-knowledge-base/atlas-forge-native-rivals-protocol-v1.md',
            'docs/engineering-knowledge-base/atlas-programming-forge-flow.md',
            'docs/engineering-knowledge-base/domains/programming-professional-completion-audit.md'] as $rel) {
            $src = base_path($rel);
            $dst = $path.DIRECTORY_SEPARATOR.$rel;
            @mkdir(dirname($dst), 0o755, true);
            if (is_file($src)) {
                copy($src, $dst);
            }
        }
        $this->runGit($path, ['init', '--quiet']);
        $this->runGit($path, ['config', 'user.email', 'tests@atlas.local']);
        $this->runGit($path, ['config', 'user.name', 'Atlas Tests']);
        $this->runGit($path, ['add', '-A']);
        $this->runGit($path, ['commit', '--quiet', '-m', 'seed']);

        return $path;
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

    private function purge(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.DIRECTORY_SEPARATOR.$item;
            if (is_dir($path)) {
                $this->purge($path);
            } else {
                @unlink($path);
            }
        }
        @rmdir($dir);
    }
}
