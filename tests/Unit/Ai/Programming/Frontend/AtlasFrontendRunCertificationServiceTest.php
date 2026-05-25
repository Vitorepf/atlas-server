<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignReviewService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendEvidencePackVerifierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendOutcomeMemoryService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductProofRuntimeService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendQualityBudgetGateService;
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
            'quality_budget_report' => $dir.'/quality-budget-report.json',
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
        $this->assertContains('quality_budget_passed', $payload['blockers']);
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
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $manifestPath,
            'evidence_root' => $dir.'/evidence',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertNull($payload['task_spec_hash']);
        $this->assertContains('task_spec_hash_consistent', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'task_spec_hash_consistent')['status']);
    }

    public function test_certifies_run_when_frontend_app_scope_matches_across_evidence_chain(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'));
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
            'outcome_store' => $outcomeStore,
        ]);

        $this->assertSame('warning', $payload['status']);
        $this->assertSame('subscope_selected', data_get($payload, 'frontend_app_scope.status'));
        $this->assertSame('apps/web', data_get($payload, 'frontend_app_scope.relative_name'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.relative_name_hash'));
        $this->assertSame('pass', collect($payload['checks'])->firstWhere('id', 'frontend_app_scope_consistent')['status']);
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_requires_frontend_app_scope_consistency'));
    }

    public function test_blocks_mismatched_frontend_app_scope_across_evidence_chain(): void
    {
        $dir = $this->fixtureDir($this->frontendAppScope('apps/web'));
        $outcomeStore = $dir.'/outcomes.jsonl';
        app(AtlasFrontendOutcomeMemoryService::class)->record([
            'status' => 'passed',
            'gates' => ['visual_quality_gate', 'design_5d_review', 'evidence_pack_verifier'],
            'evidence_refs' => ['receipt://visual-quality'],
        ], $outcomeStore);

        $manifestPath = $dir.'/evidence/evidence-pack.json';
        $manifest = json_decode((string) File::get($manifestPath), true);
        $manifest['frontend_app_scope'] = $this->frontendAppScope('apps/admin');
        File::put($manifestPath, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $manifestPath,
            'evidence_root' => $dir.'/evidence',
            'outcome_store' => $outcomeStore,
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('mismatch', data_get($payload, 'frontend_app_scope.status'));
        $this->assertContains('frontend_app_scope_consistent', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'frontend_app_scope_consistent')['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertSame(hash('sha256', 'apps/web'), data_get($payload, 'frontend_app_scope.artifact_scopes.visual_quality.relative_name_hash'));
        $this->assertSame(hash('sha256', 'apps/admin'), data_get($payload, 'frontend_app_scope.artifact_scopes.evidence_pack.relative_name_hash'));
    }

    public function test_blocks_completion_claim_without_outcome_memory(): void
    {
        $dir = $this->fixtureDir();

        $payload = app(AtlasFrontendRunCertificationService::class)->certify([
            'visual_report' => $dir.'/visual-quality-report.json',
            'design_review_report' => $dir.'/design-review-report.json',
            'quality_budget_report' => $dir.'/quality-budget-report.json',
            'evidence_manifest' => $dir.'/evidence/evidence-pack.json',
            'evidence_root' => $dir.'/evidence',
        ]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse((bool) data_get($payload, 'claim_policy.frontend_completion_claim_allowed'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_requires_outcome_memory'));
        $this->assertContains('outcome_memory_available', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'outcome_memory_available')['status']);
    }

    public function test_blocks_completion_claim_without_quality_budget(): void
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
        $this->assertTrue((bool) data_get($payload, 'claim_policy.frontend_completion_claim_requires_quality_budget'));
        $this->assertContains('quality_budget_passed', $payload['blockers']);
        $this->assertSame('fail', collect($payload['checks'])->firstWhere('id', 'quality_budget_passed')['status']);
    }

    /**
     * @param  array<string,mixed>|null  $frontendAppScope
     */
    private function fixtureDir(?array $frontendAppScope = null): string
    {
        $dir = sys_get_temp_dir().'/atlas-frontend-run-cert-'.bin2hex(random_bytes(4));
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
            'pack_id' => 'run-cert-1',
            'case_id' => 'saas_dashboard_repair',
            'system' => 'atlas_frontend',
            'task_spec_hash' => $taskSpecHash,
            'artifacts' => $artifacts,
        ];
        if ($frontendAppScope !== null) {
            $evidencePack['frontend_app_scope'] = $frontendAppScope;
        }
        File::put($dir.'/evidence/evidence-pack.json', json_encode($evidencePack, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

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
