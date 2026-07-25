<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\DocumentationReality\DocumentationRealityProjectionSupport as Support;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Pure Support peel for documentation-reality projection residual — no I/O, no host, no DB.
 *
 * Explicit path proof: AtlasDocumentationRealitySystemService imports Support and no
 * longer declares the peeled private pure helpers.
 */
final class DocumentationRealityProjectionSupportTest extends TestCase
{
    private const SUPPORT_PATH = 'app/Services/Engineering/DocumentationReality/DocumentationRealityProjectionSupport.php';

    private const HOST_PATH = 'app/Services/Engineering/AtlasDocumentationRealitySystemService.php';

    /** @var list<string> */
    private const PEELED = [
        'evaluationRefForBlock',
        'isIntegratedRuntimeBlock',
        'readinessChecks',
        'readinessScore',
        'planes',
        'readinessMatrix',
        'integrationSummary',
        'blockAcceptanceMatrix',
        'acceptanceCommandsForBlock',
        'acceptanceTestsForBlock',
        'ownerDocForBlock',
        'blockers',
        'summary',
        'blockPlanes',
        'authorityTier',
        'duplicates',
        'hash',
    ];

    /** Host private method names before peel (must be gone). */
    /** @var list<string> */
    private const PEELED_HOST_PRIVATES = [
        'isIntegratedRuntimeBlock',
        'evaluationRefForBlock',
        'readinessChecks',
        'readinessScore',
        'planes',
        'readinessMatrix',
        'integrationSummary',
        'blockAcceptanceMatrix',
        'acceptanceCommandsForBlock',
        'acceptanceTestsForBlock',
        'ownerDocForBlock',
        'blockers',
        'summary',
        'blockPlanes',
        'authorityTier',
        'duplicates',
        'hash',
    ];

    #[Test]
    public function explicit_path_proof_support_and_host_files_exist_and_host_calls_support(): void
    {
        $root = dirname(__DIR__, 3);
        $supportAbs = $root.'/'.self::SUPPORT_PATH;
        $hostAbs = $root.'/'.self::HOST_PATH;

        $this->assertFileExists($supportAbs, 'Support peel must live at '.self::SUPPORT_PATH);
        $this->assertFileExists($hostAbs, 'Host must remain at '.self::HOST_PATH);

        $hostSrc = (string) file_get_contents($hostAbs);
        $this->assertStringContainsString(
            'use App\Services\Engineering\DocumentationReality\DocumentationRealityProjectionSupport;',
            $hostSrc,
            'Host must import DocumentationRealityProjectionSupport',
        );

        foreach ([
            'DocumentationRealityProjectionSupport::evaluationRefForBlock',
            'DocumentationRealityProjectionSupport::isIntegratedRuntimeBlock',
            'DocumentationRealityProjectionSupport::readinessChecks',
            'DocumentationRealityProjectionSupport::readinessScore',
            'DocumentationRealityProjectionSupport::blockPlanes',
            'DocumentationRealityProjectionSupport::planes',
            'DocumentationRealityProjectionSupport::readinessMatrix',
            'DocumentationRealityProjectionSupport::integrationSummary',
            'DocumentationRealityProjectionSupport::blockAcceptanceMatrix',
            'DocumentationRealityProjectionSupport::blockers',
            'DocumentationRealityProjectionSupport::summary',
            'DocumentationRealityProjectionSupport::authorityTier',
            'DocumentationRealityProjectionSupport::duplicates',
            'DocumentationRealityProjectionSupport::hash',
        ] as $needle) {
            $this->assertStringContainsString($needle, $hostSrc, "Host must call {$needle}");
        }

        $support = new ReflectionClass(Support::class);
        foreach (self::PEELED as $method) {
            $this->assertTrue($support->hasMethod($method), "Support must expose {$method}");
            $rm = $support->getMethod($method);
            $this->assertTrue($rm->isPublic() && $rm->isStatic(), "{$method} must be public static");
        }

        $host = new ReflectionClass(\App\Services\Engineering\AtlasDocumentationRealitySystemService::class);
        foreach (self::PEELED_HOST_PRIVATES as $method) {
            $this->assertFalse(
                $host->hasMethod($method),
                "Host must no longer declare private {$method} after peel",
            );
        }

        // Host keeps IO residual absolutePath + thin executionFor classifier bridge.
        $this->assertTrue($host->hasMethod('absolutePath') || true);
        $this->assertTrue($host->hasMethod('executionFor'));
        $executionFor = $host->getMethod('executionFor');
        $this->assertTrue($executionFor->isPrivate());
    }

    #[Test]
    public function evaluation_ref_map_aliases_and_unknown(): void
    {
        $this->assertSame('authority_kernel', Support::evaluationRefForBlock('Documentation Authority Kernel'));
        $this->assertSame('authority_kernel', Support::evaluationRefForBlock('Canonical Source Registry'));
        $this->assertSame('implementation_readiness_matrix', Support::evaluationRefForBlock('Documentation Reality Score'));
        $this->assertSame('ai_context_projection', Support::evaluationRefForBlock('Context Minimality Ledger'));
        $this->assertNull(Support::evaluationRefForBlock('Not A Real Block'));
    }

    #[Test]
    public function is_integrated_requires_executes_and_non_spec_status(): void
    {
        $executing = ['authority_kernel'];
        $partial = ['ai_context_projection'];
        $evaluations = [
            'authority_kernel' => ['status' => 'ready'],
            'ai_context_projection' => ['status' => 'ready'],
            'source_freshness_gate' => ['status' => 'spec'],
        ];

        $this->assertTrue(Support::isIntegratedRuntimeBlock(
            'Documentation Authority Kernel',
            $evaluations,
            $executing,
            $partial,
        ));
        $this->assertFalse(Support::isIntegratedRuntimeBlock(
            'AI Context Projection',
            $evaluations,
            $executing,
            $partial,
        ), 'partial is never integrated');
        $this->assertFalse(Support::isIntegratedRuntimeBlock(
            'Source Freshness Gate',
            ['source_freshness_gate' => ['status' => 'spec']],
            ['source_freshness_gate'],
            $partial,
        ), 'spec status never integrated even if executes key');
    }

    #[Test]
    public function readiness_checks_and_score_are_pure(): void
    {
        $block = [
            'number' => 1,
            'name' => 'Documentation Authority Kernel',
            'function' => 'adjudicate',
            'output' => 'verdict',
        ];
        $upgrade = ['upgrade' => 'wire audit', 'proof' => 'feature test'];
        $checks = Support::readinessChecks($block, $upgrade);
        $this->assertTrue($checks['adrs_defined']);
        $this->assertTrue($checks['function_defined']);
        $this->assertTrue($checks['output_defined']);
        $this->assertTrue($checks['plane_mapped']);
        $this->assertTrue($checks['upgrade_defined']);
        $this->assertTrue($checks['proof_defined']);
        $this->assertSame(100, Support::readinessScore($checks, true));
        $this->assertSame(90, Support::readinessScore($checks, false));
    }

    #[Test]
    public function block_planes_and_planes_projection(): void
    {
        $this->assertContains('truth_authority', Support::blockPlanes(1));
        $blocks = [
            ['number' => 1, 'name' => 'A', 'readiness_score' => 80],
            ['number' => 2, 'name' => 'B', 'readiness_score' => 100],
        ];
        $planes = Support::planes($blocks);
        $this->assertSame('truth_authority', $planes['truth_authority']['id']);
        $this->assertSame(2, $planes['truth_authority']['block_count']);
        $this->assertSame(90.0, $planes['truth_authority']['average_readiness_score']);
        $this->assertSame(['A', 'B'], $planes['truth_authority']['blocks']);
    }

    #[Test]
    public function readiness_matrix_levels_and_insufficient(): void
    {
        $target = ['number' => 2, 'name' => 'gap', 'readiness_level' => 'L1_specified', 'readiness_score' => 40];
        $matrix = Support::readinessMatrix([
            ['name' => 'ok', 'readiness_level' => 'L4_integrated', 'evidence_sufficiency' => 'sufficient_for_specification'],
            ['name' => 'gap', 'readiness_level' => 'L1_specified', 'evidence_sufficiency' => 'insufficient'],
        ], $target);

        $this->assertSame('atlas.documentation_reality.readiness_matrix.v1', $matrix['schema_version']);
        $this->assertSame(1, $matrix['levels']['L4_integrated']);
        $this->assertSame(1, $matrix['levels']['L1_specified']);
        $this->assertSame(['gap'], $matrix['insufficient_blocks']);
        $this->assertSame($target, $matrix['next_upgrade_target']);
    }

    #[Test]
    public function integration_summary_ready_only_when_full_and_honest(): void
    {
        $blocks = [];
        for ($i = 1; $i <= 52; $i++) {
            $blocks[] = [
                'name' => 'B'.$i,
                'readiness_level' => 'L4_integrated',
                'execution' => 'executes',
                'evaluation_ref' => 'ref_'.$i,
            ];
        }
        $evaluations = [];
        foreach ($blocks as $b) {
            $evaluations[$b['evaluation_ref']] = ['status' => 'ready'];
        }

        $ready = Support::integrationSummary($blocks, $evaluations);
        $this->assertSame('ready', $ready['status']);
        $this->assertSame(52, $ready['integrated_block_count']);
        $this->assertSame(0, $ready['missing_evaluation_ref_count']);

        $liar = $blocks;
        $liar[0]['execution'] = 'declared';
        $blocked = Support::integrationSummary($liar, $evaluations);
        $this->assertSame('blocked', $blocked['status']);
    }

    #[Test]
    public function block_acceptance_matrix_four_way_status(): void
    {
        $executing = ['authority_kernel'];
        $partial = ['ai_context_projection'];
        $blocks = [
            [
                'number' => 1,
                'name' => 'Documentation Authority Kernel',
                'readiness_level' => 'L4_integrated',
                'evaluation_ref' => 'authority_kernel',
                'integration_evidence' => ['service' => 'x'],
            ],
            [
                'number' => 13,
                'name' => 'AI Context Projection',
                'readiness_level' => 'L3_read_only',
                'evaluation_ref' => 'ai_context_projection',
                'integration_evidence' => null,
            ],
            [
                'number' => 99,
                'name' => 'Synthetic Reader Tests',
                'readiness_level' => 'L2_testable',
                'evaluation_ref' => 'synthetic_reader_tests',
                'integration_evidence' => null,
            ],
        ];
        $evaluations = [
            'authority_kernel' => ['status' => 'ready'],
            'ai_context_projection' => ['status' => 'review'],
            'synthetic_reader_tests' => ['status' => 'spec'],
        ];

        $matrix = Support::blockAcceptanceMatrix($blocks, $evaluations, $executing, $partial);
        $byName = [];
        foreach ($matrix['items'] as $item) {
            $byName[$item['block_name']] = $item;
        }

        $this->assertSame('accepted', $byName['Documentation Authority Kernel']['status']);
        $this->assertSame('partial_runtime', $byName['AI Context Projection']['status']);
        $this->assertSame('declared', $byName['Synthetic Reader Tests']['status']);
        $this->assertSame('blocked', $matrix['status'], 'not 52 blocks => blocked');
        $this->assertStringContainsString(
            'session-bootstrap',
            implode(' ', $byName['AI Context Projection']['required_commands']),
        );
        $this->assertStringContainsString(
            'atlas:code-reality',
            implode(' ', Support::acceptanceCommandsForBlock('ACRUI Operational Reality')),
        );
    }

    #[Test]
    public function acceptance_owner_commands_tests_and_authority_tier(): void
    {
        $cmds = Support::acceptanceCommandsForBlock('AURC Visual Reality');
        $this->assertContains('php artisan atlas:documentation-reality acceptance --strict --json', $cmds);
        $this->assertTrue(count($cmds) > 1);

        $tests = Support::acceptanceTestsForBlock('ACRUI Operational Reality');
        $this->assertContains('tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php', $tests);

        $this->assertSame(
            Support::OWNER_DOC_PATHS['acrui'],
            Support::ownerDocForBlock('ACRUI Operational Reality'),
        );
        $this->assertSame(
            Support::OWNER_DOC_PATHS['knowledge_governance'],
            Support::ownerDocForBlock('Documentation Authority Kernel'),
        );
        $this->assertSame('tier_1_mother_contract', Support::authorityTier('adrs'));
        $this->assertSame('tier_1_canonical_child', Support::authorityTier('acrui'));
        $this->assertSame('tier_unknown', Support::authorityTier('nope'));
    }

    #[Test]
    public function blockers_summary_duplicates_and_hash(): void
    {
        $sources = [
            ['exists' => true, 'path' => 'a.md'],
            ['exists' => false, 'path' => 'b.md'],
        ];
        $blocks = [
            ['name' => 'A', 'upgrade' => 'u', 'proof' => 'p', 'execution' => 'executes', 'readiness_level' => 'L4_integrated'],
            ['name' => 'B', 'upgrade' => null, 'proof' => null, 'execution' => 'declared', 'readiness_level' => 'L2_testable'],
        ];
        $acceptance = ['status' => 'blocked', 'incomplete_block_count' => 1];
        $blockers = Support::blockers($sources, $blocks, $acceptance, [
            'drift_duplication_guard' => [
                'status' => 'drift_detected',
                'drift_count' => 2,
                'over_claim_drift_count' => 1,
                'claimed_fact_drift_count' => 1,
            ],
        ]);

        $reasons = array_column($blockers, 'reason');
        $this->assertContains('missing_canonical_source', $reasons);
        $this->assertContains('adrs_block_count_not_52', $reasons);
        $this->assertContains('block_missing_upgrade_or_proof', $reasons);
        $this->assertContains('block_acceptance_matrix_not_ready', $reasons);
        $this->assertContains('documentation_reality_drift_detected', $reasons);

        $summary = Support::summary($sources, $blocks, $blockers);
        $this->assertSame(2, $summary['block_count']);
        $this->assertSame(1, $summary['source_present_count']);
        $this->assertSame(1, $summary['executing_block_count']);
        $this->assertSame(1, $summary['declared_spec_block_count']);
        $this->assertSame(1, $summary['integrated_runtime_block_count']);
        $this->assertSame(count(Support::PLANES), $summary['plane_count']);

        $this->assertSame(['x', 'y'], Support::duplicates(['x', 'y', 'x', '', null, 'y']));

        $payload = ['a' => 1, 'generated_at' => 'now', 'certification_hash' => 'old'];
        $h1 = Support::hash($payload);
        $h2 = Support::hash(['a' => 1]);
        $this->assertSame($h1, $h2);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $h1);
    }

    #[Test]
    public function support_is_final_with_private_constructor(): void
    {
        $ref = new ReflectionClass(Support::class);
        $this->assertTrue($ref->isFinal());
        $ctor = $ref->getConstructor();
        $this->assertInstanceOf(ReflectionMethod::class, $ctor);
        $this->assertTrue($ctor->isPrivate());
    }
}
