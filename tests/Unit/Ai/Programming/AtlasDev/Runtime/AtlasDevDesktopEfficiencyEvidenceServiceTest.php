<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopEfficiencyEvidenceService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevDesktopEfficiencyEvidenceServiceTest extends TestCase
{
    private string $receiptsPath;

    private AtlasDevDesktopEfficiencyEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptsPath = sys_get_temp_dir().'/atlas-dev-efficiency-unit-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->receiptsPath);
        config()->set('atlas_dev.receipts_path', $this->receiptsPath);
        $this->service = new AtlasDevDesktopEfficiencyEvidenceService;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->receiptsPath);

        parent::tearDown();
    }

    public function test_build_blocks_when_schema_version_is_invalid(): void
    {
        $payload = $this->service->build([
            'schema_version' => 'atlas.dev.desktop_efficiency_cases.v0',
            'measurement_mode' => 'observed_operator_runs',
            'cases' => [],
        ]);

        $this->assertSame(AtlasDevDesktopEfficiencyEvidenceService::EVIDENCE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('schema_version_invalid', $payload['blocking_findings']);
    }

    public function test_build_passes_with_valid_observed_cases_and_ten_x_multiplier(): void
    {
        $cases = $this->validCases();
        $this->writeSourceEvidence($cases);

        $payload = $this->service->build($cases, 'cases.json');

        $this->assertSame('passed', $payload['status']);
        $this->assertGreaterThanOrEqual(10.0, $payload['measured_multiplier']);
        $this->assertSame(5, $payload['case_count']);
        $this->assertTrue($payload['evidence_quality']['required_task_kinds_covered']);
        $this->assertSame([], $payload['blocking_findings']);
        $this->assertSame(300.0, $payload['effort_formula']['manual_step_seconds']);
        $this->assertSame(30.0, $payload['effort_formula']['provider_call_seconds']);
    }

    public function test_build_accepts_numeric_string_metrics_in_participant_payloads(): void
    {
        $cases = $this->validCases();
        $cases['cases'][0]['atlas']['elapsed_seconds'] = '20';
        $cases['cases'][0]['atlas']['manual_steps'] = '0';
        $cases['cases'][0]['atlas']['provider_calls'] = '1';
        $this->writeSourceEvidence($cases);

        $payload = $this->service->build($cases);

        $this->assertSame('passed', $payload['status']);
        $this->assertNotContains('patch_case:atlas_invalid', $payload['blocking_findings']);
        $this->assertSame(50.0, $payload['cases'][0]['atlas_effort_seconds']);
    }

    public function test_persist_writes_timestamped_and_latest_refs(): void
    {
        $evidence = [
            'schema_version' => AtlasDevDesktopEfficiencyEvidenceService::EVIDENCE_SCHEMA_VERSION,
            'status' => 'passed',
        ];

        $refs = $this->service->persist($evidence);

        $this->assertMatchesRegularExpression('#^desktop_efficiency/efficiency-\d{14}\.json$#', $refs['ref']);
        $this->assertSame('desktop_efficiency/latest.json', $refs['latest_ref']);
        $this->assertFileExists($this->receiptsPath.'/desktop_efficiency/latest.json');
        $this->assertFileExists($this->receiptsPath.'/'.str_replace('/', DIRECTORY_SEPARATOR, $refs['ref']));
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
            'schema_version' => AtlasDevDesktopEfficiencyEvidenceService::CASES_SCHEMA_VERSION,
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
    private function writeSourceEvidence(array $payload): void
    {
        foreach ((array) ($payload['cases'] ?? []) as $case) {
            foreach (['atlas', 'claude_code', 'codex'] as $participant) {
                foreach ((array) data_get($case, $participant.'.evidence_refs', []) as $ref) {
                    if (! is_string($ref)) {
                        continue;
                    }

                    $path = $this->receiptsPath.'/'.str_replace('/', DIRECTORY_SEPARATOR, $ref);
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
        $path = $this->receiptsPath.'/'.str_replace('/', DIRECTORY_SEPARATOR, $ref);
        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode([
            'schema_version' => 'atlas.dev.desktop_efficiency_raw_run.v1',
            'status' => 'passed',
            'case_id' => $caseId,
            'task_kind' => $taskKind,
            'participant' => $participant,
            'summary' => 'Observed unit-test raw run evidence.',
            'verification_command' => 'php artisan test --filter=ObservedCase',
            'verification_passed' => true,
            'captured_at' => '2026-05-16T12:00:00Z',
            'blocking_findings' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
    }
}
