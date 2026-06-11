<?php

namespace Tests\Feature\Ai\VentureFoundry\Comprehension;

use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureDocumentationArtifact;
use App\Services\Ai\VentureFoundry\Comprehension\VentureComprehensionService;
use App\Services\Ai\VentureFoundry\VentureBusinessRuleService;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use App\Services\Ai\VentureFoundry\VentureRegistryService;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureComprehensionServiceTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureComprehensionTables;
    use CreatesVentureFoundryTables;

    private string $ws;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
        $this->createVentureComprehensionTables();
        $this->ws = $this->makeFakeWorkspace();
    }

    protected function tearDown(): void
    {
        $this->removeFakeWorkspace($this->ws);
        $this->dropVentureComprehensionTables();
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    private function makeVenture(): \App\Models\AiVenture
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa comprehension',
            'problem' => 'p', 'icp' => 'i', 'pain' => 'd',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    public function test_full_pass_runs_all_five_capabilities_and_consolidates(): void
    {
        $venture = $this->makeVenture();

        $report = app(VentureComprehensionService::class)->run($venture, [
            'workspace_path' => $this->ws,
            'promote_rules' => true,
        ]);

        $this->assertSame('atlas.ai.venture.comprehension_report.v1', $report['schema_version']);
        $this->assertSame(VentureComprehensionService::CAPABILITY_ORDER, $report['capabilities_run']);

        // Run row completed with consolidated counts.
        $run = AiVentureComprehensionRun::query()->latest('created_at')->firstOrFail();
        $this->assertSame('completed', $run->status);
        $this->assertGreaterThan(0, $run->findings_total);
        $this->assertNotNull($run->completed_at);

        // Each finding-producing capability persisted real findings.
        foreach (['business_rule', 'problem', 'improvement', 'audience_usage'] as $capability) {
            $this->assertGreaterThan(
                0,
                AiVentureComprehensionFinding::query()->where('run_id', $run->id)->where('capability', $capability)->count(),
                "capability {$capability} must persist findings",
            );
        }

        // Every finding with a code location carries a cited path.
        $uncited = AiVentureComprehensionFinding::query()
            ->where('run_id', $run->id)
            ->where('evidence_kind', 'observed')
            ->whereNull('evidence_path')
            ->count();
        $this->assertSame(0, $uncited, 'observed findings must cite a path');

        // Documentation artifact generated with cartography frontmatter.
        $doc = AiVentureDocumentationArtifact::query()->where('run_id', $run->id)->firstOrFail();
        $this->assertNotEmpty($doc->content);
        foreach (['human_name', 'canonical_name', 'technical_name', 'cartography_type', 'canonical_source', 'graph_parent'] as $field) {
            $this->assertStringContainsString($field, (string) $doc->content, "doc frontmatter must declare {$field}");
        }

        // Rule promotion fed the official venture canon.
        $this->assertGreaterThan(0, $report['rule_promotion']['promoted'] ?? 0, 'high-confidence mined rules must promote');
        $active = app(VentureBusinessRuleService::class)->activeRules($venture);
        $this->assertNotEmpty($active, 'promoted rules land in the official canon');
    }

    public function test_only_subset_runs_selected_capabilities(): void
    {
        $venture = $this->makeVenture();

        $report = app(VentureComprehensionService::class)->run($venture, [
            'workspace_path' => $this->ws,
            'only' => ['problem'],
        ]);

        $this->assertSame(['problem'], $report['capabilities_run']);
        $run = AiVentureComprehensionRun::query()->latest('created_at')->firstOrFail();
        $this->assertGreaterThan(0, AiVentureComprehensionFinding::query()->where('run_id', $run->id)->where('capability', 'problem')->count());
        $this->assertSame(0, AiVentureComprehensionFinding::query()->where('run_id', $run->id)->where('capability', 'business_rule')->count());
    }

    public function test_missing_workspace_path_throws(): void
    {
        $venture = $this->makeVenture();
        $this->expectException(\App\Services\Ai\VentureFoundry\Comprehension\ComprehensionException::class);
        app(VentureComprehensionService::class)->run($venture, ['workspace_path' => '']);
    }
}
