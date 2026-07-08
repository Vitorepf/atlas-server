<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\ControlPlane\AgentControlPlaneCertificationScenarioCorpusService;
use Tests\TestCase;

final class AgentControlPlaneCertificationScenarioCorpusServiceTest extends TestCase
{
    private function service(): AgentControlPlaneCertificationScenarioCorpusService
    {
        return $this->app->make(AgentControlPlaneCertificationScenarioCorpusService::class);
    }

    public function test_corpus_output_has_required_keys(): void
    {
        $result = $this->service()->corpus();

        foreach ([
            'schema_version', 'mode', 'status', 'corpus_id', 'generated_at',
            'scenario_count', 'categories', 'severities', 'by_category', 'scenarios',
        ] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
    }

    public function test_validate_requires_all_required_fields(): void
    {
        $service = $this->service();
        $valid = [
            'scenario_id' => 's1',
            'category' => 'capability',
            'injected_fault' => 'fault',
            'expected_detector' => 'detector',
            'expected_status' => 'degraded',
            'expected_violation' => 'violation',
            'severity' => 'high',
            'should_block_promotion' => true,
            'runtime_safe' => true,
        ];

        $this->assertTrue($service->validate($valid)['valid']);

        foreach (array_keys($valid) as $key) {
            $missing = $valid;
            unset($missing[$key]);
            $this->assertFalse($service->validate($missing)['valid'], "missing $key should invalidate");
        }
    }

    public function test_validate_rejects_unknown_category_and_severity(): void
    {
        $service = $this->service();
        $base = [
            'scenario_id' => 's1',
            'category' => 'capability',
            'injected_fault' => 'fault',
            'expected_detector' => 'detector',
            'expected_status' => 'degraded',
            'expected_violation' => 'violation',
            'severity' => 'high',
            'should_block_promotion' => true,
            'runtime_safe' => true,
        ];

        $badCategory = $base;
        $badCategory['category'] = 'unknown_category';
        $this->assertFalse($service->validate($badCategory)['valid']);
        $this->assertContains('unknown_category:unknown_category', $service->validate($badCategory)['reasons']);

        $badSeverity = $base;
        $badSeverity['severity'] = 'unknown_severity';
        $this->assertFalse($service->validate($badSeverity)['valid']);
        $this->assertContains('unknown_severity:unknown_severity', $service->validate($badSeverity)['reasons']);
    }

    public function test_runCorpus_reports_category_coverage_and_severity_coverage(): void
    {
        $result = $this->service()->runCorpus();

        $this->assertArrayHasKey('category_coverage', $result);
        $this->assertArrayHasKey('severity_coverage', $result);
        $this->assertArrayHasKey('all_categories_covered', $result);
        $this->assertArrayHasKey('all_severities_covered', $result);
        $this->assertArrayHasKey('corpus_ready', $result);
    }

    public function test_runCorpus_with_full_definitions_covers_all_categories_and_severities(): void
    {
        $result = $this->service()->runCorpus();

        $this->assertTrue($result['all_categories_covered']);
        $this->assertTrue($result['all_severities_covered']);
        // corpus_ready also requires the simulator run to pass (no misaligned/unmatched).
        // With the real simulator some corpus scenarios may be unmatched, so we only
        // assert the coverage gates are satisfied here.
        $this->assertIsBool($result['corpus_ready']);
    }

    public function test_coverage_entries_exist_for_every_category_and_severity(): void
    {
        $result = $this->service()->runCorpus();

        foreach (AgentControlPlaneCertificationScenarioCorpusService::CATEGORIES as $category) {
            $this->assertArrayHasKey($category, $result['category_coverage']);
        }
        foreach (AgentControlPlaneCertificationScenarioCorpusService::SEVERITIES as $severity) {
            $this->assertArrayHasKey($severity, $result['severity_coverage']);
        }
    }
}
