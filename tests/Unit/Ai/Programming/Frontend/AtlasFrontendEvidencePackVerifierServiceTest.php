<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendEvidencePackVerifierServiceTest extends TestCase
{
    public function test_verifier_passes_pack_with_matching_hashes(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-pass-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/artifacts');
        $service = app(AtlasFrontendEvidencePackVerifierService::class);
        $artifacts = [];

        foreach ($service->requiredArtifactKinds() as $kind) {
            $path = 'artifacts/'.$kind.'.json';
            File::put($dir.'/'.$path, json_encode(['kind' => $kind, 'ok' => true], JSON_THROW_ON_ERROR));
            $artifacts[] = [
                'kind' => $kind,
                'path' => $path,
                'sha256' => hash_file('sha256', $dir.'/'.$path),
            ];
        }

        $manifest = $dir.'/evidence-pack.json';
        File::put($manifest, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'run-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => hash('sha256', 'task'),
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->verify($manifest, $dir);

        $this->assertSame('atlas.frontend.evidence_pack_verifier.v1', $payload['schema_version']);
        $this->assertSame('passed', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.passed_pack_can_support_replay_manifest'));
        $this->assertCount(count($service->requiredArtifactKinds()), $payload['artifact_results']);
        $this->assertContains('browser_detector_event', collect($payload['artifact_results'])->pluck('kind')->all());
        $this->assertContains('design_system_drift_report', collect($payload['artifact_results'])->pluck('kind')->all());
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['verification_hash']);
    }

    public function test_verifier_blocks_missing_artifact_and_hash_mismatch(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-block-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/artifacts');
        $manifest = $dir.'/evidence-pack.json';

        File::put($dir.'/artifacts/output_artifact.json', '{}');
        File::put($manifest, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'run-2',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => hash('sha256', 'task'),
            'artifacts' => [
                [
                    'kind' => 'output_artifact',
                    'path' => 'artifacts/output_artifact.json',
                    'sha256' => str_repeat('a', 64),
                ],
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendEvidencePackVerifierService::class)->verify($manifest, $dir);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('artifact_hash_mismatch', data_get($payload, 'artifact_results.0.blockers'));
        $this->assertContains('missing_artifact_kind_receipt', $payload['blockers']);
    }

    public function test_verifier_blocks_raw_prompt_or_source_fields(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $manifest = $dir.'/evidence-pack.json';

        File::put($manifest, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'run-3',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => hash('sha256', 'task'),
            'raw_prompt' => 'secret prompt',
            'artifacts' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendEvidencePackVerifierService::class)->verify($manifest, $dir);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('forbidden_raw_prompt_or_source_field_present', $payload['blockers']);
    }

    public function test_verifier_blocks_nested_raw_prompt_or_source_fields_in_manifest(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-nested-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir);
        $manifest = $dir.'/evidence-pack.json';

        File::put($manifest, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'run-4',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => hash('sha256', 'task'),
            'debug' => [
                'metadata' => [
                    'customer_source' => 'secret source excerpt',
                ],
            ],
            'artifacts' => [],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = app(AtlasFrontendEvidencePackVerifierService::class)->verify($manifest, $dir);

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('forbidden_raw_prompt_or_source_field_present', $payload['blockers']);
    }

    public function test_verifier_blocks_raw_prompt_or_source_fields_inside_artifact_json(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-artifact-raw-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/artifacts');
        $service = app(AtlasFrontendEvidencePackVerifierService::class);
        $artifacts = [];

        foreach ($service->requiredArtifactKinds() as $kind) {
            $path = 'artifacts/'.$kind.'.json';
            File::put($dir.'/'.$path, json_encode([
                'kind' => $kind,
                'debug' => $kind === 'receipt' ? ['raw_prompt' => 'secret prompt'] : [],
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);
            $artifacts[] = [
                'kind' => $kind,
                'path' => $path,
                'sha256' => hash_file('sha256', $dir.'/'.$path),
            ];
        }

        $manifest = $dir.'/evidence-pack.json';
        File::put($manifest, json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'run-5',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => hash('sha256', 'task'),
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL);

        $payload = $service->verify($manifest, $dir);
        $receipt = collect($payload['artifact_results'])->firstWhere('kind', 'receipt');

        $this->assertSame('blocked', $payload['status']);
        $this->assertContains('artifact_forbidden_raw_prompt_or_source_field_present', $payload['blockers']);
        $this->assertContains('artifact_forbidden_raw_prompt_or_source_field_present', $receipt['blockers']);
    }

    public function test_template_writes_pack_manifest_skeleton(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-evidence-template-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendEvidencePackVerifierService::class)->writeTemplate($dir);

        $this->assertSame('atlas.frontend.evidence_pack_template.v1', $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue(File::isFile($dir.'/evidence-pack.json'));
        $this->assertContains('screenshot_set', $payload['required_artifact_kinds']);
        $this->assertContains('quality_budget_report', $payload['required_artifact_kinds']);
        $this->assertContains('browser_detector_event', $payload['required_artifact_kinds']);
        $this->assertContains('design_system_drift_report', $payload['required_artifact_kinds']);
    }
}
