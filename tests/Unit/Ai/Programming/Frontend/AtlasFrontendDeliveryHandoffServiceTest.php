<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDeliveryHandoffServiceTest extends TestCase
{
    public function test_compiles_customer_safe_handoff_from_certified_run(): void
    {
        $dir = $this->fixtureDir();
        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $dir.'/evidence/evidence-pack.json',
        );

        $this->assertSame(AtlasFrontendDeliveryHandoffService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.customer_handoff_allowed'));
        $this->assertTrue((bool) data_get($payload, 'delivery_claims.frontend_completion'));
        $this->assertFalse((bool) data_get($payload, 'delivery_claims.world_best_frontend_system'));
        $this->assertContains('public_distribution_not_verified', $payload['known_limitations']);
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['handoff_hash']);
    }

    public function test_blocks_handoff_when_evidence_manifest_hash_does_not_match_run(): void
    {
        $dir = $this->fixtureDir();
        $manifestPath = $dir.'/evidence/evidence-pack.json';
        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifest['task_spec_hash'] = str_repeat('c', 64);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $manifestPath,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.customer_handoff_allowed'));
        $this->assertContains('evidence_manifest_task_spec_hash_mismatch', $payload['blockers']);
    }

    public function test_template_is_not_delivery_handoff(): void
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-handoff-template-'.bin2hex(random_bytes(4));

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->writeTemplate($dir);

        $this->assertSame(AtlasFrontendDeliveryHandoffService::TEMPLATE_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.template_is_not_delivery_handoff'));
        $this->assertFileExists($dir.'/frontend-delivery-handoff-inputs.json');
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-handoff-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/evidence/artifacts');
        $taskSpecHash = str_repeat('a', 64);
        $visualGate = app(AtlasFrontendVisualQualityGateService::class);
        $reviewGate = app(AtlasFrontendDesignReviewService::class);
        $evidenceGate = app(AtlasFrontendEvidencePackVerifierService::class);

        File::put($dir.'/visual-quality-report.json', json_encode([
            'schema_version' => AtlasFrontendVisualQualityGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'routes' => ['/', '/settings'],
            'viewports' => $visualGate->requiredViewports(),
            'checks' => array_fill_keys($visualGate->requiredChecks(), 'passed'),
            'artifacts' => array_map(fn (string $kind): array => [
                'kind' => $kind,
                'path' => 'artifacts/'.$kind.'.json',
                'sha256' => str_repeat('b', 64),
            ], $visualGate->requiredArtifactKinds()),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        File::put($dir.'/design-review-report.json', json_encode([
            'schema_version' => AtlasFrontendDesignReviewService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'dimensions' => collect($reviewGate->requiredDimensions())->mapWithKeys(fn (string $dimension): array => [
                $dimension => ['score' => 9, 'rationale' => 'Evidence-backed pass.', 'evidence_refs' => ['receipt://'.$dimension]],
            ])->all(),
            'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $artifacts = [];
        foreach ($evidenceGate->requiredArtifactKinds() as $kind) {
            $path = 'artifacts/'.$kind.'.json';
            File::put($dir.'/evidence/'.$path, json_encode(['kind' => $kind, 'ok' => true], JSON_THROW_ON_ERROR));
            $artifacts[] = ['kind' => $kind, 'path' => $path, 'sha256' => hash_file('sha256', $dir.'/evidence/'.$path)];
        }
        File::put($dir.'/evidence/evidence-pack.json', json_encode([
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'handoff-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $run = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'bundle' => $bundle,
            'outcome_store' => $outcomeStore,
        ]);
        File::put($dir.'/run-certification.json', json_encode($run, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $publication = app(AtlasFrontendPublicationVerifierService::class)->verify($bundle);
        File::put($dir.'/publication-report.json', json_encode($publication, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }
}
