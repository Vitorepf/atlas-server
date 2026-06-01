<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasProgrammingToolBacklogService;
use Tests\TestCase;

final class AtlasProgrammingToolBacklogTest extends TestCase
{
    private AtlasProgrammingToolBacklogService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AtlasProgrammingToolBacklogService();
    }

    public function testBacklogExposesTheFourDocumentedPrioritiesInOrder(): void
    {
        $priorities = $this->service->priorities();

        // The doc body is exactly four priority sections, P0 -> P3.
        $this->assertSame(4, $priorities['count']);
        $this->assertSame(
            ['P0', 'P1', 'P2', 'P3'],
            array_column($priorities['priorities'], 'id')
        );

        // Titles are carried verbatim from the doc headings.
        $byId = [];
        foreach ($priorities['priorities'] as $row) {
            $byId[$row['id']] = $row;
        }
        $this->assertSame('Real Cheap Recipes', $byId['P0']['title']);
        $this->assertSame('Specific Normalizers', $byId['P1']['title']);
        $this->assertSame('Gates By Authority', $byId['P2']['title']);
        $this->assertSame('Operational UX', $byId['P3']['title']);

        // P1 enumerates the seven normalizer fields.
        $this->assertSame(7, $byId['P1']['item_count']);
        $this->assertContains('fingerprint', $byId['P1']['items']);
        $this->assertContains('authority_group', $byId['P1']['items']);
    }

    public function testAdHocCommandExecutionIsNeverAPromotionChannel(): void
    {
        // Frontmatter decision: promoted through recipes/normalizers/gates/UX,
        // "never by ad hoc command execution".
        $adHoc = $this->service->classifyPromotion('ad_hoc_command');
        $this->assertFalse($adHoc['promotable']);
        $this->assertTrue($adHoc['is_ad_hoc_command']);
        $this->assertSame(
            'ad_hoc_command_execution_is_never_a_promotion_channel',
            $adHoc['reason']
        );

        // The four governed channels are promotable.
        foreach (['recipe', 'normalizer', 'gate', 'ux'] as $channel) {
            $verdict = $this->service->classifyPromotion($channel);
            $this->assertTrue($verdict['promotable'], "channel {$channel} should be promotable");
            $this->assertFalse($verdict['is_ad_hoc_command']);
        }

        // An unknown channel cannot promote a slice either.
        $unknown = $this->service->classifyPromotion('wishful_thinking');
        $this->assertFalse($unknown['promotable']);
        $this->assertSame(
            'unknown_channel_must_use_recipe_normalizer_gate_or_ux',
            $unknown['reason']
        );
    }

    public function testNormalizerNeedsAllSevenFieldsToBeAStructuredFinding(): void
    {
        // All seven P1 fields present -> structured finding.
        $complete = $this->service->evaluateNormalizer([
            'severity' => true,
            'file_line' => true,
            'fingerprint' => true,
            'rule_id' => true,
            'authority_group' => true,
            'repair_hint' => true,
            'artifact_refs' => true,
        ]);
        $this->assertTrue($complete['structured']);
        $this->assertSame([], $complete['missing']);
        $this->assertSame(7, $complete['total_required']);

        // Missing fields -> still generic output, not yet structured.
        $partial = $this->service->evaluateNormalizer([
            'severity' => true,
            'file_line' => true,
        ]);
        $this->assertFalse($partial['structured']);
        $this->assertContains('fingerprint', $partial['missing']);
        $this->assertContains('artifact_refs', $partial['missing']);
        $this->assertSame(
            'generic_output_missing_required_normalizer_fields',
            $partial['reason']
        );
    }

    public function testAuthorityGateKeepsPrimaryAndSuppressesComplementaryDuplicates(): void
    {
        // P2: gates use the primary tool per authority group and suppress duplicate
        // findings from complementary tools.
        $gate = $this->service->evaluateAuthorityGate('static_analysis', [
            ['tool' => 'primary_linter', 'role' => 'primary', 'fingerprint' => 'fp-1'],
            ['tool' => 'complementary_linter', 'role' => 'complementary', 'fingerprint' => 'fp-1'],
            ['tool' => 'complementary_linter', 'role' => 'complementary', 'fingerprint' => 'fp-2'],
        ]);

        $this->assertTrue($gate['has_primary']);
        // The primary finding is authoritative; the duplicate complementary one is
        // suppressed; the non-duplicate complementary finding survives.
        $this->assertSame(1, $gate['suppressed_count']);
        $this->assertSame('complementary_linter', $gate['suppressed'][0]['tool']);
        $this->assertSame('fp-1', $gate['suppressed'][0]['fingerprint']);

        $authoritativeTools = array_column($gate['authoritative'], 'tool');
        $this->assertContains('primary_linter', $authoritativeTools);
        $this->assertSame(
            'primary_kept_complementary_duplicates_suppressed',
            $gate['reason']
        );
    }

    public function testAuthorityGateNeverSuppressesWhenThereIsNoPrimary(): void
    {
        // Without a primary tool finding the gate cannot invent authority and must
        // not silently suppress complementary findings.
        $gate = $this->service->evaluateAuthorityGate('static_analysis', [
            ['tool' => 'complementary_a', 'role' => 'complementary', 'fingerprint' => 'fp-9'],
            ['tool' => 'complementary_b', 'role' => 'complementary', 'fingerprint' => 'fp-9'],
        ]);

        $this->assertFalse($gate['has_primary']);
        $this->assertSame(0, $gate['suppressed_count']);
        $this->assertCount(2, $gate['authoritative']);
        $this->assertSame(
            'no_primary_tool_finding_nothing_authoritative_for_group',
            $gate['reason']
        );
    }
}
