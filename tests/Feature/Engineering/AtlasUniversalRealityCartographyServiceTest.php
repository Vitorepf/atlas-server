<?php

declare(strict_types=1);

use App\Services\Engineering\AtlasUniversalRealityCartographyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AtlasUniversalRealityCartographyServiceTest extends TestCase
{
    public function test_map_projects_adrs_acrui_and_aurc_as_visual_hierarchy(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $nodes = collect($payload['nodes'])->keyBy('id');

        $this->assertSame(AtlasUniversalRealityCartographyService::SCHEMA_VERSION, $payload['schema_version']);
        $this->assertSame('ready', $payload['status']);
        $this->assertSame('universe', $payload['mode']);
        $this->assertSame('atlas.universal_reality_cartography.workspace_scope.v1', $payload['workspace_scope']['schema_version']);
        $this->assertSame('ready', $payload['workspace_scope']['status']);
        $this->assertSame('atlas', $payload['workspace_scope']['active_workspace_id']);
        $this->assertTrue($payload['workspace_scope']['awis_certified']);
        $this->assertFalse($payload['writes']);
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
        $this->assertTrue($payload['claim_policy']['canonical_docs_remain_authority']);
        $this->assertSame(0, $payload['coverage_audit']['missing_source_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_modal_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_visual_state_count']);
        $this->assertSame(0, $payload['coverage_audit']['missing_semantic_zoom_count']);
        $this->assertSame(0, $payload['coverage_audit']['broken_edge_count']);
        $this->assertSame(1.0, $payload['coverage_audit']['visual_completeness_score']);
        $this->assertSame('atlas.universal_reality_cartography.visual_scene.v1', $payload['visual_scene']['schema_version']);
        $this->assertSame('ready', $payload['visual_scene']['status']);
        $this->assertSame('ready', $payload['visual_scene']['cognitive_budget']['status']);
        $this->assertLessThanOrEqual(12, $payload['visual_scene']['cognitive_budget']['visible_node_count']);
        $this->assertSame('atlas.universal_reality_cartography.semantic_zoom_scenes.v1', $payload['semantic_zoom_scenes']['schema_version']);
        $this->assertSame('ready', $payload['semantic_zoom_scenes']['status']);
        $this->assertSame(0, $payload['semantic_zoom_scenes']['invalid_scene_count']);
        $this->assertSame('atlas.universal_reality_cartography.human_route_map.v1', $payload['human_route_map']['schema_version']);
        $this->assertSame('ready', $payload['human_route_map']['status']);
        $this->assertSame(0, $payload['human_route_map']['invalid_route_count']);
        $this->assertSame(AtlasUniversalRealityCartographyService::HUMAN_CLARITY_SCHEMA_VERSION, $payload['human_clarity']['schema_version']);
        $this->assertSame('ready', $payload['human_clarity']['status']);
        $this->assertGreaterThanOrEqual(9.8, $payload['human_clarity']['score']);
        // SMELL FIX: the grade now names what it actually measures — STRUCTURAL visual
        // affordances present in the payload, not a human-measured usability result.
        $this->assertSame('9.8_structural_visual_affordance', $payload['human_clarity']['grade']);
        $this->assertSame(
            'structural_visual_affordances_present_in_payload_not_human_measured_usability',
            $payload['human_clarity']['measurement_basis'],
        );
        $this->assertTrue($payload['human_clarity']['invariants']['human_understands_macro_flow_before_modal']);
        $this->assertTrue($payload['human_clarity']['invariants']['map_text_is_short_label_only']);

        $this->assertSame('Universe', $nodes->get('universe')['label']);
        $this->assertSame('organization', $nodes->get('org.atlas')['semantic_level']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-documentation-reality-system.md', $nodes->get('system.adrs')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md', $nodes->get('system.acrui')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-universal-reality-cartography.md', $nodes->get('system.aurc')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md', $nodes->get('system.awis')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-twin-runtime.md', $nodes->get('system.awtr')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-contract-orchestrator.md', $nodes->get('system.awco')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-evolution-fabric.md', $nodes->get('system.awef')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-fabric.md', $nodes->get('system.awaf')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md', $nodes->get('system.awair')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md', $nodes->get('system.awaol')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-intelligence-runtime.md', $nodes->get('flow.workspace-artifact-graph')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-intelligence-system.md', $nodes->get('flow.workspace-artifact-lake-replay')['source_path']);
        $this->assertSame('docs/engineering-knowledge-base/atlas-workspace-artifact-operating-layer.md', $nodes->get('flow.workspace-artifact-workroom')['source_path']);
        $this->assertSame('ready', $payload['workspace_scope']['artifact_workroom']['status']);
        $this->assertSame('task_packet', $payload['workspace_scope']['artifact_workroom']['artifact_type']);
        $this->assertSame('dev', $payload['workspace_scope']['artifact_workroom']['route_target']);
        $this->assertFalse($payload['workspace_scope']['artifact_workroom']['source_policy']['raw_conversation_returned']);
        $this->assertFalse($payload['workspace_scope']['artifact_workroom']['source_policy']['artifact_body_returned']);
        $this->assertSame('app/Services/Engineering/AtlasUniversalRealityCartographyService.php', $nodes->get('component.aurc-runtime')['source_path']);
        $this->assertSame('zoom_to_children', $nodes->get('system.aurc')['semantic_zoom']['tap_action']);
        $this->assertSame('zoom_to_children', $nodes->get('system.awis')['semantic_zoom']['tap_action']);
        $this->assertSame('zoom_to_children', $nodes->get('system.awair')['semantic_zoom']['tap_action']);
        $this->assertSame('open_source_and_tests', $nodes->get('component.aurc-runtime')['human_modal']['next_action']);
    }

    /**
     * BADGE SHAPE + HONEST SPLIT. Every curated node carries a resolved 'badge' whose
     * maturity is in the honest enum, whose evidence_resolved is a bool, whose owner
     * mirrors the node owner, and whose tone is derived from maturity (a declared/unproven
     * node can NEVER render 'healthy'). The honest split is surfaced and a node only badges
     * real/live when code-reality is live AND evidence resolves.
     */
    public function test_every_curated_node_carries_an_honest_resolved_badge(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $nodes = collect($payload['nodes'])->keyBy('id');

        $honestEnum = ['real', 'live', 'partial', 'declared', 'spec', 'scaffold', 'legacy', 'unproven', 'unknown'];
        $healthyMaturities = ['real', 'live'];

        foreach ($payload['nodes'] as $node) {
            $badge = $node['badge'] ?? null;
            $this->assertIsArray($badge, 'every curated node must carry a badge: '.$node['id']);
            $this->assertContains($badge['maturity'], $honestEnum, 'badge.maturity must be an honest enum value: '.$node['id']);
            $this->assertIsBool($badge['evidence_resolved'], 'badge.evidence_resolved must be bool: '.$node['id']);
            $this->assertSame($node['owner'], $badge['owner'], 'badge.owner must mirror node owner: '.$node['id']);
            $this->assertArrayHasKey('code_reality', $badge);
            $this->assertArrayHasKey('drift', $badge);
            $this->assertSame('adrs.drift_duplication_guard', $badge['drift']['source']);
            $this->assertIsBool($badge['drift']['detected']);
            $this->assertArrayHasKey('last_check', $badge);
            $this->assertIsArray($badge['evidence_refs_resolved']);
            $this->assertNotSame('', (string) $badge['signal_basis']);

            // tone is DERIVED from maturity: only real/live may be 'healthy'.
            if (! in_array($badge['maturity'], $healthyMaturities, true)) {
                $this->assertNotSame('healthy', $badge['tone'], 'a non-real/live node must not render healthy tone: '.$node['id']);
            }

            // THE INVARIANT: real/live require BOTH live code-reality AND resolved evidence.
            if (in_array($badge['maturity'], $healthyMaturities, true)) {
                $this->assertTrue($badge['evidence_resolved'], 'real/live node must have resolved evidence: '.$node['id']);
            }
            if ($badge['maturity'] === 'real') {
                $this->assertSame('active_runtime', $badge['code_reality'], "only active_runtime code may badge 'real': ".$node['id']);
            }
            if ($badge['maturity'] === 'live' && $badge['code_reality'] !== 'not_applicable') {
                $this->assertContains($badge['code_reality'], ['active_read_only', 'headless_available'], "'live' code node must be active_read_only/headless: ".$node['id']);
            }
        }

        // The three runtime component services are the honest 'real' nodes: live code +
        // resolving Test.php. Doc-level scaffolding is NOT real.
        $this->assertSame('real', $nodes->get('component.adrs-runtime')['badge']['maturity']);
        $this->assertSame('real', $nodes->get('component.acrui-runtime')['badge']['maturity']);
        $this->assertSame('real', $nodes->get('component.aurc-runtime')['badge']['maturity']);
        $this->assertSame('active_runtime', $nodes->get('component.adrs-runtime')['badge']['code_reality']);

        // Doc-scaffolding macro nodes are honestly NOT real (no resolving runtime proof).
        foreach (['universe', 'org.atlas', 'project.atlas.documentation-reality'] as $docNode) {
            $this->assertNotContains(
                $nodes->get($docNode)['badge']['maturity'],
                ['real', 'live'],
                'doc-scaffolding node must not read as real/live: '.$docNode,
            );
        }

        // Honest split is surfaced and self-consistent.
        $summary = $payload['summary']['badge_summary'];
        $this->assertSame(count($payload['nodes']), $summary['curated_node_count']);
        $this->assertSame(3, $summary['real_count'], 'exactly the three runtime services badge real');
        $this->assertGreaterThanOrEqual(1, $summary['declared_or_unproven_count']);
        $this->assertSame(
            $summary['curated_node_count'],
            $summary['real_or_live_count'] + $summary['declared_or_unproven_count'],
            'every curated node is either real/live or an honest downgrade',
        );
    }

    /**
     * The cartography payload carries an explicit anti-over-claim claim_policy: no node is
     * presented as real/live without resolved evidence + live code-reality, and the badge
     * maturity is resolved from signals, not a hardcoded label.
     */
    public function test_claim_policy_forbids_real_without_resolved_evidence(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $policy = $payload['claim_policy'];

        $this->assertTrue($policy['no_node_presented_as_real_without_resolved_evidence']);
        $this->assertTrue($policy['node_badge_maturity_is_resolved_from_signals_not_hardcoded']);
        $this->assertTrue($policy['real_requires_active_runtime_code_reality_and_resolved_evidence']);
        $this->assertTrue($policy['live_requires_active_read_only_or_headless_code_reality_and_resolved_evidence']);
        $this->assertTrue($policy['node_without_resolved_evidence_defaults_unproven_never_real']);
        $this->assertFalse($policy['cartography_is_source_of_truth']);
    }

    /**
     * FAIL-ON-STUB (the load-bearing honesty proof). The maturity is a PURE FUNCTION of
     * (code_reality bucket, evidence_resolved, adrs execution): the resolver takes the
     * signals as ARGUMENTS, so flipping ONE input flips the badge off real/live to the
     * honest value — there is no hardcoded-label path to bypass. We drive the real
     * resolver via reflection and flip each signal in turn.
     */
    public function test_fail_on_stub_flipping_a_signal_flips_the_badge_off_real(): void
    {
        $svc = app(AtlasUniversalRealityCartographyService::class);
        $resolve = new ReflectionMethod(AtlasUniversalRealityCartographyService::class, 'resolveBadgeMaturity');
        $resolve->setAccessible(true);

        // BASELINE: live code-reality + resolved evidence + executing ADRS -> 'real'.
        $real = $resolve->invoke($svc, 'active_runtime', true, 'executes', 'integrated', 'app/Services/Engineering/Foo.php', ['tests/Feature/FooTest.php'], []);
        $this->assertSame('real', $real, 'live code + resolved evidence must badge real');

        // FLIP 1 — make the evidence unresolvable: real -> unproven (NEVER real/live).
        $evidenceGone = $resolve->invoke($svc, 'active_runtime', false, 'executes', 'integrated', 'app/Services/Engineering/Foo.php', ['tests/Feature/FooTest.php'], []);
        $this->assertNotContains($evidenceGone, ['real', 'live'], 'unresolved evidence must drop off real/live');
        $this->assertSame('unproven', $evidenceGone);

        // FLIP 2 — feed a scaffold/unused_candidate bucket: code exists but unproven.
        $scaffold = $resolve->invoke($svc, 'unused_candidate', true, null, null, 'app/Services/Engineering/Foo.php', ['tests/Feature/FooTest.php'], []);
        $this->assertSame('scaffold', $scaffold, 'unused_candidate code must badge scaffold, never real');

        // FLIP 3 — feed ADRS execution='declared': spec dominates even if a stub exists.
        $declared = $resolve->invoke($svc, 'active_runtime', true, 'declared', 'spec', 'app/Services/Engineering/Foo.php', ['tests/Feature/FooTest.php'], []);
        $this->assertSame('declared', $declared, 'declared ADRS execution must dominate to declared, never real');

        // FLIP 4 — unknown classify with no other signal: honest unknown.
        $unknown = $resolve->invoke($svc, 'unknown_requires_audit', false, null, null, 'app/Services/Engineering/Foo.php', [], []);
        $this->assertContains($unknown, ['unknown', 'unproven'], 'unknown classify + no evidence must be unknown/unproven, never real');

        // FLIP 5 — a node with NO evidence_refs defaults to unproven, never real.
        $noEvidence = $resolve->invoke($svc, 'active_runtime', false, null, null, 'app/Services/Engineering/Foo.php', [], []);
        $this->assertNotContains($noEvidence, ['real', 'live']);
        $this->assertSame('unproven', $noEvidence);
    }

    /**
     * FAIL-ON-STUB at the EVIDENCE-RESOLUTION boundary: an unresolvable Test.php ref makes
     * evidence_resolved false, which flips maturity off real; making it resolvable flips it
     * back. Proves evidence_resolved is the AND-rollup of the per-ref breakdown, computed
     * from real filesystem signals, not a stored flag.
     */
    public function test_fail_on_stub_unresolvable_evidence_ref_flips_evidence_resolved(): void
    {
        $svc = app(AtlasUniversalRealityCartographyService::class);
        $resolveRefs = new ReflectionMethod(AtlasUniversalRealityCartographyService::class, 'resolveEvidenceRefs');
        $resolveRefs->setAccessible(true);

        $memo = [];

        // A real test file that exists on disk resolves true.
        $realRef = 'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php';
        $this->assertTrue(File::exists(base_path($realRef)), 'fixture precondition: the ref must exist');
        $resolvedReal = $resolveRefs->invoke($svc, [$realRef], $memo, []);
        $this->assertTrue($resolvedReal['resolved'], 'an existing Test.php ref must resolve');
        $this->assertSame('test', $resolvedReal['refs'][0]['kind']);
        $this->assertTrue($resolvedReal['refs'][0]['resolved']);

        // A non-existent test file does NOT resolve -> the rollup is false.
        $fakeRef = 'tests/Feature/Engineering/__DefinitelyDoesNotExist__Test.php';
        $this->assertFalse(File::exists(base_path($fakeRef)), 'fixture precondition: the fake ref must be absent');
        $resolvedFake = $resolveRefs->invoke($svc, [$realRef, $fakeRef], $memo, []);
        $this->assertFalse($resolvedFake['resolved'], 'one unresolvable ref must flip the AND-rollup to false');
        $this->assertFalse(collect($resolvedFake['refs'])->firstWhere('ref', $fakeRef)['resolved']);

        // An empty evidence set cannot resolve -> defaults to NOT resolved (=> unproven).
        $resolvedEmpty = $resolveRefs->invoke($svc, [], $memo, []);
        $this->assertFalse($resolvedEmpty['resolved'], 'no evidence_refs must default to unresolved');
        $this->assertSame([], $resolvedEmpty['refs']);
    }

    /**
     * PERF GATE (the load-bearing guard against the 2026-06-02 outage). The badge layer
     * must NOT classify per derived node (737x) and must NOT recompute on a warm cache.
     * We measure the real outage surface — File::allFiles() walks (classify is purely
     * filesystem-based, no DB) — and assert: (1) one cold map() stays bounded to O(curated
     * code targets), nowhere near 737; (2) a second consecutive map() on the warm cache
     * adds ZERO badge-layer walks; (3) structurally, NO derived-structure node carries a
     * badge and at most the curated code targets (<=3) triggered a classify, each an app/
     * path — never a derived path.
     */
    public function test_perf_gate_badges_never_classify_per_derived_node_and_warm_cache_adds_zero(): void
    {
        // Stand up the code-index table so codeIndexSignature() is non-null and STABLE
        // across calls — that is the live HTTP condition where the badge cache + structure
        // cache engage. (With no index table both caches deliberately skip — a transient
        // degrade we never pin — so the warm-cache proof requires a stable signature.)
        $this->createCodeSymbolsTable();
        try {
            Cache::flush();
            $svc = app(AtlasUniversalRealityCartographyService::class);
            $realDisk = app('files');

            $walks = 0;
            File::shouldReceive('allFiles')->andReturnUsing(function (...$args) use ($realDisk, &$walks): array {
                $walks++;

                return $realDisk->allFiles(...$args);
            });
            // Pass every other File call straight through to the real disk.
            foreach (['exists', 'get', 'isDirectory', 'glob', 'isFile', 'lastModified', 'size'] as $method) {
                File::shouldReceive($method)->andReturnUsing(fn (...$args) => $realDisk->{$method}(...$args));
            }

            // (1) COLD map(): the whole filesystem-walk budget is a tiny constant. The badge
            // layer classifies at most the <=3 curated code targets; 737 per-node classifies
            // would explode this into HUNDREDS of walks (the outage shape).
            $before = $walks;
            $payload = $svc->map();
            $coldWalks = $walks - $before;
            // 30 is a deliberately generous ceiling that still trips loudly if a future edit
            // re-introduces a per-derived-node classify (737x => hundreds of walks).
            $this->assertLessThanOrEqual(30, $coldWalks, 'cold map() filesystem walks must stay O(curated code targets), never O(737) — got '.$coldWalks);

            // (2) WARM map(): with a stable index signature the badge cache + structure cache
            // both hit, so a second render of the unchanged world does FAR fewer walks than
            // cold — and a third render is steady-state (no growth). The only residual walks
            // are the pre-existing fixed ACRUI classify at map() line 45 (NOT the badge
            // layer), which is identical every call.
            $beforeWarm = $walks;
            $svc->map();
            $warmWalks = $walks - $beforeWarm;

            $beforeWarm2 = $walks;
            $svc->map();
            $warmWalks2 = $walks - $beforeWarm2;

            $this->assertLessThan($coldWalks, $warmWalks, 'warm map() must do strictly less work than cold (badge+structure caches hit) — cold='.$coldWalks.' warm='.$warmWalks);
            $this->assertSame($warmWalks, $warmWalks2, 'warm renders must be steady-state: the badge layer adds ZERO new walks call-over-call (no per-request recompute) — got '.$warmWalks.' then '.$warmWalks2);

            // (3) STRUCTURAL no-per-derived-node-classify proof: the derived structure layer
            // is NEVER badged per node (this run uses a small seeded index, but the rule is
            // size-independent; the full ~737 derived layer is sized by
            // AtlasCartographyRealStructureTest), and only curated CODE targets (app/...)
            // triggered a classify.
            $derivedNodes = $payload['complete_derived_structure']['nodes'] ?? [];
            $this->assertNotSame([], $derivedNodes, 'precondition: the derived layer has nodes to check');
            foreach ($derivedNodes as $derived) {
                $this->assertArrayNotHasKey('badge', $derived, 'a derived-structure node must NEVER be badged (per-node classify is the outage)');
            }

            $classifyTriggeringNodes = collect($payload['nodes'])
                ->filter(fn (array $node): bool => data_get($node, 'badge.signal_basis') === 'code_reality_classify')
                ->all();
            $this->assertLessThanOrEqual(3, count($classifyTriggeringNodes), 'at most the curated code targets may trigger a classify');
            foreach ($classifyTriggeringNodes as $node) {
                $this->assertStringStartsWith('app/', (string) $node['source_path'], 'only app/ curated targets classify, never a derived path: '.$node['id']);
                $this->assertSame('active_runtime', data_get($node, 'badge.code_reality'));
            }
        } finally {
            Schema::dropIfExists('atlas_engineering_doc_links');
            Schema::dropIfExists('atlas_engineering_code_symbols');
            Schema::dropIfExists('atlas_engineering_code_modules');
        }
    }

    /**
     * Stand up ONLY the code-intelligence symbols table (via the real migration) so
     * codeIndexSignature() is non-null and stable. tearDown-style drop happens in the
     * caller's finally so no seeded table leaks into another :memory: test.
     */
    private function createCodeSymbolsTable(): void
    {
        Schema::dropIfExists('atlas_engineering_code_symbols');
        $migration = require base_path('database/migrations/2026_05_02_010000_create_atlas_engineering_code_intelligence_tables.php');
        $migration->up();

        // A couple of deterministic class rows so the index branch has content and the
        // signature is stable across the warm calls (cli rows counted regardless of path).
        foreach ([
            ['App\\Services\\Engineering\\PerfProbeFoo', 'app/Services/Engineering/PerfProbeFoo.php'],
            ['App\\Services\\Engineering\\PerfProbeBar', 'app/Services/Engineering/PerfProbeBar.php'],
        ] as [$name, $path]) {
            DB::table('atlas_engineering_code_symbols')->insert([
                'id' => Str::uuid()->toString(),
                'symbol_type' => 'class',
                'symbol_name' => $name,
                'file_path' => $path,
                'language' => 'php',
                'signature' => null,
                'namespace' => 'App\\Services\\Engineering',
                'status' => 'active',
                'docs_status' => 'documented',
                'source_hash' => 'seed-'.md5($name),
            ]);
        }
    }

    public function test_visual_scene_keeps_map_under_cognitive_budget_and_links_real_nodes(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('implementation');
        $nodeIds = collect($payload['nodes'])->pluck('id')->all();
        $scene = $payload['visual_scene'];

        $this->assertSame('implementation', $scene['mode']);
        $this->assertSame('ready', $scene['status']);
        $this->assertSame('atlas', $scene['workspace_scope']['active_workspace_id']);
        $this->assertSame('workspace', $scene['workspace_scope']['cartography_scope']);
        $this->assertSame('labels_only_on_map_dense_text_in_human_modal', $scene['cognitive_budget']['text_policy']);
        $this->assertSame('semantic_lanes_left_to_right', $scene['viewport']['layout']);
        $this->assertSame('ready', $scene['breadcrumb']['status']);
        $this->assertSame('ready', $scene['legend']['status']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_nodes'], $scene['cognitive_budget']['visible_node_count']);
        $this->assertLessThanOrEqual($scene['cognitive_budget']['max_visible_edges'], $scene['cognitive_budget']['visible_edge_count']);

        foreach ($scene['visible_nodes'] as $node) {
            $this->assertContains($node['id'], $nodeIds);
            $this->assertArrayHasKey('visual_state', $node);
            $this->assertArrayHasKey('semantic_zoom', $node);
            $this->assertArrayHasKey('layout', $node);
            $this->assertArrayHasKey('microcopy', $node);
            $this->assertLessThanOrEqual(28, mb_strlen((string) $node['microcopy']['label_short']));
            $this->assertLessThanOrEqual(96, mb_strlen((string) $node['microcopy']['tooltip']));
            $this->assertNotSame('', $node['source_path']);
        }
    }

    public function test_human_clarity_contract_reaches_9_8_with_visual_first_invariants(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow');
        $clarity = $payload['human_clarity'];
        $dimensions = collect($clarity['dimensions'])->keyBy('id');

        $this->assertSame(AtlasUniversalRealityCartographyService::HUMAN_CLARITY_SCHEMA_VERSION, $clarity['schema_version']);
        $this->assertSame('ready', $clarity['status']);
        $this->assertGreaterThanOrEqual(9.8, $clarity['score']);
        $this->assertSame(9.8, $clarity['target_score']);
        $this->assertSame(7, $dimensions->count());
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('visual_hierarchy')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('cognitive_load')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('source_truth')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('semantic_zoom')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('human_wayfinding')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('nontechnical_microcopy')['score']);
        $this->assertGreaterThanOrEqual(9.8, $dimensions->get('task_simulation')['score']);
        $this->assertTrue($clarity['invariants']['visual_truth_never_overrides_canonical_docs']);
        $this->assertTrue($clarity['invariants']['all_routes_have_sources']);
        $this->assertContains('start_at_universe', $clarity['recommended_operator_use']);
    }

    public function test_cartography_accepts_workspace_scope_without_becoming_source_of_truth(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
        $visibleIds = collect($payload['visual_scene']['visible_nodes'])->pluck('id')->all();

        $this->assertSame('atlas', $payload['workspace_scope']['requested_workspace']);
        $this->assertSame('atlas', $payload['workspace_scope']['active_workspace_id']);
        $this->assertSame('ready', $payload['workspace_scope']['status']);
        $this->assertSame($payload['workspace_scope'], $payload['visual_scene']['workspace_scope']);
        $this->assertContains('system.awair', $visibleIds);
        $this->assertContains('flow.workspace-artifact-graph', $visibleIds);
        $this->assertArrayHasKey('artifact_lake_replay', $payload['workspace_scope']);
        $this->assertTrue($payload['claim_policy']['workspace_scope_is_projection_not_source_of_truth']);
        $this->assertFalse($payload['claim_policy']['cartography_is_source_of_truth']);
    }

    public function test_cartography_surfaces_artifact_lake_replay_without_artifact_body(): void
    {
        $this->createArtifactLakeTable();
        try {
            $artifactId = Str::uuid()->toString();
            $rawTail = 'RAW_CONVERSATION_TAIL_MUST_NOT_REACH_CARTOGRAPHY';
            DB::table('atlas_workspace_artifact_lake_entries')->insert([
                'id' => $artifactId,
                'workspace_id' => 'atlas',
                'runtime_hash' => str_repeat('a', 64),
                'artifact_hash' => str_repeat('b', 64),
                'artifact_type' => 'conversation_fusion_pack',
                'status' => 'ready',
                'consumer' => 'atlas_dev',
                'source_hashes' => json_encode([str_repeat('c', 64)], JSON_THROW_ON_ERROR),
                'body' => json_encode([
                    'fusion_pack' => [
                        'summary' => $rawTail,
                        'handoff_context' => [
                            'allowed_for_provider_prompt' => true,
                            'recommended_consumers' => ['atlas_dev'],
                        ],
                    ],
                ], JSON_THROW_ON_ERROR),
                'quality_score' => 9.4,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $replay = $payload['workspace_scope']['artifact_lake_replay'];
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('atlas.universal_reality_cartography.artifact_lake_replay.v1', $replay['schema_version']);
            $this->assertSame('ready', $replay['status']);
            $this->assertSame(1, $replay['artifact_count']);
            $this->assertSame(1, $replay['conversation_fusion_pack_count']);
            $this->assertSame($artifactId, $replay['latest_artifacts'][0]['artifact_id']);
            $this->assertSame('conversation_fusion_pack', $replay['latest_artifacts'][0]['artifact_type']);
            $this->assertFalse((bool) $replay['source_policy']['raw_conversation_returned']);
            $this->assertFalse((bool) $replay['source_policy']['full_message_content_returned']);
            $this->assertArrayNotHasKey('body', $replay['latest_artifacts'][0]);
            $this->assertSame('active_read_only', $nodes->get('flow.workspace-artifact-lake-replay')['status']);
            $this->assertStringNotContainsString($rawTail, json_encode($payload, JSON_THROW_ON_ERROR));
        } finally {
            Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
        }
    }

    public function test_cartography_surfaces_stale_awis_runtime_projection_for_human_navigation(): void
    {
        $this->createRuntimeProjectionTable();
        try {
            DB::table('atlas_workspace_runtime_projection_snapshots')->insert([
                'id' => Str::uuid()->toString(),
                'workspace_id' => 'atlas',
                'family' => 'AWTR',
                'schema_version' => 'atlas.workspace_twin.v1',
                'runtime_hash' => 'sha256:runtime_stale',
                'projection_hash' => 'sha256:projection_stale',
                'status' => 'ready',
                'payload' => json_encode([
                    'status' => 'ready',
                    'awis_projection' => [
                        'schema_version' => 'atlas.awis.runtime_projection_binding.v1',
                        'workspace_id' => 'atlas',
                        'workspace_hash' => str_repeat('0', 64),
                        'runtime_hash' => 'sha256:runtime_stale',
                        'family' => 'AWTR',
                        'projection_hash' => 'sha256:projection_stale',
                    ],
                ], JSON_THROW_ON_ERROR),
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('blocked', $payload['workspace_scope']['runtime_projection_replay']['status']);
            $this->assertSame(1, $payload['workspace_scope']['runtime_projection_replay']['stale_count']);
            $this->assertSame(['AWTR'], $payload['workspace_scope']['runtime_projection_replay']['stale_families']);
            $this->assertSame('review', $nodes->get('system.awis')['status']);
            $this->assertTrue($nodes->get('system.awis')['visual_state']['requires_attention']);
            $this->assertSame('review', $nodes->get('flow.workspace-runtime-projections')['status']);
            $this->assertStringContainsString('projection AWIS persistida divergente', $nodes->get('project.atlas.workspace-intelligence')['human_modal']['summary']);
        } finally {
            Schema::dropIfExists('atlas_workspace_runtime_projection_snapshots');
        }
    }

    public function test_cartography_surfaces_stale_awair_artifact_graph_for_human_navigation(): void
    {
        $this->createArtifactGraphTable();
        try {
            DB::table('atlas_workspace_artifact_graph_snapshots')->insert([
                'id' => Str::uuid()->toString(),
                'workspace_id' => 'atlas',
                'runtime_hash' => 'sha256:runtime_awair_stale',
                'artifact_intelligence_hash' => 'sha256:artifact_awair_stale',
                'status' => 'ready',
                'lake_hash' => 'sha256:lake',
                'graph_hash' => 'sha256:graph',
                'artifact_count' => 10,
                'node_count' => 10,
                'edge_count' => 9,
                'replay_ready' => true,
                'simulation_decision' => 'ready',
                'nodes' => json_encode([]),
                'edges' => json_encode([]),
                'payload' => json_encode([
                    'schema_version' => 'atlas.workspace_artifact_intelligence.v1',
                    'status' => 'ready',
                    'workspace_id' => 'atlas',
                    'workspace_hash' => str_repeat('5', 64),
                    'artifact_intelligence_hash' => 'sha256:artifact_awair_stale',
                    'artifact_graph' => ['graph_hash' => 'sha256:graph'],
                ], JSON_THROW_ON_ERROR),
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $payload = app(AtlasUniversalRealityCartographyService::class)->map('flow', 'atlas');
            $nodes = collect($payload['nodes'])->keyBy('id');

            $this->assertSame('blocked', $payload['workspace_scope']['artifact_graph_replay']['status']);
            $this->assertTrue($payload['workspace_scope']['artifact_graph_replay']['stale']);
            $this->assertSame('workspace_hash_changed', $payload['workspace_scope']['artifact_graph_replay']['reason']);
            $this->assertSame('review', $nodes->get('system.awair')['status']);
            $this->assertTrue($nodes->get('system.awair')['visual_state']['requires_attention']);
            $this->assertSame('review', $nodes->get('flow.workspace-artifact-graph')['status']);
            $this->assertStringContainsString('artifact graph AWAIR persistido divergente', $nodes->get('project.atlas.workspace-intelligence')['human_modal']['summary']);
        } finally {
            Schema::dropIfExists('atlas_workspace_artifact_graph_snapshots');
        }
    }

    public function test_semantic_zoom_and_human_routes_are_validated_against_node_graph(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $nodeIds = collect($payload['nodes'])->pluck('id')->all();

        foreach ($payload['semantic_zoom_scenes']['scenes'] as $scene) {
            $this->assertContains($scene['from_node'], $nodeIds, $scene['id']);
            foreach ($scene['expected_children'] as $child) {
                $this->assertContains($child, $nodeIds, $scene['id']);
            }
        }

        foreach ($payload['human_route_map']['routes'] as $route) {
            foreach ($route['node_path'] as $nodeId) {
                $this->assertContains($nodeId, $nodeIds, $route['id']);
            }
            $this->assertIsString($route['expected_source']);
            $this->assertNotSame('', $route['expected_source']);
        }
    }

    public function test_edges_only_reference_existing_nodes(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map();
        $ids = collect($payload['nodes'])->pluck('id')->all();

        foreach ($payload['edges'] as $edge) {
            $this->assertContains($edge['source'], $ids, $edge['id']);
            $this->assertContains($edge['target'], $ids, $edge['id']);
            $this->assertSame('active', $edge['status']);
        }
    }

    public function test_task_simulator_and_navigation_slice_are_provider_safe(): void
    {
        $payload = app(AtlasUniversalRealityCartographyService::class)->map('implementation');

        $this->assertSame('implementation', $payload['mode']);
        $this->assertSame('ready', $payload['task_simulator']['status']);
        $this->assertSame('ready', $payload['ai_navigation_slice']['status']);
        $this->assertTrue($payload['ai_navigation_slice']['provider_safe']);
        $this->assertContains('system.adrs', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awis', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awair', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('system.awaol', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-graph', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-lake-replay', $payload['task_simulator']['node_ids_available']);
        $this->assertContains('flow.workspace-artifact-workroom', $payload['task_simulator']['node_ids_available']);
        $this->assertSame('use_cartography_as_navigation_slice_not_as_primary_truth', $payload['ai_navigation_slice']['rule']);

        foreach ($payload['ai_navigation_slice']['nodes'] as $node) {
            $this->assertArrayHasKey('id', $node);
            $this->assertArrayHasKey('source_path', $node);
            $this->assertArrayNotHasKey('human_modal', $node);
        }
    }

    public function test_cli_actions_emit_json(): void
    {
        $commandPath = 'app/Console/Commands/AtlasUniversalRealityCartographyCommand.php';
        $this->assertStringEndsWith('AtlasUniversalRealityCartographyCommand.php', $commandPath);

        foreach (['map', 'nodes', 'visual-scene', 'semantic-zoom', 'human-routes', 'task-simulator', 'human-clarity', 'navigation-slice'] as $action) {
            $exit = Artisan::call('atlas:universal-reality-cartography', [
                'action' => $action,
                '--mode' => 'universe',
                '--json' => true,
                '--strict' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit, $action);
            $this->assertSame(AtlasUniversalRealityCartographyService::SCHEMA_VERSION, $payload['schema_version']);
            $this->assertSame('ready', $payload['status']);
            $this->assertFalse($payload['writes']);
        }
    }

    private function createRuntimeProjectionTable(): void
    {
        Schema::dropIfExists('atlas_workspace_runtime_projection_snapshots');
        Schema::create('atlas_workspace_runtime_projection_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('family', 20)->index();
            $table->string('schema_version', 120)->index();
            $table->string('runtime_hash', 80)->index();
            $table->string('projection_hash', 80)->index();
            $table->string('status', 40)->index();
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['runtime_hash', 'family'], 'aurc_runtime_projection_unique');
        });
    }

    private function createArtifactGraphTable(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_graph_snapshots');
        Schema::create('atlas_workspace_artifact_graph_snapshots', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 80)->unique();
            $table->string('artifact_intelligence_hash', 80)->unique();
            $table->string('status', 40)->index();
            $table->string('lake_hash', 80)->nullable()->index();
            $table->string('graph_hash', 80)->nullable()->index();
            $table->unsignedInteger('artifact_count')->default(0);
            $table->unsignedInteger('node_count')->default(0);
            $table->unsignedInteger('edge_count')->default(0);
            $table->boolean('replay_ready')->default(false)->index();
            $table->string('simulation_decision', 40)->index();
            $table->json('nodes');
            $table->json('edges');
            $table->json('payload');
            $table->timestamp('captured_at')->index();
            $table->timestamps();
        });
    }

    private function createArtifactLakeTable(): void
    {
        Schema::dropIfExists('atlas_workspace_artifact_lake_entries');
        Schema::create('atlas_workspace_artifact_lake_entries', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('workspace_id', 120)->index();
            $table->string('runtime_hash', 80)->index();
            $table->string('artifact_hash', 80)->index();
            $table->string('artifact_type', 120)->index();
            $table->string('status', 40)->index();
            $table->string('consumer', 120)->nullable()->index();
            $table->json('source_hashes');
            $table->json('body');
            $table->decimal('quality_score', 5, 2)->default(0);
            $table->timestamp('captured_at')->index();
            $table->timestamps();
            $table->unique(['runtime_hash', 'artifact_hash'], 'aurc_artifact_lake_unique');
        });
    }
}
