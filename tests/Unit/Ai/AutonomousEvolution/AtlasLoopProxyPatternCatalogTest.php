<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProxyPatternCatalog;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProxyPatternCatalogEntry;
use Error;
use Tests\TestCase;

final class AtlasLoopProxyPatternCatalogTest extends TestCase
{
    public function test_catalog_seeds_at_least_seven_patterns_covering_every_family(): void
    {
        $catalog = new AtlasLoopProxyPatternCatalog;
        $entries = $catalog->all();

        $this->assertGreaterThanOrEqual(7, count($entries), 'catalog must seed at least 7 patterns');

        $byFamily = [];
        foreach ($entries as $entry) {
            $byFamily[$entry->family] = ($byFamily[$entry->family] ?? 0) + 1;
        }
        foreach (AtlasLoopProxyPatternCatalog::VALID_FAMILIES as $family) {
            $this->assertGreaterThanOrEqual(1, $byFamily[$family] ?? 0, "family {$family} must have at least one entry");
        }
    }

    public function test_pattern_ids_are_stable_kebab_case_strings(): void
    {
        foreach ((new AtlasLoopProxyPatternCatalog)->ids() as $id) {
            $this->assertMatchesRegularExpression('/^[a-z][a-z0-9-]*$/', $id, "pattern id '$id' must be kebab-case");
        }
    }

    public function test_entries_are_immutable_readonly_structs(): void
    {
        $entry = (new AtlasLoopProxyPatternCatalog)->all()[0];
        $this->assertInstanceOf(AtlasLoopProxyPatternCatalogEntry::class, $entry);

        $threw = false;
        try {
            // PHP throws an Error when trying to write to a readonly property.
            $entry->id = 'tampered';
        } catch (Error) {
            $threw = true;
        }
        $this->assertTrue($threw, 'mutating a catalog entry MUST throw (final readonly)');
    }

    public function test_by_id_round_trips_and_returns_null_for_unknown(): void
    {
        $catalog = new AtlasLoopProxyPatternCatalog;
        $entry = $catalog->byId('cyclomatic-proxy');
        $this->assertNotNull($entry);
        $this->assertSame(AtlasLoopProxyPatternCatalog::FAMILY_METRIC_PROXY, $entry->family);

        $this->assertNull($catalog->byId('does-not-exist'));
    }

    public function test_antigoodhart_refusal_source_contains_no_orphan_pattern_id_literals(): void
    {
        // The catalog is the SINGLE source of pattern_id strings. AtlasLoopAntiGoodhartUnifiedRefusal
        // accepts pattern_ids as INPUT only — its source must NOT hardcode any of the catalog ids as
        // string literals (which would be a backdoor seed).
        $refusalSrc = (string) file_get_contents(base_path('app/Services/Ai/AutonomousEvolution/AtlasLoopAntiGoodhartUnifiedRefusal.php'));
        $offenders = [];
        foreach ((new AtlasLoopProxyPatternCatalog)->ids() as $catalogId) {
            $needle = "'".$catalogId."'";
            if (str_contains($refusalSrc, $needle) || str_contains($refusalSrc, '"'.$catalogId.'"')) {
                $offenders[] = $catalogId;
            }
        }
        $this->assertSame(
            [],
            $offenders,
            'AtlasLoopAntiGoodhartUnifiedRefusal must NOT hardcode catalog ids as string literals: '.implode(',', $offenders),
        );
    }

    public function test_catalog_doc_anchor_exists_in_the_docs(): void
    {
        $doc = (string) file_get_contents(base_path('docs/loop-proxy-pattern-catalog.md'));
        foreach ((new AtlasLoopProxyPatternCatalog)->all() as $entry) {
            $this->assertStringContainsString('## '.$entry->doc_anchor, $doc, "doc must carry an H2 anchor for {$entry->doc_anchor}");
        }
    }
}
