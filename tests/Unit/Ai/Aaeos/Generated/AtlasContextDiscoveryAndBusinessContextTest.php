<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasContextDiscoveryAndBusinessContextService;
use Tests\TestCase;

final class AtlasContextDiscoveryAndBusinessContextTest extends TestCase
{
    private AtlasContextDiscoveryAndBusinessContextService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasContextDiscoveryAndBusinessContextService();
    }

    public function testInputsChecklistMatchesTheDocumentedEleven(): void
    {
        // Doc "Inputs" -> "Atlas should discover": exactly eleven sources.
        $inputs = $this->service->requiredInputs();
        $this->assertSame(11, $inputs['count']);

        $keys = array_column($inputs['inputs'], 'key');
        $this->assertContains('repo_branch_active_files', $keys);
        $this->assertContains('database_schema_and_migrations', $keys);
        $this->assertContains('prior_specs_receipts_evidence', $keys);
        // AGENTS/CLAUDE is explicitly auxiliary-only in the doc.
        $this->assertContains('agents_claude_projections', $keys);
    }

    public function testBusinessQuestionsAreTheSevenPreSpecQuestions(): void
    {
        // Doc "Business Questions" -> "Before spec": seven questions.
        $bq = $this->service->businessQuestions();
        $this->assertSame(7, $bq['count']);
        // The three load-bearing ones (business object, action, governing rule)
        // are the required set the gate keys on.
        $this->assertSame(3, $bq['required_count']);
        $this->assertSame(
            ['business_object', 'action_requested', 'governing_rule'],
            $this->service->defaultRequiredFields(),
        );
    }

    public function testConfidenceClassesAndExecutionGrade(): void
    {
        // Doc "Confidence Classes": four classes; only confirmed/strong are
        // execution-grade, only blocking_ambiguity blocks.
        $classes = $this->service->confidenceClasses();
        $this->assertSame(4, $classes['count']);
        // Ranked highest-confidence first.
        $this->assertSame('confirmed_fact', $classes['classes'][0]['class']);

        $this->assertTrue($this->service->isExecutionGrade('confirmed_fact'));
        $this->assertTrue($this->service->isExecutionGrade('strong_inference'));
        $this->assertFalse($this->service->isExecutionGrade('hypothesis'));
        $this->assertFalse($this->service->isExecutionGrade('blocking_ambiguity'));

        // Unknown labels are treated as the safest interpretation: blocking.
        $unknown = $this->service->classifyItem('totally-made-up');
        $this->assertFalse($unknown['known']);
        $this->assertSame('blocking_ambiguity', $unknown['class']);
        $this->assertTrue($unknown['blocking']);
    }

    public function testGateExecutesOnlyWhenAllFourGuardsHold(): void
    {
        // Doc rule: execute without asking only when required fields are
        // confirmed/strongly inferred AND risk is low/medium WITH gates.
        $ready = $this->service->evaluateReadiness(
            ['business_object' => 'confirmed_fact', 'action_requested' => 'strong_inference', 'governing_rule' => 'confirmed_fact'],
            'low',
            true,
        );
        $this->assertSame(AtlasContextDiscoveryAndBusinessContextService::VERDICT_EXECUTE, $ready['verdict']);
        $this->assertTrue($ready['may_execute_without_asking']);
        $this->assertSame([], $ready['blocking_fields']);

        // medium risk + gates is also executable.
        $mediumOk = $this->service->evaluateReadiness(
            ['business_object' => 'confirmed_fact', 'action_requested' => 'confirmed_fact', 'governing_rule' => 'confirmed_fact'],
            'medium',
            true,
        );
        $this->assertTrue($mediumOk['may_execute_without_asking']);
    }

    public function testEachGuardIndependentlyForcesClarify(): void
    {
        $base = ['business_object' => 'confirmed_fact', 'action_requested' => 'confirmed_fact', 'governing_rule' => 'confirmed_fact'];

        // 1. High risk -> clarify even with everything else green.
        $highRisk = $this->service->evaluateReadiness($base, 'high', true);
        $this->assertSame(AtlasContextDiscoveryAndBusinessContextService::VERDICT_CLARIFY, $highRisk['verdict']);
        $this->assertFalse($highRisk['risk_executable']);

        // 2. Gates unavailable -> clarify.
        $noGates = $this->service->evaluateReadiness($base, 'low', false);
        $this->assertFalse($noGates['may_execute_without_asking']);
        $this->assertContains('quality gates are not available', $noGates['reasons']);

        // 3. A blocking_ambiguity on a required field -> clarify, field listed.
        $blocked = $this->service->evaluateReadiness(
            ['business_object' => 'confirmed_fact', 'action_requested' => 'blocking_ambiguity', 'governing_rule' => 'confirmed_fact'],
            'low',
            true,
        );
        $this->assertFalse($blocked['may_execute_without_asking']);
        $this->assertSame(['action_requested'], $blocked['blocking_fields']);

        // 4. A mere hypothesis (non-blocking but not execution-grade) -> clarify.
        $weak = $this->service->evaluateReadiness(
            ['business_object' => 'confirmed_fact', 'action_requested' => 'hypothesis', 'governing_rule' => 'confirmed_fact'],
            'low',
            true,
        );
        $this->assertFalse($weak['may_execute_without_asking']);
        $this->assertContains('action_requested', $weak['unconfirmed_fields']);
    }

    public function testMissingRequiredFieldIsTreatedAsBlockingNotAssumedSafe(): void
    {
        // Only two of three required fields supplied; the absent one must NOT be
        // assumed safe -> it counts as blocking_ambiguity and forces clarify.
        $partial = $this->service->evaluateReadiness(
            ['business_object' => 'confirmed_fact', 'action_requested' => 'confirmed_fact'],
            'low',
            true,
        );
        $this->assertFalse($partial['may_execute_without_asking']);
        $this->assertContains('governing_rule', $partial['blocking_fields']);
    }
}
