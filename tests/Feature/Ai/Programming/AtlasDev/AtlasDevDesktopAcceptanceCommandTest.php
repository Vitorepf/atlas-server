<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Programming\AtlasDev;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

final class AtlasDevDesktopAcceptanceCommandTest extends TestCase
{
    private string $receiptsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptsPath = sys_get_temp_dir().'/atlas-dev-acceptance-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->receiptsPath);
        config()->set('atlas_dev.receipts_path', $this->receiptsPath);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->receiptsPath);

        parent::tearDown();
    }

    public function test_strict_acceptance_fails_when_no_real_smoke_evidence_exists(): void
    {
        $payload = $this->runAcceptanceCommand(expectedExit: 1);

        $this->assertSame('atlas.dev.desktop_acceptance_gate.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertContains('desktop_acceptance_evidence_missing', $payload['remaining_blockers']);
        $this->assertContains('desktop_acceptance_latest_missing', $payload['remaining_blockers']);
        $this->assertSame(0, $payload['summary']['records_found']);
    }

    public function test_strict_acceptance_passes_with_valid_recent_real_smoke_evidence(): void
    {
        $this->writeEvidence('dev-valid-1', $this->validEvidence('dev-valid-1'));

        $payload = $this->runAcceptanceCommand(expectedExit: 0);

        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertSame(1, $payload['summary']['records_found']);
        $this->assertSame(1, $payload['summary']['passed_records']);
        $this->assertSame('dev-valid-1', $payload['summary']['latest_run_id']);
        $this->assertSame('desktop_acceptance/dev-valid-1.json', $payload['records'][0]['ref']);
        $this->assertSame('receipts/dev-valid-1/verification_receipt.json', $payload['records'][0]['receipt_ref']);
        $this->assertArrayNotHasKey('path', $payload['records'][0]);
    }

    public function test_strict_acceptance_ignores_invalid_historical_records_when_latest_real_smoke_passes(): void
    {
        $old = $this->validEvidence('dev-old-invalid');
        $old['provider'] = 'atlas_deterministic';
        $old['model_family'] = 'atlas_dev_fast_path';
        $this->writeEvidence('dev-old-invalid', $old);
        $this->writeEvidence('dev-valid-latest', $this->validEvidence('dev-valid-latest'));

        $payload = $this->runAcceptanceCommand(expectedExit: 0);

        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertSame(2, $payload['summary']['records_found']);
        $this->assertSame(1, $payload['summary']['passed_records']);
        $this->assertSame('dev-valid-latest', $payload['summary']['latest_run_id']);
        $this->assertSame('blocked', $payload['records'][1]['status']);
        $this->assertContains('desktop_acceptance_provider_invalid', $payload['records'][1]['blockers']);
    }

    public function test_strict_acceptance_blocks_stale_or_dishonest_evidence(): void
    {
        $evidence = $this->validEvidence('dev-stale-1');
        $evidence['recorded_at'] = now()->subDays(10)->toISOString();
        $evidence['honesty_flags'] = ['verification_not_run'];
        $this->writeEvidence('dev-stale-1', $evidence);

        $payload = $this->runAcceptanceCommand(expectedExit: 1, maxAgeHours: 24);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('desktop_acceptance_evidence_stale_or_invalid_time', $payload['records'][0]['blockers']);
        $this->assertContains('desktop_acceptance_honesty_flags_present', $payload['records'][0]['blockers']);
        $this->assertContains('desktop_acceptance_min_real_runs_not_met', $payload['remaining_blockers']);
    }

    /**
     * @return array<string,mixed>
     */
    private function runAcceptanceCommand(int $expectedExit, int $maxAgeHours = 168): array
    {
        $output = new BufferedOutput;
        $exit = Artisan::call('atlas:dev:desktop:acceptance', [
            '--json' => true,
            '--strict' => true,
            '--min-real-runs' => 1,
            '--max-age-hours' => $maxAgeHours,
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
    private function validEvidence(string $runId): array
    {
        return [
            'schema_version' => 'atlas.dev.desktop_acceptance_evidence.v1',
            'recorded_at' => now()->toISOString(),
            'source_command' => 'atlas:dev:desktop:real-smoke',
            'status' => 'passed',
            'run_id' => $runId,
            'external_provider_call' => true,
            'provider' => 'claude_cli',
            'model_family' => 'sonnet',
            'completion_state' => 'passed',
            'scope_guard_status' => 'passed',
            'verification_status' => 'passed',
            'patch_apply_status' => 'applied',
            'receipt_hash' => str_repeat('a', 64),
            'changed_files' => ['src/SmokeSubject.php'],
            'tests_count' => 1,
            'honesty_flags' => [],
            'workspace_assertion_passed' => true,
            'operator_confirmation' => [
                'token_issued' => true,
                'token_consumed' => true,
                'compact_sdd_hash_pinned' => true,
            ],
            'reason' => null,
        ];
    }

    /**
     * @param  array<string,mixed>  $evidence
     */
    private function writeEvidence(string $runId, array $evidence): void
    {
        File::ensureDirectoryExists($this->receiptsPath.'/desktop_acceptance');
        File::ensureDirectoryExists($this->receiptsPath.'/'.$runId);
        File::put($this->receiptsPath.'/'.$runId.'/verification_receipt.json', "{}\n");
        File::put(
            $this->receiptsPath.'/desktop_acceptance/'.$runId.'.json',
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
        File::put(
            $this->receiptsPath.'/desktop_acceptance/latest.json',
            json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );
    }
}
