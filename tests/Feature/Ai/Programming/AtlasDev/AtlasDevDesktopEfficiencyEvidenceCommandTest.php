<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasDevDesktopEfficiencyEvidenceCommandTest extends TestCase
{
    private string $workspace;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = sys_get_temp_dir().'/atlas-dev-efficiency-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->workspace);
        config()->set('atlas_dev.receipts_path', $this->workspace.'/receipts');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->workspace);

        parent::tearDown();
    }

    public function test_strict_blocks_when_input_file_is_missing(): void
    {
        $payload = $this->runEfficiencyCommand($this->workspace.'/missing.json', expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(0, $payload['case_count']);
        $this->assertContains('input_file_missing', $payload['blocking_findings']);
    }

    public function test_strict_persists_when_observed_cases_prove_ten_x_across_required_task_kinds(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);
        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 0, persist: true);

        $this->assertSame('atlas.dev.desktop_efficiency_evidence.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['input_sha256']);
        $this->assertGreaterThanOrEqual(10.0, $payload['measured_multiplier']);
        $this->assertSame(['claude_code', 'codex'], $payload['compared_against']);
        $this->assertSame('observed_operator_runs', $payload['measurement_mode']);
        $this->assertSame(['frontend', 'patch', 'question', 'repair', 'review'], $payload['evidence_quality']['covered_task_kinds']);
        $this->assertTrue($payload['evidence_quality']['required_task_kinds_covered']);
        $this->assertSame([], $payload['blocking_findings']);
        $this->assertSame('desktop_efficiency/latest.json', $payload['persistence']['latest_ref']);
        $this->assertFileExists($this->workspace.'/receipts/desktop_efficiency/latest.json');
    }

    public function test_strict_blocks_when_case_refs_are_absolute(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);
        $cases['cases'][0]['atlas']['evidence_refs'] = ['/Users/operator/private.json'];
        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_invalid', $payload['blocking_findings']);
    }

    public function test_strict_blocks_when_referenced_evidence_files_do_not_exist(): void
    {
        $inputPath = $this->writeCases($this->validCases());

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_evidence_refs_missing', $payload['blocking_findings']);
        $this->assertContains('patch_case:claude_code_evidence_refs_missing', $payload['blocking_findings']);
        $this->assertContains('patch_case:codex_evidence_refs_missing', $payload['blocking_findings']);
    }

    public function test_strict_blocks_when_referenced_evidence_file_does_not_match_case_or_participant(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);

        $badRef = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        File::put($badRef, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => 'different_case',
            'participant' => 'atlas',
            'status' => 'passed',
            'verification_passed' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_evidence_refs_invalid', $payload['blocking_findings']);
        $this->assertSame(
            ['desktop_efficiency/source/patch_case/atlas.json'],
            $payload['cases'][0]['invalid_evidence_refs']['atlas'],
        );
    }

    public function test_strict_blocks_when_referenced_evidence_metrics_do_not_match_case_input(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);

        $badRef = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        File::put($badRef, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => 'patch_case',
            'participant' => 'atlas',
            'status' => 'passed',
            'verification_passed' => true,
            'elapsed_seconds' => 999,
            'manual_steps' => 0,
            'provider_calls' => 1,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_evidence_refs_invalid', $payload['blocking_findings']);
    }

    public function test_strict_blocks_when_source_evidence_has_no_observed_timestamp(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);

        $badRef = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        $source = json_decode((string) file_get_contents($badRef), true);
        $this->assertIsArray($source);
        unset($source['observed_at']);
        File::put($badRef, json_encode($source, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_evidence_refs_invalid', $payload['blocking_findings']);
    }

    public function test_strict_blocks_when_declared_task_prompt_hash_does_not_match_prompt(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);
        $cases['cases'][0]['task_prompt_sha256'] = str_repeat('b', 64);
        $inputPath = $this->writeCases($cases);

        $payload = $this->runEfficiencyCommand($inputPath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:task_prompt_sha256_mismatch', $payload['blocking_findings']);
    }

    public function test_write_template_creates_operator_fillable_cases_file(): void
    {
        $templatePath = $this->workspace.'/efficiency-cases.json';
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $templatePath,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $payload = json_decode($output->fetch(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame('blocked', $payload['status']);
        $this->assertTrue($payload['template_written']);
        $this->assertSame(15, $payload['source_template_count']);
        $this->assertSame(15, $payload['source_template_written_count']);
        $this->assertSame(0, $payload['source_template_preserved_count']);
        $this->assertContains('desktop_efficiency/source/patch_case/atlas.json', $payload['source_template_refs']);
        $this->assertFileExists($templatePath);

        $template = json_decode((string) file_get_contents($templatePath), true);
        $this->assertIsArray($template);
        $this->assertSame('atlas.dev.desktop_efficiency_cases.v1', $template['schema_version']);
        $this->assertSame('observed_operator_runs', $template['measurement_mode']);
        $this->assertSame(['patch', 'repair', 'review', 'frontend', 'question'], array_column($template['cases'], 'task_kind'));
        $this->assertIsString($template['cases'][0]['task_prompt']);
        $this->assertSame(hash('sha256', $template['cases'][0]['task_prompt']), $template['cases'][0]['task_prompt_sha256']);
        $this->assertCount(15, $template['source_write_commands']);
        $this->assertSame('patch_case', $template['source_write_commands'][0]['case_id']);
        $this->assertSame('atlas', $template['source_write_commands'][0]['participant']);
        $this->assertStringContainsString('--write-source=desktop_efficiency/source/patch_case/atlas.json', $template['source_write_commands'][0]['command']);
        $this->assertStringContainsString('--task-prompt='.escapeshellarg($template['cases'][0]['task_prompt']), $template['source_write_commands'][0]['command']);
        $this->assertStringNotContainsString('<EXACT_TASK_PROMPT>', $template['source_write_commands'][0]['command']);
        $this->assertStringContainsString('--task-prompt-sha256='.$template['cases'][0]['task_prompt_sha256'], $template['source_write_commands'][0]['command']);
        $this->assertStringContainsString('--elapsed-seconds=<REAL_SECONDS>', $template['source_write_commands'][0]['command']);
        $this->assertStringContainsString('--run-ref=desktop_efficiency/raw/patch_case/atlas.json', $template['source_write_commands'][0]['command']);

        $sourcePath = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        $this->assertFileExists($sourcePath);
        $source = json_decode((string) file_get_contents($sourcePath), true);
        $this->assertIsArray($source);
        $this->assertSame('atlas.dev.desktop_efficiency_source.v1', $source['schema_version']);
        $this->assertSame('patch_case', $source['case_id']);
        $this->assertSame('patch', $source['task_kind']);
        $this->assertSame('atlas', $source['participant']);
        $this->assertSame(hash('sha256', $template['cases'][0]['task_prompt']), $source['task_prompt_sha256']);
        $this->assertSame('blocked', $source['status']);
        $this->assertFalse($source['verification_passed']);
    }

    public function test_write_template_preserves_existing_source_evidence_files(): void
    {
        $sourcePath = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => 'patch_case',
            'task_kind' => 'patch',
            'participant' => 'atlas',
            'task_prompt_sha256' => str_repeat('b', 64),
            'status' => 'passed',
            'verification_passed' => true,
            'elapsed_seconds' => 19,
            'manual_steps' => 0,
            'provider_calls' => 1,
            'run_ref' => 'desktop_efficiency/raw/patch_case/atlas.json',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $this->workspace.'/efficiency-cases.json',
            '--json' => true,
            '--strict' => true,
        ], $output);
        $payload = json_decode($output->fetch(), true);

        $this->assertSame(1, $exit);
        $this->assertIsArray($payload);
        $this->assertSame(14, $payload['source_template_written_count']);
        $this->assertSame(1, $payload['source_template_preserved_count']);
        $this->assertSame(['desktop_efficiency/source/patch_case/atlas.json'], $payload['source_template_preserved_refs']);

        $source = json_decode((string) file_get_contents($sourcePath), true);
        $this->assertSame('passed', $source['status']);
        $this->assertSame(19, $source['elapsed_seconds']);
        $this->assertSame(str_repeat('b', 64), $source['task_prompt_sha256']);
    }

    public function test_write_template_refreshes_old_unobserved_source_templates(): void
    {
        $sourcePath = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        File::ensureDirectoryExists(dirname($sourcePath));
        File::put($sourcePath, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
            'case_id' => 'patch_case',
            'task_kind' => 'patch',
            'participant' => 'atlas',
            'task_prompt_sha256' => 'old-placeholder',
            'status' => 'blocked',
            'verification_passed' => false,
            'observed_at' => null,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $this->workspace.'/efficiency-cases.json',
            '--json' => true,
            '--strict' => true,
        ], $output);
        $payload = json_decode($output->fetch(), true);

        $this->assertSame(1, $exit);
        $this->assertSame(14, $payload['source_template_written_count']);
        $this->assertSame(1, $payload['source_template_refreshed_count']);
        $this->assertSame(['desktop_efficiency/source/patch_case/atlas.json'], $payload['source_template_refreshed_refs']);

        $source = json_decode((string) file_get_contents($sourcePath), true);
        $this->assertSame('blocked', $source['status']);
        $this->assertFalse($source['verification_passed']);
        $this->assertSame($payload['required_task_kinds'][0].'_case', $source['case_id']);
        $this->assertSame('Apply a narrow code patch in the workspace and verify the changed behavior with the focused test named in the task.', $source['task_prompt']);
        $this->assertSame(hash('sha256', $source['task_prompt']), $source['task_prompt_sha256']);
        $this->assertArrayNotHasKey('elapsed_seconds', $source);
        $this->assertArrayNotHasKey('manual_steps', $source);
        $this->assertArrayNotHasKey('provider_calls', $source);
    }

    public function test_write_source_records_operator_observed_participant_evidence(): void
    {
        $casesPath = $this->writeCases($this->validCases());

        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: '',
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 0,
            casesPath: $casesPath,
            runRef: 'desktop_efficiency/raw/patch_case/atlas-write.json',
        );

        $this->assertSame('atlas.dev.desktop_efficiency_source.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertSame('desktop_efficiency/source/patch_case/atlas.json', $payload['ref']);
        $this->assertSame([], $payload['blocking_findings']);

        $sourcePath = $this->workspace.'/receipts/desktop_efficiency/source/patch_case/atlas.json';
        $this->assertFileExists($sourcePath);
        $source = json_decode((string) file_get_contents($sourcePath), true);
        $this->assertSame('patch_case', $source['case_id']);
        $this->assertSame('patch', $source['task_kind']);
        $this->assertSame('atlas', $source['participant']);
        $this->assertSame('Canonical patch measurement prompt.', $source['task_prompt']);
        $this->assertSame(hash('sha256', 'Canonical patch measurement prompt.'), $source['task_prompt_sha256']);
        $this->assertSame(21, $source['elapsed_seconds']);
        $this->assertSame('desktop_efficiency/raw/patch_case/atlas-write.json', $source['run_ref']);
        $this->assertTrue($source['verification_passed']);
    }

    public function test_write_source_blocks_missing_run_ref(): void
    {
        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
            runRef: '',
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_ref_missing', $payload['blocking_findings']);
    }

    public function test_write_source_blocks_absolute_run_ref(): void
    {
        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
            runRef: '/tmp/private-run.json',
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_ref_invalid', $payload['blocking_findings']);
    }

    public function test_write_source_blocks_missing_run_ref_file(): void
    {
        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
            runRef: 'desktop_efficiency/raw/patch_case/missing.json',
            createRunRef: false,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_ref_not_found', $payload['blocking_findings']);
    }

    public function test_write_run_ref_records_raw_observed_evidence(): void
    {
        $payload = $this->runWriteRunRefCommand(
            ref: 'desktop_efficiency/raw/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            summary: 'Atlas Dev completed the observed patch task and persisted a receipt.',
            verificationCommand: 'php artisan test tests/Feature/Ai/Programming/AtlasDev/ObservedPatchTest.php',
            verificationPassed: true,
            expectedExit: 0,
        );

        $this->assertSame('passed', $payload['status']);
        $this->assertSame('desktop_efficiency/raw/patch_case/atlas.json', $payload['ref']);
        $this->assertSame([], $payload['blocking_findings']);

        $path = $this->workspace.'/receipts/desktop_efficiency/raw/patch_case/atlas.json';
        $this->assertFileExists($path);
        $raw = json_decode((string) file_get_contents($path), true);
        $this->assertSame('atlas.dev.desktop_efficiency_raw_run.v1', $raw['schema_version']);
        $this->assertSame('patch_case', $raw['case_id']);
        $this->assertSame('atlas', $raw['participant']);
        $this->assertTrue($raw['verification_passed']);
    }

    public function test_write_source_blocks_run_ref_with_wrong_payload(): void
    {
        $this->runWriteRunRefCommand(
            ref: 'desktop_efficiency/raw/patch_case/atlas.json',
            caseId: 'repair_case',
            taskKind: 'repair',
            participant: 'atlas',
            summary: 'Wrong case raw run evidence.',
            verificationCommand: 'php artisan test --filter=WrongCase',
            verificationPassed: true,
            expectedExit: 0,
        );

        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
            runRef: 'desktop_efficiency/raw/patch_case/atlas.json',
            createRunRef: false,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_ref_invalid_payload', $payload['blocking_findings']);
    }

    public function test_write_source_blocks_self_referential_run_ref(): void
    {
        $ref = 'desktop_efficiency/source/patch_case/atlas.json';
        $this->writeRawRunRef($ref, 'patch_case', 'patch', 'atlas');

        $payload = $this->runWriteSourceCommand(
            ref: $ref,
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
            runRef: $ref,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('run_ref_self_reference', $payload['blocking_findings']);
    }

    public function test_write_source_blocks_absolute_ref_without_writing_file(): void
    {
        $payload = $this->runWriteSourceCommand(
            ref: '/tmp/private.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPromptSha256: str_repeat('a', 64),
            elapsedSeconds: 21,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('source_ref_invalid', $payload['blocking_findings']);
        $this->assertArrayNotHasKey('ref', $payload);
    }

    public function test_write_source_blocks_invalid_participant_or_metrics(): void
    {
        $payload = $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/unknown.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'unknown',
            taskPromptSha256: 'not-a-hash',
            elapsedSeconds: null,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 1,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('participant_invalid', $payload['blocking_findings']);
        $this->assertContains('task_prompt_sha256_invalid', $payload['blocking_findings']);
        $this->assertContains('elapsed_seconds_invalid', $payload['blocking_findings']);
    }

    public function test_build_cases_from_sources_writes_cases_json_and_can_prove_ten_x(): void
    {
        $templatePath = $this->workspace.'/template-cases.json';
        $output = new BufferedOutput;
        Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $templatePath,
            '--json' => true,
        ], $output);
        $template = json_decode((string) file_get_contents($templatePath), true);
        $this->assertIsArray($template);

        foreach ((array) $template['cases'] as $case) {
            $caseId = (string) $case['case_id'];
            $taskKind = (string) $case['task_kind'];
            $taskPrompt = (string) $case['task_prompt'];
            $hash = hash('sha256', (string) $case['task_prompt']);
            $this->runWriteSourceCommand(
                ref: 'desktop_efficiency/source/'.$caseId.'/atlas.json',
                caseId: $caseId,
                taskKind: $taskKind,
                participant: 'atlas',
                taskPrompt: $taskPrompt,
                taskPromptSha256: $hash,
                elapsedSeconds: 20,
                manualSteps: 0,
                providerCalls: 1,
                verificationPassed: true,
                expectedExit: 0,
                runRef: 'desktop_efficiency/raw/'.$caseId.'/atlas.json',
            );
            $this->runWriteSourceCommand(
                ref: 'desktop_efficiency/source/'.$caseId.'/claude_code.json',
                caseId: $caseId,
                taskKind: $taskKind,
                participant: 'claude_code',
                taskPrompt: $taskPrompt,
                taskPromptSha256: $hash,
                elapsedSeconds: 400,
                manualSteps: 2,
                providerCalls: 3,
                verificationPassed: true,
                expectedExit: 0,
                runRef: 'desktop_efficiency/raw/'.$caseId.'/claude_code.json',
            );
            $this->runWriteSourceCommand(
                ref: 'desktop_efficiency/source/'.$caseId.'/codex.json',
                caseId: $caseId,
                taskKind: $taskKind,
                participant: 'codex',
                taskPrompt: $taskPrompt,
                taskPromptSha256: $hash,
                elapsedSeconds: 380,
                manualSteps: 2,
                providerCalls: 3,
                verificationPassed: true,
                expectedExit: 0,
                runRef: 'desktop_efficiency/raw/'.$caseId.'/codex.json',
            );
        }

        $casesPath = $this->workspace.'/generated-cases.json';
        $payload = $this->runBuildCasesFromSourcesCommand($casesPath, expectedExit: 0);

        $this->assertSame('passed', $payload['status']);
        $this->assertSame(5, $payload['case_count']);
        $this->assertSame(15, $payload['source_count']);
        $this->assertFileExists($casesPath);

        $evidence = $this->runEfficiencyCommand($casesPath, expectedExit: 0);
        $this->assertSame('passed', $evidence['status']);
        $this->assertGreaterThanOrEqual(10.0, $evidence['measured_multiplier']);
    }

    public function test_build_cases_from_sources_blocks_when_source_is_missing(): void
    {
        $payload = $this->runBuildCasesFromSourcesCommand($this->workspace.'/generated-cases.json', expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('patch_case:atlas_source_missing', $payload['blocking_findings']);
        $this->assertContains('question_case:codex_source_missing', $payload['blocking_findings']);
    }

    public function test_source_status_reports_blocked_slots_and_next_write_command(): void
    {
        $templatePath = $this->workspace.'/template-cases.json';
        $output = new BufferedOutput;
        Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $templatePath,
            '--json' => true,
        ], $output);

        $payload = $this->runSourceStatusCommand($templatePath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(15, $payload['source_collection_status']['total_slots']);
        $this->assertSame(0, $payload['source_collection_status']['passed_slots']);
        $this->assertSame(15, $payload['source_collection_status']['blocked_slots']);
        $this->assertSame(0, $payload['source_collection_status']['missing_slots']);
        $this->assertSame(15, $payload['source_collection_status']['remaining_slots']);
        $this->assertSame('patch_case', $payload['next_required_source']['case_id']);
        $this->assertSame('atlas', $payload['next_required_source']['participant']);
        $this->assertStringContainsString('--write-source=desktop_efficiency/source/patch_case/atlas.json', $payload['next_required_source']['write_command']);
        $this->assertStringContainsString('--run-ref=desktop_efficiency/raw/patch_case/atlas.json', $payload['next_required_source']['write_command']);
        $this->assertStringContainsString("--task-prompt='Apply a narrow code patch in the workspace and verify the changed behavior with the focused test named in the task.'", $payload['next_required_source']['write_command']);
        $this->assertStringNotContainsString('<EXACT_TASK_PROMPT>', $payload['next_required_source']['write_command']);
        $this->assertContains('elapsed_seconds_invalid', $payload['next_required_source']['blocking_findings']);
        $this->assertContains('run_ref_missing', $payload['next_required_source']['blocking_findings']);
    }

    public function test_source_commands_reports_only_pending_write_commands(): void
    {
        $templatePath = $this->workspace.'/template-cases.json';
        $output = new BufferedOutput;
        Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $templatePath,
            '--json' => true,
        ], $output);

        $template = json_decode((string) file_get_contents($templatePath), true);
        $this->assertIsArray($template);
        $firstCase = (array) $template['cases'][0];
        $firstPrompt = (string) $firstCase['task_prompt'];
        $firstHash = hash('sha256', $firstPrompt);
        $this->runWriteSourceCommand(
            ref: 'desktop_efficiency/source/patch_case/atlas.json',
            caseId: 'patch_case',
            taskKind: 'patch',
            participant: 'atlas',
            taskPrompt: $firstPrompt,
            taskPromptSha256: $firstHash,
            elapsedSeconds: 20,
            manualSteps: 0,
            providerCalls: 1,
            verificationPassed: true,
            expectedExit: 0,
        );

        $payload = $this->runSourceCommandsCommand($templatePath, expectedExit: 1);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame(14, $payload['pending_command_count']);
        $this->assertSame(1, $payload['source_collection_status']['passed_slots']);
        $this->assertSame('patch_case', $payload['pending_commands'][0]['case_id']);
        $this->assertSame('claude_code', $payload['pending_commands'][0]['participant']);
        $this->assertSame('desktop_efficiency/raw/patch_case/claude_code.json', $payload['pending_commands'][0]['run_ref']);
        $this->assertStringContainsString('--write-run-ref=desktop_efficiency/raw/patch_case/claude_code.json', $payload['pending_commands'][0]['raw_run_command']);
        $this->assertStringContainsString("--raw-summary='<OBSERVED_RUN_SUMMARY>'", $payload['pending_commands'][0]['raw_run_command']);
        $this->assertStringContainsString('--write-source=desktop_efficiency/source/patch_case/claude_code.json', $payload['pending_commands'][0]['command']);
        $this->assertStringContainsString("--task-prompt='Apply a narrow code patch in the workspace and verify the changed behavior with the focused test named in the task.'", $payload['pending_commands'][0]['command']);
        $this->assertStringNotContainsString('<EXACT_TASK_PROMPT>', $payload['pending_commands'][0]['command']);
    }

    public function test_source_status_passes_after_all_observed_sources_are_recorded(): void
    {
        $templatePath = $this->workspace.'/template-cases.json';
        $output = new BufferedOutput;
        Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-template' => $templatePath,
            '--json' => true,
        ], $output);
        $template = json_decode((string) file_get_contents($templatePath), true);
        $this->assertIsArray($template);

        foreach ((array) $template['cases'] as $case) {
            $caseId = (string) $case['case_id'];
            $taskKind = (string) $case['task_kind'];
            $taskPrompt = (string) $case['task_prompt'];
            $hash = hash('sha256', (string) $case['task_prompt']);
            foreach (['atlas', 'claude_code', 'codex'] as $participant) {
                $this->runWriteSourceCommand(
                    ref: 'desktop_efficiency/source/'.$caseId.'/'.$participant.'.json',
                    caseId: $caseId,
                    taskKind: $taskKind,
                    participant: $participant,
                    taskPrompt: $taskPrompt,
                    taskPromptSha256: $hash,
                    elapsedSeconds: 20,
                    manualSteps: 0,
                    providerCalls: 1,
                    verificationPassed: true,
                    expectedExit: 0,
                    runRef: 'desktop_efficiency/raw/'.$caseId.'/'.$participant.'.json',
                );
            }
        }

        $payload = $this->runSourceStatusCommand($templatePath, expectedExit: 0);

        $this->assertSame('passed', $payload['status']);
        $this->assertSame(15, $payload['source_collection_status']['passed_slots']);
        $this->assertSame(0, $payload['source_collection_status']['remaining_slots']);
        $this->assertNull($payload['next_required_source']);
        $this->assertSame([], $payload['blocking_findings']);
    }

    /**
     * @return array<string,mixed>
     */
    private function runEfficiencyCommand(string $inputPath, int $expectedExit, bool $persist = false): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--input' => $inputPath,
            '--persist' => $persist,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runWriteSourceCommand(
        string $ref,
        string $caseId,
        string $taskKind,
        string $participant,
        string $taskPromptSha256,
        ?int $elapsedSeconds,
        int $manualSteps,
        int $providerCalls,
        bool $verificationPassed,
        int $expectedExit,
        ?string $casesPath = null,
        string $taskPrompt = '',
        ?string $runRef = null,
        bool $createRunRef = true,
    ): array {
        $output = new BufferedOutput;
        $arguments = [
            '--write-source' => $ref,
            '--case-id' => $caseId,
            '--task-kind' => $taskKind,
            '--participant' => $participant,
            '--manual-steps' => (string) $manualSteps,
            '--provider-calls' => (string) $providerCalls,
            '--verification-passed' => $verificationPassed,
            '--json' => true,
            '--strict' => true,
        ];
        if ($taskPrompt !== '') {
            $arguments['--task-prompt'] = $taskPrompt;
        }
        if ($elapsedSeconds !== null) {
            $arguments['--elapsed-seconds'] = (string) $elapsedSeconds;
        }
        if ($taskPromptSha256 !== '') {
            $arguments['--task-prompt-sha256'] = $taskPromptSha256;
        }
        if ($casesPath !== null) {
            $arguments['--cases'] = $casesPath;
        }
        if ($runRef === null) {
            $runRef = 'desktop_efficiency/raw/'.$caseId.'/'.$participant.'.json';
        }
        if ($runRef !== '') {
            if ($createRunRef && ! str_starts_with($runRef, '/') && ! str_contains($runRef, '://') && ! str_contains($runRef, '..')) {
                $this->writeRawRunRef($runRef, $caseId, $taskKind, $participant);
            }
            $arguments['--run-ref'] = $runRef;
        }

        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', $arguments, $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runBuildCasesFromSourcesCommand(string $path, int $expectedExit): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--build-cases-from-sources' => $path,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runSourceStatusCommand(string $casesPath, int $expectedExit): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--source-status' => true,
            '--cases' => $casesPath,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runSourceCommandsCommand(string $casesPath, int $expectedExit): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--source-commands' => true,
            '--cases' => $casesPath,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function runWriteRunRefCommand(
        string $ref,
        string $caseId,
        string $taskKind,
        string $participant,
        string $summary,
        string $verificationCommand,
        bool $verificationPassed,
        int $expectedExit,
    ): array {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:efficiency-evidence', [
            '--write-run-ref' => $ref,
            '--case-id' => $caseId,
            '--task-kind' => $taskKind,
            '--participant' => $participant,
            '--raw-summary' => $summary,
            '--raw-verification-command' => $verificationCommand,
            '--verification-passed' => $verificationPassed,
            '--json' => true,
            '--strict' => true,
        ], $output);
        $rawOutput = $output->fetch();
        $payload = json_decode($rawOutput, true);

        $this->assertSame($expectedExit, $exit, $rawOutput);
        $this->assertIsArray($payload, $rawOutput);

        return $payload;
    }

    /**
     * @return array<string,mixed>
     */
    private function validCases(): array
    {
        $cases = [];
        foreach ([
            'patch_case' => 'patch',
            'repair_case' => 'repair',
            'review_case' => 'review',
            'frontend_case' => 'frontend',
            'question_case' => 'question',
        ] as $caseId => $taskKind) {
            $cases[] = [
                'case_id' => $caseId,
                'task_kind' => $taskKind,
                'task_prompt' => $taskPrompt = 'Canonical '.$taskKind.' measurement prompt.',
                'task_prompt_sha256' => hash('sha256', $taskPrompt),
                'atlas' => $this->participant($caseId, 'atlas', elapsed: 20, manualSteps: 0, providerCalls: 1),
                'claude_code' => $this->participant($caseId, 'claude_code', elapsed: 400, manualSteps: 2, providerCalls: 3),
                'codex' => $this->participant($caseId, 'codex', elapsed: 380, manualSteps: 2, providerCalls: 3),
            ];
        }

        return [
            'schema_version' => 'atlas.dev.desktop_efficiency_cases.v1',
            'measurement_mode' => 'observed_operator_runs',
            'cases' => $cases,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function participant(string $caseId, string $participant, int $elapsed, int $manualSteps, int $providerCalls): array
    {
        return [
            'status' => 'passed',
            'verification_passed' => true,
            'elapsed_seconds' => $elapsed,
            'manual_steps' => $manualSteps,
            'provider_calls' => $providerCalls,
            'evidence_refs' => [
                'desktop_efficiency/source/'.$caseId.'/'.$participant.'.json',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeCases(array $payload): string
    {
        $path = $this->workspace.'/cases.json';
        File::put($path, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        return $path;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function writeSourceEvidence(array $payload): void
    {
        foreach ((array) ($payload['cases'] ?? []) as $case) {
            foreach (['atlas', 'claude_code', 'codex'] as $participant) {
                foreach ((array) data_get($case, $participant.'.evidence_refs', []) as $ref) {
                    if (! is_string($ref)) {
                        continue;
                    }

                    $path = $this->workspace.'/receipts/'.str_replace('/', DIRECTORY_SEPARATOR, $ref);
                    File::ensureDirectoryExists(dirname($path));
                    $rawRef = str_replace('/source/', '/raw/', $ref);
                    $this->writeRawRunRef(
                        $rawRef,
                        (string) ($case['case_id'] ?? ''),
                        (string) ($case['task_kind'] ?? ''),
                        $participant,
                    );
                    File::put($path, json_encode([
                        'schema_version' => 'atlas.dev.desktop_efficiency_source.v1',
                        'case_id' => $case['case_id'] ?? null,
                        'task_kind' => $case['task_kind'] ?? null,
                        'participant' => $participant,
                        'task_prompt' => $case['task_prompt'] ?? null,
                        'task_prompt_sha256' => hash('sha256', (string) ($case['task_prompt'] ?? '')),
                        'status' => 'passed',
                        'verification_passed' => true,
                        'elapsed_seconds' => data_get($case, $participant.'.elapsed_seconds'),
                        'manual_steps' => data_get($case, $participant.'.manual_steps'),
                        'provider_calls' => data_get($case, $participant.'.provider_calls'),
                        'observed_at' => '2026-05-16T12:00:00Z',
                        'run_ref' => $rawRef,
                    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
                }
            }
        }
    }

    private function writeRawRunRef(string $ref, string $caseId, string $taskKind, string $participant): void
    {
        $path = $this->workspace.'/receipts/'.str_replace('/', DIRECTORY_SEPARATOR, $ref);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_raw_run.v1',
            'status' => 'passed',
            'case_id' => $caseId,
            'task_kind' => $taskKind,
            'participant' => $participant,
            'summary' => 'Observed test raw run evidence.',
            'verification_command' => 'php artisan test --filter=ObservedCase',
            'verification_passed' => true,
            'captured_at' => '2026-05-16T12:00:00Z',
            'blocking_findings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
