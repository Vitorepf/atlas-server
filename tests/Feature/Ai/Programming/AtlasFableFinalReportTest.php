<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming;

use App\Services\Ai\Programming\AtlasDevBeatTestReportService;
use App\Services\Ai\Programming\AtlasFableFinalReportService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasFableFinalReportTest extends TestCase
{
    private string $baselinePath;

    private string $seriesPath;

    private string $reportPath;

    private string $packetPath;

    private string $devBeatEvidencePath;

    protected function setUp(): void
    {
        parent::setUp();

        $id = (string) Str::uuid();
        $this->baselinePath = storage_path("framework/testing/fable-l4-13-baseline-{$id}.json");
        $this->seriesPath = storage_path("framework/testing/fable-l4-13-series-{$id}.jsonl");
        $this->reportPath = storage_path("framework/testing/fable-l4-13-report-{$id}.json");
        $this->packetPath = storage_path("framework/testing/fable-l4-13-packet-{$id}.json");
        $this->devBeatEvidencePath = storage_path("framework/testing/fable-l4-13-dev-beat-{$id}.json");

        File::ensureDirectoryExists(dirname($this->baselinePath));
        File::put($this->baselinePath, json_encode([
            'schema_version' => 'atlas.fable_campaign.marco_zero.v1',
            'recorded_at' => '2026-06-11',
            'baseline' => [
                'maturity_scorecard' => ['acos_overall' => 7.86],
                'learning_capture_quality_7d' => ['gate_mode' => 'observe'],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    protected function tearDown(): void
    {
        @File::delete($this->baselinePath);
        @File::delete($this->seriesPath);
        @File::delete($this->reportPath);
        @File::delete($this->packetPath);
        @File::delete($this->devBeatEvidencePath);

        parent::tearDown();
    }

    public function test_final_report_writes_resolved_sources_and_a_cold_session_packet(): void
    {
        $this->writeDevBeatEvidence([
            $this->devBeatTask('bug', 74),
            $this->devBeatTask('feature', 152),
            $this->devBeatTask('refactor', 95),
        ]);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--dev-beat-evidence' => $this->devBeatEvidencePath,
            '--write-report' => true,
            '--report-path' => $this->reportPath,
            '--write-packet' => true,
            '--packet-path' => $this->packetPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame(AtlasFableFinalReportService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready_with_operator_gated_external_proofs', $payload['status']);
        $this->assertSame('resolved', data_get($payload, 'resolved_sources.delta.status'));
        $this->assertSame('resolved', data_get($payload, 'resolved_sources.delta_series.status'));
        $this->assertSame('ready', data_get($payload, 'cold_session_verification.status'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.provider_dispatches_now'));

        $this->assertFileExists($this->reportPath);
        $this->assertFileExists($this->packetPath);

        $writtenReport = json_decode((string) File::get($this->reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasFableFinalReportService::SCHEMA_VERSION, $writtenReport['schema_version']);
        $this->assertArrayHasKey('waste', $writtenReport['n_x_m']);
        $this->assertArrayHasKey('measured_cost', $writtenReport['n_x_m']);
        $this->assertArrayHasKey('scorecard', $writtenReport['n_x_m']);
        $this->assertArrayHasKey('recall', $writtenReport['n_x_m']);

        $packet = json_decode((string) File::get($this->packetPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(AtlasFableFinalReportService::PACKET_SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame('L4-14 final M capture', data_get($packet, 'resume_state.next_item'));
        $this->assertStringContainsString('gpt-5.5/M3', $packet['audience']);
        $this->assertStringContainsString('continue in order from L4-14', $packet['start_prompt']);
        $this->assertContains('docs/fable-lista-6-14-itens.md', data_get($packet, 'cold_session.required_read_files'));
        $this->assertStringContainsString('atlas:fable:final-report --verify-packet=<packet.json>', implode("\n", data_get($packet, 'cold_session.required_commands')));
        $this->assertSame('external_claim_blocked', data_get($packet, 'evidence_summary.dev_beat_status'));
        $this->assertContains(
            'L4-9 Atlas Dev internal 3/3 evidence is collected; external comparison/benchmark receipt is still pending',
            data_get($packet, 'resume_state.operator_gated_truths'),
        );
        $this->assertNotContains(
            'L4-9 real Atlas Dev and external comparison evidence still pending',
            data_get($packet, 'resume_state.operator_gated_truths'),
        );
    }

    public function test_handoff_packet_reports_comparable_external_baseline_without_superiority_honestly(): void
    {
        $this->writeDevBeatEvidence([
            $this->devBeatTask('bug', 74, baselineDuration: 73),
            $this->devBeatTask('feature', 152, baselineDuration: 240),
            $this->devBeatTask('refactor', 95, baselineDuration: 48),
        ]);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--dev-beat-evidence' => $this->devBeatEvidencePath,
            '--write-packet' => true,
            '--packet-path' => $this->packetPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame('comparable_report_ready_no_superiority', data_get($payload, 'source_reports.dev_beat_test.status'));

        $packet = json_decode((string) File::get($this->packetPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('comparable_report_ready_no_superiority', data_get($packet, 'evidence_summary.dev_beat_status'));
        $this->assertContains(
            'L4-9 comparable external evidence exists, but Atlas Dev has no superiority claim yet (1 Atlas wins / 2 external wins)',
            data_get($packet, 'resume_state.operator_gated_truths'),
        );
        $this->assertNotContains(
            'L4-9 Atlas Dev/external comparison evidence is still pending',
            data_get($packet, 'resume_state.operator_gated_truths'),
        );
    }

    public function test_cold_session_packet_verification_can_be_run_from_a_fresh_command(): void
    {
        $writeOut = new BufferedOutput;
        Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--write-packet' => true,
            '--packet-path' => $this->packetPath,
            '--json' => true,
        ], $writeOut);

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--verify-packet' => $this->packetPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit, $raw);
        $this->assertSame('ready', data_get($payload, 'verified_packet_from_path.status'));
        $this->assertSame([], data_get($payload, 'verified_packet_from_path.blockers'));
    }

    public function test_strict_verification_fails_a_packet_that_cannot_resume_cold_session(): void
    {
        File::ensureDirectoryExists(dirname($this->packetPath));
        File::put($this->packetPath, json_encode([
            'schema_version' => AtlasFableFinalReportService::PACKET_SCHEMA_VERSION,
            'start_prompt' => 'too short',
            'claim_policy' => ['provider_dispatches_now' => false],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $out = new BufferedOutput;
        $exit = Artisan::call('atlas:fable:final-report', [
            '--baseline' => $this->baselinePath,
            '--series' => $this->seriesPath,
            '--date' => '2026-06-12',
            '--verify-packet' => $this->packetPath,
            '--json' => true,
            '--strict' => true,
        ], $out);
        $raw = $out->fetch();
        $payload = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(1, $exit);
        $this->assertSame('blocked', data_get($payload, 'verified_packet_from_path.status'));
        $this->assertContains('start_prompt_too_short', data_get($payload, 'verified_packet_from_path.blockers'));
        $this->assertContains('packet_hash_mismatch', data_get($payload, 'verified_packet_from_path.blockers'));
    }

    /**
     * @param  list<array<string,mixed>>  $tasks
     */
    private function writeDevBeatEvidence(array $tasks): void
    {
        File::ensureDirectoryExists(dirname($this->devBeatEvidencePath));
        File::put($this->devBeatEvidencePath, json_encode([
            'schema_version' => AtlasDevBeatTestReportService::EVIDENCE_SCHEMA_VERSION,
            'tasks' => $tasks,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * @return array<string,mixed>
     */
    private function devBeatTask(string $type, int $duration, ?int $baselineDuration = null): array
    {
        return [
            'id' => $type.'-medium-live',
            'task_type' => $type,
            'title' => ucfirst($type).' medium Atlas Dev task',
            'difficulty' => 'medium',
            'target_path' => 'app/Services/Ai/Programming/AtlasFableFinalReportService.php',
            'atlas_dev' => [
                'executed' => true,
                'provider' => 'hermes_cli',
                'model' => 'gpt-5.5',
                'duration_seconds' => $duration,
                'tests_passed' => true,
                'scope_passed' => true,
                'changed_files' => ['app/Services/Ai/Programming/AtlasFableFinalReportService.php'],
                'validation_commands' => [
                    ['command' => 'php artisan test tests/Feature/Ai/Programming/AtlasFableFinalReportTest.php', 'exit_code' => 0],
                ],
                'evidence_refs' => ['receipt:'.$type],
            ],
            'baseline' => $baselineDuration === null
                ? [
                    'executed' => false,
                    'provider' => 'claude_code',
                ]
                : [
                    'executed' => true,
                    'provider' => 'cursor',
                    'model' => 'cursor-auto',
                    'duration_seconds' => $baselineDuration,
                    'tests_passed' => true,
                    'scope_passed' => true,
                    'changed_files' => ['tests/Feature/Ai/Programming/'.$type.'External.php'],
                    'validation_commands' => [
                        ['command' => 'php artisan test tests/Feature/Ai/Programming/'.$type.'External.php', 'exit_code' => 0],
                    ],
                    'evidence_refs' => ['external-receipt:'.$type],
                ],
        ];
    }
}
