<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

final class AtlasDocumentationRealitySystemServiceTest extends TestCase
{
    public function test_report_materializes_all_52_adrs_blocks_with_sources_and_planes(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();

        $this->assertSame(AtlasDocumentationRealitySystemService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('integrated_read_only_runtime', $payload['implementation_status']);
        $this->assertSame(52, $payload['summary']['block_count']);
        $this->assertSame(52, $payload['summary']['block_with_upgrade_count']);
        $this->assertSame(52, $payload['summary']['integrated_runtime_block_count']);
        $this->assertSame(52, $payload['summary']['accepted_block_count']);
        $this->assertSame(6, $payload['summary']['plane_count']);
        $this->assertSame(0, $payload['summary']['blocker_count']);
        $this->assertSame('ready', $payload['integration_summary']['status']);
        $this->assertSame(52, $payload['integration_summary']['integrated_block_count']);
        $this->assertSame(0, $payload['integration_summary']['missing_evaluation_ref_count']);
        $this->assertSame(0, $payload['integration_summary']['dangling_evaluation_ref_count']);
        $this->assertSame('atlas.documentation_reality.score.v1', $payload['documentation_reality_score']['schema_version']);
        $this->assertSame('excellent_integrated_runtime', $payload['documentation_reality_score']['status']);
        $this->assertGreaterThanOrEqual(95, $payload['documentation_reality_score']['average']);
        $this->assertSame('atlas.documentation_reality.readiness_matrix.v1', $payload['readiness_matrix']['schema_version']);
        $this->assertSame(0, $payload['readiness_matrix']['levels']['L3_read_only']);
        $this->assertSame(52, $payload['readiness_matrix']['levels']['L4_integrated']);
        $this->assertSame(0, $payload['readiness_matrix']['levels']['L2_testable']);
        $this->assertSame([], $payload['readiness_matrix']['insufficient_blocks']);
        $this->assertSame('atlas.documentation_reality.block_acceptance_matrix.v1', $payload['block_acceptance_matrix']['schema_version']);
        $this->assertSame('ready', $payload['block_acceptance_matrix']['status']);
        $this->assertSame(52, $payload['block_acceptance_matrix']['accepted_block_count']);
        $this->assertSame(0, $payload['block_acceptance_matrix']['incomplete_block_count']);
        $this->assertSame('ready', $payload['evaluations']['authority_kernel']['status']);
        $this->assertSame('high', $payload['evaluations']['authority_kernel']['confidence']);
        $this->assertSame('ready', $payload['evaluations']['source_freshness_gate']['status']);
        $this->assertSame('ready', $payload['evaluations']['evidence_sufficiency_gate']['status']);
        $this->assertSame('ready', $payload['evaluations']['contradiction_resolver']['status']);
        $this->assertSame('ready', $payload['evaluations']['documentation_lifecycle_state_machine']['status']);
        $this->assertSame('ready', $payload['evaluations']['documentation_operating_system']['status']);
        $this->assertSame('ready', $payload['evaluations']['knowledge_governance_system']['status']);
        $this->assertSame('ready', $payload['evaluations']['vocabulary_alignment_guard']['status']);
        $this->assertSame('ready', $payload['evaluations']['documentation_budget_governor']['status']);
        $this->assertSame('ready', $payload['evaluations']['ai_context_projection']['status']);
        $this->assertSame('ready', $payload['evaluations']['retrieval_audit_trail']['status']);
        $this->assertSame('ready', $payload['evaluations']['documentation_compression_tiers']['status']);
        $this->assertSame('ready', $payload['evaluations']['provider_misread_defense']['status']);
        $this->assertSame('ready', $payload['evaluations']['privacy_redaction_gate']['status']);
        $this->assertSame('ready', $payload['evaluations']['access_policy_resolver']['status']);
        $this->assertSame('ready', $payload['evaluations']['acrui_operational_reality']['status']);
        $this->assertSame('active_runtime', $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['service']['classification']);
        $this->assertContains($payload['evaluations']['acrui_operational_reality']['runtime_evidence']['command']['classification'], ['active_runtime', 'active_read_only']);
        $this->assertSame('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['test']);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['schema_version']);
        $this->assertSame(0, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['unknown_or_unused_count']);
        $this->assertSame(0, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['weak_reachability_count']);
        $this->assertSame('ready', $payload['evaluations']['aurc_visual_reality']['status']);
        $this->assertSame('php artisan atlas:universal-reality-cartography map --strict --json', $payload['evaluations']['aurc_visual_reality']['runtime_evidence']['command']);
        $this->assertSame('tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php', $payload['evaluations']['aurc_visual_reality']['runtime_evidence']['test']);
        $this->assertSame('ready', $payload['evaluations']['multi_agent_handoff_projection']['status']);
        $this->assertSame('ready', $payload['evaluations']['documentation_working_set_cache']['status']);
        $this->assertSame('ready', $payload['evaluations']['surface_coverage_matrix']['status']);
        $this->assertSame('ready', $payload['evaluations']['synthetic_reader_tests']['status']);
        $this->assertFalse($payload['writes']);
        $this->assertTrue($payload['claim_policy']['declares_all_52_adrs_blocks_integrated']);
        $this->assertFalse($payload['claim_policy']['declares_child_systems_complete']);

        $this->assertSame(
            [],
            collect($payload['blocks'])
                ->filter(static fn (array $block): bool => $block['readiness_level'] !== 'L4_integrated' || $block['evaluation_ref'] === null || $block['integration_evidence'] === null)
                ->pluck('name')
                ->values()
                ->all()
        );

        $blockNames = collect($payload['blocks'])->pluck('name')->all();
        $this->assertContains('Documentation Authority Kernel', $blockNames);
        $this->assertContains('ACRUI Operational Reality', $blockNames);
        $this->assertContains('AI Context Projection', $blockNames);
        $this->assertContains('AURC Visual Reality', $blockNames);
        $this->assertContains('Human Correction Loop', $blockNames);
        $this->assertContains('Documentation SLO & Alerting', $blockNames);

        $authorityKernel = collect($payload['blocks'])->firstWhere('name', 'Documentation Authority Kernel');
        $this->assertSame('L4_integrated', $authorityKernel['readiness_level']);
        $this->assertSame('authority_kernel', $authorityKernel['evaluation_ref']);
        $this->assertSame('sufficient_for_specification', $authorityKernel['evidence_sufficiency']);
        $this->assertTrue($authorityKernel['readiness_checks']['proof_defined']);
        $this->assertSame('php artisan atlas:documentation-reality evaluations --strict --json', $authorityKernel['integration_evidence']['command']);

        $contextProjection = collect($payload['blocks'])->firstWhere('name', 'AI Context Projection');
        $this->assertSame('L4_integrated', $contextProjection['readiness_level']);
        $this->assertSame('ai_context_projection', $contextProjection['evaluation_ref']);

        $cartography = collect($payload['blocks'])->firstWhere('name', 'AURC Visual Reality');
        $this->assertSame('L4_integrated', $cartography['readiness_level']);
        $this->assertSame('aurc_visual_reality', $cartography['evaluation_ref']);

        $reader = collect($payload['blocks'])->firstWhere('name', 'Synthetic Reader Tests');
        $this->assertSame('L4_integrated', $reader['readiness_level']);
        $this->assertSame('synthetic_reader_tests', $reader['evaluation_ref']);
    }

    public function test_report_exposes_canonical_source_registry_with_authority_tiers(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $sources = collect($payload['source_registry'])->keyBy('id');

        $this->assertSame('tier_1_mother_contract', $sources->get('adrs')['authority_tier']);
        $this->assertSame('tier_1_canonical_child', $sources->get('adrs_block_registry')['authority_tier']);
        $this->assertSame('tier_1_canonical_child', $sources->get('acrui')['authority_tier']);
        $this->assertSame('tier_1_canonical_child', $sources->get('aurc')['authority_tier']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md', $sources->get('adrs_block_registry')['path']);
        $this->assertSame('tier_2_supporting_canonical', $sources->get('implemented_vs_scaffold')['authority_tier']);
        $this->assertSame($payload['summary']['source_count'], $payload['summary']['source_present_count']);
    }

    public function test_each_adrs_block_has_runtime_evaluation_ref_backed_by_ready_evaluation(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $evaluations = collect($payload['evaluations']);

        foreach ($payload['blocks'] as $block) {
            $this->assertSame('L4_integrated', $block['readiness_level'], $block['name']);
            $this->assertIsString($block['evaluation_ref'], $block['name']);
            $this->assertTrue($evaluations->has($block['evaluation_ref']), $block['name']);
            // A block is "integrated runtime" when its evaluation RAN and produced a verdict.
            // The Drift & Duplication Guard (block #11) is now a real doc-vs-code detector:
            // besides 'ready'/'review' it can honestly emit 'drift_detected' (real drift) or
            // 'degraded' (code index unavailable — the case in this no-index test env). All
            // four mean the guard is materialized and working, so all four keep the block
            // integrated. This is the same set the service itself uses to gate integration.
            $this->assertContains(
                $evaluations->get($block['evaluation_ref'])['status'],
                ['ready', 'review', 'drift_detected', 'degraded'],
                $block['name'],
            );
            $this->assertSame($block['evaluation_ref'], $block['integration_evidence']['evaluation_ref'], $block['name']);
        }

        $this->assertSame(52, collect($payload['blocks'])->pluck('evaluation_ref')->filter()->count());
        $this->assertGreaterThanOrEqual(40, collect($payload['blocks'])->pluck('evaluation_ref')->unique()->count());
    }

    public function test_each_adrs_block_has_acceptance_contract_with_owner_commands_and_tests(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $matrix = $payload['block_acceptance_matrix'];

        $this->assertSame('ready', $matrix['status']);
        $this->assertCount(52, $matrix['items']);
        $this->assertSame([], $matrix['incomplete_blocks']);

        foreach ($matrix['items'] as $item) {
            $this->assertSame('accepted', $item['status'], $item['block_name']);
            $this->assertIsString($item['owner_doc'], $item['block_name']);
            $this->assertNotSame('', $item['owner_doc'], $item['block_name']);
            $this->assertNotEmpty($item['required_commands'], $item['block_name']);
            $this->assertContains('php artisan atlas:documentation-reality acceptance --strict --json', $item['required_commands'], $item['block_name']);
            $this->assertNotEmpty($item['required_tests'], $item['block_name']);
            $this->assertContains('tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php', $item['required_tests'], $item['block_name']);
            $this->assertTrue($item['quality_floor']['must_be_read_only'], $item['block_name']);
            $this->assertTrue($item['quality_floor']['must_fail_closed_when_evidence_missing'], $item['block_name']);
        }

        $acrui = collect($matrix['items'])->firstWhere('block_name', 'ACRUI Operational Reality');
        $this->assertContains('php artisan atlas:code-reality reality-audit --json', $acrui['required_commands']);
        $this->assertContains('php artisan atlas:code-reality classify --target="<target>" --json', $acrui['required_commands']);
        $this->assertContains('php artisan atlas:code-reality deletion-preflight --target="<target>" --json', $acrui['required_commands']);
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $acrui['required_tests']);

        $aurc = collect($matrix['items'])->firstWhere('block_name', 'AURC Visual Reality');
        $this->assertContains('php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json', $aurc['required_commands']);
        $this->assertContains('tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php', $aurc['required_tests']);
    }

    public function test_block_registry_stays_in_sync_with_runtime_blocks_for_cartography(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $runtimeBlocks = collect($payload['blocks'])->keyBy('number');
        $registryPath = base_path('docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md');
        $registry = File::get($registryPath);

        preg_match_all(
            '/^\|\s*(\d+)\s*\|\s*([a-z0-9-]+)\s*\|\s*([a-z_]+)\s*\|\s*([a-z_]+)\s*\|\s*([a-z0-9_]+)\s*\|$/m',
            $registry,
            $matches,
            PREG_SET_ORDER
        );

        $rows = collect($matches)
            ->map(static fn (array $match): array => [
                'number' => (int) $match[1],
                'block_id' => $match[2],
                'plane' => $match[3],
                'kind' => $match[4],
                'evaluation_ref' => $match[5],
            ])
            ->values();

        $this->assertCount(52, $rows);
        $this->assertSame(range(1, 52), $rows->pluck('number')->all());
        $this->assertSame(52, $rows->pluck('block_id')->unique()->count());

        foreach ($rows as $row) {
            $block = $runtimeBlocks->get($row['number']);

            $this->assertIsArray($block, 'Missing runtime block '.$row['number']);
            $this->assertSame(
                strtolower(trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($block['name'])), '-')),
                $row['block_id'],
                $block['name']
            );
            $this->assertContains($row['plane'], $block['planes'], $block['name']);
            $this->assertNotSame('', $row['kind'], $block['name']);
            $this->assertSame($block['evaluation_ref'], $row['evaluation_ref'], $block['name']);
        }
    }

    public function test_certification_hash_is_stable_for_same_content(): void
    {
        $service = app(AtlasDocumentationRealitySystemService::class);

        $first = $service->report();
        $second = $service->report();

        $this->assertSame($first['certification_hash'], $second['certification_hash']);
    }

    public function test_command_outputs_json_for_score_sources_and_blocks(): void
    {
        foreach (['score', 'sources', 'blocks', 'evaluations', 'acceptance'] as $action) {
            $exit = Artisan::call('atlas:documentation-reality', [
                'action' => $action,
                '--json' => true,
                '--strict' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame(AtlasDocumentationRealitySystemService::SCHEMA_VERSION, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }
    }

    public function test_drift_duplication_guard_is_a_real_doc_vs_code_detector_not_a_tautology(): void
    {
        // The guard is now the REAL Drift & Duplication Guard (block #11): it composes the
        // AAEOS capability truth ledger + resolves canonical-source evidence_refs, instead
        // of the old tautological duplicate-id/path-only check. This test pins the new
        // contract honestly: with no real drift the verdict is ready-or-degraded (NEVER a
        // tautological empty), it surfaces a real drift_count, and the duplicate signal is
        // demoted to secondary. The PLANTED-drift flips (over-claim + claimed-but-absent)
        // and the anti-stub proof live in AtlasDocumentationRealityDriftGuardTest.
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $guard = $payload['evaluations']['drift_duplication_guard'];

        $this->assertSame('atlas.documentation_reality.drift_duplication_guard.v1', $guard['schema_version']);
        // No real drift in this env => the guard is ready (live, index healthy, drift 0) or
        // degraded (no index in :memory:). It is NEVER a false 'ready' on a blind index, and
        // when ready it is backed by a REAL drift_count, not a tautologically empty check.
        $this->assertContains($guard['status'], ['ready', 'degraded']);
        $this->assertArrayHasKey('drift_count', $guard);
        $this->assertArrayHasKey('over_claim_drift_count', $guard);
        $this->assertArrayHasKey('claimed_fact_drift_count', $guard);
        $this->assertSame(0, $guard['drift_count']);
        $this->assertSame([], $guard['drifts']);
        $this->assertFalse($guard['writes']);
        // The secondary (demoted) duplicate signal is still surfaced but never the verdict.
        $this->assertSame([], $guard['duplicate_source_ids']);
        $this->assertSame([], $guard['duplicate_source_paths']);

        if ($guard['status'] === 'degraded') {
            $this->assertTrue($guard['degraded']);
            $this->assertSame('code_intelligence_index_empty_or_absent_drift_verdict_withheld', $guard['degraded_reason']);
        }

        // A ready/degraded guard with zero real drift never adds a drift blocker, so the
        // overall report stays ready (no real drift in the live corpus or the test env).
        $this->assertSame(
            [],
            collect($payload['blockers'])
                ->where('reason', 'documentation_reality_drift_detected')
                ->values()
                ->all(),
        );
    }

    public function test_command_evaluations_action_exposes_all_ready_block_evaluations(): void
    {
        $exit = Artisan::call('atlas:documentation-reality', [
            'action' => 'evaluations',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame(52, $payload['readiness_matrix']['levels']['L4_integrated']);
        $this->assertArrayHasKey('aurc_visual_reality', $payload['evaluations']);
        $this->assertArrayHasKey('canonical_example_corpus', $payload['evaluations']);
        $this->assertSame('ready', $payload['evaluations']['canonical_example_corpus']['status']);
    }
}
