<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCanonicalIndexAuthorityMapService;
use Tests\TestCase;

/**
 * Pins the canonical-index authority-map doc's enforceable rules.
 *
 * @see docs/engineering-knowledge-base/canonical-index/authority-map.md
 */
final class AtlasCanonicalIndexAuthorityMapTest extends TestCase
{
    private AtlasCanonicalIndexAuthorityMapService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasCanonicalIndexAuthorityMapService();
    }

    /** Authority table: a known subject resolves to its single documented owner doc. */
    public function test_known_subject_resolves_to_its_documented_authority(): void
    {
        $result = $this->service->authorityFor('Knowledge governance');

        $this->assertTrue($result['found']);
        $this->assertSame('Knowledge governance', $result['subject']);
        $this->assertSame(['atlas-ai-knowledge-governance-system.md'], $result['authority']);
    }

    /** Pipeline/topology row carries all three documented owner docs, in order. */
    public function test_multi_owner_subject_returns_all_documented_docs(): void
    {
        $result = $this->service->authorityFor('Pipeline and topology');

        $this->assertSame(
            ['atlas-ai-pipeline.md', 'atlas-ai-core-vs-domain.md', 'atlas-ai-operating-system.md'],
            $result['authority'],
        );
    }

    /** An alias and a doc basename normalize to the same canonical subject. */
    public function test_alias_and_doc_basename_resolve_to_same_subject(): void
    {
        $viaAlias = $this->service->authorityFor('ACOS');
        $viaName = $this->service->authorityFor('atlas-cognition-operating-system.md');

        $this->assertTrue($viaAlias['found']);
        $this->assertSame('Cognition Operating System (ACOS)', $viaAlias['subject']);
        $this->assertSame($viaAlias['subject'], $viaName['subject']);
    }

    /**
     * Doc decision: "Subject authority must be explicit to prevent duplicate
     * docs." An unmapped subject is a GAP, never a guessed owner.
     */
    public function test_unmapped_subject_is_a_gap_not_a_guess(): void
    {
        $result = $this->service->authorityFor('a subject that is deliberately absent');

        $this->assertFalse($result['found']);
        $this->assertNull($result['subject']);
        $this->assertSame([], $result['authority']);
    }

    /**
     * Doc "## Rule": "If the subject is not here, find the closest owner
     * README/doc before creating a new authority surface." A bare lookup never
     * authorizes a new authority surface — for a known OR an unknown subject.
     */
    public function test_rule_never_authorizes_a_new_authority_surface(): void
    {
        $gap = $this->service->resolveSubject('totally new subject');
        $this->assertSame(AtlasCanonicalIndexAuthorityMapService::RESOLUTION_GAP, $gap['resolution']);
        $this->assertFalse($gap['may_create_authority_surface']);
        $this->assertSame(
            'find_closest_owner_readme_before_creating_new_authority_surface',
            $gap['next_action'],
        );

        $known = $this->service->resolveSubject('Finance');
        $this->assertSame(AtlasCanonicalIndexAuthorityMapService::RESOLUTION_MAPPED, $known['resolution']);
        $this->assertFalse($known['may_create_authority_surface']);
        $this->assertSame('use_existing_authority', $known['next_action']);
    }

    /**
     * Doc boundary row: Genesis "is not an OS and does not supersede Autonomous
     * Holding or Domain Company Runtimes." A supersession claim is rejected; the
     * same doc with no supersession claim is allowed.
     */
    public function test_genesis_supersession_claim_is_rejected(): void
    {
        $claim = $this->service->checkSupersession('atlas-autonomous-company-os-genesis-initiative.md', true);
        $this->assertFalse($claim['allowed']);
        $this->assertSame('genesis_not_os', $claim['boundary']);
        $this->assertNotNull($claim['reason']);

        $noClaim = $this->service->checkSupersession('atlas-autonomous-company-os-genesis-initiative.md', false);
        $this->assertTrue($noClaim['allowed']);
        $this->assertNull($noClaim['reason']);
    }

    /** Map snapshot exposes a stable, deterministic subject/doc inventory. */
    public function test_map_snapshot_is_deterministic_and_nonempty(): void
    {
        $map = $this->service->map();

        $this->assertSame(count(AtlasCanonicalIndexAuthorityMapService::AUTHORITY), $map['subject_count']);
        $this->assertGreaterThan(0, $map['authority_doc_count']);
        $this->assertSame(
            count(AtlasCanonicalIndexAuthorityMapService::SUPERSESSION_BOUNDARIES),
            $map['boundary_count'],
        );
    }
}
