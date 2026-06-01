<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSddTemplatesAndSchemasService;
use Tests\TestCase;

final class AtlasSddTemplatesAndSchemasTest extends TestCase
{
    private AtlasSddTemplatesAndSchemasService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSddTemplatesAndSchemasService();
    }

    public function testFullySpecifiedSpecWithLegalEnumsIsValid(): void
    {
        $spec = [
            'spec' => ['id' => 'S1', 'title' => 't', 'status' => 'approved', 'type' => 'feature', 'risk' => 'low'],
            'intent' => [],
            'context' => [],
            'business' => [],
            'requirements' => [],
            'acceptance_criteria' => [],
            'technical_constraints' => [],
            'assumptions' => [],
            'test_strategy' => [],
        ];

        $result = $this->service->validateSpec($spec);

        $this->assertTrue($result['valid']);
        $this->assertSame([], $result['missing_sections']);
        $this->assertSame([], $result['enum_violations']);
        // The doc declares exactly nine required top-level sections.
        $this->assertSame(9, $result['required_section_count']);
    }

    public function testMissingSectionsAreReportedAndSpecIsInvalid(): void
    {
        // Only two of the nine documented sections present.
        $result = $this->service->validateSpec([
            'spec' => ['status' => 'draft'],
            'intent' => [],
        ]);

        $this->assertFalse($result['valid']);
        $this->assertContains('requirements', $result['missing_sections']);
        $this->assertContains('acceptance_criteria', $result['missing_sections']);
        $this->assertContains('test_strategy', $result['missing_sections']);
        $this->assertSame(['spec', 'intent'], $result['present_sections']);
    }

    public function testValueOutsideClosedEnumSetIsRejected(): void
    {
        // Every section present, but spec.type uses a value the doc never lists.
        $spec = array_fill_keys(AtlasSddTemplatesAndSchemasService::SPEC_SECTIONS, []);
        $spec['spec'] = ['status' => 'approved', 'type' => 'enhancement', 'risk' => 'low'];

        $result = $this->service->validateSpec($spec);

        $this->assertFalse($result['valid']);
        $this->assertCount(1, $result['enum_violations']);
        $this->assertSame('spec.type', $result['enum_violations'][0]['field']);
        $this->assertSame('enhancement', $result['enum_violations'][0]['value']);
        $this->assertSame(
            ['feature', 'bugfix', 'refactor', 'ui_change', 'api_change', 'data_change'],
            $result['enum_violations'][0]['allowed']
        );
    }

    public function testSddPolicyExecutesWhenNoClarificationTriggerFires(): void
    {
        // No ask_only_when condition raised -> default_mode governs, may execute.
        $result = $this->service->evaluateSddPolicy([
            'has_diff' => true,
            'has_test_output' => true,
            'has_traceability' => true,
        ]);

        $this->assertSame('auto_spec_then_execute', $result['default_mode']);
        $this->assertSame('auto_spec_then_execute', $result['decision']);
        $this->assertFalse($result['must_clarify']);
        $this->assertTrue($result['may_execute']);
        $this->assertSame([], $result['triggered_clarifications']);
        $this->assertTrue($result['promotable']);
    }

    public function testSddPolicyClarifiesOnlyOnDocumentedTriggers(): void
    {
        // A documented trigger forces clarify and blocks autonomous execution.
        $result = $this->service->evaluateSddPolicy([
            'security_or_permission_unclear' => true,
            'has_diff' => true,
            'has_test_output' => true,
            'has_traceability' => true,
        ]);
        $this->assertTrue($result['must_clarify']);
        $this->assertFalse($result['may_execute']);
        $this->assertSame(['security_or_permission_unclear'], $result['triggered_clarifications']);
        // Clarification gate is red, so promotion is withheld even with full evidence.
        $this->assertFalse($result['promotable']);

        // A signal OUTSIDE the closed ask_only_when set must NOT force a clarify.
        $offList = $this->service->evaluateSddPolicy([
            'minor_naming_preference' => true,
            'has_diff' => true,
            'has_test_output' => true,
            'has_traceability' => true,
        ]);
        $this->assertFalse($offList['must_clarify']);
        $this->assertSame([], $offList['triggered_clarifications']);
    }

    public function testEvidenceGateAndUiAccessibilityRuleAreEnforced(): void
    {
        // Missing test output -> evidence unsatisfied -> not promotable.
        $result = $this->service->evaluateSddPolicy([
            'spec_type' => 'ui_change',
            'has_diff' => true,
            'has_traceability' => true,
            // has_test_output omitted
        ]);

        $this->assertFalse($result['evidence']['satisfied']);
        $this->assertSame(['require_test_output'], $result['evidence']['missing']);
        $this->assertFalse($result['promotable']);

        // A UI change trips the accessibility requirement; colour/token rules
        // are always on per the doc.
        $this->assertTrue($result['design_system']['is_ui_change']);
        $this->assertTrue($result['design_system']['accessibility_required']);
        $this->assertTrue($result['design_system']['forbid_hardcoded_colors']);
        $this->assertTrue($result['design_system']['prefer_tokens']);

        // A non-UI change does not require accessibility evidence.
        $backend = $this->service->evaluateSddPolicy(['spec_type' => 'data_change']);
        $this->assertFalse($backend['design_system']['is_ui_change']);
        $this->assertFalse($backend['design_system']['accessibility_required']);
    }
}
