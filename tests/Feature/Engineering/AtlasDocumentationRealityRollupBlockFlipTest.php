<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FAIL-ON-STUB flip proof for the Batch-C roll-up promotions in ADRS: 'documentation_slo_alerting'
 * (block #49) and 'owner_escalation_queue' (block #50). Each was a declared spec constant and is now
 * an EXECUTES evaluator that computes its FULL declared verb as a constant-free ROLL-UP of sibling
 * evaluations (freshness/drift/orphan/budget/cartography/contradiction/lifecycle) that themselves
 * derive from the real corpus. The promotion is REAL only if mutating the rolled-up input flips the
 * verdict — a constant cannot flip. The integration test asserts both blocks execute over the real
 * corpus; the flip test drives the real private methods with a CLEAN sibling set (verdict ready) and
 * then a sibling set carrying one planted defect (verdict review), asserting the breach/escalation
 * appears. Reverting either method to its old spec constant makes the planted defect invisible and
 * FAILS these tests.
 *
 * sqlite :memory:, extends Tests\TestCase, NO RefreshDatabase, no DB tables touched.
 */
final class AtlasDocumentationRealityRollupBlockFlipTest extends TestCase
{
    public function test_slo_and_owner_escalation_execute_over_the_real_corpus(): void
    {
        $report = app(AtlasDocumentationRealitySystemService::class)->report();

        $slo = $report['evaluations']['documentation_slo_alerting'];
        $this->assertContains($slo['status'], ['ready', 'review', 'degraded'], 'SLO must derive a runtime status, not spec');
        $this->assertArrayHasKey('slos', $slo);
        $this->assertGreaterThanOrEqual(6, $slo['slo_count']);

        $owner = $report['evaluations']['owner_escalation_queue'];
        $this->assertContains($owner['status'], ['ready', 'review', 'blocked']);
        $this->assertArrayHasKey('escalation_queue', $owner);

        foreach (['Documentation SLO & Alerting' => 'documentation_slo_alerting', 'Owner Escalation Queue' => 'owner_escalation_queue'] as $name => $_key) {
            $block = collect($report['blocks'])->firstWhere('name', $name);
            $this->assertNotNull($block, $name.' block must exist');
            $this->assertSame('executes', $block['execution'], $name.' must be classified executes');
            $this->assertNotNull($block['integration_evidence'], $name.' must carry integration_evidence');
        }
    }

    public function test_slo_verdict_flips_on_a_planted_orphan_breach(): void
    {
        $service = app(AtlasDocumentationRealitySystemService::class);
        $method = new ReflectionMethod($service, 'documentationSloEvaluation');
        $method->setAccessible(true);

        $sources = [['id' => 'a', 'exists' => true], ['id' => 'b', 'exists' => true]];
        $clean = [
            'source_freshness_gate' => ['stale_sources' => []],
            'drift_duplication_guard' => ['drift_count' => 0, 'degraded' => false],
            'orphaned_decision_finder' => ['orphan_count' => 0, 'orphan_queue' => []],
            'documentation_budget_governor' => ['max_source_lines' => 100],
            'aurc_visual_reality' => ['status' => 'ready'],
        ];

        $ready = $method->invoke($service, $sources, $clean);
        $this->assertSame('ready', $ready['status'], 'a clean sibling set must yield zero breaches');
        $this->assertSame(0, $ready['breached_slo_count']);

        // PLANT a real orphan signal into the rolled-up sibling — the orphan SLO must breach and the
        // DERIVED status flips. The old spec constant ('spec') would ignore this entirely.
        $withOrphans = $clean;
        $withOrphans['orphaned_decision_finder'] = ['orphan_count' => 5, 'orphan_queue' => [['path' => 'x.md']]];
        $breached = $method->invoke($service, $sources, $withOrphans);
        $this->assertSame('review', $breached['status'], 'a real orphan breach must flip the SLO to review');
        $this->assertGreaterThanOrEqual(1, $breached['breached_slo_count']);
        $this->assertContains('orphan_count', array_column($breached['alerts'], 'metric'));
    }

    public function test_owner_escalation_verdict_flips_on_a_planted_missing_owner(): void
    {
        $service = app(AtlasDocumentationRealitySystemService::class);
        $method = new ReflectionMethod($service, 'ownerEscalationEvaluation');
        $method->setAccessible(true);

        $ownedSources = [['id' => 'a', 'exists' => true, 'owner' => 'owner-a']];
        $clean = [
            'orphaned_decision_finder' => ['orphan_queue' => []],
            'contradiction_resolver' => ['contradiction_packet' => []],
            'documentation_lifecycle_state_machine' => ['invalid_sources' => []],
            'drift_duplication_guard' => ['drifts' => []],
        ];

        $ready = $method->invoke($service, $ownedSources, $clean);
        $this->assertSame('ready', $ready['status'], 'a fully-owned, defect-free corpus escalates nothing');
        $this->assertSame(0, $ready['escalation_count']);

        // PLANT a missing-owner orphan row — a real escalation must appear and the verdict flips.
        $withGap = $clean;
        $withGap['orphaned_decision_finder'] = ['orphan_queue' => [['path' => 'orphan.md', 'missing' => ['owner', 'evidence']]]];
        $escalated = $method->invoke($service, $ownedSources, $withGap);
        $this->assertSame('review', $escalated['status'], 'a real owner gap must flip the queue to review');
        $this->assertGreaterThanOrEqual(1, $escalated['escalation_count']);
        $this->assertContains('missing_owner', array_column($escalated['escalation_queue'], 'reason'));
    }
}
