<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsCorpusPreValidationService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDryRunService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsIndustrialExecutionSuiteService;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsModeRegistry;
use App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsPreflightService;
use App\Services\Ai\Programming\ForgeRivals\Corpus\AtlasForgeRivalsProviderArenaCorpusService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasForgeRivalsIndustrialExecutionSuiteTest extends TestCase
{
    protected function tearDown(): void
    {
        foreach ([
            '__tmp_industrial_execution_empty_seed',
            '__tmp_industrial_execution_extreme',
            '__tmp_industrial_execution_ceiling',
            '__tmp_industrial_execution_single_override',
            '__tmp_industrial_execution_missing_expected',
            '__tmp_industrial_execution_missing_test',
        ] as $caseId) {
            File::deleteDirectory(base_path('storage/forge-rivals-corpus/'.$caseId));
        }

        parent::tearDown();
    }

    public function test_industrial_50_has_50_executable_cases(): void
    {
        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(50, $payload['total_cases']);
        $this->assertSame(50, $payload['executable_cases']);
        $this->assertSame([], $payload['empty_seed_cases']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_extreme_differentiator_is_an_executable_industrial_case_set(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_extreme');
        $case['industrial_case_set'] = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR;
        $this->writeExecutableSeed($case);

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'ensure_fixtures' => false,
            'cases_override' => array_fill(0, 80, $case),
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(80, $payload['required_cases']);
        $this->assertSame(80, $payload['total_cases']);
        $this->assertSame(80, $payload['executable_cases']);
        $this->assertContains(
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            $payload['execution_case_sets'],
        );
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_ceiling_360_is_an_executable_industrial_case_set(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_ceiling');
        $case['industrial_case_set'] = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360;
        $this->writeExecutableSeed($case);

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
            'ensure_fixtures' => false,
            'cases_override' => array_fill(0, 120, $case),
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(120, $payload['required_cases']);
        $this->assertSame(120, $payload['total_cases']);
        $this->assertSame(120, $payload['executable_cases']);
        $this->assertContains(
            AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_CEILING_360,
            $payload['execution_case_sets'],
        );
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_single_case_override_can_materialize_fixture_with_required_floor_one(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_single_override');
        $case['industrial_case_set'] = AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR;

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'ensure_fixtures' => true,
            'cases_override' => [$case],
            'required_cases_override' => 1,
        ]);

        $this->assertSame('ok', $payload['status'], json_encode($payload, JSON_PRETTY_PRINT));
        $this->assertSame(1, $payload['required_cases']);
        $this->assertSame(1, $payload['total_cases']);
        $this->assertSame(1, $payload['executable_cases']);
        $this->assertFileExists(base_path($case['fixture_seed_path'].'/'.$case['expected_changed_files'][0]));
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertTrue($payload['advisory_only']);
        $this->assertSame('none', $payload['routing_effect']);
    }

    public function test_empty_fixture_blocks_execution_readiness(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_empty_seed');
        File::ensureDirectoryExists(base_path($case['fixture_seed_path']));
        File::put(base_path($case['fixture_seed_path']).'/README.md', 'readme only');

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            'ensure_fixtures' => false,
            'cases_override' => [$case],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains($case['case_id'], $payload['empty_seed_cases']);
        $this->assertContains('industrial_case_not_executable:'.$case['case_id'].':fixture_seed_empty', $payload['blockers']);
    }

    public function test_missing_expected_changed_files_blocks_readiness(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_missing_expected');
        $case['expected_changed_files'] = [];
        $this->writeExecutableSeed($case);

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            'ensure_fixtures' => false,
            'cases_override' => [$case],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains($case['case_id'], $payload['missing_expected_changed_files']);
    }

    public function test_missing_test_command_blocks_readiness(): void
    {
        $case = $this->fixtureCase('__tmp_industrial_execution_missing_test');
        $case['quick_test_command'] = '';
        $case['full_test_command'] = '';
        $case['test_command'] = '';
        $this->writeExecutableSeed($case);

        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            'ensure_fixtures' => false,
            'cases_override' => [$case],
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains($case['case_id'], $payload['missing_tests']);
    }

    public function test_quick_and_release_cannot_pass_as_industrial_execution(): void
    {
        $suite = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class);

        foreach (['quick', 'release'] as $caseSet) {
            $payload = $suite->readiness(['case_set' => $caseSet]);

            $this->assertSame('blocked', $payload['status']);
            $this->assertContains('not_industrial_execution_case_set:'.$caseSet, $payload['blockers']);
        }
    }

    public function test_local_fake_dry_run_does_not_call_provider(): void
    {
        $exitCode = Artisan::call('atlas:forge:rivals', [
            'action' => 'run-battery',
            '--preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            '--mode' => AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            '--dry-run' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = $this->jsonOutput();
        $this->assertSame('ok', $payload['status']);
        $this->assertSame('industrial_dry_run_planned', $payload['verdict']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertNull($payload['scorecard']);
    }

    public function test_industrial_preflight_uses_industrial_contract_instead_of_legacy_single_case_protocol(): void
    {
        $payload = app(AtlasForgeRivalsPreflightService::class)->preflight([
            'preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'workspace' => base_path(),
            'baseline_workspace' => base_path(),
            'confirmations' => [
                'runbook_reviewed' => true,
                'provider_cost' => true,
                'real_provider_call' => true,
            ],
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(80, $payload['cases_count']);
        $this->assertTrue($payload['industrial_protocol_bypass']);
        $this->assertSame('ready_for_provider_battery', $payload['protocol_status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['protocol_report']['legacy_protocol_bypassed']);
        $this->assertFalse($payload['protocol_report']['external_provider_call']);
        $this->assertFalse($payload['protocol_report']['provider_tokens_spent']);
        $this->assertSame('none', $payload['protocol_report']['routing_effect']);
        $this->assertStringNotContainsString('protocol:case_manifest_invalid', implode(',', $payload['blockers']));
        $this->assertStringNotContainsString('protocol:atlas_arm_not_forge', implode(',', $payload['blockers']));
    }

    public function test_industrial_dry_run_uses_industrial_plan_instead_of_legacy_single_case_protocol(): void
    {
        $payload = app(AtlasForgeRivalsDryRunService::class)->plan([
            'preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'mode' => AtlasForgeRivalsModeRegistry::MODE_FAIR,
            'atlas_model' => 'sonnet',
            'rival' => 'claude_sonnet',
            'workspace' => base_path(),
            'baseline_workspace' => base_path(),
        ]);

        $this->assertSame('ok', $payload['status']);
        $this->assertSame(80, $payload['cases_count']);
        $this->assertTrue($payload['industrial_protocol_bypass']);
        $this->assertSame('industrial_dry_run_planned', $payload['protocol_status']);
        $this->assertSame([], $payload['blockers']);
        $this->assertTrue($payload['dry_run_report']['legacy_protocol_bypassed']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertSame('none', $payload['routing_effect']);
        $this->assertStringNotContainsString('protocol:case_manifest_invalid', implode(',', $payload['blockers']));
        $this->assertStringNotContainsString('protocol:replay_manifest_invalid', implode(',', $payload['blockers']));
    }

    public function test_extreme_prevalidation_uses_materialized_fixture_seed_path_fallback(): void
    {
        app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'ensure_fixtures' => true,
        ]);

        $payload = app(AtlasForgeRivalsCorpusPreValidationService::class)->validate([
            'preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_EXTREME_DIFFERENTIATOR,
            'require_expected_changed_files' => true,
        ]);

        $this->assertSame('ok', $payload['status'], json_encode($payload['blockers'], JSON_PRETTY_PRINT));
        $this->assertSame(80, $payload['case_count']);
        $this->assertSame(80, $payload['valid_count']);
        $this->assertSame(0, $payload['blocked_count']);
        $this->assertSame([], $payload['blockers']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
    }

    public function test_local_fake_execution_does_not_call_provider_and_keeps_claim_blocked(): void
    {
        $this->skipWorktreeHeavyIndustrialBatteryTestWhenDiskIsInsufficient();

        $exitCode = Artisan::call('atlas:forge:rivals', [
            'action' => 'run-battery',
            '--preset' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
            '--mode' => AtlasForgeRivalsModeRegistry::MODE_LOCAL_FAKE,
            '--json' => true,
        ]);

        $this->assertSame(0, $exitCode);
        $payload = $this->jsonOutput();
        $this->assertSame('ok', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertFalse($payload['provider_tokens_spent']);
        $this->assertFalse($payload['claim_ready']);
        $this->assertFalse($payload['external_claim_allowed']);
        $this->assertTrue($payload['local_fake_is_not_real_claim']);
        $this->assertSame(0, $payload['phases_failed']);
        $this->assertNotEmpty($payload['evidence_paths']);
    }

    public function test_evidence_replay_matrix_are_required_before_strong_claim(): void
    {
        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_INDUSTRIAL_50,
        ]);
        $claim = $payload['claim_status'];

        $this->assertFalse($claim['ready_for_strong_benchmark_claim']);
        $this->assertTrue($claim['requires_evidence_pack_complete']);
        $this->assertTrue($claim['requires_replay_green']);
        $this->assertTrue($claim['requires_scorecard_per_case']);
        $this->assertTrue($claim['requires_matrix_report_green']);
    }

    public function test_statistical_repeat_blocks_confidence_without_repetitions(): void
    {
        $payload = app(AtlasForgeRivalsIndustrialExecutionSuiteService::class)->readiness([
            'case_set' => AtlasForgeRivalsProviderArenaCorpusService::CASE_SET_STATISTICAL_REPEAT,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['statistical_repeat']['confidence_ready']);
        $this->assertContains('statistical_repetitions_missing', $payload['blockers']);
    }

    public function test_audit_includes_industrial_execution_certification_and_advisory_invariants(): void
    {
        Artisan::call('atlas:forge:rivals', [
            'action' => 'audit',
            '--json' => true,
        ]);

        $payload = $this->jsonOutput();
        $cert = $payload['certifications']['atlas_forge_rivals_industrial_execution_suite_certification'];

        $this->assertSame('atlas.forge_rivals_industrial_execution_suite_certification.v1', $cert['schema_version']);
        $this->assertSame('external_rivals_certification', $cert['separated_from']);
        $this->assertFalse($cert['external_provider_call']);
        $this->assertFalse($cert['provider_tokens_spent']);
        $this->assertFalse($cert['external_rivals_certification_unlocked']);
        $this->assertTrue($cert['invariants']['atlas_decide_advisory_only']['ok']);
        $this->assertTrue($cert['invariants']['external_rivals_certification_blocked']['ok']);
    }

    /**
     * @return array<string,mixed>
     */
    private function fixtureCase(string $caseId): array
    {
        $root = 'storage/forge-rivals-industrial/'.$caseId;

        return [
            'case_id' => $caseId,
            'id' => $caseId,
            'category' => 'backend',
            'difficulty' => 'medium',
            'difficulty_level' => 'L3',
            'task_type' => 'bugfix',
            'objective' => 'Exercise industrial execution fixture validation.',
            'fixture_seed_path' => 'storage/forge-rivals-corpus/'.$caseId.'/seed',
            'expected_changed_files' => [
                $root.'/src/'.$caseId.'.php',
                $root.'/tests/'.$caseId.'Test.php',
            ],
            'quick_test_command' => 'php '.$root.'/tests/'.$caseId.'Test.php',
            'full_test_command' => 'php '.$root.'/tests/'.$caseId.'Test.php',
            'test_command' => 'php '.$root.'/tests/'.$caseId.'Test.php',
            'acceptance_criteria' => ['case passes fixture readiness'],
            'evidence_requirements' => [
                'patch_diff',
                'provider_receipt',
                'test_log',
                'scorecard_per_case',
                'workspace_hashes',
            ],
            'invalid_if' => ['missing_evidence_pack', 'missing_replay', 'missing_case_scorecard'],
            'oracle' => ['type' => 'public_oracle'],
            'hidden_oracle_metadata' => ['oracle_hash' => hash('sha256', $caseId)],
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function writeExecutableSeed(array $case): void
    {
        $seed = base_path((string) $case['fixture_seed_path']);
        foreach ((array) $case['expected_changed_files'] as $relative) {
            $path = $seed.'/'.$relative;
            File::ensureDirectoryExists(dirname($path));
            File::put($path, "<?php\n// fixture\n");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function jsonOutput(): array
    {
        $raw = (string) Artisan::output();
        $payload = json_decode($raw, true);
        $this->assertIsArray($payload, $raw);

        return $payload;
    }

    private function skipWorktreeHeavyIndustrialBatteryTestWhenDiskIsInsufficient(): void
    {
        $probePath = base_path('../Atlas-rivals/arms');
        $probeRoot = is_dir($probePath) ? $probePath : dirname($probePath);
        $free = @disk_free_space($probeRoot);
        if ($free === false) {
            $this->markTestSkipped('Cannot probe free disk space for worktree-heavy industrial Rivals battery test.');
        }

        $required = (int) config(
            'atlas_rivals.min_free_bytes_before_worktree_add',
            env('ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD', 1073741824),
        );
        if ($required > 0 && (int) $free < $required) {
            $this->markTestSkipped(sprintf(
                'Skipping worktree-heavy industrial Rivals battery test: free_bytes=%d required_bytes=%d.',
                (int) $free,
                $required,
            ));
        }
    }
}
