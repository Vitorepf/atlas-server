<?php

namespace Tests\Feature\Ai\VentureFoundry\Assessment;

use App\Models\AiVenture;
use App\Models\AiVentureAssessmentRun;
use App\Models\AiVentureComprehensionFinding;
use App\Models\AiVentureComprehensionRun;
use App\Models\AiVentureQuestionAnswer;
use App\Services\Ai\Strategy\StrategyCanonicalHash;
use App\Services\Ai\VentureFoundry\Assessment\VentureAssessmentService;
use App\Services\Ai\VentureFoundry\Assessment\VentureQuestionCatalog;
use App\Services\Ai\VentureFoundry\Comprehension\Capabilities\VentureBusinessRuleMinerService;
use App\Services\Ai\VentureFoundry\VentureGrowthLadderService;
use App\Services\Ai\VentureFoundry\VentureIdeationService;
use App\Services\Ai\VentureFoundry\VentureRegistryService;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\Concerns\CreatesVentureAssessmentTables;
use Tests\Concerns\CreatesVentureComprehensionTables;
use Tests\Concerns\CreatesVentureFoundryTables;
use Tests\TestCase;

class VentureAssessmentTest extends TestCase
{
    use CreatesStrategyRuntimeTables;
    use CreatesVentureAssessmentTables;
    use CreatesVentureComprehensionTables;
    use CreatesVentureFoundryTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
        $this->createVentureFoundryTables();
        $this->createVentureComprehensionTables();
        $this->createVentureAssessmentTables();
    }

    protected function tearDown(): void
    {
        $this->dropVentureAssessmentTables();
        $this->dropVentureComprehensionTables();
        $this->dropVentureFoundryTables();
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    private function makeVenture(): AiVenture
    {
        $idea = app(VentureIdeationService::class)->register([
            'title' => 'Empresa assessment',
            'problem' => 'Times perdem visibilidade de conversão',
            'icp' => 'Performance marketers',
            'pain' => 'Mídia paga queimada',
        ]);

        return app(VentureRegistryService::class)->promoteIdea($idea);
    }

    private function seedComprehension(AiVenture $venture): AiVentureComprehensionRun
    {
        $run = AiVentureComprehensionRun::query()->create([
            'uuid' => (string) Str::uuid(),
            'venture_id' => $venture->id,
            'workspace_path' => '/tmp/fake',
            'status' => 'completed',
            'run_hash' => StrategyCanonicalHash::sha256(['x' => (string) Str::uuid()]),
        ]);

        $this->finding($run, $venture, 'problem', 'secret', 'critical', 'Chave privada GCP commitada', 'app/key.json', 5);
        $this->finding($run, $venture, 'problem', 'test_gap', 'medium', 'Controller sem teste', 'app/X.php', null);
        $this->finding($run, $venture, 'business_rule', 'pricing', null, "Plano 'pro' custa 149.90 BRL", 'config/plans.php', 3, 'pricing');
        $this->finding($run, $venture, 'audience_usage', 'plan_tier', null, "Plano 'pro' (149.90 BRL)", 'config/plans.php', 3);
        $this->finding($run, $venture, 'audience_usage', 'integration', null, 'Stripe', 'composer.json', 10);
        $this->finding($run, $venture, 'improvement', 'perf', null, 'Eliminar N+1 em X', 'app/Y.php', 12, 'performance');

        return $run;
    }

    private function finding(AiVentureComprehensionRun $run, AiVenture $venture, string $cap, string $kind, ?string $sev, string $title, string $path, ?int $line, ?string $category = null): void
    {
        AiVentureComprehensionFinding::query()->create([
            'uuid' => (string) Str::uuid(),
            'run_id' => $run->id,
            'venture_id' => $venture->id,
            'capability' => $cap,
            'kind' => $kind,
            'category' => $category,
            'title' => $title,
            'severity' => $sev,
            'evidence_kind' => 'observed',
            'evidence_path' => $path,
            'evidence_line' => $line,
            'source' => 'deterministic',
            'finding_hash' => StrategyCanonicalHash::sha256(['t' => $title, 'r' => $run->id]),
        ]);
    }

    public function test_catalog_is_well_formed(): void
    {
        $catalog = new VentureQuestionCatalog;
        $all = $catalog->all();

        $this->assertGreaterThanOrEqual(30, count($all));
        $ids = array_column($all, 'id');
        $this->assertCount(count($ids), array_unique($ids), 'question ids must be unique');

        foreach ($all as $q) {
            foreach (['id', 'dimension', 'stages', 'severity_if_blind', 'question', 'why', 'data_kind', 'answer_source', 'decision_trigger'] as $key) {
                $this->assertArrayHasKey($key, $q, "question {$q['id']} missing {$key}");
            }
            $this->assertArrayHasKey($q['dimension'], VentureQuestionCatalog::DIMENSION_LABELS);
            if ($q['data_kind'] === AiVentureQuestionAnswer::DATA_EXTERNAL_REQUIRED) {
                $this->assertNotNull($q['external_source'], "external question {$q['id']} must name a source");
                $this->assertArrayHasKey($q['external_source'], VentureQuestionCatalog::EXTERNAL_SOURCES);
            }
        }
    }

    public function test_assess_answers_from_data_and_focuses_on_critical_risk(): void
    {
        $venture = $this->makeVenture();
        $this->seedComprehension($venture);
        app(VentureGrowthLadderService::class)->recordMetric($venture, ['metric_key' => 'arr_usd', 'value' => 50000]);

        $report = app(VentureAssessmentService::class)->run($venture);

        $this->assertSame('atlas.ai.venture.assessment_report.v1', $report['schema_version']);
        $this->assertGreaterThan(0, $report['questions_total']);

        // The critical leaked secret must drive the #1 focus (existential risk).
        $this->assertSame(VentureQuestionCatalog::DIM_RISK, $report['focus']['top_dimension']);
        $this->assertStringContainsStringIgnoringCase('risco', $report['focus']['headline']);

        // The risk question is answered from the real finding, cited.
        $risk = AiVentureQuestionAnswer::query()->where('question_id', 'Q-RSK-001')->firstOrFail();
        $this->assertSame('answered', $risk->status);
        $this->assertNotNull($risk->evidence_refs);

        // Pricing answered from the mined rule; monetization metric answered from arr.
        $pricing = AiVentureQuestionAnswer::query()->where('question_id', 'Q-MON-001')->firstOrFail();
        $this->assertSame('answered', $pricing->status);
        $this->assertStringContainsString('149.90', (string) $pricing->answer);

        // Focus questions were filled by the decider.
        $foc = AiVentureQuestionAnswer::query()->where('question_id', 'Q-FOC-001')->firstOrFail();
        $this->assertSame('answered', $foc->status);

        // External questions are honestly blocked and grouped into data gaps.
        $this->assertGreaterThan(0, count($report['data_gaps']));
        $this->assertLessThan(100.0, $report['data_readiness_pct']);
        $comp = AiVentureQuestionAnswer::query()->where('question_id', 'Q-COMP-001')->firstOrFail();
        $this->assertSame('blocked_external', $comp->status);
        $this->assertSame('competitor_intel', $comp->external_source);
    }

    public function test_assess_without_comprehension_blocks_internal_questions(): void
    {
        $venture = $this->makeVenture();

        app(VentureAssessmentService::class)->run($venture);

        $health = AiVentureQuestionAnswer::query()->where('question_id', 'Q-HLT-001')->firstOrFail();
        $this->assertSame('blocked_internal', $health->status);
        $this->assertStringContainsStringIgnoringCase('comprehend', (string) $health->recommendation);
    }

    public function test_command_surface_assess_questions_focus_readiness(): void
    {
        $venture = $this->makeVenture();
        $this->seedComprehension($venture);

        $this->assertSame(0, $this->artisan('atlas:venture', ['action' => 'assess', '--venture' => $venture->venture_id, '--json' => true])->run());
        $this->assertSame(1, AiVentureAssessmentRun::query()->count());

        foreach (['assessment-report', 'questions', 'focus', 'data-readiness'] as $action) {
            $this->assertSame(0, $this->artisan('atlas:venture', ['action' => $action, '--venture' => $venture->venture_id, '--json' => true])->run(), "action {$action} must succeed");
        }

        $this->assertSame(0, $this->artisan('atlas:venture', ['action' => 'question-catalog', '--json' => true])->run());
    }
}
