<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Console\Commands\AtlasProgrammingRivalsEvidencePackCommand;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackService;
use App\Services\Ai\Programming\AtlasRivalsEvidencePackVerifierService;
use App\Services\Ai\Programming\AtlasRivalsOneShotEnterpriseEvaluationService;
use App\Services\Ai\Programming\ProgrammingProfessionalCompletionAuditService;
use Tests\TestCase;

class AtlasRivalsEvidencePackTest extends TestCase
{
    public function test_evidence_pack_command_returns_json_and_no_provider_call(): void
    {
        $this->assertTrue(class_exists(AtlasProgrammingRivalsEvidencePackCommand::class));

        $exitCode = $this->artisan('atlas:programming:rivals-evidence-pack', ['--json' => true])
            ->run();

        $this->assertSame(0, $exitCode);

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $this->assertFalse($pack['external_provider_call']);
        $this->assertFalse($pack['provider_dispatched']);
        $this->assertFalse($pack['provider_tokens_spent']);
        $this->assertFalse($pack['claim_ready']);
        $this->assertFalse($pack['promotes_external_rivals_claim']);
        $this->assertFalse($pack['synthetic_scores_allowed']);
    }

    public function test_evidence_pack_reports_missing_evidence_when_not_running_tests_or_quality(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertContains('missing_test_run_log', $pack['missing_evidence']);
        $this->assertContains('missing_quality_scan_log', $pack['missing_evidence']);
        $this->assertFalse((bool) data_get($pack, 'tests.present'));
        $this->assertFalse((bool) data_get($pack, 'quality_scan.present'));
        $this->assertSame('not_run', data_get($pack, 'tests.source'));
        $this->assertSame('not_run', data_get($pack, 'quality_scan.source'));
    }

    public function test_strict_command_fails_when_required_evidence_missing(): void
    {
        $exitCode = $this->artisan('atlas:programming:rivals-evidence-pack', [
            '--json' => true,
            '--strict' => true,
        ])->run();

        $this->assertSame(1, $exitCode);
    }

    public function test_evidence_pack_includes_replay_manifest_hash(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'replay_manifest.present'));
        $hash = (string) data_get($pack, 'replay_manifest.hash', '');
        $this->assertSame(64, strlen($hash));
        $this->assertSame(AtlasRivalsEvidencePackService::REPLAY_MANIFEST_SCHEMA, data_get($pack, 'replay_manifest.schema_version'));
    }

    public function test_evidence_pack_includes_business_rule_from_case_manifest(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'business_rule.present'));
        $this->assertSame('case_manifest', data_get($pack, 'business_rule.source'));
        $hash = (string) data_get($pack, 'business_rule.hash', '');
        $this->assertSame(64, strlen($hash));
        $this->assertNotEmpty(data_get($pack, 'business_rule.objective'));
    }

    public function test_evidence_pack_detects_git_dirty_state_honestly(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $workspace = (array) ($pack['workspace'] ?? []);

        if (($workspace['is_git'] ?? false) === true) {
            // workspace is git: clean/dirty must be coherent
            $dirty = (int) ($workspace['dirty_count'] ?? 0);
            $this->assertSame($dirty === 0, (bool) ($workspace['clean'] ?? false));
        } else {
            // workspace not git: never marked clean
            $this->assertFalse((bool) ($workspace['clean'] ?? false));
        }
    }

    public function test_verifier_passes_honest_pack(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame(AtlasRivalsEvidencePackVerifierService::SCHEMA_VERSION, $verification['schema_version']);
        $this->assertSame('passed', $verification['status']);
        $this->assertSame([], $verification['blockers']);
        $this->assertTrue($verification['verifier_blocks_fake_evidence']);
        $this->assertTrue($verification['no_provider_call']);
    }

    public function test_verifier_blocks_fake_evidence_present_true_without_hash_or_source(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['tests'] = [
            'present' => true,
            'source' => null,
            'log_hash' => null,
            'exit_code' => 0,
            'passed' => true,
        ];
        $pack['command_exit_codes']['test_command'] = 0;
        if (isset($pack['missing_evidence']) && is_array($pack['missing_evidence'])) {
            $pack['missing_evidence'] = array_values(array_filter($pack['missing_evidence'], static fn (string $m): bool => $m !== 'missing_test_run_log'));
        }

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('tests_present_but_source_missing', $verification['blockers']);
        $this->assertContains('tests_present_but_hash_missing', $verification['blockers']);
    }

    public function test_verifier_blocks_provider_call_flag_true(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['external_provider_call'] = true;

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('provider_call_flag_not_false', $verification['blockers']);
    }

    public function test_verifier_blocks_workspace_clean_inconsistency(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);
        $pack['workspace']['clean'] = true;
        $pack['workspace']['dirty_count'] = 5;

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify($pack);

        $this->assertSame('blocked', $verification['status']);
        $this->assertContains('workspace_dirty_count_inconsistent_with_clean_flag', $verification['blockers']);
    }

    public function test_evaluator_with_evidence_pack_changes_dimension_inputs_without_promoting_claim(): void
    {
        $service = app(AtlasRivalsEvidencePackService::class);
        $pack = $service->generate([]);
        $evidenceInput = $service->toEvaluationEvidenceInput($pack);

        $report = app(AtlasRivalsOneShotEnterpriseEvaluationService::class)->evaluate([
            'replay_manifest' => [
                'schema_version' => AtlasRivalsEvidencePackService::REPLAY_MANIFEST_SCHEMA,
                'valid' => true,
                'atlas_arm' => ['runtime' => 'forge'],
                'rival_arm' => ['runtime' => 'claude_code_baseline'],
                'acceptance_gates' => ['forge_runtime_certified'],
            ],
            'case_manifest' => ['case' => ['objective' => 'test objective']],
            'evidence_pack' => $evidenceInput,
            'evaluation_mode' => 'local_manifest_evaluation',
        ]);

        $this->assertFalse($report['promotes_external_rivals_claim']);
        $this->assertFalse($report['claim_ready']);
        $this->assertFalse($report['external_provider_call']);
        $this->assertSame(100, (int) $report['max_score']);
    }

    public function test_completion_audit_exposes_rivals_evidence_pack_certification(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);

        $this->assertArrayHasKey('rivals_evidence_pack_certification', $report);
        $cert = $report['rivals_evidence_pack_certification'];

        $this->assertSame('atlas.programming.rivals_evidence_pack_certification.v1', $cert['schema_version']);
        $this->assertTrue($cert['evidence_pack_service_available']);
        $this->assertTrue($cert['verifier_service_available']);
        $this->assertTrue($cert['command_available']);
        $this->assertTrue($cert['schema_available']);
        $this->assertTrue($cert['doc_available']);
        $this->assertTrue($cert['integrates_with_one_shot_evaluation']);
        $this->assertTrue($cert['replay_manifest_hash_available']);
        $this->assertTrue($cert['patch_diff_supported']);
        $this->assertTrue($cert['test_log_supported']);
        $this->assertTrue($cert['quality_log_supported']);
        $this->assertTrue($cert['missing_evidence_reported']);
        $this->assertTrue($cert['verifier_blocks_fake_evidence']);
        $this->assertTrue($cert['no_provider_call']);
        $this->assertFalse($cert['promotes_external_rivals_claim']);
        $this->assertTrue($cert['separated_from_external_rivals_certification']);
        $this->assertFalse($cert['synthetic_scores_allowed']);
    }

    public function test_external_rivals_remains_blocked(): void
    {
        $report = app(ProgrammingProfessionalCompletionAuditService::class)->report(base_path(), false);
        $external = $report['external_rivals_certification'];

        $this->assertContains(
            $external['status'],
            ['blocked', 'blocked_requires_operator_approval'],
            'external_rivals_certification must never be unlocked by Evidence Pack certification.',
        );
        $this->assertFalse((bool) ($external['claim_ready'] ?? true));
    }

    public function test_no_synthetic_score_admitted_as_claim(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertFalse($pack['synthetic_scores_allowed']);
        $this->assertFalse($pack['claim_ready']);
        $this->assertFalse($pack['promotes_external_rivals_claim']);
    }

    public function test_quick_preset_uses_case_quick_test_command_by_default(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'preset' => 'quick',
            'run_tests' => false,
        ]);

        $expected = \App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService::DEFAULT_QUICK_TEST_COMMAND;
        $this->assertSame($expected, data_get($pack, 'tests.command'));
        $this->assertSame('preset_default', data_get($pack, 'tests.command_origin'));
    }

    public function test_full_preset_uses_case_full_test_command_by_default(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'preset' => 'full',
            'run_tests' => false,
        ]);

        $expected = \App\Services\Ai\Programming\AtlasForgeNativeRivalsCaseManifestService::DEFAULT_FULL_TEST_COMMAND;
        $this->assertSame($expected, data_get($pack, 'tests.command'));
        $this->assertSame('preset_default', data_get($pack, 'tests.command_origin'));
    }

    public function test_operator_explicit_test_command_overrides_preset_and_is_recorded(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'preset' => 'quick',
            'run_tests' => false,
            'test_command' => "php -r 'echo \"custom\";'",
        ]);

        $this->assertSame("php -r 'echo \"custom\";'", data_get($pack, 'tests.command'));
        $this->assertSame('operator_explicit', data_get($pack, 'tests.command_origin'));
    }

    public function test_evidence_pack_records_after_clean_check_when_tests_run(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'run_tests' => true,
            'test_command' => "php -r 'echo \"ok\";'",
        ]);

        $afterCleanCheck = (array) data_get($pack, 'workspace.after_clean_check', []);
        $this->assertTrue((bool) ($afterCleanCheck['ran'] ?? false));
        $this->assertTrue((bool) ($afterCleanCheck['clean'] ?? false));
        $this->assertNotSame('', (string) ($afterCleanCheck['hash_before'] ?? ''));
        $this->assertSame($afterCleanCheck['hash_before'], $afterCleanCheck['hash_after']);
        $this->assertSame([], (array) ($afterCleanCheck['dirty_files'] ?? []));
        $this->assertFalse((bool) ($afterCleanCheck['head_changed'] ?? false));
    }

    public function test_evidence_pack_marks_dirty_when_command_writes_file(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'run_tests' => true,
            'test_command' => "touch atlas_dirty_after_run.tmp && echo 'mutated'",
        ]);

        $afterCleanCheck = (array) data_get($pack, 'workspace.after_clean_check', []);
        $this->assertTrue((bool) ($afterCleanCheck['ran'] ?? false));
        $this->assertFalse((bool) ($afterCleanCheck['clean'] ?? true));
        $this->assertNotSame($afterCleanCheck['hash_before'] ?? null, $afterCleanCheck['hash_after'] ?? null);

        $dirtyFiles = collect((array) ($afterCleanCheck['dirty_files'] ?? []))
            ->map(static fn (mixed $value): string => (string) $value);
        $this->assertTrue(
            $dirtyFiles->contains(fn (string $f): bool => str_contains($f, 'atlas_dirty_after_run.tmp')),
            'after_clean_check.dirty_files must list the file the subprocess wrote',
        );
    }

    public function test_evidence_pack_skips_after_clean_check_when_no_subprocess_invoked(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
        ]);

        $afterCleanCheck = (array) data_get($pack, 'workspace.after_clean_check', []);
        $this->assertFalse((bool) ($afterCleanCheck['ran'] ?? true));
        $this->assertArrayHasKey('clean', $afterCleanCheck);
        $this->assertNull($afterCleanCheck['clean']);
        $this->assertSame('no_subprocess_invoked_by_evidence_pack', $afterCleanCheck['reason_not_run'] ?? null);
    }

    public function test_evidence_pack_forces_pythondontwritebytecode_env(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generate([
            'workspace' => $workspace,
            'run_tests' => true,
            'test_command' => 'php -r \'echo "PYTHONDONTWRITEBYTECODE=" . getenv("PYTHONDONTWRITEBYTECODE");\'',
        ]);

        $log = (string) data_get($pack, 'tests.log_excerpt', '');
        $this->assertStringContainsString('PYTHONDONTWRITEBYTECODE=1', $log);
    }

    public function test_present_true_carries_source_and_hash_for_business_rule_and_replay_manifest(): void
    {
        $pack = app(AtlasRivalsEvidencePackService::class)->generate([]);

        $this->assertTrue((bool) data_get($pack, 'business_rule.present'));
        $this->assertNotEmpty(data_get($pack, 'business_rule.source'));
        $this->assertSame(64, strlen((string) data_get($pack, 'business_rule.hash', '')));

        $this->assertTrue((bool) data_get($pack, 'replay_manifest.present'));
        $this->assertNotEmpty(data_get($pack, 'replay_manifest.source'));
        $this->assertSame(64, strlen((string) data_get($pack, 'replay_manifest.hash', '')));
    }

    public function test_real_run_pack_promotes_replay_manifest_to_executed_state(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();

        $pack = app(AtlasRivalsEvidencePackService::class)->generateForRealRun([
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'provider_receipt' => [
                'exit_code' => 0,
                'stdout_hash' => str_repeat('a', 64),
                'model' => 'sonnet',
                'binary_resolved' => '/opt/claude-cli',
            ],
            'timeline_events' => [
                ['ts' => '2026-05-14T00:00:00Z', 'kind' => 'provider_start', 'monotonic_ms_since_start' => 0],
                ['ts' => '2026-05-14T00:00:30Z', 'kind' => 'provider_done', 'monotonic_ms_since_start' => 30000],
            ],
            'human_intervention' => ['count' => 0, 'source' => 'orchestrator_runtime', 'entries' => []],
            'final_gates' => ['release_gate_status' => 'passed', 'gates' => ['forge_runtime_certified' => 'passed']],
        ]);

        $this->assertSame('executed', data_get($pack, 'replay_manifest.state'));
        $this->assertNotNull(data_get($pack, 'replay_manifest.executed_at'));
        $this->assertSame(str_repeat('a', 64), data_get($pack, 'replay_manifest.provider_receipt_hash'));
        $this->assertSame('real_run_evaluation', $pack['evaluation_mode']);
    }

    public function test_verifier_real_run_mode_blocks_when_provider_receipt_missing(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();
        $pack = app(AtlasRivalsEvidencePackService::class)->generateForRealRun([
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            // No provider_receipt provided.
            'timeline_events' => [['ts' => '2026-05-14T00:00:00Z', 'kind' => 'provider_start', 'monotonic_ms_since_start' => 0]],
            'human_intervention' => ['count' => 0, 'source' => 'orchestrator_runtime'],
            'final_gates' => ['release_gate_status' => 'passed'],
        ]);

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify(
            $pack,
            AtlasRivalsEvidencePackVerifierService::MODE_REAL_RUN,
        );

        $this->assertSame('invalid_missing_evidence', $verification['status']);
        $this->assertContains('invalid_missing_evidence_for_real_run', $verification['blockers']);
        $missing = (array) ($verification['missing_real_run_fields'] ?? []);
        $this->assertContains('provider_receipt.exit_code', $missing);
        $this->assertContains('provider_receipt.stdout_hash', $missing);
        $this->assertContains('provider_receipt.model', $missing);
        $this->assertContains('provider_receipt.binary_resolved', $missing);
    }

    public function test_verifier_real_run_blocks_when_after_clean_check_dirty(): void
    {
        $workspace = $this->makeCleanGitWorkspaceForEvidence();
        $pack = app(AtlasRivalsEvidencePackService::class)->generateForRealRun([
            'atlas_workspace' => $workspace,
            'preset' => 'quick',
            'provider_receipt' => [
                'exit_code' => 0,
                'stdout_hash' => str_repeat('b', 64),
                'model' => 'sonnet',
                'binary_resolved' => '/opt/claude-cli',
            ],
            'timeline_events' => [['ts' => '2026-05-14T00:00:00Z', 'kind' => 'provider_start']],
            'human_intervention' => ['count' => 0, 'source' => 'orchestrator_runtime'],
            'final_gates' => ['release_gate_status' => 'passed'],
            'workspace_before' => ['status_hash' => str_repeat('1', 64), 'head_sha' => 'abcd1234', 'is_git' => true],
            'workspace_after' => [
                'ran' => true,
                'clean' => false,
                'hash_before' => str_repeat('1', 64),
                'hash_after' => str_repeat('2', 64),
                'dirty_files' => ['some_file.tmp'],
                'dirty_files_truncated' => false,
                'head_changed' => false,
            ],
        ]);

        $verification = app(AtlasRivalsEvidencePackVerifierService::class)->verify(
            $pack,
            AtlasRivalsEvidencePackVerifierService::MODE_REAL_RUN,
        );

        $this->assertContains('dirty_workspace_after_run', $verification['blockers']);
    }

    public function test_real_run_required_fields_list_covers_user_spec_evidence(): void
    {
        $required = AtlasRivalsEvidencePackVerifierService::REQUIRED_FIELDS_FOR_REAL_RUN;
        foreach (['workspace.before_status_hash', 'workspace.after_clean_check.ran', 'workspace.after_clean_check.clean',
            'replay_manifest.state', 'provider_receipt.exit_code', 'provider_receipt.stdout_hash',
            'provider_receipt.model', 'provider_receipt.binary_resolved', 'timeline_events',
            'human_intervention.count', 'human_intervention.source', 'final_gates'] as $field) {
            $this->assertContains($field, $required, "Required real-run field '$field' must be declared.");
        }
    }

    private function makeCleanGitWorkspaceForEvidence(): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'atlas_rivals_evidence_pack_'.bin2hex(random_bytes(8));
        @mkdir($path, 0o755, true);
        file_put_contents($path.'/README.md', "seed\n");
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
}
