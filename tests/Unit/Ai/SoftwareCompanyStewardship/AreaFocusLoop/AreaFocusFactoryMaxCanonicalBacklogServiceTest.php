<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusFactoryMaxCanonicalBacklogService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSelfConstructionAdmissionBridgeService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use Tests\TestCase;

/**
 * AP-806/AP-790 canonical backlog depth.
 *
 * Provider-free proof: canonical AAEOS docs become high-value findings, those
 * findings decompose into bounded Self-Construction packets, and the first
 * packet is selectable by factory_max without falling to recovery/filler.
 */
final class AreaFocusFactoryMaxCanonicalBacklogServiceTest extends TestCase
{
    private function backlog(): AreaFocusFactoryMaxCanonicalBacklogService
    {
        return app(AreaFocusFactoryMaxCanonicalBacklogService::class);
    }

    public function test_canonical_backlog_exposes_five_plus_high_value_findings_with_real_sources(): void
    {
        $findings = $this->backlog()->findings('agentic_engineering_os', 'dev_forge');

        // Extended to 38 findings (8 + 18 AAEOS + 12 factory-runtime gap findings) for 10h run depth.
        $this->assertGreaterThanOrEqual(30, count($findings));

        foreach ($findings as $finding) {
            $this->assertStringStartsWith('canonical_aaeos_', (string) ($finding['finding_id'] ?? ''));
            $this->assertSame('canonical_aaeos_backlog', $finding['origin'] ?? null);
            $this->assertSame('runtime_gap', $finding['origin_type'] ?? null);
            $this->assertSame('high', $finding['severity'] ?? null);
            $this->assertSame('atlas_dev', $finding['owner_candidate'] ?? null);
            $this->assertTrue((bool) ($finding['auto_execution_allowed'] ?? false));
            $this->assertFalse((bool) ($finding['operator_review_required'] ?? true));
            $this->assertNotEmpty($finding['value_reason'] ?? '');
            $this->assertIsInt($finding['factory_priority_order'] ?? null);
            $this->assertNotEmpty($finding['factory_priority_group'] ?? '');
            $this->assertNotEmpty($finding['factory_priority_reason'] ?? '');
            $this->assertSame($finding['factory_priority_order'], data_get($finding, 'spec_seed.factory_priority_order'));
            $this->assertSame($finding['factory_priority_group'], data_get($finding, 'spec_seed.factory_priority_group'));
            $this->assertFileExists(base_path((string) ($finding['source_doc'] ?? '')));

            $source = (string) (($finding['affected_files'] ?? [])[0] ?? '');
            $this->assertStringStartsWith('app/', $source);
            $this->assertFileExists(base_path($source));

            $tests = (array) data_get($finding, 'spec_seed.tests_required', []);
            $this->assertNotEmpty($tests);
            $this->assertFileExists(base_path((string) $tests[0]));
            $this->assertNotContains('missing_test', $finding);
            $this->assertStringNotContainsString('rivals', strtolower((string) ($finding['finding_id'] ?? '')));
        }
    }

    public function test_canonical_backlog_prioritizes_factory_reliability_before_heavy_aaeos_work(): void
    {
        $findings = $this->backlog()->findings('agentic_engineering_os', 'dev_forge');
        $orders = array_map(static fn (array $finding): int => (int) $finding['factory_priority_order'], $findings);
        $sortedOrders = $orders;
        sort($sortedOrders);

        $this->assertSame($sortedOrders, $orders);
        $this->assertSame('repair_agent_real', $findings[0]['factory_priority_group']);
        $this->assertLessThan(
            $this->firstGroupIndex($findings, 'provider_fallback_routing'),
            $this->firstGroupIndex($findings, 'repair_agent_real'),
        );
        $this->assertLessThan(
            $this->firstGroupIndex($findings, 'backlog_depth_anti_starvation'),
            $this->firstGroupIndex($findings, 'provider_fallback_routing'),
        );
        $this->assertLessThan(
            $this->firstGroupIndex($findings, 'cycle_firewall_post_auditor'),
            $this->firstGroupIndex($findings, 'backlog_depth_anti_starvation'),
        );
        $this->assertLessThan(
            $this->firstGroupIndex($findings, 'context_memory_quality'),
            $this->firstGroupIndex($findings, 'evidence_ledger_hygiene'),
        );
        $this->assertGreaterThan(
            $this->firstGroupIndex($findings, 'long_run_supervisor'),
            $this->firstGroupIndex($findings, 'aaeos_quality_gates'),
        );
    }

    public function test_admission_report_proves_fifty_plus_eligible_packets_without_provider_or_loop(): void
    {
        $report = $this->backlog()->admissionReport(
            app(AreaFocusSelfConstructionAdmissionBridgeService::class),
            'agentic_engineering_os',
            'dev_forge',
        );

        $this->assertSame(AreaFocusFactoryMaxCanonicalBacklogService::REPORT_SCHEMA, $report['schema_version']);
        $this->assertFalse((bool) ($report['provider_invoked'] ?? true));
        $this->assertFalse((bool) ($report['loop_run'] ?? true));
        // Extended to 38 parent findings — each decomposes into 3 semantic slices = ~114 packets.
        // Minimum target is 50 admissible packets for a 10h autonomous run.
        $this->assertGreaterThanOrEqual(30, (int) $report['eligible_parent_finding_count']);
        $this->assertGreaterThanOrEqual(90, (int) $report['eligible_packet_count']);

        foreach ((array) $report['items'] as $item) {
            $this->assertNotEmpty($item['parent_finding_id'] ?? '');
            $this->assertNotEmpty($item['source_doc'] ?? '');
            $this->assertNotEmpty($item['value_reason'] ?? '');
            $this->assertIsInt($item['factory_priority_order'] ?? null);
            $this->assertNotEmpty($item['factory_priority_group'] ?? '');
            $this->assertNotEmpty($item['factory_priority_reason'] ?? '');
            $this->assertNotEmpty($item['allowed_files'] ?? []);
            $this->assertNotEmpty($item['required_tests'] ?? []);
            $this->assertSame('high', $item['risk'] ?? null);
            $this->assertGreaterThanOrEqual(2, (int) ($item['packet_count'] ?? 0));
            $this->assertLessThanOrEqual(5, (int) ($item['packet_count'] ?? 0));
            $this->assertGreaterThanOrEqual(1, (int) ($item['safe_packet_count'] ?? 0));
            $this->assertTrue((bool) ($item['first_packet_selectable'] ?? false));
        }
    }

    public function test_every_canonical_parent_is_authority_gated_and_first_packet_clears_factory_max(): void
    {
        $session = app(AutonomousEvolutionSessionService::class);
        $candidateRejection = new \ReflectionMethod($session, 'candidateRejectionReason');
        $allowedFiles = new \ReflectionMethod($session, 'allowedFiles');
        $bridge = app(AreaFocusSelfConstructionAdmissionBridgeService::class);

        foreach ($this->backlog()->findings('agentic_engineering_os', 'dev_forge') as $finding) {
            $parentReason = (string) $candidateRejection->invoke(
                $session,
                $finding,
                $allowedFiles->invoke($session, $finding),
                [],
                AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
                'agentic_engineering_os',
                'dev_forge',
                [],
                false,
                [],
                null,
            );
            $this->assertContains(
                $parentReason,
                AreaFocusSelfConstructionAdmissionBridgeService::ADMISSIBLE_REJECTION_REASONS,
                'Parent should be bridge-admissible, got '.$parentReason.' for '.(string) ($finding['finding_id'] ?? ''),
            );

            $admission = $bridge->admit($finding, $parentReason, 'agentic_engineering_os', 'dev_forge');
            $this->assertTrue((bool) ($admission['admissible'] ?? false));

            $packetFinding = (array) ($admission['first_packet_finding'] ?? []);
            $packetReason = (string) $candidateRejection->invoke(
                $session,
                $packetFinding,
                $allowedFiles->invoke($session, $packetFinding),
                [],
                AutonomousEvolutionSessionService::SCOPE_FACTORY_MAX,
                'agentic_engineering_os',
                'dev_forge',
                [],
                false,
                [],
                null,
            );
            $this->assertSame('', $packetReason, 'First packet must clear factory_max; got '.$packetReason);
        }
    }

    public function test_s86_l7_completion_gap_outranks_pure_new_class_and_is_selected_first(): void
    {
        $svc = $this->backlog();
        $factoryPriority = new \ReflectionMethod($svc, 'factoryPriority');
        $prioritized = new \ReflectionMethod($svc, 'prioritizedFindings');

        // L7-completion candidate: the S83 AutonomyLadderRuntimeService ladder gap.
        $l7 = $factoryPriority->invoke(
            $svc,
            'aaeos_s83_autonomy_ladder_runtime_l7_completion',
            'Wire S83 AutonomyLadderRuntimeService L7 completion gap',
            'Materialize the L7 completion runtime_wiring for the S83 autonomy ladder runtime so the ladder converges.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomyLadderRuntimeService.php',
            'Closes an L7 completion gap from the ladder backlog.',
        );

        // Pure new-class candidate: a brand-new capability service, no ladder signal.
        $pure = $factoryPriority->invoke(
            $svc,
            'aaeos_new_capability_service',
            'Introduce a brand new capability service',
            'Build an entirely new runtime capability class for a fresh feature surface.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeBrandNewService.php',
            'A new capability multiplier.',
        );

        // L7 must rank strictly above pure_new_class on the computed order, and carry
        // a higher score, because the loop targets L7 completion not commit volume.
        $this->assertSame(5, (int) $l7['order']);
        $this->assertSame('l7_completion_runtime_wiring', $l7['group']);
        $this->assertSame(100, (int) $pure['order']);
        $this->assertSame('heavy_aaeos_runtime', $pure['group']);
        $this->assertLessThan((int) $pure['order'], (int) $l7['order']);
        $this->assertGreaterThan((int) $pure['score'], (int) $l7['score']);

        // When both are eligible, the real tie-break sort selects the L7 (S83) finding first.
        $sorted = $prioritized->invoke($svc, [
            ['finding_id' => 'pure_new_class', 'factory_priority_order' => $pure['order'], 'factory_priority_score' => $pure['score'], 'factory_priority_group' => $pure['group']],
            ['finding_id' => 's83_l7_completion', 'factory_priority_order' => $l7['order'], 'factory_priority_score' => $l7['score'], 'factory_priority_group' => $l7['group']],
        ]);
        $this->assertSame('s83_l7_completion', $sorted[0]['finding_id']);
        $this->assertSame('l7_completion_runtime_wiring', $sorted[0]['factory_priority_group']);
    }

    public function test_s86_docs_only_no_op_finding_gets_fatal_penalty_and_sorts_last(): void
    {
        $svc = $this->backlog();
        $factoryPriority = new \ReflectionMethod($svc, 'factoryPriority');
        $prioritized = new \ReflectionMethod($svc, 'prioritizedFindings');

        $docs = $factoryPriority->invoke(
            $svc,
            'aaeos_doc_only_polish',
            'Documentation only polish',
            'This is a docs_only change with no runtime change and no test change.',
            'docs/engineering-knowledge-base/some-doc.md',
            'Improves a doc.',
        );

        // Fatal penalty: sinks below the default heavy bucket (100) with a negative
        // score so the tie-break pushes it last.
        $this->assertSame(999, (int) $docs['order']);
        $this->assertSame('docs_only_no_op_fatal', $docs['group']);
        $this->assertSame(-10000, (int) $docs['score']);
        $this->assertGreaterThan(100, (int) $docs['order']);

        // Real default (pure) bucket for comparison, then prove docs-only sorts last.
        $pure = $factoryPriority->invoke(
            $svc,
            'aaeos_new_capability_service',
            'Introduce a brand new capability service',
            'Build an entirely new runtime capability class.',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/SomeBrandNewService.php',
            'A new capability multiplier.',
        );
        $sorted = $prioritized->invoke($svc, [
            ['finding_id' => 'docs_only', 'factory_priority_order' => $docs['order'], 'factory_priority_score' => $docs['score'], 'factory_priority_group' => $docs['group']],
            ['finding_id' => 'pure_new_class', 'factory_priority_order' => $pure['order'], 'factory_priority_score' => $pure['score'], 'factory_priority_group' => $pure['group']],
        ]);
        $this->assertSame('docs_only', $sorted[count($sorted) - 1]['finding_id']);
        $this->assertSame('docs_only_no_op_fatal', $sorted[count($sorted) - 1]['factory_priority_group']);
    }

    public function test_s86_l7_completion_takes_precedence_over_docs_only_signal(): void
    {
        $svc = $this->backlog();
        $factoryPriority = new \ReflectionMethod($svc, 'factoryPriority');

        // A finding that is BOTH an L7 completion gap AND mentions docs_only/no test:
        // L7 is evaluated first, so it wins and never receives the fatal penalty.
        $both = $factoryPriority->invoke(
            $svc,
            'aaeos_s90_l7_completion_with_doc_mention',
            'S90 L7 completion that also mentions docs_only',
            'An L7 completion_gap for S90 that mentions docs_only and no test change in its rationale.',
            'docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md',
            'L7 completion wins over the docs-only mention.',
        );

        $this->assertSame(5, (int) $both['order']);
        $this->assertSame('l7_completion_runtime_wiring', $both['group']);
        $this->assertNotSame('docs_only_no_op_fatal', $both['group']);
    }

    public function test_s86_selected_l7_finding_carries_l7_phase_and_completion_gap_id(): void
    {
        $svc = $this->backlog();
        $finding = new \ReflectionMethod($svc, 'finding');

        // Build a real L7-completion finding (S83 ladder gap) through the per-finding builder.
        $l7Finding = $finding->invoke(
            $svc,
            's83_autonomy_ladder_runtime_l7_completion',
            'Wire S83 AutonomyLadderRuntimeService L7 completion gap',
            'Materialize the L7 completion runtime_wiring for the S83 autonomy ladder runtime.',
            'docs/engineering-knowledge-base/atlas-aaeos-l7-l10-governed-ladder-backlog.md',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomyLadderRuntimeService.php',
            'AutonomyLadderRuntimeServiceTest.php',
            'Closes the S83 L7 completion gap from the ladder backlog.',
            'agentic_engineering_os',
            'dev_forge',
        );

        // The L7 finding carries the ladder-completion attribution as computed fields.
        $this->assertArrayHasKey('l7_phase', $l7Finding);
        $this->assertArrayHasKey('completion_gap_id', $l7Finding);
        $this->assertSame('L7', $l7Finding['l7_phase']);
        $this->assertSame('S83', $l7Finding['completion_gap_id']);
        $this->assertSame(5, (int) $l7Finding['factory_priority_order']);
        $this->assertSame('l7_completion_runtime_wiring', $l7Finding['factory_priority_group']);

        // A non-L7 finding keeps the keys present but empty, so the shape stays stable.
        $plainFinding = $finding->invoke(
            $svc,
            'aaeos_new_capability_service',
            'Introduce a brand new capability service',
            'Build an entirely new runtime capability class.',
            'docs/engineering-knowledge-base/atlas-agentic-engineering-os-runbook.md',
            'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AutonomousEvolutionSessionService.php',
            'AutonomousEvolutionSessionServiceTest.php',
            'A new capability multiplier.',
            'agentic_engineering_os',
            'dev_forge',
        );
        $this->assertArrayHasKey('l7_phase', $plainFinding);
        $this->assertArrayHasKey('completion_gap_id', $plainFinding);
        $this->assertSame('', $plainFinding['l7_phase']);
        $this->assertSame('', $plainFinding['completion_gap_id']);
    }

    public function test_s86_existing_canonical_findings_keep_stable_l7_attribution_and_top_bucket(): void
    {
        // Regression guard: the live canonical backlog has no L7-completion/docs-only
        // finding today, so every finding carries empty L7 attribution and the top
        // finding stays repair_agent_real (no existing bucket was altered).
        $findings = $this->backlog()->findings('agentic_engineering_os', 'dev_forge');

        $this->assertSame('repair_agent_real', $findings[0]['factory_priority_group']);
        foreach ($findings as $finding) {
            $this->assertArrayHasKey('l7_phase', $finding);
            $this->assertArrayHasKey('completion_gap_id', $finding);
            $this->assertSame('', $finding['l7_phase'], (string) ($finding['finding_id'] ?? ''));
            $this->assertSame('', $finding['completion_gap_id'], (string) ($finding['finding_id'] ?? ''));
            $this->assertNotSame('docs_only_no_op_fatal', $finding['factory_priority_group']);
            $this->assertNotSame('l7_completion_runtime_wiring', $finding['factory_priority_group']);
        }
    }

    /**
     * @param  list<array<string,mixed>>  $findings
     */
    private function firstGroupIndex(array $findings, string $group): int
    {
        foreach ($findings as $index => $finding) {
            if (($finding['factory_priority_group'] ?? '') === $group) {
                return $index;
            }
        }

        $this->fail("Factory priority group {$group} not found.");
    }
}
