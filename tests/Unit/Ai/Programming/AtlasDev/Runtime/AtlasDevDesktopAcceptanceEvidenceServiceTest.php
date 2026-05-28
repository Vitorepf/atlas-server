<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Runtime;

use App\Services\Ai\Programming\AtlasDev\Runtime\AtlasDevDesktopAcceptanceEvidenceService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDevDesktopAcceptanceEvidenceServiceTest extends TestCase
{
    private string $receiptsPath;

    private AtlasDevDesktopAcceptanceEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->receiptsPath = sys_get_temp_dir().'/atlas-dev-acceptance-unit-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($this->receiptsPath);
        config()->set('atlas_dev.receipts_path', $this->receiptsPath);
        $this->service = new AtlasDevDesktopAcceptanceEvidenceService;
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->receiptsPath);

        parent::tearDown();
    }

    public function test_inspect_blocks_when_no_real_smoke_evidence_exists(): void
    {
        $payload = $this->service->inspect();

        $this->assertSame(AtlasDevDesktopAcceptanceEvidenceService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['external_provider_call']);
        $this->assertContains('desktop_acceptance_evidence_missing', $payload['remaining_blockers']);
        $this->assertContains('desktop_acceptance_latest_missing', $payload['remaining_blockers']);
        $this->assertSame(0, $payload['summary']['records_found']);
    }

    public function test_inspect_passes_with_valid_recent_real_smoke_evidence(): void
    {
        $this->writeEvidence('dev-valid-1', $this->validEvidence('dev-valid-1'));

        $payload = $this->service->inspect();

        $this->assertSame('passed', $payload['status']);
        $this->assertSame([], $payload['remaining_blockers']);
        $this->assertSame(1, $payload['summary']['records_found']);
        $this->assertSame(1, $payload['summary']['passed_records']);
        $this->assertSame('dev-valid-1', $payload['summary']['latest_run_id']);
        $this->assertSame('desktop_acceptance/dev-valid-1.json', $payload['records'][0]['ref']);
    }

    public function test_inspect_blocks_when_latest_json_run_id_has_no_matching_record(): void
    {
        $this->writeEvidence('dev-valid-1', $this->validEvidence('dev-valid-1'));
        File::put(
            $this->receiptsPath.'/desktop_acceptance/latest.json',
            json_encode(['run_id' => 'dev-missing'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
        );

        $payload = $this->service->inspect();

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('desktop_acceptance_latest_not_passed', $payload['remaining_blockers']);
        $this->assertContains('desktop_acceptance_latest_run_not_found', $payload['remaining_blockers']);
        $this->assertSame('dev-missing', $payload['summary']['latest_run_id']);
    }

    public function test_inspect_clamps_min_real_runs_and_max_age_hours_to_at_least_one(): void
    {
        $this->writeEvidence('dev-valid-1', $this->validEvidence('dev-valid-1'));

        $payload = $this->service->inspect(minRealRuns: 0, maxAgeHours: -5);

        $this->assertSame(1, $payload['requirements']['min_real_runs']);
        $this->assertSame(1, $payload['requirements']['max_age_hours']);
        $this->assertSame('passed', $payload['status']);
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
