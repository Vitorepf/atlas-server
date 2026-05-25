<?php

namespace Tests\Unit\Ai\Programming\Frontend;

use App\Services\Ai\Programming\Frontend\AtlasFrontendDesignDossierService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AtlasFrontendDesignDossierServiceTest extends TestCase
{
    public function test_ready_dossier_can_drive_company_frontend(): void
    {
        $workspace = $this->workspace();
        $service = app(AtlasFrontendDesignDossierService::class);
        foreach ($service->requiredDocuments() as $definition) {
            File::ensureDirectoryExists(dirname($workspace.'/'.$definition['path']));
            File::put($workspace.'/'.$definition['path'], $this->filledDocument((string) $definition['title'], (array) $definition['sections']));
        }

        $payload = $service->inspect($workspace);

        $this->assertSame(AtlasFrontendDesignDossierService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertTrue((bool) data_get($payload, 'readiness.can_drive_company_frontend'));
        $this->assertTrue((bool) data_get($payload, 'claim_policy.local_company_repo_is_default_operating_mode'));
        $this->assertFalse((bool) data_get($payload, 'claim_policy.raw_customer_source_returned'));
        $this->assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/', (string) $payload['dossier_hash']);
    }

    public function test_missing_docs_are_partial_and_template_does_not_count_as_context(): void
    {
        $workspace = $this->workspace();
        $service = app(AtlasFrontendDesignDossierService::class);

        $missing = $service->inspect($workspace);
        $template = $service->writeTemplate($workspace);
        $afterTemplate = $service->inspect($workspace);

        $this->assertSame('partial', $missing['status']);
        $this->assertContains('missing_design_doc_product_experience_brief', $missing['blockers']);
        $this->assertSame(AtlasFrontendDesignDossierService::TEMPLATE_SCHEMA_VERSION, $template['schema_version']);
        $this->assertTrue((bool) data_get($template, 'claim_policy.template_is_not_design_context_until_filled'));
        $this->assertTrue(File::isFile($workspace.'/docs/design/product-experience-brief.md'));
        $this->assertSame('partial', $afterTemplate['status']);
        $this->assertContains('template_placeholder_still_present_product_experience_brief', $afterTemplate['warnings']);
    }

    public function test_raw_prompt_or_secret_blocks_dossier(): void
    {
        $workspace = $this->workspace();
        $service = app(AtlasFrontendDesignDossierService::class);
        $service->writeTemplate($workspace);
        File::put($workspace.'/docs/design/brand-system.md', "raw_prompt: secret provider instruction\n");

        $payload = $service->inspect($workspace);

        $this->assertSame('partial', $payload['status']);
        $this->assertContains('forbidden_raw_prompt_or_secret_in_brand_system', $payload['blockers']);
    }

    private function workspace(): string
    {
        $workspace = sys_get_temp_dir().'/atlas-frontend-design-dossier-'.bin2hex(random_bytes(4));
        File::ensureDirectoryExists($workspace);

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
            $lines[] = 'This section contains approved company design context, product intent, constraints, examples, and measurable acceptance signals for Atlas Frontend execution.';
            $lines[] = '';
        }

        return implode(PHP_EOL, $lines);
    }
}
