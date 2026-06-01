<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingPipelineTargetService;
use Tests\TestCase;

/**
 * Pins the executable rules from the Programming Pipeline Target audit doc:
 * the intent set the classifier detects, the closed executor set
 * (simple_provider | dev_repair_executor | engineering_harness) and its
 * intent/risk precedence, the repair-loop iteration cap that escalates instead
 * of looping, the quality matrix gate-by-task-type, and surface unification +
 * new-surface bypass detection. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/architecture-audit/programming-pipeline-target.md
 */
class AtlasProgrammingPipelineTargetTest extends TestCase
{
    private function service(): AtlasProgrammingPipelineTargetService
    {
        return new AtlasProgrammingPipelineTargetService;
    }

    public function test_intent_classifier_detects_documented_intents_with_priority(): void
    {
        $svc = $this->service();

        // A failing-test prompt is a bugfix (not generic implementation).
        $this->assertSame('bugfix', $svc->classifyIntent('the build is failing, fix the bug')['intent']);

        // A migration prompt is db-class.
        $this->assertSame('db', $svc->classifyIntent('add a migration to create the orders table')['intent']);

        // Security outranks a co-occurring implementation verb (priority order).
        $sec = $svc->classifyIntent('implement auth and sanitize the secret');
        $this->assertSame('security', $sec['intent']);
        $this->assertContains('implementation', $sec['signals']);

        // Empty / unmatched input defaults to implementation, matched=false.
        $none = $svc->classifyIntent('   ');
        $this->assertSame('implementation', $none['intent']);
        $this->assertFalse($none['matched']);
    }

    public function test_executor_selection_uses_the_closed_set_and_precedence(): void
    {
        $svc = $this->service();

        // Default lane.
        $this->assertSame(
            AtlasProgrammingPipelineTargetService::EXECUTOR_SIMPLE,
            $svc->selectExecutor(['intent' => 'implementation', 'risk' => 'medium'])['executor'],
        );

        // Repair intent -> dev repair executor, with repair enabled.
        $repair = $svc->selectExecutor(['intent' => 'bugfix', 'risk' => 'medium']);
        $this->assertSame(AtlasProgrammingPipelineTargetService::EXECUTOR_DEV_REPAIR, $repair['executor']);
        $this->assertTrue($repair['repair_enabled']);

        // High risk -> engineering harness (heaviest lane) even for a plain intent.
        $this->assertSame(
            AtlasProgrammingPipelineTargetService::EXECUTOR_HARNESS,
            $svc->selectExecutor(['intent' => 'implementation', 'risk' => 'high'])['executor'],
        );

        // Heaviest lane dominates: a repair intent at critical risk still goes harness.
        $dom = $svc->selectExecutor(['intent' => 'bugfix', 'risk' => 'critical']);
        $this->assertSame(AtlasProgrammingPipelineTargetService::EXECUTOR_HARNESS, $dom['executor']);

        // Security intent alone forces the harness lane.
        $this->assertSame(
            AtlasProgrammingPipelineTargetService::EXECUTOR_HARNESS,
            $svc->selectExecutor(['intent' => 'security', 'risk' => 'low'])['executor'],
        );

        // A valid force_executor policy override wins outright.
        $this->assertSame(
            AtlasProgrammingPipelineTargetService::EXECUTOR_SIMPLE,
            $svc->selectExecutor(['intent' => 'security', 'risk' => 'critical', 'force_executor' => 'simple_provider'])['executor'],
        );
    }

    public function test_repair_loop_caps_at_three_then_escalates(): void
    {
        $svc = $this->service();

        // Mid-loop with red gates -> retry, with remaining budget.
        $retry = $svc->repairLoopStep(1, false);
        $this->assertSame('retry', $retry['decision']);
        $this->assertSame(3, $retry['max_iterations']);
        $this->assertSame(2, $retry['remaining']);
        $this->assertFalse($retry['escalated']);

        // At the cap with red gates -> escalate, not another retry.
        $escalate = $svc->repairLoopStep(3, false);
        $this->assertSame('escalate', $escalate['decision']);
        $this->assertTrue($escalate['escalated']);
        $this->assertSame(0, $escalate['remaining']);

        // Green gates short-circuit to done before the cap.
        $this->assertSame('done', $svc->repairLoopStep(2, true)['decision']);
    }

    public function test_quality_matrix_layers_extra_gates_by_task_type(): void
    {
        $svc = $this->service();

        // Plain implementation -> baseline only, no extras.
        $impl = $svc->qualityGates('implementation');
        $this->assertSame(['lint', 'build', 'test'], $impl['gates']);
        $this->assertSame([], $impl['extra']);

        // DB intent adds migration safety + schema drift gates on top of baseline.
        $db = $svc->qualityGates('db');
        $this->assertContains('migration_safety', $db['gates']);
        $this->assertContains('schema_drift', $db['gates']);
        $this->assertContains('test', $db['gates']);

        // Security intent adds the security/secret scans.
        $this->assertContains('security_scan', $svc->qualityGates('security')['gates']);
        $this->assertContains('secret_scan', $svc->qualityGates('security')['gates']);
    }

    public function test_surface_unification_and_bypass_detection(): void
    {
        $svc = $this->service();

        // forge = heavier intensity of the SAME pipeline, not a second product.
        $forge = $svc->normalizeSurface('forge');
        $this->assertSame('programming', $forge['pipeline']);
        $this->assertSame('heavy', $forge['intensity']);
        $this->assertFalse($forge['bypass']);
        $this->assertTrue($forge['allowed']);

        // fix = repair flow inside Programming (repair enabled).
        $fix = $svc->normalizeSurface('fix');
        $this->assertTrue($fix['repair_enabled']);
        $this->assertSame('programming', $fix['pipeline']);

        // dev one-shot resolves onto the same pipeline as interactive dev.
        $this->assertTrue($svc->normalizeSurface('dev:one-shot')['allowed']);

        // An unknown surface is a bypass that must be rejected, not silently run.
        $bypass = $svc->normalizeSurface('totally-new-surface');
        $this->assertTrue($bypass['bypass']);
        $this->assertFalse($bypass['allowed']);
        $this->assertSame('unknown_surface_bypass', $bypass['reason']);
    }

    public function test_end_to_end_plan_is_coherent_and_rejects_bypass(): void
    {
        $svc = $this->service();

        // A db migration on forge: db intent -> harness lane, db gates, heavy intensity.
        $plan = $svc->plan('add a migration to alter the users table', 'forge', ['risk' => 'medium']);
        $this->assertTrue($plan['allowed']);
        $this->assertSame('db', $plan['intent']['intent']);
        $this->assertSame(AtlasProgrammingPipelineTargetService::EXECUTOR_HARNESS, $plan['executor']['executor']);
        $this->assertContains('migration_safety', $plan['quality']['gates']);
        $this->assertSame('heavy', $plan['surface']['intensity']);

        // An unknown surface makes the whole plan not-allowed.
        $this->assertFalse($svc->plan('implement a feature', 'mystery-surface')['allowed']);
    }
}
