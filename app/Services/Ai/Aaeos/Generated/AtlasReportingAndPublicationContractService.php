<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Research reporting-and-publication contract decider.
 *
 * Pure, deterministic runtime for the research reporting/publication contract
 * doc. Given a drafted research report (its present sections, its conclusions,
 * the topic, the requested publication channel, and whether each channel link
 * points back to the research run + evidence) it decides whether the report may
 * publish, whether it must hold for human review, and how an alert may be
 * dispatched — enforcing exactly what the doc states.
 *
 * Contract (from the doc):
 *   - "Enterprise Report Shape": every high-rigor report carries the same 14
 *     ordered sections (executive summary ... research log). A report missing
 *     any section is incomplete and cannot publish as high-rigor.
 *   - "Per-Conclusion Fields": each important conclusion must carry the same 8
 *     fields (source, date, evidence, confidence, source type, classification,
 *     citation health, contradiction status). A conclusion missing a field is
 *     unverifiable.
 *   - "Critical Topic Gate": security, medical, legal, finance, compliance,
 *     privacy, credentials and infrastructure-critical reports REQUIRE human
 *     review before final publication or action.
 *   - "Alert Rule": alerts can create tasks or proposals; they can NEVER
 *     directly change Atlas policy, memory truth, provider routing, runtime
 *     code, credentials or production configuration.
 *   - "Publication Channels": a closed allow-list; every channel must point back
 *     to the research run and evidence records.
 *
 * The service NEVER publishes, sends a notification, mutates memory, routes a
 * provider, changes code/credentials/config or touches the database. It emits a
 * decision plus an auditable receipt; callers decide whether to publish, hold or
 * dispatch the alert as a task/proposal.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/reporting-and-publication-contract.md
 */
final class AtlasReportingAndPublicationContractService
{
    /** Stable receipt schema id for the decision this service emits. */
    public const RECEIPT_SCHEMA = 'atlas.research.reporting_and_publication_contract.v1';

    /** Publication decisions. */
    public const DECISION_PUBLISH = 'publish';                 // verified, non-critical, complete -> may publish
    public const DECISION_HOLD_FOR_REVIEW = 'hold_for_review'; // critical topic -> human review first
    public const DECISION_BLOCK = 'block';                     // shape/conclusion/channel invalid -> cannot publish

    /** Alert dispatch verdicts. */
    public const ALERT_ALLOWED = 'allowed';   // target is a task/proposal -> permitted
    public const ALERT_FORBIDDEN = 'forbidden'; // target is a runtime/policy mutation -> refused

    /**
     * "Enterprise Report Shape" — the 14 ordered sections, exactly as the doc
     * lists them. Order is part of the contract, so this is a list, not a set.
     *
     * @var list<string>
     */
    private const REPORT_SECTIONS = [
        'executive_summary',
        'what_changed_since_last_run',
        'key_findings',
        'primary_evidence',
        'secondary_evidence',
        'verified_claims',
        'uncertain_claims',
        'contradictions_found',
        'practical_impact',
        'risks',
        'recommendations',
        'sources_cited',
        'technical_appendix',
        'research_log',
    ];

    /**
     * "Per-Conclusion Fields" — the 8 fields each important conclusion must
     * carry, exactly as the doc lists them.
     *
     * @var list<string>
     */
    private const CONCLUSION_FIELDS = [
        'source',
        'date',
        'evidence',
        'confidence',
        'source_type',
        'classification',
        'citation_health',
        'contradiction_status',
    ];

    /**
     * "Critical Topic Gate" — the 8 topic classes that REQUIRE human review
     * before final publication or action, exactly as the doc lists them.
     *
     * @var list<string>
     */
    private const CRITICAL_TOPICS = [
        'security',
        'medical',
        'legal',
        'finance',
        'compliance',
        'privacy',
        'credentials',
        'infrastructure',
    ];

    /**
     * "Publication Channels" — the closed allow-list of future channels, exactly
     * as the doc lists them.
     *
     * @var list<string>
     */
    private const PUBLICATION_CHANNELS = [
        'atlas_dashboard',
        'email_summary',
        'slack_teams_notification',
        'notion_confluence_export',
        'pdf_html_report',
        'github_issue_jira_ticket',
        'proposal_inbox_item',
    ];

    /**
     * "Alert Rule" — alerts MAY produce these targets.
     *
     * @var list<string>
     */
    private const ALERT_ALLOWED_TARGETS = [
        'task',
        'proposal',
    ];

    /**
     * "Alert Rule" — alerts can NEVER directly change these. Each is a forbidden
     * mutation target, exactly as the doc lists them.
     *
     * @var list<string>
     */
    private const ALERT_FORBIDDEN_TARGETS = [
        'policy',
        'memory_truth',
        'provider_routing',
        'runtime_code',
        'credentials',
        'production_config',
    ];

    /**
     * Decide whether a drafted report may publish.
     *
     * Ordered gates (block dominates hold dominates publish):
     *   1. Report shape — all 14 sections present, else BLOCK (incomplete).
     *   2. Conclusions — every conclusion carries all 8 fields, else BLOCK.
     *   3. Channel — requested channel is on the allow-list AND links back to the
     *      research run + evidence, else BLOCK.
     *   4. Critical topic — if the topic is critical, HOLD for human review even
     *      when the report is otherwise complete and verified.
     *   5. Otherwise PUBLISH.
     *
     * @param array<string,mixed> $report
     *        topic            : string        report topic (free text; matched
     *                                         against the critical-topic classes)
     *        sections         : list<string>  section ids present in the draft
     *        conclusions      : list<array>   each an assoc array of field=>value
     *        channel          : string        requested publication channel id
     *        links_to_run     : bool          channel link points back to the
     *                                         research run (default false)
     *        links_to_evidence: bool          channel link points back to evidence
     *                                         records (default false)
     *
     * @return array<string,mixed> the decision + audit receipt
     */
    public function decidePublication(array $report): array
    {
        $topic = is_string($report['topic'] ?? null) ? trim((string) $report['topic']) : '';
        $sections = $this->normalizeStringList($report['sections'] ?? []);
        $conclusions = is_array($report['conclusions'] ?? null) ? $report['conclusions'] : [];
        $channel = $this->normalizeChannel($report['channel'] ?? null);
        $linksToRun = (bool) ($report['links_to_run'] ?? false);
        $linksToEvidence = (bool) ($report['links_to_evidence'] ?? false);

        $blockReasons = [];

        // Gate 1: Enterprise Report Shape — all 14 sections, in the canonical set.
        $missingSections = array_values(array_diff(self::REPORT_SECTIONS, $sections));
        if ($missingSections !== []) {
            $blockReasons[] = 'report_missing_sections';
        }

        // Gate 2: Per-Conclusion Fields — every conclusion carries all 8 fields.
        $incompleteConclusions = [];
        foreach ($conclusions as $index => $conclusion) {
            $present = is_array($conclusion) ? array_keys($conclusion) : [];
            $missingFields = array_values(array_diff(self::CONCLUSION_FIELDS, $present));
            if ($missingFields !== []) {
                $incompleteConclusions[] = [
                    'index' => (int) $index,
                    'missing_fields' => $missingFields,
                ];
            }
        }
        if ($incompleteConclusions !== []) {
            $blockReasons[] = 'conclusion_missing_required_fields';
        }

        // Gate 3: Publication Channel — must be on the allow-list AND every
        // channel must point back to the research run and evidence records.
        $channelAllowed = $channel !== null;
        if (! $channelAllowed) {
            $blockReasons[] = 'channel_not_on_allow_list';
        }
        if (! $linksToRun || ! $linksToEvidence) {
            $blockReasons[] = 'channel_does_not_link_back_to_run_and_evidence';
        }

        // Gate 4: Critical Topic Gate.
        $criticalTopic = $this->matchCriticalTopic($topic);
        $requiresHumanReview = $criticalTopic !== null;

        // --- Decision resolution (ordered) -----------------------------------
        if ($blockReasons !== []) {
            $decision = self::DECISION_BLOCK;
        } elseif ($requiresHumanReview) {
            $decision = self::DECISION_HOLD_FOR_REVIEW;
        } else {
            $decision = self::DECISION_PUBLISH;
        }

        $sectionsComplete = $missingSections === [];
        $conclusionsComplete = $incompleteConclusions === [];
        $channelValid = $channelAllowed && $linksToRun && $linksToEvidence;

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'decision' => $decision,
            'topic' => $topic,
            'critical_topic' => $criticalTopic,
            'requires_human_review' => $requiresHumanReview,
            'sections_complete' => $sectionsComplete,
            'missing_sections' => $missingSections,
            'conclusions_complete' => $conclusionsComplete,
            'incomplete_conclusions' => $incompleteConclusions,
            'channel' => $channel,
            'channel_valid' => $channelValid,
            'links_back_to_run_and_evidence' => $linksToRun && $linksToEvidence,
            'block_reasons' => array_values(array_unique($blockReasons)),
            'publishable' => $decision === self::DECISION_PUBLISH,
            'auditable' => true,
        ];
    }

    /**
     * Decide whether an alert may dispatch to a given target.
     *
     * "Alerts can create tasks or proposals. Alerts cannot directly change Atlas
     * policy, memory truth, provider routing, runtime code, credentials or
     * production configuration." Any target outside {task, proposal} is refused.
     *
     * @param string $target the action the alert wants to take
     *
     * @return array<string,mixed> the alert dispatch decision + receipt
     */
    public function decideAlert(string $target): array
    {
        $normalized = strtolower(trim($target));

        $allowed = in_array($normalized, self::ALERT_ALLOWED_TARGETS, true);
        $forbidden = in_array($normalized, self::ALERT_FORBIDDEN_TARGETS, true);

        // The contract is allow-list-first: only task/proposal are permitted.
        // Anything else (including unrecognised targets) is forbidden, because an
        // alert may never directly mutate runtime state.
        $verdict = $allowed ? self::ALERT_ALLOWED : self::ALERT_FORBIDDEN;

        $reason = match (true) {
            $allowed => 'target_is_task_or_proposal',
            $forbidden => 'alert_cannot_directly_mutate_runtime_state',
            default => 'target_not_on_alert_allow_list',
        };

        return [
            'schema' => self::RECEIPT_SCHEMA,
            'verdict' => $verdict,
            'target' => $normalized,
            'allowed' => $allowed,
            'is_known_forbidden_target' => $forbidden,
            'creates_runtime_change' => $verdict === self::ALERT_FORBIDDEN,
            'reason' => $reason,
            'auditable' => true,
        ];
    }

    /**
     * Is a topic gated for mandatory human review?
     */
    public function isCriticalTopic(string $topic): bool
    {
        return $this->matchCriticalTopic(trim($topic)) !== null;
    }

    /**
     * The 14 ordered Enterprise Report Shape sections.
     *
     * @return list<string>
     */
    public function reportSections(): array
    {
        return self::REPORT_SECTIONS;
    }

    /**
     * The 8 required Per-Conclusion Fields.
     *
     * @return list<string>
     */
    public function conclusionFields(): array
    {
        return self::CONCLUSION_FIELDS;
    }

    /**
     * The 8 Critical Topic classes that require human review.
     *
     * @return list<string>
     */
    public function criticalTopics(): array
    {
        return self::CRITICAL_TOPICS;
    }

    /**
     * The closed allow-list of Publication Channels.
     *
     * @return list<string>
     */
    public function publicationChannels(): array
    {
        return self::PUBLICATION_CHANNELS;
    }

    /**
     * The mutation targets an alert may NEVER touch.
     *
     * @return list<string>
     */
    public function alertForbiddenTargets(): array
    {
        return self::ALERT_FORBIDDEN_TARGETS;
    }

    /**
     * Match a free-text topic against the critical-topic classes. Returns the
     * matched class id, or null when the topic is non-critical.
     */
    private function matchCriticalTopic(string $topic): ?string
    {
        if ($topic === '') {
            return null;
        }

        $haystack = strtolower($topic);
        foreach (self::CRITICAL_TOPICS as $class) {
            if (str_contains($haystack, $class)) {
                return $class;
            }
        }

        return null;
    }

    /**
     * Normalize a requested channel to its canonical id, or null when it is not
     * on the allow-list.
     */
    private function normalizeChannel(mixed $channel): ?string
    {
        if (! is_string($channel)) {
            return null;
        }

        $key = strtolower(trim($channel));

        return in_array($key, self::PUBLICATION_CHANNELS, true) ? $key : null;
    }

    /**
     * @param mixed $list
     *
     * @return list<string>
     */
    private function normalizeStringList(mixed $list): array
    {
        if (! is_array($list)) {
            return [];
        }

        $out = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $out[] = strtolower(trim($item));
            }
        }

        return array_values(array_unique($out));
    }
}
