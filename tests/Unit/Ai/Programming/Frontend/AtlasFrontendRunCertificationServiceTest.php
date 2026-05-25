<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendRunCertificationService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendVisualQualityGateService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendRunCertificationServiceTest extends TestCase
{
    public function test_certifies_run_with_real_artifacts(): void
    {
        $dir = $this->fixtureDir();
        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle($bundle);
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'bundle' => $bundle,
            'outcome_store' => $outcomeStore,
        ]);

        $this->assertSame(AtlasFrontendRunCertificationService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('certified', $payload['status']);
        $this->assertSame(str_repeat('a', 64), $payload['task_spec_hash']);
        $this->assertSame('pass', collect($payload['checks'])->firstWhere('id', 'task_spec_hash_consistent')['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['run_certification_hash']);
    }

    public function test_blocks_missing_evidence(): void
    {
        $payload = app(AtlasFrontendRunCertificationService::class)->certify([]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertContains('visual_quality_passed', $payload['blockers']);
        $this->assertContains('design_5d_review_passed', $payload['blockers']);
        $this->assertContains('evidence_pack_passed', $payload['blockers']);
    }

    public function test_blocks_mismatched_task_spec_hash_across_evidence_chain(): void
    {
        $dir = $this->fixtureDir();
        $manifestPath = $dir.'/evidence/evidence-pack.json';
        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifest['task_spec_hash'] = str_repeat('c', 64);
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'evidence_manifest' => $manifestPath,
            'evidence_root' => $dir.'/evidence',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertNull($payload['task_spec_hash']);
        $this->assertContains('task_spec_hash_consistent', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'task_spec_hash_consistent')['status']);
    }

    public function test_blocks_completion_claim_without_outcome_memory(): void
    {
        $dir = $this->fixtureDir();

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_requires_outcome_memory'));
        $this->assertContains('outcome_memory_available', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'outcome_memory_available')['status']);
    }

    private function fixtureDir(): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-run-cert-'.bin2hex(random_bytes(4));
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
            'pack_id' => 'run-cert-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return $dir;
    }
}
