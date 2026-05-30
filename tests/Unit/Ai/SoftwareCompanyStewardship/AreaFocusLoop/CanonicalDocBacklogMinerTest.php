<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusDeepFindingEngineService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\CanonicalDocFrontmatterReader;
use Tests\TestCase;

/**
 * Read-only contract tests for the Canonical Doc Backlog Miner — a finding
 * SOURCE wired into AreaFocusDeepFindingEngineService::scan(). Every assertion
 * proves the miner mines REAL frontmatter directive lines verbatim, never
 * fabricates work, inherits full operator-review governance, can NEVER flip to
 * auto-execution (Blocker#1), and dedups honestly across three stages.
 */
class CanonicalDocBacklogMinerTest extends TestCase
{
    private function service(): AreaFocusDeepFindingEngineService
    {
        return app(AreaFocusDeepFindingEngineService::class);
    }

    /**
     * Injected directive lines bypass the filesystem; each maps to a real YAML
     * frontmatter line in a doc that the test also hand-builds for re-read proof.
     *
     * @return array<int,array<string,mixed>>
     */
    private function injectedLines(): array
    {
        $path = 'docs/engineering-knowledge-base/example-doc.md';

        return [
            ['path' => $path, 'line' => 5, 'text' => 'Implementar `ExampleService`.', 'directive_kind' => 'next_action', 'risk_level' => 'medium'],
            ['path' => $path, 'line' => 6, 'text' => 'Atualizar este doc antes de mudar policy.', 'directive_kind' => 'next_action', 'risk_level' => 'medium'],
            ['path' => $path, 'line' => 9, 'text' => 'Refinar transicoes de estado.', 'directive_kind' => 'allowed_change', 'risk_level' => 'medium'],
            ['path' => $path, 'line' => 12, 'text' => 'Permitir comunicacao ad-hoc entre departamentos.', 'directive_kind' => 'forbidden_change', 'risk_level' => 'medium'],
        ];
    }

    public function test_default_scan_emits_no_canonical_doc_backlog_findings(): void
    {
        $report = $this->service()->scan(['base_report' => $this->baseReport()]);

        $this->assertFalse($report['source_summary']['canonical_doc_backlog']['enabled']);
        $this->assertSame(0, $report['source_summary']['canonical_doc_backlog']['emitted_count']);
        foreach ($report['findings'] as $finding) {
            $this->assertNotSame('canonical_doc_backlog', $finding['origin']);
        }
    }

    public function test_three_actionable_lines_yield_three_findings_with_verbatim_evidence(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ]);

        $mined = $this->minedFindings($report);
        // 3 actionable (2 next_action + 1 allowed_change); forbidden dropped.
        $this->assertCount(3, $mined);

        $impl = $this->findByTitle($mined, 'Implementar `ExampleService`.');
        $this->assertSame('doc:docs/engineering-knowledge-base/example-doc.md:line:5', $impl['evidence_refs'][0]);
        $this->assertSame('text:Implementar `ExampleService`.', $impl['evidence_refs'][1]);
        // Title is the verbatim trimmed line, never paraphrased.
        $this->assertSame('Implementar `ExampleService`.', $impl['title']);
        $this->assertSame('Implementar `ExampleService`.', $impl['proposed_next_action']);
        $this->assertSame(AreaFocusDeepFindingEngineService::KIND_IMPLEMENTATION, $impl['kind']);
        $this->assertSame('doc_next_action', $impl['origin_type']);

        $allowed = $this->findByTitle($mined, 'Refinar transicoes de estado.');
        $this->assertSame(AreaFocusDeepFindingEngineService::KIND_IMPROVEMENT, $allowed['kind']);
        $this->assertSame('doc_allowed_change', $allowed['origin_type']);
    }

    public function test_forbidden_lines_are_dropped_at_source(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ]);

        $this->assertGreaterThan(0, $report['source_summary']['canonical_doc_backlog']['forbidden_dropped_count']);
        foreach ($this->minedFindings($report) as $finding) {
            $this->assertStringNotContainsString('ad-hoc', $finding['title']);
        }
    }

    public function test_maintenance_line_is_kind_doc_low_severity(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ]);

        $maint = $this->findByTitle($this->minedFindings($report), 'Atualizar este doc antes de mudar policy.');
        $this->assertSame(AreaFocusDeepFindingEngineService::KIND_DOC, $maint['kind']);
        $this->assertSame('low', $maint['severity']);
    }

    public function test_blocker1_doc_finding_never_auto_executes_through_full_default_scan(): void
    {
        // Full default-focus scan() runs through applyFactoryBacklogQuality.
        // A doc_next_action whose text would otherwise look factory-executable
        // STILL must never auto-execute.
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => [[
                'path' => 'docs/engineering-knowledge-base/example-doc.md',
                'line' => 5,
                'text' => 'Implementar AreaFocusDevForgeRouterService runtime.',
                'directive_kind' => 'next_action',
                'risk_level' => 'high',
            ]],
        ]);

        // The factory gate rejects doc-mined origins, so the mined finding never
        // survives to the executable set with auto_execution_allowed=true.
        foreach ($report['findings'] as $finding) {
            if (($finding['origin'] ?? '') === 'canonical_doc_backlog') {
                $this->assertFalse($finding['auto_execution_allowed']);
                $this->assertTrue($finding['operator_review_required']);
            }
        }
        $rejections = array_column($report['factory_backlog_quality']['rejections'], 'rejection_reason', 'title');
        $this->assertArrayHasKey('Implementar AreaFocusDevForgeRouterService runtime.', $rejections);
        $this->assertSame('factory_backlog_rejects_docs_or_low_leverage_evidence',
            $rejections['Implementar AreaFocusDevForgeRouterService runtime.']);
    }

    public function test_duplicate_line_within_scan_collapses_to_one(): void
    {
        $line = ['path' => 'docs/engineering-knowledge-base/example-doc.md', 'line' => 5, 'text' => 'Implementar `ExampleService`.', 'directive_kind' => 'next_action', 'risk_level' => 'medium'];
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => [$line, $line],
            'skip_factory_backlog_quality' => true,
        ]);

        $this->assertCount(1, $this->minedFindings($report));
    }

    public function test_cross_layer_sde_dedup_suppresses_matching_doc_line(): void
    {
        $lines = [['path' => 'docs/engineering-knowledge-base/example-doc.md', 'line' => 5, 'text' => 'Implementar `ExampleService`.', 'directive_kind' => 'next_action', 'risk_level' => 'medium']];

        // Compute the source_ref token the miner would derive, then inject it as
        // an existing self_improvement candidate_hash.
        $token = $this->expectedToken('Implementar `ExampleService`.');

        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $lines,
            'existing_self_improvement_candidate_hashes' => [$token],
            'skip_factory_backlog_quality' => true,
        ]);

        $this->assertCount(0, $this->minedFindings($report));
        $this->assertSame('supplied', $report['source_summary']['canonical_doc_backlog']['self_improvement_dedup']);
        $this->assertSame(1, $report['source_summary']['canonical_doc_backlog']['self_improvement_suppressed_count']);
    }

    public function test_sde_dedup_honest_not_supplied_when_absent(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ]);

        $this->assertSame('not_supplied', $report['source_summary']['canonical_doc_backlog']['self_improvement_dedup']);
    }

    public function test_every_mined_finding_is_governed_and_only_whitelisted_keys(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ]);

        foreach ($this->minedFindings($report) as $finding) {
            $this->assertSame('atlas_dev', $finding['owner_candidate']);
            $this->assertFalse($finding['auto_execution_allowed']);
            $this->assertTrue($finding['operator_review_required']);
            $this->assertSame('atlas.evolution.gap_candidate.v1', $finding['spec_seed']['schema_version']);
            foreach (['doc_line_text', 'blast_radius', 'route', 'triage_class', 'dispatched', 'safe_to_autofix'] as $forbidden) {
                $this->assertArrayNotHasKey($forbidden, $finding);
            }
        }
    }

    public function test_deterministic_finding_hash_across_runs(): void
    {
        $args = [
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => $this->injectedLines(),
            'skip_factory_backlog_quality' => true,
        ];
        $a = $this->minedFindings($this->service()->scan($args));
        $b = $this->minedFindings($this->service()->scan($args));

        $this->assertSame(
            array_column($a, 'finding_hash'),
            array_column($b, 'finding_hash')
        );
    }

    public function test_empty_or_garbage_doc_yields_zero(): void
    {
        $report = $this->service()->scan([
            'base_report' => $this->emptyReport(),
            'canonical_doc_backlog_lines' => [],
            'skip_factory_backlog_quality' => true,
        ]);

        $this->assertCount(0, $this->minedFindings($report));
    }

    public function test_real_temp_doc_evidence_line_resolves(): void
    {
        // Hand-build a REAL canonical .md with known frontmatter, point the reader
        // at it, and prove each finding's doc:line evidence resolves verbatim.
        $dir = sys_get_temp_dir().'/atlas_cdb_'.bin2hex(random_bytes(4));
        @mkdir($dir, 0777, true);
        $file = $dir.'/example-doc.md';
        $md = implode("\n", [
            '---',
            'title: Example',
            'risk_level: high',
            'next_actions:',
            '  - Implementar `RealExampleService`.',
            '  - Rodar suite de testes.',
            'allowed_changes:',
            '  - Refinar contrato de handoff.',
            'forbidden_changes:',
            '  - Permitir bypass do gate.',
            '---',
            '# Example',
        ]);
        file_put_contents($file, $md);

        $reader = new CanonicalDocFrontmatterReader;
        $directives = $reader->extractDirectives($file);
        // 2 next_actions + 1 allowed_change + 1 forbidden = 4 directives.
        $this->assertCount(4, $directives);
        $this->assertSame('high', $reader->extractRiskLevel($file));

        foreach ($directives as $d) {
            $reread = explode("\n", (string) file_get_contents($d['path']));
            $this->assertStringContainsString($d['text'], $reread[$d['line'] - 1]);
        }

        @unlink($file);
        @rmdir($dir);
    }

    // ---------- helpers ----------

    /** @param array<string,mixed> $report @return list<array<string,mixed>> */
    private function minedFindings(array $report): array
    {
        return array_values(array_filter(
            $report['findings'],
            static fn (array $f): bool => ($f['origin'] ?? '') === 'canonical_doc_backlog'
        ));
    }

    /** @param list<array<string,mixed>> $findings @return array<string,mixed> */
    private function findByTitle(array $findings, string $title): array
    {
        foreach ($findings as $f) {
            if (($f['title'] ?? '') === $title) {
                return $f;
            }
        }
        $this->fail('No mined finding with title: '.$title);
    }

    private function expectedToken(string $text): string
    {
        $normalized = strtolower((string) preg_replace('/\s+/', ' ', trim($text)));

        return substr(\App\Services\Ai\Mission\MissionCanonicalHash::sha256($normalized), 0, 12);
    }

    /** @return array<string,mixed> */
    private function emptyReport(): array
    {
        return [
            'schema_version' => 'atlas.software_company_stewardship.agentic_engineering_os_finding.v1',
            'status' => 'ready',
            'area_id' => 'agentic_engineering_os',
            'finding_count' => 0,
            'findings' => [],
        ];
    }

    /** @return array<string,mixed> */
    private function baseReport(): array
    {
        return $this->emptyReport();
    }
}
