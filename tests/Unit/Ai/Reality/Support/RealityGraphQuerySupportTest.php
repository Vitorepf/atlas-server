<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Reality\Support;

use App\Services\Ai\Reality\Support\RealityGraphQuerySupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for AURG Reality Graph query residual — no I/O, no host service, no DB.
 *
 * Explicit path proof: AtlasRealityGraphQueryService imports Support and no
 * longer declares the peeled private pure helpers.
 */
final class RealityGraphQuerySupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Ai/Reality/Support/RealityGraphQuerySupport.php';

    private const HOST_PATH = 'app/Services/Ai/Reality/AtlasRealityGraphQueryService.php';

    /** @var list<string> */
    private const PEELED = [
        'normalizeExpand',
        'mergeSeeds',
        'orderPaths',
        'pprShadowStatus',
        'normaliseTargetNodeIds',
        'terms',
        'entityRepoPaths',
        'entityMemoryRefs',
        'lexicalSeedQuality',
        'lexicalSourcePriority',
        'isGenericProviderSeedLabel',
        'rankTextSurface',
        'providerAdmissible',
        'workspaceAdmissible',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'normalizeExpand',
        'mergeSeeds',
        'orderPaths',
        'pprShadowStatus',
        'normaliseTargetNodeIds',
        'terms',
        'entityRepoPaths',
        'entityMemoryRefs',
        'lexicalSeedQuality',
        'lexicalSourcePriority',
        'isGenericProviderSeedNode',
        'rankTextSurface',
        'providerAdmissible',
        'workspaceAdmissible',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 5);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Ai\Reality\Support\RealityGraphQuerySupport;',
            $hostSrc,
            'Host must import RealityGraphQuerySupport',
        );

        foreach ([
            'RealityGraphQuerySupport::terms',
            'RealityGraphQuerySupport::mergeSeeds',
            'RealityGraphQuerySupport::orderPaths',
            'RealityGraphQuerySupport::normalizeExpand',
            'RealityGraphQuerySupport::entityRepoPaths',
            'RealityGraphQuerySupport::entityMemoryRefs',
            'RealityGraphQuerySupport::lexicalSeedQuality',
            'RealityGraphQuerySupport::providerAdmissible',
            'RealityGraphQuerySupport::workspaceAdmissible',
            'RealityGraphQuerySupport::rankTextSurface',
            'RealityGraphQuerySupport::normaliseTargetNodeIds',
            'RealityGraphQuerySupport::pprShadowStatus',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        foreach (self::PEELED_HOST_PRIVATES as $method) {
            $this->assertStringNotContainsString(
                'private function '.$method,
                $hostSrc,
                "Peeled method residual on host: {$method}",
            );
            $this->assertStringNotContainsString(
                'private static function '.$method,
                $hostSrc,
                "Peeled static residual on host: {$method}",
            );
        }

        $this->assertStringNotContainsString(
            'private const MAX_QUERY_TERMS',
            $hostSrc,
            'Host must not retain private MAX_QUERY_TERMS const after peel',
        );
    }

    #[Test]
    public function pure_support_is_static_and_final_with_no_instance_state(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $this->assertTrue($ref->getConstructor()?->isPrivate() ?? false);

        foreach (self::PEELED as $method) {
            $m = new ReflectionMethod(Support::class, $method);
            $this->assertTrue($m->isPublic() && $m->isStatic(), "{$method} must be public static");
        }
    }

    #[Test]
    public function normalize_expand_accepts_bool_string_list_and_dedupes(): void
    {
        $this->assertSame([], Support::normalizeExpand(null));
        $this->assertSame([], Support::normalizeExpand(''));
        $this->assertSame([], Support::normalizeExpand(false));
        $this->assertSame(['code'], Support::normalizeExpand(true));
        $this->assertSame(['code'], Support::normalizeExpand('code'));
        $this->assertSame(['code', 'docs'], Support::normalizeExpand('code, docs'));
        $this->assertSame(['code', 'docs'], Support::normalizeExpand(['Code', 'docs', 'code', '']));
        $this->assertSame([], Support::normalizeExpand(42));
    }

    #[Test]
    public function merge_seeds_prefers_entity_then_half_semantic_then_lexical_with_overflow(): void
    {
        $entity = [
            ['node_id' => 'e1', 'via' => 'entity_exact'],
        ];
        $semantic = [
            ['node_id' => 's1', 'via' => 'semantic'],
            ['node_id' => 's2', 'via' => 'semantic'],
            ['node_id' => 's3', 'via' => 'semantic'],
        ];
        $lexical = [
            ['node_id' => 'l1', 'via' => 'lexical'],
            ['node_id' => 'l2', 'via' => 'lexical'],
        ];

        $overflow = false;
        $merged = Support::mergeSeeds($entity, $semantic, $lexical, 3, $overflow);
        $this->assertTrue($overflow);
        $this->assertCount(3, $merged);
        $this->assertSame(['e1', 's1', 's2'], array_column($merged, 'node_id'));

        $overflow = false;
        // Cap 10 → semantic half-slice is 5, so all semantic land before lexical.
        $roomy = Support::mergeSeeds($entity, $semantic, $lexical, 10, $overflow);
        $this->assertFalse($overflow);
        $this->assertSame(['e1', 's1', 's2', 's3', 'l1', 'l2'], array_column($roomy, 'node_id'));
    }

    #[Test]
    public function order_paths_follows_ranked_ids_then_depth_then_target(): void
    {
        $paths = [
            ['target' => 'c', 'depth' => 2],
            ['target' => 'a', 'depth' => 1],
            ['target' => 'b', 'depth' => 1],
            ['target' => 'z', 'depth' => 9],
        ];
        $ordered = Support::orderPaths($paths, ['b', 'a', 'c']);
        $this->assertSame(['b', 'a', 'c', 'z'], array_column($ordered, 'target'));
    }

    #[Test]
    public function ppr_shadow_status_covers_observed_missing_unmeasured_latency_and_regression(): void
    {
        $this->assertSame('observed_no_targets', Support::pprShadowStatus(0, 0, 0.5, 0.5, true));
        $this->assertSame('targets_missing', Support::pprShadowStatus(2, 1, 0.5, 0.5, true));
        $this->assertSame('recall_unmeasured', Support::pprShadowStatus(1, 1, null, 0.5, true));
        $this->assertSame('latency_budget_exceeded', Support::pprShadowStatus(1, 1, 0.5, 0.5, false));
        $this->assertSame('candidate_non_regression', Support::pprShadowStatus(1, 1, 0.4, 0.5, true));
        $this->assertSame('candidate_non_regression', Support::pprShadowStatus(1, 1, 0.5, 0.5, true));
        $this->assertSame('candidate_regression', Support::pprShadowStatus(1, 1, 0.6, 0.5, true));
    }

    #[Test]
    public function normalise_target_node_ids_splits_and_dedupes(): void
    {
        $this->assertSame([], Support::normaliseTargetNodeIds(null));
        $this->assertSame(['a', 'b'], Support::normaliseTargetNodeIds('a, b a'));
        $this->assertSame(['x', 'y'], Support::normaliseTargetNodeIds([' x ', 'y', '', 'x']));
        $this->assertSame([], Support::normaliseTargetNodeIds(7));
    }

    #[Test]
    public function terms_tokenise_expand_separators_and_bound_length(): void
    {
        $this->assertSame([], Support::terms(''));
        $this->assertSame([], Support::terms('a')); // len < 2 discarded
        // Keeps original separator tokens and expands their parts.
        $this->assertSame(
            ['reality_graph', 'reality', 'graph', 'provider-bound', 'provider', 'bound'],
            Support::terms('reality_graph provider-bound'),
        );
        $this->assertContains('hello', Support::terms('Hello, world!'));
        $this->assertContains('world', Support::terms('Hello, world!'));

        $many = implode(' ', array_map(static fn (int $i): string => 't'.$i, range(1, 30)));
        $this->assertCount(Support::MAX_QUERY_TERMS, Support::terms($many));
    }

    #[Test]
    public function entity_repo_paths_extract_repo_paths_and_fqcn_maps(): void
    {
        $paths = Support::entityRepoPaths(
            'see app/Services/Ai/Reality/AtlasRealityGraphQueryService.php and App\\Services\\Ai\\Foo and tests/Unit/x.php',
        );
        $this->assertContains('app/Services/Ai/Reality/AtlasRealityGraphQueryService.php', $paths);
        $this->assertContains('tests/Unit/x.php', $paths);
        $this->assertContains('app/Services/Ai/Foo.php', $paths);
        $this->assertSame([], Support::entityRepoPaths('no paths here'));
        // traversal escape rejected
        $this->assertSame([], Support::entityRepoPaths('app/../etc/passwd'));
    }

    #[Test]
    public function entity_memory_refs_emit_uuid_and_ulid_case_variants(): void
    {
        $uuid = '550e8400-e29b-41d4-a716-446655440000';
        $refs = Support::entityMemoryRefs("memory {$uuid} and 01ARZ3NDEKTSV4RRFFQ69G5FAV");
        $this->assertContains(strtolower($uuid), $refs);
        $this->assertContains(strtoupper($uuid), $refs);
        $this->assertContains('01arz3ndektsv4rrffq69g5fav', $refs);
        $this->assertContains('01ARZ3NDEKTSV4RRFFQ69G5FAV', $refs);
        $this->assertSame([], Support::entityMemoryRefs('no ids'));
    }

    #[Test]
    public function lexical_source_priority_and_seed_quality_gate_provider_bound_noise(): void
    {
        $this->assertSame(90, Support::lexicalSourcePriority('memory', 'entry'));
        $this->assertSame(80, Support::lexicalSourcePriority('code', 'module'));
        $this->assertSame(75, Support::lexicalSourcePriority('docs', 'doc'));
        $this->assertSame(55, Support::lexicalSourcePriority('domain', 'domain'));
        $this->assertSame(45, Support::lexicalSourcePriority('evidence', 'receipt'));
        $this->assertSame(35, Support::lexicalSourcePriority('mission', 'mission'));
        $this->assertSame(20, Support::lexicalSourcePriority('mission', 'step'));
        $this->assertSame(10, Support::lexicalSourcePriority('other', 'x'));

        $this->assertTrue(Support::isGenericProviderSeedLabel('mission_outcome'));
        $this->assertTrue(Support::isGenericProviderSeedLabel('[request interrupted by user for tool use]'));
        $this->assertFalse(Support::isGenericProviderSeedLabel('real memory title'));

        // unbounded: priority only
        $this->assertSame(90, Support::lexicalSeedQuality('memory', 'entry', 'x', [], false));

        // provider-bound drops generic labels and mission/evidence without label match
        $this->assertNull(Support::lexicalSeedQuality('memory', 'entry', 'mission_outcome', [], true));
        $this->assertNull(Support::lexicalSeedQuality('mission', 'evidence', 'ok', ['ok'], true));
        $this->assertNull(Support::lexicalSeedQuality('mission', 'mission', 'ok', [], true));
        $this->assertNull(Support::lexicalSeedQuality('evidence', 'receipt', 'ok', [], true));
        $this->assertSame(35, Support::lexicalSeedQuality('mission', 'mission', 'ok', ['ok'], true));
        $this->assertSame(45, Support::lexicalSeedQuality('evidence', 'receipt', 'ok', ['ok'], true));
    }

    #[Test]
    public function rank_text_surface_flattens_scalar_meta_and_caps_length(): void
    {
        $surface = Support::rankTextSurface('Label', 'src-1', [
            'slug' => 'module-a',
            'nested' => ['inner', ['skip'], 3],
            'empty' => '',
        ]);
        $this->assertStringContainsString('Label', $surface);
        $this->assertStringContainsString('src-1', $surface);
        $this->assertStringContainsString('module-a', $surface);
        $this->assertStringContainsString('inner', $surface);
        $this->assertStringContainsString('3', $surface);

        $long = Support::rankTextSurface(str_repeat('x', 3000), '', []);
        $this->assertSame(2000, mb_strlen($long));
    }

    #[Test]
    public function provider_and_workspace_admission_are_structural_gates(): void
    {
        $this->assertTrue(Support::providerAdmissible(true, false));
        $this->assertFalse(Support::providerAdmissible(true, true));
        $this->assertFalse(Support::providerAdmissible(false, false));
        $this->assertFalse(Support::providerAdmissible(false, true));

        $this->assertTrue(Support::workspaceAdmissible(null, ''));
        $this->assertTrue(Support::workspaceAdmissible('ws-a', ''));
        $this->assertTrue(Support::workspaceAdmissible(null, 'ws-a'));
        $this->assertTrue(Support::workspaceAdmissible('', 'ws-a'));
        $this->assertTrue(Support::workspaceAdmissible('ws-a', 'ws-a'));
        $this->assertFalse(Support::workspaceAdmissible('ws-b', 'ws-a'));
    }
}
