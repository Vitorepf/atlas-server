<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSystemGraphContextBuilderService;
use Tests\TestCase;

final class AtlasSystemGraphContextBuilderTest extends TestCase
{
    private AtlasSystemGraphContextBuilderService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasSystemGraphContextBuilderService();
    }

    public function testUntraceableSourceIsRejectedNeverAdmitted(): void
    {
        // INVARIANT: "toda fonte deve ser rastreavel". A source with no reference
        // (no traceable origin) must be rejected, never slipped into the pack.
        $pack = $this->service->compile([
            'domain' => 'programming',
            'obra' => 'work',
            'intent' => 'implement',
            'authorized_origins' => ['repo_docs'],
            'candidates' => [
                ['id' => 'ghost', 'origin' => 'repo_docs', 'title' => 'No ref', 'reference' => '', 'relevance' => 0.9],
            ],
        ]);

        $this->assertSame([], $pack['sources']);
        $this->assertCount(1, $pack['rejected']);
        $this->assertSame('ghost', $pack['rejected'][0]['id']);
        $this->assertSame(
            AtlasSystemGraphContextBuilderService::REJECT_UNTRACEABLE,
            $pack['rejected'][0]['reason']
        );
        // No traceable context admitted -> not ready for an implementation decision.
        $this->assertFalse($pack['ready_for_implementation']);
    }

    public function testUnauthorizedOriginIsExcludedNotMixedIn(): void
    {
        // Proibido: "misturar fonte nao autorizada". A traceable source whose
        // origin is outside the authorized allow-list is excluded as unauthorized.
        $pack = $this->service->compile([
            'authorized_origins' => ['repo_docs'],
            'candidates' => [
                ['id' => 'ok', 'origin' => 'repo_docs', 'title' => 'Doc', 'reference' => 'docs/x.md', 'relevance' => 0.6],
                ['id' => 'leak', 'origin' => 'random_blog', 'title' => 'Blog', 'reference' => 'http://x.test', 'relevance' => 0.99],
            ],
        ]);

        $admittedIds = array_map(static fn (array $s): string => $s['id'], $pack['sources']);
        $this->assertSame(['ok'], $admittedIds);
        $this->assertCount(1, $pack['rejected']);
        $this->assertSame('leak', $pack['rejected'][0]['id']);
        $this->assertSame(
            AtlasSystemGraphContextBuilderService::REJECT_UNAUTHORIZED,
            $pack['rejected'][0]['reason']
        );
    }

    public function testTooMuchContextIsCompactedToBudgetToProtectPrecision(): void
    {
        // Risco: "contexto demais reduzir precisao". Permitido: ranking + compactacao.
        // With a budget of 2, only the top-2 ranked sources survive; the rest are
        // trimmed (recorded), and the pack is ranked by relevance descending.
        $candidates = [];
        foreach ([0.10, 0.90, 0.50, 0.70] as $i => $rel) {
            $candidates[] = [
                'id' => 'd'.$i,
                'origin' => 'repo_docs',
                'title' => 'Doc '.$i,
                'reference' => 'docs/d'.$i.'.md',
                'relevance' => $rel,
            ];
        }

        $pack = $this->service->compile([
            'authorized_origins' => ['repo_docs'],
            'budget' => 2,
            'candidates' => $candidates,
        ]);

        $this->assertCount(2, $pack['sources']);
        $this->assertSame(['d1', 'd3'], array_map(static fn (array $s): string => $s['id'], $pack['sources']));
        $this->assertSame(1, $pack['sources'][0]['rank']);
        $this->assertSame(2, $pack['sources'][1]['rank']);
        $this->assertSame(2, $pack['limits']['trimmed']);
        $this->assertSame(2, $pack['limits']['admitted']);
        // The trimmed sources are the two lowest-ranked (d0=0.10, d2=0.50).
        $trimmedIds = array_map(static fn (array $s): string => $s['id'], $pack['trimmed']);
        $this->assertEqualsCanonicalizing(['d0', 'd2'], $trimmedIds);
    }

    public function testCompiledPackExposesCitationsAndIsTraceableNotARawDump(): void
    {
        // Decision: "Contexto deve ser compilado e rastreavel; nao deve ser despejo
        // bruto de docs." Regras para IA: decision must cite context references.
        $pack = $this->service->compile([
            'obra' => 'refactor-x',
            'intent' => 'implement',
            'authorized_origins' => ['repo_docs', 'memory_core'],
            'candidates' => [
                ['id' => 'a', 'origin' => 'repo_docs', 'title' => 'Canon', 'reference' => 'docs/a.md', 'relevance' => 0.8],
                ['id' => 'b', 'origin' => 'memory_core', 'title' => 'Memory', 'reference' => 'mem://b', 'relevance' => 0.4],
            ],
        ]);

        $this->assertTrue($pack['traceable']);
        $this->assertTrue($pack['ready_for_implementation']);
        $this->assertSame(['docs/a.md', 'mem://b'], $pack['citations']);
        // Summary is a compiled descriptor (mentions the Obra), not a raw paste.
        $this->assertStringContainsString('refactor-x', $pack['summary']);
        $this->assertStringContainsString('2 source(s)', $pack['summary']);
    }

    public function testAdmitsHelperEnforcesBothGatesIndependently(): void
    {
        $authorized = ['repo_docs'];

        $good = $this->service->admits(
            ['origin' => 'repo_docs', 'reference' => 'docs/x.md'],
            $authorized
        );
        $this->assertTrue($good['admit']);
        $this->assertNull($good['reason']);

        $untraceable = $this->service->admits(['origin' => 'repo_docs', 'reference' => ''], $authorized);
        $this->assertFalse($untraceable['admit']);
        $this->assertSame(AtlasSystemGraphContextBuilderService::REJECT_UNTRACEABLE, $untraceable['reason']);

        $unauthorized = $this->service->admits(['origin' => 'elsewhere', 'reference' => 'x'], $authorized);
        $this->assertFalse($unauthorized['admit']);
        $this->assertSame(AtlasSystemGraphContextBuilderService::REJECT_UNAUTHORIZED, $unauthorized['reason']);
    }
}
