<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDeliveryHandoffService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendPublicationVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
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
        $this->assertSame('missing_report', data_get($payload, 'publication_attestation.status'));
        $this->assertTrue((bool) data_get($payload, 'publication_attestation.claim_policy.local_bundle_is_not_public_distribution'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['handoff_hash']);
    }

    public function test_handoff_attests_local_publication_report_without_public_distribution_claim(): void
    {
        $dir = $this->fixtureDir();

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $dir.'/evidence/evidence-pack.json',
            $dir.'/publication-report.json',
        );

        $this->assertSame('ready', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'delivery_claims.public_distribution'));
        $this->assertSame('local_bundle_ready_publication_pending', data_get($payload, 'publication_attestation.status'));
        $this->assertSame('local_ready', data_get($payload, 'publication_attestation.publication_report_status'));
        $this->assertContains('provide_operator_approved_publication_receipt', data_get($payload, 'publication_attestation.required_next_actions'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_publication_report_is_not_public_distribution'));
    }

    public function test_handoff_attests_verified_publication_without_returning_public_url(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'));
        $manifest = json_decode((string) File::get($dir.'/bundle/manifest.json'), true);
        $receiptPath = $dir.'/publication-receipt.json';
        File::put($receiptPath, json_encode([
            'schema_version' => AtlasFrontendPublicationVerifierService::RECEIPT_SCHEMA_VERSION,
            'status' => 'verified',
            'public_url' => 'https://example.com/atlas-frontend/apps-web/',
            'bundle_hash' => $manifest['bundle_hash'],
            'index_content_hash' => data_get($manifest, 'index.hash'),
            'local_index_hash' => data_get($manifest, 'index.hash'),
            'frontend_app_scope' => $this->frontendAppScope('apps/web'),
            'http_status' => 200,
            'checked_at' => '2026-05-25T12:00:00Z',
            'operator_approved' => true,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        $publication = app(AtlasFrontendPublicationVerifierService::class)->verify($dir.'/bundle', $receiptPath);
        File::put($dir.'/publication-report.json', json_encode($publication, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $dir.'/evidence/evidence-pack.json',
            $dir.'/publication-report.json',
        );

        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'delivery_claims.public_distribution'));
        $this->assertSame('public_verified', data_get($payload, 'publication_attestation.status'));
        $this->assertSame('verified', data_get($payload, 'publication_attestation.public_receipt_status'));
        $this->assertNull(data_get($payload, 'publication_attestation.public_url'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) data_get($payload, 'publication_attestation.public_url_hash'));
        $this->assertSame([], data_get($payload, 'publication_attestation.required_next_actions'));
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

    public function test_handoff_carries_matching_frontend_app_scope(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'));

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $dir.'/evidence/evidence-pack.json',
        );

        $this->assertSame('ready', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertTrue((bool) data_get($payload, 'frontend_app_scope.consistent'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.requires_matching_frontend_app_scope'));
    }

    public function test_blocks_handoff_when_evidence_manifest_frontend_app_scope_does_not_match_run(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'));
        $manifestPath = $dir.'/evidence/evidence-pack.json';
        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifest['frontend_app_scope'] = $this->frontendAppScope('apps/admin');
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $manifestPath,
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.customer_handoff_allowed'));
        $this->assertSame('mismatch', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('evidence_manifest_frontend_app_scope_mismatch', $payload['blockers']);
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.run_certification_scope.relative_name_hash'));
        $this->assertSame(hash('sha256', 'apps/admin'), data_get($payload, 'frontend_app_scope.evidence_manifest_scope.relative_name_hash'));
    }

    public function test_blocks_handoff_when_publication_report_frontend_app_scope_does_not_match_run(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'), 'apps/admin');

        $payload = app(AtlasFrontendDeliveryHandoffService::class)->compile(
            $dir.'/run-certification.json',
            $dir.'/evidence/evidence-pack.json',
            $dir.'/publication-report.json',
        );

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('publication_report', data_get($payload, 'frontend_app_scope.mismatch_source'));
        $this->assertContains('publication_report_frontend_app_scope_mismatch', $payload['blockers']);
        $this->assertFalse((bool) data_get($payload, 'delivery_claims.public_distribution'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.run_certification_scope.relative_name_hash'));
        $this->assertSame(hash('sha256', 'apps/admin'), data_get($payload, 'frontend_app_scope.publication_report_scope.relative_name_hash'));
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

    /**
     * @param  array<string,mixed>|null  $frontendAppScope
     */
    private function fixtureDir(?array $frontendAppScope = null, ?string $publicationFrontendApp = null): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-handoff-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($dir.'/evidence/artifacts');
        $taskSpecHash = str_repeat('a', 64);
        $visualGate = app(AtlasFrontendVisualQualityGateService::class);
        $reviewGate = app(AtlasFrontendDesignReviewService::class);
        $qualityBudgetGate = app(AtlasFrontendQualityBudgetGateService::class);
        $evidenceGate = app(AtlasFrontendEvidencePackVerifierService::class);

        $visualReport = [
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
        ];
        if ($frontendAppScope !== null) {
            $visualReport['frontend_app_scope'] = $frontendAppScope;
        }
        File::put($dir.'/visual-quality-report.json', json_encode($visualReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $designReviewReport = [
            'schema_version' => AtlasFrontendDesignReviewService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'dimensions' => collect($reviewGate->requiredDimensions())->mapWithKeys(fn (string $dimension): array => [
                $dimension => ['score' => 9, 'rationale' => 'Evidence-backed pass.', 'evidence_refs' => ['receipt://'.$dimension]],
            ])->all(),
            'evidence_refs' => ['receipt://visual-quality', 'receipt://anti-slop'],
        ];
        if ($frontendAppScope !== null) {
            $designReviewReport['frontend_app_scope'] = $frontendAppScope;
        }
        File::put($dir.'/design-review-report.json', json_encode($designReviewReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $qualityBudgetReport = [
            'schema_version' => AtlasFrontendQualityBudgetGateService::REPORT_SCHEMA_VERSION,
            'status' => 'passed',
            'task_spec_hash' => $taskSpecHash,
            'viewports' => $qualityBudgetGate->requiredViewports(),
            'metrics' => collect($qualityBudgetGate->budgets())->mapWithKeys(fn (array $budget, string $id): array => [
                $id => $budget['warning'],
            ])->all(),
            'operator_approved_exception' => false,
        ];
        if ($frontendAppScope !== null) {
            $qualityBudgetReport['frontend_app_scope'] = $frontendAppScope;
        }
        File::put($dir.'/quality-budget-report.json', json_encode($qualityBudgetReport, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $artifacts = [];
        foreach ($evidenceGate->requiredArtifactKinds() as $kind) {
            $path = 'artifacts/'.$kind.'.json';
            File::put($dir.'/evidence/'.$path, json_encode(['kind' => $kind, 'ok' => true], JSON_THROW_ON_ERROR));
            $artifacts[] = ['kind' => $kind, 'path' => $path, 'sha256' => hash_file('sha256', $dir.'/evidence/'.$path)];
        }
        $evidencePack = [
            'schema_version' => AtlasFrontendEvidencePackVerifierService::PACK_SCHEMA_VERSION,
            'pack_id' => 'handoff-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ];
        if ($frontendAppScope !== null) {
            $evidencePack['frontend_app_scope'] = $frontendAppScope;
        }
        File::put($dir.'/evidence/evidence-pack.json', json_encode($evidencePack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $bundle = $dir.'/bundle';
        app(AtlasFrontendProductProofRuntimeService::class)->buildStaticBundle(
            $bundle,
            $publicationFrontendApp ?? (is_string($frontendAppScope['relative_name'] ?? null) ? (string) $frontendAppScope['relative_name'] : null),
        );
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $run = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
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

    /**
     * @return array<string,mixed>
     */
    private function frontendAppScope(string $relativeName): array
    {
        return [
            'status' => 'subscope_selected',
            'relative_name' => $relativeName,
            'relative_name_hash' => hash('sha256', $relativeName),
            'repo_workspace_remains_primary' => true,
        ];
    }
}
