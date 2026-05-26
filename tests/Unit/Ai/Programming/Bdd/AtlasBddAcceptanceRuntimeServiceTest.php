<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\Bdd;

use App\Services\Ai\Governance\AtlasAutonomyAdmissionService;
use App\Services\Ai\Governance\AtlasConstitutionalKernelService;
use App\Services\Ai\Programming\Bdd\AtlasBddAcceptanceRuntimeService;
use Tests\TestCase;

class AtlasBddAcceptanceRuntimeServiceTest extends TestCase
{
    private string $tempDir;

    private string $kernelLog;

    private string $admissionLog;

    private string $scenariosLog;

    private string $executionsLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = sys_get_temp_dir().'/atlas-bdd-runtime-'.uniqid('', true);
        @mkdir($this->tempDir, 0777, true);
        $this->kernelLog = $this->tempDir.'/kernel-violations.jsonl';
        $this->admissionLog = $this->tempDir.'/admission-tickets.jsonl';
        $this->scenariosLog = $this->tempDir.'/scenarios.jsonl';
        $this->executionsLog = $this->tempDir.'/executions.jsonl';
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir.'/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($this->tempDir);
        }
        parent::tearDown();
    }

    private function runtime(): AtlasBddAcceptanceRuntimeService
    {
        $kernel = new AtlasConstitutionalKernelService;
        $kernel->setViolationsLogPathForTesting($this->kernelLog);
        $admission = new AtlasAutonomyAdmissionService($kernel);
        $admission->setTicketsLogPathForTesting($this->admissionLog);
        $svc = new AtlasBddAcceptanceRuntimeService($kernel, $admission);
        $svc->setScenariosLogPathForTesting($this->scenariosLog);
        $svc->setExecutionsLogPathForTesting($this->executionsLog);

        return $svc;
    }

    private function sampleGherkin(): string
    {
        return <<<'GHERKIN'
@dev @workspace
Feature: Atlas Dev workspace patch
  Scenario: Operator commits a small fix
    Given a workspace task is created
    When the operator approves
    Then the receipt is signed
GHERKIN;
    }

    public function test_compile_returns_canonical_scenario(): void
    {
        $s = $this->runtime()->compile($this->sampleGherkin());
        $this->assertSame(AtlasBddAcceptanceRuntimeService::SCENARIO_SCHEMA, $s['schema_version']);
        $this->assertSame('Atlas Dev workspace patch', $s['feature']);
        $this->assertSame('Operator commits a small fix', $s['name']);
        $this->assertContains('@dev', $s['tags']);
        $this->assertContains('@workspace', $s['tags']);
        $this->assertCount(3, $s['steps']);
        $this->assertSame('given', $s['steps'][0]['kind']);
        $this->assertSame('when', $s['steps'][1]['kind']);
        $this->assertSame('then', $s['steps'][2]['kind']);
        $this->assertStringStartsWith('sha256:', $s['scenario_hash']);
    }

    public function test_compile_empty_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->compile('');
    }

    public function test_compile_missing_feature_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->compile("Scenario: x\nGiven a\nThen b");
    }

    public function test_compile_missing_scenario_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->compile('Feature: x');
    }

    public function test_compile_no_steps_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->compile("Feature: f\nScenario: s");
    }

    public function test_execute_pending_definition_when_no_steps_registered(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $r = $svc->execute($s['scenario_id']);
        $this->assertSame(AtlasBddAcceptanceRuntimeService::EXECUTION_REPORT_SCHEMA, $r['schema_version']);
        $this->assertSame(0, $r['passed']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame(3, $r['pending_definition']);
        $this->assertSame(AtlasBddAcceptanceRuntimeService::OVERALL_INCOMPLETE, $r['overall']);
    }

    public function test_execute_passes_when_all_steps_registered_and_succeed(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->registerStep('/a workspace task is created/', static fn () => true, 'given');
        $svc->registerStep('/the operator approves/', static fn () => true, 'when');
        $svc->registerStep('/the receipt is signed/', static fn () => true, 'then');
        $r = $svc->execute($s['scenario_id']);
        $this->assertSame(3, $r['passed']);
        $this->assertSame(0, $r['failed']);
        $this->assertSame(0, $r['pending_definition']);
        $this->assertSame(AtlasBddAcceptanceRuntimeService::OVERALL_PASS, $r['overall']);
    }

    public function test_execute_fails_when_a_step_callback_returns_false(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->registerStep('/a workspace task is created/', static fn () => true, 'given');
        $svc->registerStep('/the operator approves/', static fn () => false, 'when');
        $svc->registerStep('/the receipt is signed/', static fn () => true, 'then');
        $r = $svc->execute($s['scenario_id']);
        $this->assertSame(2, $r['passed']);
        $this->assertSame(1, $r['failed']);
        $this->assertSame(AtlasBddAcceptanceRuntimeService::OVERALL_FAIL, $r['overall']);
    }

    public function test_execute_fails_when_callback_throws(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->registerStep('/a workspace task is created/', static fn () => true, 'given');
        $svc->registerStep('/the operator approves/', static function () {
            throw new \RuntimeException('boom');
        }, 'when');
        $svc->registerStep('/the receipt is signed/', static fn () => true, 'then');
        $r = $svc->execute($s['scenario_id']);
        $this->assertSame(1, $r['failed']);
        $this->assertStringContainsString('boom', $r['step_results'][1]['error']);
    }

    public function test_execute_unknown_scenario_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->execute('scn_unknown');
    }

    public function test_claim_policy_safe_in_report(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $r = $svc->execute($s['scenario_id']);
        $cp = $r['claim_policy'];
        $this->assertFalse($cp['benchmark_claim_allowed']);
        $this->assertFalse($cp['rivals_claim_allowed']);
        $this->assertFalse($cp['superiority_claim_allowed']);
        $this->assertFalse($cp['auto_pass_undefined']);
    }

    public function test_pending_definition_never_inflates_to_pass(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->registerStep('/a workspace task is created/', static fn () => true, 'given');
        $r = $svc->execute($s['scenario_id']);
        $this->assertSame(1, $r['passed']);
        $this->assertSame(2, $r['pending_definition']);
        $this->assertNotSame(AtlasBddAcceptanceRuntimeService::OVERALL_PASS, $r['overall']);
    }

    public function test_unknown_step_kind_in_register_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->registerStep('/x/', static fn () => true, 'galactic');
    }

    public function test_invalid_pcre_pattern_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->runtime()->registerStep('not_a_valid_regex', static fn () => true, 'given');
    }

    public function test_scenarios_persisted_append_only(): void
    {
        $svc = $this->runtime();
        $svc->compile($this->sampleGherkin());
        $svc->compile(str_replace('small fix', 'big fix', $this->sampleGherkin()));
        $this->assertCount(2, $svc->listScenarios());
    }

    public function test_executions_persisted_append_only(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->execute($s['scenario_id']);
        $svc->execute($s['scenario_id']);
        $this->assertCount(2, $svc->listExecutions());
    }

    public function test_aggregate_report_envelope(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->execute($s['scenario_id']);
        $r = $svc->report();
        $this->assertSame('atlas.bdd.aggregate_report.v1', $r['schema_version']);
        $this->assertSame(1, $r['total_executions']);
        $this->assertSame(1, $r['scenarios_count']);
        $this->assertFalse($r['claim_policy']['auto_pass_undefined']);
    }

    public function test_and_step_kind_supported(): void
    {
        $g = <<<'GHERKIN'
Feature: with And
  Scenario: chained
    Given a precondition
    And another precondition
    When something happens
    Then result is good
GHERKIN;
        $s = $this->runtime()->compile($g);
        $this->assertCount(4, $s['steps']);
        $this->assertSame('and', $s['steps'][1]['kind']);
    }

    public function test_step_result_carries_duration_ms(): void
    {
        $svc = $this->runtime();
        $s = $svc->compile($this->sampleGherkin());
        $svc->registerStep('/a workspace task is created/', static function () {
            usleep(2000);

            return true;
        }, 'given');
        $r = $svc->execute($s['scenario_id']);
        $this->assertGreaterThanOrEqual(0, $r['step_results'][0]['duration_ms']);
    }
}
