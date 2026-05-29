<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\StewardshipEvolution;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipEvolution\StewardshipRuntimeResultBridgeService;
use Tests\TestCase;

/**
 * AP-765 inbox quality GUARANTEE.
 *
 * The operator reported that every stewardship loop inbox item looked
 * identical and mocked: "O que encontrei" == "Qual o problema", the generic
 * phrase "O resultado do runtime ... esta pronto para revisao", no real
 * finding, no commit. These invariants make a generic/duplicated/contentless
 * inbox item STRUCTURALLY IMPOSSIBLE to emit from buildInboxContent.
 */
final class StewardshipRuntimeResultBridgeInboxContentTest extends TestCase
{
    /** Phrases from the old hardcoded boilerplate that must never appear again. */
    private const BANNED = [
        'esta pronto para revisao do operador antes de qualquer merge',
        'Problema ainda nao detalhado',
        'Solucao ainda nao detalhada',
        'Precisa de revisao do operador.',
    ];

    private function bridge(): StewardshipRuntimeResultBridgeService
    {
        return app(StewardshipRuntimeResultBridgeService::class);
    }

    private function norm(string $s): string
    {
        return trim((string) preg_replace('/\s+/', ' ', mb_strtolower($s)));
    }

    /**
     * @param  array<string,string>  $content
     */
    private function assertGuarantee(array $content, bool $expectChangedFile = false, ?string $changedFile = null, ?string $title = null, ?string $why = null): void
    {
        foreach (['finding', 'problem', 'solution', 'worth_it', 'best_solution_rationale', 'title'] as $key) {
            $this->assertArrayHasKey($key, $content);
            $this->assertNotSame('', trim($content[$key]), "inbox content field '{$key}' must not be empty");
        }

        // 1. No two body lines are identical (the original duplication bug).
        $lines = [$content['finding'], $content['problem'], $content['solution'], $content['worth_it']];
        $normalized = array_map(fn (string $l): string => $this->norm($l), $lines);
        $this->assertSame(count($normalized), count(array_unique($normalized)), 'inbox body lines must be mutually distinct (no duplication)');

        // 2. The specific finding->problem collapse must be gone.
        $this->assertNotSame($this->norm($content['finding']), $this->norm($content['problem']), '"O que encontrei" must differ from "Qual o problema"');

        // 3. No banned generic boilerplate anywhere.
        $blob = mb_strtolower(implode(' | ', $content));
        foreach (self::BANNED as $banned) {
            $this->assertStringNotContainsString(mb_strtolower($banned), $blob, "banned boilerplate must not appear: {$banned}");
        }

        // 4. Solution is explicit about the pre-merge state (no fake commit/merge claim).
        $this->assertMatchesRegularExpression('/(pre-merge|antes do merge|nao mergeado)/i', $content['solution']);

        // 5. Real finding title surfaces when provided.
        if ($title !== null) {
            $this->assertStringContainsString($title, $content['finding'].' '.$content['title']);
        }
        // 6. Real why_it_matters surfaces when provided.
        if ($why !== null) {
            $this->assertStringContainsString($why, $content['problem'].' '.$content['worth_it']);
        }
        // 7. Real changed file surfaces in the solution when present.
        if ($expectChangedFile && $changedFile !== null) {
            $this->assertStringContainsString($changedFile, $content['solution']);
        }
    }

    public function test_rich_finding_produces_distinct_evidence_backed_inbox_content(): void
    {
        $result = [
            'finding_title' => 'Wire universal gate report into merge governor',
            'finding_kind' => 'runtime',
            'finding_why_it_matters' => 'Every merge decision becomes auditable against the 15-gate contract instead of an opaque pass/fail.',
            'finding_detail' => 'The merge governor only checks a subset via opaque bool flags.',
            'summary' => 'Atlas implemented the first bounded gate-report contract in StewardshipBranchMergeGovernorService.',
            'changed_files' => [
                'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorService.php',
                'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipBranchMergeGovernorServiceTest.php',
            ],
            'tests' => ['php artisan test --filter=StewardshipBranchMergeGovernorService'],
        ];

        $content = $this->bridge()->buildInboxContent(
            $result,
            ['branch_ref' => 'atlas/area-focus/agentic_engineering_os/atlas_dev/abc123'],
            'atlas_dev',
            'agentic_engineering_os',
            'completed',
            'srep_test',
        );

        $this->assertGuarantee(
            $content,
            expectChangedFile: true,
            changedFile: 'StewardshipBranchMergeGovernorService.php',
            title: 'Wire universal gate report into merge governor',
            why: 'Every merge decision becomes auditable',
        );
    }

    public function test_missing_finding_context_still_produces_distinct_non_generic_content(): void
    {
        // Degraded input (old-style result without threaded finding context):
        // the guarantee must still hold — distinct, specific, no boilerplate.
        $result = [
            'summary' => 'Atlas found "Expose provider fallback chain", invoked the runtime and committed the scoped result.',
            'changed_files' => ['app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/LoopProviderRoutingService.php'],
            'tests' => [],
        ];

        $content = $this->bridge()->buildInboxContent(
            $result,
            ['branch_ref' => ''],
            'atlas_dev',
            'agentic_engineering_os',
            'partial',
            'srep_degraded',
        );

        $this->assertGuarantee(
            $content,
            expectChangedFile: true,
            changedFile: 'LoopProviderRoutingService.php',
        );
    }

    public function test_empty_result_is_still_distinct_and_flags_missing_evidence(): void
    {
        $content = $this->bridge()->buildInboxContent(
            [],
            [],
            'atlas_dev',
            'agentic_engineering_os',
            'blocked',
            'srep_empty',
        );

        // Even with nothing, the four lines must be distinct and free of boilerplate.
        $this->assertGuarantee($content);
        // And it must honestly flag the absence of code-change evidence.
        $this->assertStringContainsString('sem evidencia de mudanca de codigo', $content['best_solution_rationale']);
    }
}
