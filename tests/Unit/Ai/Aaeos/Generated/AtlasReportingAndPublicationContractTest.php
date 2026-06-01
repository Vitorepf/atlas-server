<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasReportingAndPublicationContractService;
use Tests\TestCase;

/**
 * Pins the documented research reporting/publication contract rules.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
 */
class AtlasReportingAndPublicationContractTest extends TestCase
{
    private function service(): AtlasReportingAndPublicationContractService
    {
        return new AtlasReportingAndPublicationContractService();
    }

    /**
     * Build a fully valid, non-critical report draft: all 14 sections, one
     * conclusion carrying all 8 fields, an allow-listed channel that links back
     * to the run + evidence.
     *
     * @param array<string,mixed> $overrides
     *
     * @return array<string,mixed>
     */
    private function validReport(array $overrides = []): array
    {
        $service = $this->service();

        return array_merge([
            'topic' => 'developer tooling update',
            'sections' => $service->reportSections(),
            'conclusions' => [array_fill_keys($service->conclusionFields(), 'present')],
            'channel' => 'atlas_dashboard',
            'links_to_run' => true,
            'links_to_evidence' => true,
        ], $overrides);
    }

    /**
     * Doc "Enterprise Report Shape" + "Publication Channels": a complete,
     * non-critical report on an allow-listed channel that links back to the run
     * and evidence -> PUBLISH.
     */
    public function test_complete_non_critical_report_publishes(): void
    {
        $result = $this->service()->decidePublication($this->validReport());

        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_PUBLISH, $result['decision']);
        $this->assertTrue($result['publishable']);
        $this->assertTrue($result['sections_complete']);
        $this->assertTrue($result['conclusions_complete']);
        $this->assertTrue($result['channel_valid']);
        $this->assertSame([], $result['block_reasons']);
        $this->assertNull($result['critical_topic']);
    }

    /**
     * Doc "Critical Topic Gate": security/medical/legal/finance/compliance/
     * privacy/credentials/infrastructure reports REQUIRE human review before
     * final publication — even when the report is otherwise complete. The
     * decision is HOLD, not PUBLISH, and the matched class is reported.
     */
    public function test_critical_topic_holds_for_human_review_even_when_complete(): void
    {
        $result = $this->service()->decidePublication($this->validReport([
            'topic' => 'quarterly finance exposure summary',
        ]));

        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_HOLD_FOR_REVIEW, $result['decision']);
        $this->assertFalse($result['publishable']);
        $this->assertTrue($result['requires_human_review']);
        $this->assertSame('finance', $result['critical_topic']);
        // The report itself is complete — the hold is purely the topic gate.
        $this->assertTrue($result['sections_complete']);
        $this->assertTrue($result['conclusions_complete']);
    }

    /**
     * Doc "Enterprise Report Shape": a report missing any of the 14 sections is
     * incomplete and BLOCKED — and the missing section is named. A block beats
     * the critical-topic hold (shape is validated first).
     */
    public function test_report_missing_a_section_is_blocked(): void
    {
        $service = $this->service();
        $sections = $service->reportSections();
        // Drop the research log (the 14th section) and use a critical topic to
        // prove BLOCK dominates HOLD.
        $partial = array_values(array_diff($sections, ['research_log']));

        $result = $service->decidePublication($this->validReport([
            'topic' => 'security advisory',
            'sections' => $partial,
        ]));

        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_BLOCK, $result['decision']);
        $this->assertFalse($result['sections_complete']);
        $this->assertContains('research_log', $result['missing_sections']);
        $this->assertContains('report_missing_sections', $result['block_reasons']);
    }

    /**
     * Doc "Per-Conclusion Fields": each important conclusion must carry all 8
     * fields. A conclusion missing one (here citation_health) blocks publication
     * and the missing field is reported at its index.
     */
    public function test_conclusion_missing_required_field_is_blocked(): void
    {
        $service = $this->service();
        $partialConclusion = array_fill_keys($service->conclusionFields(), 'present');
        unset($partialConclusion['citation_health']);

        $result = $service->decidePublication($this->validReport([
            'conclusions' => [$partialConclusion],
        ]));

        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_BLOCK, $result['decision']);
        $this->assertFalse($result['conclusions_complete']);
        $this->assertContains('conclusion_missing_required_fields', $result['block_reasons']);
        $this->assertSame(0, $result['incomplete_conclusions'][0]['index']);
        $this->assertContains('citation_health', $result['incomplete_conclusions'][0]['missing_fields']);
    }

    /**
     * Doc "Publication Channels": "All channels must point back to the research
     * run and evidence records." A complete report on a valid channel that does
     * NOT link back is blocked; an off-list channel is also rejected.
     */
    public function test_channel_must_be_allow_listed_and_link_back_to_run_and_evidence(): void
    {
        $service = $this->service();

        $noLinks = $service->decidePublication($this->validReport([
            'links_to_evidence' => false,
        ]));
        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_BLOCK, $noLinks['decision']);
        $this->assertFalse($noLinks['links_back_to_run_and_evidence']);
        $this->assertContains('channel_does_not_link_back_to_run_and_evidence', $noLinks['block_reasons']);

        $offList = $service->decidePublication($this->validReport([
            'channel' => 'personal_blog',
        ]));
        $this->assertSame(AtlasReportingAndPublicationContractService::DECISION_BLOCK, $offList['decision']);
        $this->assertNull($offList['channel']);
        $this->assertContains('channel_not_on_allow_list', $offList['block_reasons']);
    }

    /**
     * Doc "Alert Rule": alerts can create tasks or proposals, but can NEVER
     * directly change policy, memory truth, provider routing, runtime code,
     * credentials or production configuration.
     */
    public function test_alert_allows_task_and_proposal_but_forbids_runtime_mutations(): void
    {
        $service = $this->service();

        foreach (['task', 'proposal'] as $allowed) {
            $result = $service->decideAlert($allowed);
            $this->assertSame(AtlasReportingAndPublicationContractService::ALERT_ALLOWED, $result['verdict']);
            $this->assertTrue($result['allowed']);
            $this->assertFalse($result['creates_runtime_change']);
        }

        foreach ($service->alertForbiddenTargets() as $forbidden) {
            $result = $service->decideAlert($forbidden);
            $this->assertSame(AtlasReportingAndPublicationContractService::ALERT_FORBIDDEN, $result['verdict']);
            $this->assertFalse($result['allowed']);
            $this->assertTrue($result['is_known_forbidden_target']);
            $this->assertTrue($result['creates_runtime_change']);
        }

        // Unknown targets are forbidden too (allow-list-first).
        $unknown = $service->decideAlert('send_to_printer');
        $this->assertSame(AtlasReportingAndPublicationContractService::ALERT_FORBIDDEN, $unknown['verdict']);
        $this->assertSame('target_not_on_alert_allow_list', $unknown['reason']);
    }

    /**
     * Doc catalog counts: 14 report sections, 8 conclusion fields, 8 critical
     * topics, 7 publication channels, 6 forbidden alert targets. Pin the catalog
     * so the runtime stays faithful to the doc.
     */
    public function test_doc_catalog_counts_are_pinned(): void
    {
        $service = $this->service();

        $this->assertCount(14, $service->reportSections());
        $this->assertCount(8, $service->conclusionFields());
        $this->assertCount(8, $service->criticalTopics());
        $this->assertCount(7, $service->publicationChannels());
        $this->assertCount(6, $service->alertForbiddenTargets());

        // Spot-check the contract edges.
        $this->assertSame('executive_summary', $service->reportSections()[0]);
        $this->assertSame('research_log', $service->reportSections()[13]);
        $this->assertContains('credentials', $service->criticalTopics());
        $this->assertTrue($service->isCriticalTopic('patient medical record leak'));
        $this->assertFalse($service->isCriticalTopic('new css framework weekly roundup'));
    }
}
