<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use App\Services\Ai\Programming\Frontend\AtlasFrontendProductBlueprintService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendProductBlueprintServiceTest extends TestCase
{
    public function test_company_product_blueprint_maps_ux_screens_quality_and_evidence(): void
    {
        $workspace = $this->workspaceWithDossier();

        $payload = app(AtlasFrontendProductBlueprintService::class)->generate([
            'task' => 'Criar novo SaaS dashboard premium com onboarding e settings',
            'workspace' => $workspace,
        ]);

        $this->assertSame(AtlasFrontendProductBlueprintService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('company_product_frontend_success_blueprint', $payload['blueprint_type']);
        $this->assertSame('saas', data_get($payload, 'product_model.product_type'));
        $this->assertContains('task_completion', data_get($payload, 'product_model.success_metric_families'));
        $this->assertContains('visual_quality_report', $payload['evidence_map']);
        $this->assertContains('quality_budget_report', $payload['evidence_map']);
        $this->assertContains('/dashboard', collect($payload['screen_blueprint'])->pluck('route')->all());
        $this->assertTrue((bool) data_get($payload, 'visual_strategy.must_feed_design_review'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.world_best_claim_allowed'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['blueprint_hash']);
    }

    public function test_blueprint_warns_when_dossier_is_not_ready_and_blocks_missing_task(): void
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-blueprint-warning-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

        $warning = app(AtlasFrontendProductBlueprintService::class)->generate([
            'task' => 'Refinar front end premium',
            'workspace' => $workspace,
        ]);
        $blocked = app(AtlasFrontendProductBlueprintService::class)->generate([
            'workspace' => $workspace,
        ]);

        $this->assertSame('warning', $warning['status']);
        $this->assertContains('company_design_dossier_not_ready', $warning['warnings']);
        $this->assertSame('blocked', $blocked['status']);
        $this->assertContains('task_missing', $blocked['blockers']);
    }

    public function test_writes_blueprint_document_without_returning_raw_source(): void
    {
        $workspace = $this->workspaceWithDossier();

        $payload = app(AtlasFrontendProductBlueprintService::class)->writeDocument([
            'task' => 'Criar checkout ecommerce premium',
            'workspace' => $workspace,
        ]);

        $this->assertSame(AtlasFrontendProductBlueprintService::DOCUMENT_SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('written', $payload['status']);
        $this->assertTrue(File::isFile($workspace.'/docs/design/atlas-frontend-product-blueprint.json'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.raw_customer_source_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['document_hash']);
    }

    private function workspaceWithDossier(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-blueprint-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);
        $dossier = app(AtlasFrontendDesignDossierService::class);
        foreach ($dossier->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        return $workspace;
    }

    /**
     * @param  array<int,string>  $sections
     */
    private function filledDocument(string $title, array $sections): string
    {
        $lines = ['# '.$title, '', 'Status: canonical', ''];
        foreach ($sections as $section) {
            $lines[] = '## '.$section;
            $lines[] = 'Canonical company product, UX, brand and frontend quality guidance with measurable evidence requirements for premium Atlas Frontend execution.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
