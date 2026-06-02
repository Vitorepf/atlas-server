<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasCodeRealityUsageIntelligenceService;
use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
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
        // HONEST CONTRACT: only the blocks whose evaluation actually computes its verb from
        // real input (real source registry / real ledger / real runtime evidence) land in
        // L4_integrated and count as integrated runtime. Declared-spec blocks are NO LONGER
        // laundered into L4. The honest split is executes(11) + partial(12) + declared(29) = 52.
        $this->assertSame(11, $payload['summary']['integrated_runtime_block_count']);
        $this->assertSame(11, $payload['summary']['executing_block_count']);
        $this->assertSame(12, $payload['summary']['partial_runtime_block_count']);
        $this->assertSame(29, $payload['summary']['declared_spec_block_count']);
        // The three tiers must account for every block — no block is hidden or double-counted.
        $this->assertSame(
            52,
            $payload['summary']['executing_block_count']
                + $payload['summary']['partial_runtime_block_count']
                + $payload['summary']['declared_spec_block_count'],
        );
        // accepted_block_count is now "honestly reported" = every block that is NOT a
        // failed-executes: the 11 that execute-and-pass, the 12 partial, and the 29 honestly
        // declared specs. It is 52 ONLY because every spec is surfaced as declared (never
        // because a stub is blessed as integrated). The split above keeps that visible.
        $this->assertSame(52, $payload['summary']['accepted_block_count']);
        $this->assertSame(6, $payload['summary']['plane_count']);
        // Honestly-labelled specs raise no blocker — a declared block is not a defect.
        $this->assertSame(0, $payload['summary']['blocker_count']);
        // Integration gate is honest: zero integration-liars and refs resolve. It reports the
        // real integrated subset (11), not a hardcoded all-52.
        $this->assertSame('ready', $payload['integration_summary']['status']);
        $this->assertSame(11, $payload['integration_summary']['integrated_block_count']);
        $this->assertSame(12, $payload['integration_summary']['partial_runtime_block_count']);
        $this->assertSame(29, $payload['integration_summary']['declared_spec_block_count']);
        $this->assertSame(
            52,
            $payload['integration_summary']['integrated_block_count']
                + $payload['integration_summary']['partial_runtime_block_count']
                + $payload['integration_summary']['declared_spec_block_count'],
        );
        $this->assertSame(0, $payload['integration_summary']['missing_evaluation_ref_count']);
        $this->assertSame(0, $payload['integration_summary']['dangling_evaluation_ref_count']);
        $this->assertSame('atlas.documentation_reality.score.v1', $payload['documentation_reality_score']['schema_version']);
        // HONEST SCORE: the top tier 'excellent_integrated_runtime' requires average>=99 AND
        // all 52 integrated. With only 11/52 executing, the score honestly drops to 'attention'
        // and the average dips to 93.91 after removing the integration bonus from the 41
        // non-integrated blocks. No fudge is re-added to fake >=95 (anti-gaming contract).
        $this->assertSame('attention', $payload['documentation_reality_score']['status']);
        $this->assertSame(93.91, $payload['documentation_reality_score']['average']);
        $this->assertLessThan(95, $payload['documentation_reality_score']['average']);
        $this->assertSame('atlas.documentation_reality.readiness_matrix.v1', $payload['readiness_matrix']['schema_version']);
        // The full 52 is accounted for across honest levels: 11 executes -> L4_integrated,
        // 12 partial -> L3_read_only, 29 declared -> L2_testable. None silently vanish.
        $this->assertSame(11, $payload['readiness_matrix']['levels']['L4_integrated']);
        $this->assertSame(12, $payload['readiness_matrix']['levels']['L3_read_only']);
        $this->assertSame(29, $payload['readiness_matrix']['levels']['L2_testable']);
        $this->assertSame(0, $payload['readiness_matrix']['levels']['L1_specified']);
        $this->assertSame(
            52,
            $payload['readiness_matrix']['levels']['L4_integrated']
                + $payload['readiness_matrix']['levels']['L3_read_only']
                + $payload['readiness_matrix']['levels']['L2_testable']
                + $payload['readiness_matrix']['levels']['L1_specified'],
        );
        $this->assertSame([], $payload['readiness_matrix']['insufficient_blocks']);
        $this->assertSame('atlas.documentation_reality.block_acceptance_matrix.v1', $payload['block_acceptance_matrix']['schema_version']);
        // Acceptance gate is honest: it passes because incomplete_count===0, NOT because
        // accepted===52. accepted counts only executes-and-pass blocks (11); declared and
        // partial blocks are surfaced in their own honest buckets, never swallowed as accepted.
        $this->assertSame('ready', $payload['block_acceptance_matrix']['status']);
        $this->assertSame(11, $payload['block_acceptance_matrix']['accepted_block_count']);
        $this->assertSame(29, $payload['block_acceptance_matrix']['declared_block_count']);
        $this->assertSame(12, $payload['block_acceptance_matrix']['partial_runtime_block_count']);
        $this->assertSame(0, $payload['block_acceptance_matrix']['incomplete_block_count']);
        // Per-evaluation status is now DERIVED from each block's real category, never a literal.
        // executes-blocks compute their verb from real input and pass -> derived 'ready';
        // partial-blocks run a narrow real check and pass -> derived 'ready' (but execution
        // is honestly 'partial', not full integration);
        // declared-spec blocks return an honest spec -> execution 'declared' + status 'spec'
        // (NEVER 'ready' — that is the over-claim this fix kills).
        //
        // executes (real verb from real input, passes):
        $this->assertSame('executes', $payload['evaluations']['source_freshness_gate']['execution']);
        $this->assertSame('ready', $payload['evaluations']['source_freshness_gate']['status']);
        $this->assertSame('executes', $payload['evaluations']['evidence_sufficiency_gate']['execution']);
        $this->assertSame('ready', $payload['evaluations']['evidence_sufficiency_gate']['status']);
        $this->assertSame('executes', $payload['evaluations']['documentation_lifecycle_state_machine']['execution']);
        $this->assertSame('ready', $payload['evaluations']['documentation_lifecycle_state_machine']['status']);
        $this->assertSame('executes', $payload['evaluations']['documentation_operating_system']['execution']);
        $this->assertSame('ready', $payload['evaluations']['documentation_operating_system']['status']);
        $this->assertSame('executes', $payload['evaluations']['documentation_budget_governor']['execution']);
        $this->assertSame('ready', $payload['evaluations']['documentation_budget_governor']['status']);
        $this->assertSame('executes', $payload['evaluations']['retrieval_audit_trail']['execution']);
        $this->assertSame('ready', $payload['evaluations']['retrieval_audit_trail']['status']);
        $this->assertSame('executes', $payload['evaluations']['acrui_operational_reality']['execution']);
        $this->assertSame('ready', $payload['evaluations']['acrui_operational_reality']['status']);
        // partial (narrow real check from real input, passes; execution honestly 'partial'):
        $this->assertSame('partial', $payload['evaluations']['authority_kernel']['execution']);
        $this->assertSame('ready', $payload['evaluations']['authority_kernel']['status']);
        $this->assertSame('high', $payload['evaluations']['authority_kernel']['confidence']);
        $this->assertSame('partial', $payload['evaluations']['contradiction_resolver']['execution']);
        $this->assertSame('ready', $payload['evaluations']['contradiction_resolver']['status']);
        $this->assertSame('partial', $payload['evaluations']['knowledge_governance_system']['execution']);
        $this->assertSame('ready', $payload['evaluations']['knowledge_governance_system']['status']);
        $this->assertSame('partial', $payload['evaluations']['vocabulary_alignment_guard']['execution']);
        $this->assertSame('ready', $payload['evaluations']['vocabulary_alignment_guard']['status']);
        $this->assertSame('partial', $payload['evaluations']['ai_context_projection']['execution']);
        $this->assertSame('ready', $payload['evaluations']['ai_context_projection']['status']);
        // declared specs (honest spec, NOT runtime) -> execution 'declared' + status 'spec':
        $this->assertSame('declared', $payload['evaluations']['documentation_compression_tiers']['execution']);
        $this->assertSame('spec', $payload['evaluations']['documentation_compression_tiers']['status']);
        $this->assertSame('declared', $payload['evaluations']['provider_misread_defense']['execution']);
        $this->assertSame('spec', $payload['evaluations']['provider_misread_defense']['status']);
        $this->assertSame('declared', $payload['evaluations']['privacy_redaction_gate']['execution']);
        $this->assertSame('spec', $payload['evaluations']['privacy_redaction_gate']['status']);
        $this->assertSame('declared', $payload['evaluations']['access_policy_resolver']['execution']);
        $this->assertSame('spec', $payload['evaluations']['access_policy_resolver']['status']);
        $this->assertSame('active_runtime', $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['service']['classification']);
        $this->assertContains($payload['evaluations']['acrui_operational_reality']['runtime_evidence']['command']['classification'], ['active_runtime', 'active_read_only']);
        $this->assertSame('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['test']);
        $this->assertSame(AtlasCodeRealityUsageIntelligenceService::REALITY_AUDIT_SCHEMA_VERSION, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['schema_version']);
        $this->assertSame(0, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['unknown_or_unused_count']);
        $this->assertSame(0, $payload['evaluations']['acrui_operational_reality']['runtime_evidence']['reality_audit']['weak_reachability_count']);
        // aurc_visual_reality genuinely executes (real cartography runtime evidence) -> 'ready'.
        $this->assertSame('executes', $payload['evaluations']['aurc_visual_reality']['execution']);
        $this->assertSame('ready', $payload['evaluations']['aurc_visual_reality']['status']);
        $this->assertSame('php artisan atlas:universal-reality-cartography map --strict --json', $payload['evaluations']['aurc_visual_reality']['runtime_evidence']['command']);
        $this->assertSame('tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php', $payload['evaluations']['aurc_visual_reality']['runtime_evidence']['test']);
        // The remaining refs are honest declared specs, not runtime -> 'declared' + 'spec'.
        $this->assertSame('declared', $payload['evaluations']['multi_agent_handoff_projection']['execution']);
        $this->assertSame('spec', $payload['evaluations']['multi_agent_handoff_projection']['status']);
        $this->assertSame('declared', $payload['evaluations']['documentation_working_set_cache']['execution']);
        $this->assertSame('spec', $payload['evaluations']['documentation_working_set_cache']['status']);
        $this->assertSame('declared', $payload['evaluations']['surface_coverage_matrix']['execution']);
        $this->assertSame('spec', $payload['evaluations']['surface_coverage_matrix']['status']);
        $this->assertSame('declared', $payload['evaluations']['synthetic_reader_tests']['execution']);
        $this->assertSame('spec', $payload['evaluations']['synthetic_reader_tests']['status']);
        $this->assertFalse($payload['writes']);
        // THE HEADLINE LIE, KILLED: the report no longer claims all 52 blocks are integrated.
        // It honestly declares the executes/partial/declared split instead.
        $this->assertFalse($payload['claim_policy']['declares_all_52_adrs_blocks_integrated']);
        $this->assertSame(11, $payload['claim_policy']['executing_block_count']);
        $this->assertSame(12, $payload['claim_policy']['partial_runtime_block_count']);
        $this->assertSame(29, $payload['claim_policy']['declared_spec_block_count']);
        $this->assertSame(
            52,
            $payload['claim_policy']['executing_block_count']
                + $payload['claim_policy']['partial_runtime_block_count']
                + $payload['claim_policy']['declared_spec_block_count'],
        );
        $this->assertSame(
            'documentation_reality_honest_executes_partial_declared_split',
            $payload['claim_policy']['scope'],
        );
        $this->assertFalse($payload['claim_policy']['declares_child_systems_complete']);

        // HONEST per-block contract: every block's readiness_level maps to its real execution
        // tier — executes->L4_integrated, partial->L3_read_only, declared->L2_testable (spec).
        // No declared stub is laundered into L4 anymore. Every block keeps a non-null
        // evaluation_ref, and every executes (L4) block keeps real integration_evidence.
        $this->assertSame(
            [],
            collect($payload['blocks'])
                ->filter(static function (array $block): bool {
                    $execution = $block['execution'] ?? null;
                    $level = $block['readiness_level'];
                    $levelMatchesTier = match ($execution) {
                        'executes' => $level === 'L4_integrated'
                            && $block['evaluation_ref'] !== null
                            && $block['integration_evidence'] !== null,
                        'partial' => $level === 'L3_read_only' && $block['evaluation_ref'] !== null,
                        'declared' => $level === 'L2_testable'
                            && ($block['block_status'] ?? null) === 'spec'
                            && $block['evaluation_ref'] !== null,
                        default => false,
                    };

                    return ! $levelMatchesTier;
                })
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

        // Authority Kernel runs a narrow real check (composes the source registry) but does
        // not yet execute its full verb -> honest 'partial' tier at L3_read_only.
        $authorityKernel = collect($payload['blocks'])->firstWhere('name', 'Documentation Authority Kernel');
        $this->assertSame('partial', $authorityKernel['execution']);
        $this->assertSame('L3_read_only', $authorityKernel['readiness_level']);
        $this->assertSame('authority_kernel', $authorityKernel['evaluation_ref']);
        $this->assertSame('sufficient_for_specification', $authorityKernel['evidence_sufficiency']);
        $this->assertTrue($authorityKernel['readiness_checks']['proof_defined']);

        // AI Context Projection is likewise a partial real check -> L3_read_only.
        $contextProjection = collect($payload['blocks'])->firstWhere('name', 'AI Context Projection');
        $this->assertSame('partial', $contextProjection['execution']);
        $this->assertSame('L3_read_only', $contextProjection['readiness_level']);
        $this->assertSame('ai_context_projection', $contextProjection['evaluation_ref']);

        // AURC Visual Reality genuinely executes its verb from real runtime evidence -> L4.
        $cartography = collect($payload['blocks'])->firstWhere('name', 'AURC Visual Reality');
        $this->assertSame('executes', $cartography['execution']);
        $this->assertSame('L4_integrated', $cartography['readiness_level']);
        $this->assertSame('aurc_visual_reality', $cartography['evaluation_ref']);
        $this->assertSame('php artisan atlas:documentation-reality evaluations --strict --json', $cartography['integration_evidence']['command']);

        // Synthetic Reader Tests is an honest declared spec (not yet runtime) -> L2_testable,
        // block_status 'spec'. It must NOT report as integrated.
        $reader = collect($payload['blocks'])->firstWhere('name', 'Synthetic Reader Tests');
        $this->assertSame('declared', $reader['execution']);
        $this->assertSame('L2_testable', $reader['readiness_level']);
        $this->assertSame('spec', $reader['block_status']);
        $this->assertSame('synthetic_reader_tests', $reader['evaluation_ref']);
    }

    /**
     * ANTI-GAMING integration proof: when AURC is injected into ADRS (the wired runtime
     * path), the aurc_visual_reality runtime_evidence reports the COMPLETE auto-derived
     * structure cardinality (areas -> subsystems -> leaves from the live code index),
     * NOT the 23-node curated macro projection. The operator's prior over-claim was that
     * the report surfaced 23 as if it were "the structure"; this binds node_count >> 23.
     *
     * ADRS leaves cartography nullable+un-injected by default to avoid a boot cycle, so
     * we inject it explicitly here (mirroring the production wiring that supplies it).
     */
    public function test_aurc_runtime_evidence_reports_complete_derived_structure_not_curated_map(): void
    {
        $service = new AtlasDocumentationRealitySystemService(
            app(CanonicalDocsFrontmatterParser::class),
            app(AtlasCodeRealityUsageIntelligenceService::class),
            app(AtlasUniversalRealityCartographyService::class),
        );

        $payload = $service->report();
        $evidence = $payload['evaluations']['aurc_visual_reality']['runtime_evidence'];

        // The cartography IS injected, so this is the real-evidence branch (not the
        // not_injected stub branch).
        $this->assertArrayHasKey('node_count', $evidence);
        $this->assertNotSame('not_injected_to_avoid_boot_cycle', $evidence['status']);

        // node_count/edge_count are the COMPLETE derived totals (>> 23/31 curated).
        $this->assertGreaterThan(100, $evidence['node_count'], 'ADRS must report the complete derived node_count, not the 23-node curated map');
        $this->assertGreaterThan(100, $evidence['edge_count'], 'ADRS must report the complete derived edge_count, not the 31-edge curated map');
        $this->assertGreaterThan(100, $evidence['complete_node_count']);
        $this->assertGreaterThan(100, $evidence['complete_edge_count']);
        $this->assertSame($evidence['complete_node_count'], $evidence['node_count']);
        $this->assertContains($evidence['complete_structure_source'], ['index', 'filesystem']);

        // The curated macro projection node count is surfaced honestly and stays small.
        $this->assertLessThanOrEqual(30, $evidence['curated_macro_node_count']);

        // And it equals the cartography's own complete totals (single source of truth).
        $cartography = app(AtlasUniversalRealityCartographyService::class)->map('universe');
        $this->assertSame($cartography['summary']['complete_node_count'], $evidence['node_count']);
        $this->assertSame($cartography['summary']['complete_edge_count'], $evidence['edge_count']);
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
            // Every block keeps a real evaluation_ref, but its readiness_level is now HONEST:
            // it matches the block's execution tier instead of a blanket L4_integrated.
            $this->assertIsString($block['evaluation_ref'], $block['name']);
            $this->assertTrue($evaluations->has($block['evaluation_ref']), $block['name']);

            $execution = $block['execution'] ?? null;
            $this->assertContains($execution, ['executes', 'partial', 'declared'], $block['name']);

            $expectedLevel = match ($execution) {
                'executes' => 'L4_integrated',
                'partial' => 'L3_read_only',
                'declared' => 'L2_testable',
                default => 'UNKNOWN',
            };
            $this->assertSame($expectedLevel, $block['readiness_level'], $block['name']);

            // The backing evaluation status is DERIVED, and the allowed set depends on the tier:
            // - executes/partial blocks RAN a verdict -> 'ready'/'review' (and the Drift &
            //   Duplication Guard, block #11, may honestly emit 'drift_detected' on real drift
            //   or 'degraded' when the code index is absent — the case in this no-index env);
            // - declared blocks are honest specs, NOT runtime -> the only honest status is
            //   'spec'. A declared block is NEVER allowed to claim 'ready' (that is the
            //   over-claim this fix kills).
            $backingStatus = $evaluations->get($block['evaluation_ref'])['status'];
            if ($execution === 'declared') {
                $this->assertSame('spec', $backingStatus, $block['name']);
                // Declared specs are not integrated, so they carry no integration_evidence.
                $this->assertNull($block['integration_evidence'], $block['name']);
            } else {
                $this->assertContains(
                    $backingStatus,
                    ['ready', 'review', 'drift_detected', 'degraded'],
                    $block['name'],
                );
            }
        }

        // Every executes (L4) block is backed by real integration_evidence pointing at its ref.
        foreach (collect($payload['blocks'])->where('execution', 'executes') as $block) {
            $this->assertSame(
                $block['evaluation_ref'],
                $block['integration_evidence']['evaluation_ref'],
                $block['name'],
            );
        }

        // All 52 blocks keep a non-null evaluation_ref — the change is the level, not the wiring.
        $this->assertSame(52, collect($payload['blocks'])->pluck('evaluation_ref')->filter()->count());
        $this->assertGreaterThanOrEqual(40, collect($payload['blocks'])->pluck('evaluation_ref')->unique()->count());
        // And the honest tier split sums to the full 52 (no block hidden between tiers).
        $byExecution = collect($payload['blocks'])->countBy('execution');
        $this->assertSame(11, $byExecution['executes']);
        $this->assertSame(12, $byExecution['partial']);
        $this->assertSame(29, $byExecution['declared']);
    }

    public function test_each_adrs_block_has_acceptance_contract_with_owner_commands_and_tests(): void
    {
        $payload = app(AtlasDocumentationRealitySystemService::class)->report();
        $matrix = $payload['block_acceptance_matrix'];

        // The acceptance matrix passes its honest gate (incomplete_count===0), NOT because all
        // 52 are 'accepted'. It still has one item per block, and no block is incomplete —
        // every block is honestly classified as accepted / partial_runtime / declared.
        $this->assertSame('ready', $matrix['status']);
        $this->assertCount(52, $matrix['items']);
        $this->assertSame([], $matrix['incomplete_blocks']);
        $this->assertSame(11, $matrix['accepted_block_count']);
        $this->assertSame(12, $matrix['partial_runtime_block_count']);
        $this->assertSame(29, $matrix['declared_block_count']);
        $this->assertSame(0, $matrix['incomplete_block_count']);

        foreach ($matrix['items'] as $item) {
            // Per-item status is now 4-way and DERIVED — declared/partial blocks are honestly
            // NOT 'accepted'-as-integrated, and an item is NEVER 'incomplete' (all are classified).
            $this->assertContains(
                $item['status'],
                ['accepted', 'partial_runtime', 'declared'],
                $item['block_name'],
            );
            $this->assertNotSame('incomplete', $item['status'], $item['block_name']);
            // The honest per-item status lines up with its execution tier.
            $expectedStatus = match ($item['execution'] ?? null) {
                'executes' => 'accepted',
                'partial' => 'partial_runtime',
                'declared' => 'declared',
                default => 'UNKNOWN',
            };
            $this->assertSame($expectedStatus, $item['status'], $item['block_name']);
            $this->assertIsString($item['owner_doc'], $item['block_name']);
            $this->assertNotSame('', $item['owner_doc'], $item['block_name']);
            $this->assertNotEmpty($item['required_commands'], $item['block_name']);
            $this->assertContains('php artisan atlas:documentation-reality acceptance --strict --json', $item['required_commands'], $item['block_name']);
            $this->assertNotEmpty($item['required_tests'], $item['block_name']);
            $this->assertContains('tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php', $item['required_tests'], $item['block_name']);
            $this->assertTrue($item['quality_floor']['must_be_read_only'], $item['block_name']);
            $this->assertTrue($item['quality_floor']['must_fail_closed_when_evidence_missing'], $item['block_name']);
        }

        // Pin the honest tier split across all items so no spec can be silently re-blessed.
        $itemsByStatus = collect($matrix['items'])->countBy('status');
        $this->assertSame(11, $itemsByStatus['accepted']);
        $this->assertSame(12, $itemsByStatus['partial_runtime']);
        $this->assertSame(29, $itemsByStatus['declared']);

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

    public function test_command_evaluations_action_exposes_executing_and_declared_block_evaluations(): void
    {
        $exit = Artisan::call('atlas:documentation-reality', [
            'action' => 'evaluations',
            '--json' => true,
            '--strict' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('ready', $payload['status']);
        // The action exposes the honest level split, not a blanket all-52 L4.
        $this->assertSame(11, $payload['readiness_matrix']['levels']['L4_integrated']);
        $this->assertSame(12, $payload['readiness_matrix']['levels']['L3_read_only']);
        $this->assertSame(29, $payload['readiness_matrix']['levels']['L2_testable']);
        $this->assertArrayHasKey('aurc_visual_reality', $payload['evaluations']);
        // aurc genuinely executes; canonical_example_corpus is an honest declared spec.
        $this->assertSame('executes', $payload['evaluations']['aurc_visual_reality']['execution']);
        $this->assertSame('ready', $payload['evaluations']['aurc_visual_reality']['status']);
        $this->assertArrayHasKey('canonical_example_corpus', $payload['evaluations']);
        $this->assertSame('declared', $payload['evaluations']['canonical_example_corpus']['execution']);
        $this->assertSame('spec', $payload['evaluations']['canonical_example_corpus']['status']);
    }
}
