<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Company\Ventures\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureDocumentationArtifact;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\Company\Ventures\Comprehension\Capabilities\VentureDocumentationGeneratorService;
use App\Services\Ai\Company\Ventures\Comprehension\ComprehensionRecorder;
use App\Services\Ai\Company\Ventures\Comprehension\WorkspaceReader;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\TestCase;

class VentureDocumentationGeneratorTest extends TestCase
{
    use CreatesVentureComprehensionTables;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        parent::tearDown();
    }

    private function makeRun(): AiVentureComprehensionRun
    {
        return AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => (string) Str::uuid(),
            'workspace_path' => $this->ws,
            'status' => 'running',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => (string) Str::uuid()]),
        ]);
    }

    private function service(): VentureDocumentationGeneratorService
    {
        return new VentureDocumentationGeneratorService(new ComprehensionRecorder);
    }

    /**
     * Seed one finding per capability so the doc has every summary to render.
     */
    private function seedFindings(AiVentureComprehensionRun $run): void
    {
        AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'capability' => AiVentureComprehensionFinding::CAPABILITY_BUSINESS_RULE,
            'kind' => 'pricing',
            'category' => 'monetization',
            'title' => 'Minimum plan price is 149.90 BRL',
            'confidence' => 0.92,
            'evidence_kind' => AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
            'evidence_path' => 'config/plans.php',
            'evidence_line' => 3,
            'evidence_snippet' => "'raso' => ['price' => 149.90, 'currency' => 'BRL'],",
            'source' => 'deterministic',
            'finding_hash' => StrategyCanonicalHash::sha256(['k' => 'br1']),
        ]);

        AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'capability' => AiVentureComprehensionFinding::CAPABILITY_PROBLEM,
            'kind' => 'secret',
            'category' => 'security',
            'title' => 'Hardcoded Stripe live secret in source',
            'severity' => 'critical',
            'confidence' => 0.99,
            'evidence_kind' => AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
            'evidence_path' => 'app/Http/Controllers/BillingController.php',
            'evidence_line' => 11,
            'evidence_snippet' => 'sk_live_HARDCODED_SHOULD_NOT_BE_HERE',
            'source' => 'deterministic',
            'finding_hash' => StrategyCanonicalHash::sha256(['k' => 'pr1']),
        ]);

        AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'capability' => AiVentureComprehensionFinding::CAPABILITY_AUDIENCE_USAGE,
            'kind' => 'locale',
            'category' => 'i18n',
            'title' => 'Ships pt-BR, en and es locales',
            'confidence' => 0.85,
            'evidence_kind' => AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
            'evidence_path' => 'src/i18n/locales/pt-BR.json',
            'evidence_line' => 1,
            'source' => 'deterministic',
            'finding_hash' => StrategyCanonicalHash::sha256(['k' => 'au1']),
        ]);

        AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $run->venture_id,
            'capability' => AiVentureComprehensionFinding::CAPABILITY_IMPROVEMENT,
            'kind' => 'config',
            'category' => 'maintainability',
            'title' => 'Make commission rate configurable',
            'leverage_score' => 7.5,
            'confidence' => 0.7,
            'evidence_kind' => AiVentureComprehensionFinding::EVIDENCE_OBSERVED,
            'evidence_path' => 'app/Services/CommissionService.php',
            'evidence_line' => 4,
            'evidence_snippet' => 'const RATE = 0.30;',
            'source' => 'deterministic',
            'finding_hash' => StrategyCanonicalHash::sha256(['k' => 'im1']),
        ]);
    }

    public function test_capability_id(): void
    {
        $this->assertSame('documentation', $this->service()->capability());
    }

    public function test_scan_persists_company_canonical_artifact(): void
    {
        $run = $this->makeRun();
        $this->seedFindings($run);
        $reader = new WorkspaceReader($this->ws);

        $report = $this->service()->scan($run, $reader);

        $this->assertSame('documentation', $report['capability']);
        $this->assertTrue($report['has_business_rules']);
        $this->assertTrue($report['has_problems']);
        $this->assertTrue($report['has_audience']);

        $artifact = AiVentureDocumentationArtifact::query()->where('run_id', $run->id)->first();
        $this->assertNotNull($artifact, 'an artifact row must be persisted');
        $this->assertSame('company_canonical', $artifact->doc_kind);
        $this->assertSame('generated', $artifact->status);
        $this->assertFalse((bool) $artifact->written_to_disk, 'scan must never write to the target repo');
        $this->assertNotEmpty($artifact->content);
        $this->assertSame(64, strlen((string) $artifact->content_hash));
        $this->assertGreaterThan(0, (int) $artifact->line_count);
        $this->assertStringContainsString('docs/ventures/', (string) $artifact->relative_path);
    }

    public function test_content_carries_cartography_frontmatter(): void
    {
        $run = $this->makeRun();
        $this->seedFindings($run);
        $reader = new WorkspaceReader($this->ws);

        $this->service()->scan($run, $reader);
        $artifact = AiVentureDocumentationArtifact::query()->where('run_id', $run->id)->firstOrFail();
        $content = (string) $artifact->content;

        foreach (['human_name', 'canonical_name', 'technical_name', 'cartography_type', 'canonical_source', 'graph_parent'] as $key) {
            $this->assertStringContainsString($key.':', $content, "frontmatter must declare {$key}");
        }
        $this->assertStringContainsString('status: draft', $content);
        $this->assertStringContainsString('graph_parent: '.VentureDocumentationGeneratorService::GRAPH_PARENT, $content);
        $this->assertStringContainsString('doc_schema: atlas_canonical_module_doc.v1', $content);
    }

    public function test_sections_include_findings_summaries_and_cited_evidence(): void
    {
        $run = $this->makeRun();
        $this->seedFindings($run);
        $reader = new WorkspaceReader($this->ws);

        $report = $this->service()->scan($run, $reader);
        $this->assertContains('business_rules', $report['sections']);
        $this->assertContains('problems', $report['sections']);
        $this->assertContains('audience', $report['sections']);

        $artifact = AiVentureDocumentationArtifact::query()->where('run_id', $run->id)->firstOrFail();
        $content = (string) $artifact->content;

        // Headings present.
        $this->assertStringContainsString('## Business Rules', $content);
        $this->assertStringContainsString('## Problems', $content);
        $this->assertStringContainsString('## Audience & Usage', $content);

        // Criticals listed + real citation threaded through (cite or omit).
        $this->assertStringContainsString('### Critical', $content);
        $this->assertStringContainsString('Hardcoded Stripe live secret in source', $content);
        $this->assertStringContainsString('`app/Http/Controllers/BillingController.php`:11', $content);

        // Manifest-derived integrations surfaced.
        $this->assertStringContainsString('Payments (Stripe)', $content);

        // Persisted sections match.
        $this->assertSame($report['sections'], $artifact->sections);
    }

    public function test_write_to_disk_produces_a_real_file(): void
    {
        $run = $this->makeRun();
        $this->seedFindings($run);
        $reader = new WorkspaceReader($this->ws);
        $service = $this->service();

        $service->scan($run, $reader);
        $artifact = AiVentureDocumentationArtifact::query()->where('run_id', $run->id)->firstOrFail();

        $base = sys_get_temp_dir().'/venture_doc_out_'.bin2hex(random_bytes(6));
        try {
            $written = $service->writeToDisk($artifact, $base);

            $this->assertFileExists($written);
            $this->assertStringStartsWith($base, $written);
            $this->assertStringEndsWith('company-canonical.md', $written);
            $this->assertSame((string) $artifact->content, File::get($written));

            $artifact->refresh();
            $this->assertTrue((bool) $artifact->written_to_disk, 'writeToDisk must flip the flag');
        } finally {
            File::deleteDirectory($base);
        }
    }
}
