<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Organism\Marketing;

use App\Services\Ai\Organism\AbstractDomainActuator;
use App\Services\Ai\Organism\AtlasOrganismActuationGate;
use App\Services\Ai\Organism\AtlasOrganismRegistry;
use App\Services\Ai\Organism\AtlasOrganismService;
use App\Services\Ai\Organism\DomainProposal;
use App\Services\Ai\Organism\Marketing\MarketingDomainActuator;
use App\Services\Ai\Organism\Marketing\MarketingDomainProposer;
use App\Services\Ai\Organism\Marketing\MarketingDomainValidator;
use App\Services\Ai\Organism\OrganismBrainAnchor;
use App\Services\Ai\Organism\OrganismProposalRecorder;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use ReflectionMethod;
use Tests\TestCase;

/**
 * AOBG N4.F4 — the MARKETING domain PLUGGED INTO the N4 organism: the 2nd domain that PROVES
 * the organism abstraction is DOMAIN-AGNOSTIC (not finance-special).
 *
 * The assertions:
 *  - a marketing intent → a brain-anchored campaign DRAFT (a real proposal, not finance);
 *  - the validator scores a deterministic content-QUALITY heuristic — VANITY engagement
 *    metrics (impressions/likes/clicks) are FORBIDDEN (the marketing analogue of win-rate);
 *  - a click-bait / stub draft FAILS honestly (never a fabricated green);
 *  - actuate() returns requires_operator and CANNOT publish / send / spend ad budget — proven
 *    structurally (sealed, inherited act path) and grep-level (no real-world I/O in the source);
 *  - marketing is NON-sensitive (low-stakes) — but still propose-only.
 *
 * COST: zero provider tokens, zero subprocess, sqlite-safe. The proposer/validator are
 * deterministic on-machine; brain anchor + recorder are in-memory fakes.
 */
final class MarketingDomainPlugInTest extends TestCase
{
    private function fakeAnchor(array $refs = ['ref:obra:1', 'code:Campaign::draft']): OrganismBrainAnchor
    {
        return new class($refs) implements OrganismBrainAnchor
        {
            /** @param list<string> $refs */
            public function __construct(private array $refs) {}

            public function anchor(string $intent, array $opts = []): array
            {
                return ['brain_refs' => $this->refs, 'reality_graph_paths' => $this->refs];
            }
        };
    }

    private function captureRecorder(): OrganismProposalRecorder
    {
        return new class implements OrganismProposalRecorder
        {
            /** @var list<array<string,mixed>> */
            public array $recorded = [];

            public function record(DomainProposal $proposal, array $validation): array
            {
                $this->recorded[] = [
                    'domain' => $proposal->domain,
                    'sensitive' => $proposal->sensitive,
                    'provider_safe_view' => $proposal->toProviderSafeArray(),
                ];

                return ['recorded' => true, 'node_ref' => 'node:'.$proposal->ref()];
            }

            public function priorProposals(?string $domain = null, int $limit = 10): array
            {
                return [];
            }
        };
    }

    private function service(OrganismProposalRecorder $recorder): AtlasOrganismService
    {
        $registry = new AtlasOrganismRegistry;
        $registry->register(new MarketingDomainProposer, new MarketingDomainValidator, new MarketingDomainActuator);

        return new AtlasOrganismService($registry, $this->fakeAnchor(), $recorder, new CrossDomainTaxonomyMap, new AtlasOrganismActuationGate);
    }

    // ------------------------------------------------------------------
    // 1) A marketing intent → a brain-anchored campaign DRAFT, validated by the quality
    //    heuristic (vanity metrics forbidden), propose-only.
    // ------------------------------------------------------------------

    public function test_marketing_proposes_a_brain_anchored_draft_validated_by_quality_not_vanity(): void
    {
        $recorder = $this->captureRecorder();
        $svc = $this->service($recorder);

        $out = $svc->propose('marketing', 'Promote the new local-first AI workspace to indie developers', [
            'payload' => ['audience' => 'indie developers', 'channel' => 'email + dev communities'],
        ]);

        $this->assertSame('marketing', $out['domain']);
        $this->assertFalse($out['sensitive'], 'marketing is low-stakes / non-sensitive');

        // The proposal is a real campaign DRAFT, brain-anchored.
        $this->assertStringContainsString('Campaign DRAFT', $out['proposal']['content']);
        $this->assertNotEmpty($out['proposal']['brain_refs']);
        $this->assertSame('marketing.deterministic_draft.on_machine.propose_only', $out['brain']['proposer']);

        // The honest metric is content_quality — NEVER a vanity engagement metric.
        $v = $out['validation'];
        $this->assertSame('content_quality', $v['metric']);
        $this->assertStringContainsString('vanity_metrics_forbidden', $v['method']);
        $this->assertTrue($v['passed'], 'a clear draft with CTA + audience should clear the quality floor');
        $this->assertIsFloat($v['value']);

        // Forbidden vanity tokens never appear as the metric/method.
        $blob = strtolower(json_encode($v) ?: '');
        foreach (['impression', 'likes', 'click_through', 'ctr', 'followers'] as $vanity) {
            $this->assertStringNotContainsString($vanity, $blob, "vanity metric '$vanity' must never appear");
        }

        // Propose-only + recorded; payload never crosses to the provider-safe view.
        $this->assertSame('requires_operator', $out['actuation_gate']);
        $this->assertArrayNotHasKey('payload', $out['proposal']);
        $this->assertTrue($out['recorded']['recorded']);
    }

    // ------------------------------------------------------------------
    // 2) A click-bait / stub draft FAILS honestly — never a fabricated green.
    // ------------------------------------------------------------------

    public function test_clickbait_headline_fails_the_quality_heuristic_honestly(): void
    {
        $validator = new MarketingDomainValidator;

        // A click-bait, no-CTA, no-audience draft must FAIL.
        $bad = DomainProposal::fromArray([
            'domain' => 'marketing',
            'intent' => 'x',
            'content' => 'SHOCKING!! You won\'t believe this one weird trick!!!',
            'rationale' => 'r',
            'payload' => [
                'headline' => 'SHOCKING!! You won\'t believe this one weird trick!!!',
                'body' => 'short',
                'audience' => 'everyone',
                'has_cta' => false,
            ],
        ]);
        $v = $validator->validate($bad);
        $this->assertFalse($v['passed']);
        $this->assertNotEmpty($v['reasons']);
        $this->assertContains('failed:anti_clickbait', $v['reasons']);
        $this->assertContains('failed:has_cta', $v['reasons']);
    }

    public function test_no_draft_is_honest_empty_not_a_fabricated_pass(): void
    {
        $validator = new MarketingDomainValidator;
        $empty = DomainProposal::fromArray(['domain' => 'marketing', 'intent' => 'x', 'content' => '', 'rationale' => 'r']);
        $v = $validator->validate($empty);

        $this->assertNull($v['value']);
        $this->assertFalse($v['passed']);
        $this->assertContains('no_draft', $v['reasons']);
    }

    // ------------------------------------------------------------------
    // 3) THE MARKETING CEILING (load-bearing): actuate() returns requires_operator and CANNOT
    //    publish / send / spend — proven structurally + grep-level.
    // ------------------------------------------------------------------

    public function test_marketing_actuate_returns_requires_operator_and_cannot_publish(): void
    {
        $actuator = new MarketingDomainActuator;
        $proposal = DomainProposal::fromArray([
            'domain' => 'marketing',
            'intent' => 'try to publish',
            'content' => 'draft',
            'rationale' => 'r',
            'payload' => ['post' => ['platform' => 'x', 'body' => 'hi']],
        ]);

        $result = (new AtlasOrganismActuationGate)->actuate($actuator, $proposal);

        $this->assertSame('requires_operator', $result['status']);
        $this->assertTrue($result['recorded']);
        $this->assertSame('marketing', $result['domain']);
        $this->assertSame(AbstractDomainActuator::CEILING, $result['ceiling']);
        // No publish/post artifact ever comes back.
        $this->assertArrayNotHasKey('post_id', $result);
        $this->assertArrayNotHasKey('published_url', $result);
        $this->assertArrayNotHasKey('campaign_id', $result);

        // STRUCTURAL: inherits the sealed (final) act path; does NOT re-declare actuate().
        $rm = new ReflectionMethod(AbstractDomainActuator::class, 'actuate');
        $this->assertTrue($rm->isFinal(), 'the act path must be final — the propose-only publish seal');
        $declaring = (new ReflectionMethod(MarketingDomainActuator::class, 'actuate'))->getDeclaringClass()->getName();
        $this->assertSame(AbstractDomainActuator::class, $declaring);

        // GREP-LEVEL: the marketing actuator source contains NO publish/send/spend/http CALL.
        $src = file_get_contents(base_path('app/Services/Ai/Organism/Marketing/MarketingDomainActuator.php')) ?: '';
        $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
        $code = preg_replace('#//.*$#m', '', $code) ?? $code;
        $code = preg_replace('#"(?:[^"\\\\]|\\\\.)*"#s', '""', $code) ?? $code;
        $code = preg_replace("#'(?:[^'\\\\]|\\\\.)*'#s", "''", $code) ?? $code;
        foreach (['Http::', '->publish(', '->send(', '->spend(', '->postCampaign(', '->createCampaign(', 'curl_exec', 'AdsClient', 'new \\GuzzleHttp'] as $needle) {
            $this->assertStringNotContainsString($needle, $code, "marketing actuator must contain NO publish/send/spend I/O — found '$needle'");
        }
    }
}
