<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasAiResolverCorpusAuditService;
use Tests\TestCase;

class AtlasAiResolverCorpusAuditTest extends TestCase
{
    public function test_p0_with_preserved_source_promotes(): void
    {
        // "P0 | Must influence Mother Architecture now." -> promote, canonical.
        $receipt = $this->service()->audit([
            'id' => 'p0-item',
            'level' => 'P0',
            'source_link_preserved' => true,
        ]);

        $this->assertSame('P0', $receipt['level']);
        $this->assertSame('promote', $receipt['disposition']);
        $this->assertTrue($receipt['promote']);
        $this->assertSame([], $receipt['violations']);
        $this->assertTrue($receipt['compliant']);
        $this->assertSame('Must influence Mother Architecture now.', $receipt['level_meaning']);
    }

    public function test_p2_and_archive_go_to_archive_not_active_authority(): void
    {
        // "P2 | Future idea" and "Archive | Useful history" -> archive (not promote).
        $p2 = $this->service()->audit(['id' => 'p2', 'level' => 'P2', 'source_link_preserved' => true]);
        $archive = $this->service()->audit(['id' => 'h', 'level' => 'Archive', 'source_link_preserved' => true]);

        $this->assertSame('archive', $p2['disposition']);
        $this->assertFalse($p2['promote']);
        $this->assertSame('archive', $archive['disposition']);
        $this->assertSame('ARCHIVE', $archive['level']);
    }

    public function test_rule_nothing_becomes_canonical_without_classification(): void
    {
        // Rule 2: an unclassified item cannot become canonical -> reject.
        $receipt = $this->service()->audit(['id' => 'blob', 'level' => 'P7-bogus']);

        $this->assertNull($receipt['level']);
        $this->assertFalse($receipt['classified']);
        $this->assertSame('reject', $receipt['disposition']);
        $this->assertContains(
            'unclassified: cannot become canonical without a valid level (P0/P1/P2/Archive)',
            $receipt['violations'],
        );
    }

    public function test_anti_pattern_promote_only_missing_decisions(): void
    {
        // Anti-pattern: a P0 that duplicates an existing owner-doc decision is NOT
        // promotable (promote only what is missing) -> reject + competition guard.
        $receipt = $this->service()->audit([
            'id' => 'dup',
            'level' => 'P0',
            'source_link_preserved' => true,
            'duplicates_owner_decision' => true,
        ]);

        $this->assertSame('promote', $receipt['intended_disposition']);
        $this->assertSame('reject', $receipt['disposition']);
        $this->assertContains(
            'duplicate: decision already exists in an owner doc; promote only missing decisions',
            $receipt['violations'],
        );
    }

    public function test_preserve_source_link_and_no_kb_competition_invariants(): void
    {
        // Anti-pattern "preserve source links" + Rule 3 "nothing competes with the KB".
        $receipt = $this->service()->audit([
            'id' => 'p1-bad',
            'level' => 'P1',
            'source_link_preserved' => false,
            'kept_as_competing_authority' => true,
        ]);

        $this->assertSame('reject', $receipt['disposition']);
        $this->assertContains(
            'lost_source_link: promotion/archive must preserve the resolver source link',
            $receipt['violations'],
        );
        $this->assertContains(
            'competes_with_kb: a promoted/archived item must not remain a live competing authority',
            $receipt['violations'],
        );
    }

    public function test_rule_nothing_important_stays_lost_is_flagged(): void
    {
        // Rule 1: an important (P0/P1) item that does NOT end up promoted is a
        // lost-value warning the operator must resolve.
        $receipt = $this->service()->audit([
            'id' => 'p1-dropped',
            'level' => 'P1',
            'source_link_preserved' => false, // forces reject, so it is not promoted
        ]);

        $this->assertNotSame('promote', $receipt['disposition']);
        $this->assertTrue($receipt['lost_value_warning']);
        $this->assertFalse($receipt['rules']['not_lost']);
    }

    public function test_corpus_rollup_counts_dispositions_and_warnings(): void
    {
        $report = $this->service()->auditCorpus([
            ['id' => 'a', 'level' => 'P0', 'source_link_preserved' => true],   // promote
            ['id' => 'b', 'level' => 'P2', 'source_link_preserved' => true],   // archive
            ['id' => 'c', 'level' => 'P1', 'source_link_preserved' => false],  // reject + lost value
            ['id' => 'd', 'level' => 'nope'],                                  // reject (unclassified)
        ]);

        $summary = $report['summary'];
        $this->assertSame(4, $summary['evaluated']);
        $this->assertSame(1, $summary['by_disposition']['promote']);
        $this->assertSame(1, $summary['by_disposition']['archive']);
        $this->assertSame(2, $summary['by_disposition']['reject']);
        $this->assertSame(1, $summary['lost_value_warnings']);
        $this->assertFalse($summary['clean']);
    }

    private function service(): AtlasAiResolverCorpusAuditService
    {
        return new AtlasAiResolverCorpusAuditService;
    }
}
