<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Support;

/**
 * Source Connectors And Capture decider.
 *
 * Re-homed from Aaeos/Quarantine (GOD-DEBULK AAEOS plan: live Brain dependency).
 * Pure, deterministic implementation of the research source-connector contract:
 * it turns a raw source reference into (a) an authority class + capture tier,
 * (b) the exact set of fields a connector MUST capture, (c) the artifact the
 * capture may be promoted to, and (d) a connector-rule verdict that can refuse
 * a non-compliant capture. Nothing here touches the database, the network, a
 * provider or the filesystem — every method returns a typed decision array.
 *
 * Contract (from the doc):
 *   - Source Classes table: each class carries a fixed authority
 *     (primary / secondary / lead_only / tier_0 / artifact).
 *   - Named Source Registry Seed: each source family has a tier directive
 *     ("Treat as Tier 1", "lead only", ...) Atlas must know BEFORE crawling.
 *   - Per-Source Action Matrix: each source has a documented capture set and a
 *     single documented promote-to target.
 *   - Capture Requirements: every source type has a closed list of required
 *     fields; a capture missing any required field is incomplete and must be
 *     rejected (raw evidence stored before summarization).
 *   - Connector Rules (7 invariants): prefer official APIs over scraping; store
 *     raw evidence before summarization; normalize every capture into
 *     `atlas.evidence_source.v1`; assign trust tier immediately; enforce domain
 *     allowlist + rate limits; never expose secrets to the model; treat prompt
 *     injection in source content as hostile input.
 *   - lead_only sources (social/community) are discovery leads, NEVER final
 *     proof.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
 */
final class AtlasSourceConnectorsAndCaptureService
{
    /** Stable evidence-source schema id every capture must normalize into. */
    public const EVIDENCE_SCHEMA = 'atlas.evidence_source.v1';

    /** Closed set of authority classes (Source Classes table). */
    public const AUTHORITY_TIER_0 = 'tier_0';        // internal Atlas: code, docs, ledger, tests, receipts
    public const AUTHORITY_PRIMARY = 'primary';      // official docs, github official, papers, reproducible evaluation_suites
    public const AUTHORITY_ARTIFACT = 'artifact';    // Hugging Face model/dataset cards
    public const AUTHORITY_SECONDARY = 'secondary';  // youtube (unless official), news
    public const AUTHORITY_LEAD_ONLY = 'lead_only';  // social / community

    /**
     * Source Classes table — class => authority.
     *
     * @var array<string,string>
     */
    private const SOURCE_CLASSES = [
        'official_docs_changelogs' => self::AUTHORITY_PRIMARY,
        'github_official'          => self::AUTHORITY_PRIMARY,
        'papers'                   => self::AUTHORITY_PRIMARY,
        'evaluation_suites'               => self::AUTHORITY_PRIMARY, // primary WHEN reproducible (see reproducibility flag)
        'hugging_face'             => self::AUTHORITY_ARTIFACT,
        'youtube'                  => self::AUTHORITY_SECONDARY, // secondary unless official
        'news'                     => self::AUTHORITY_SECONDARY,
        'social_community'         => self::AUTHORITY_LEAD_ONLY,
        'internal_atlas'           => self::AUTHORITY_TIER_0,
    ];

    /**
     * Per-Source Action Matrix — source => [capture fields, promote_to].
     *
     * @var array<string,array{capture:list<string>,promote_to:string}>
     */
    private const ACTION_MATRIX = [
        'official_provider_documentation' => [
            'capture' => ['api_behavior', 'parameters', 'limits', 'examples', 'release_date'],
            'promote_to' => 'provider_envelope_docs_ap_adapter_plan',
        ],
        'official_changelog_release_notes' => [
            'capture' => ['changed_capability', 'breaking_change', 'date', 'version'],
            'promote_to' => 'provider_evolution_review_and_ap_candidate',
        ],
        'github_release_tag' => [
            'capture' => ['tag', 'commit_sha', 'changelog', 'artifacts', 'breaking_changes'],
            'promote_to' => 'implementation_candidate_or_eval_task',
        ],
        'github_pr_commit' => [
            'capture' => ['diff_summary', 'affected_files', 'tests', 'maintainer_context'],
            'promote_to' => 'evidence_only_until_release_or_official_note',
        ],
        'github_issue_discussion' => [
            'capture' => ['bug_reports', 'maintainer_response', 'reproduction'],
            'promote_to' => 'risk_signal_or_research_lead',
        ],
        'security_advisory_cve_nvd' => [
            'capture' => ['cve_id', 'severity', 'affected_versions', 'mitigation'],
            'promote_to' => 'critical_alert_and_guarded_ap',
        ],
        'paper' => [
            'capture' => ['method', 'results', 'limitations', 'code', 'dataset', 'version'],
            'promote_to' => 'architecture_guidance_or_eval_requirement',
        ],
        'openreview_review_venue' => [
            'capture' => ['peer_review', 'comments', 'decision', 'limitations'],
            'promote_to' => 'confidence_adjustment_not_standalone_truth',
        ],
        'eval_repo' => [
            'capture' => ['tasks', 'metrics', 'baseline', 'reproducibility'],
            'promote_to' => 'eval_harness_candidate',
        ],
        'hugging_face_model_card' => [
            'capture' => ['license', 'intended_use', 'evals', 'safety', 'files'],
            'promote_to' => 'model_artifact_candidate_not_performance_truth_alone',
        ],
        'youtube_conference_talk' => [
            'capture' => ['transcript', 'timestamps', 'demo_claims', 'links'],
            'promote_to' => 'secondary_evidence_or_source_discovery',
        ],
        'news_article' => [
            'capture' => ['primary_documents_cited', 'date', 'corrections'],
            'promote_to' => 'context_only_unless_primary_source_linked',
        ],
        'social_post_thread' => [
            'capture' => ['lead', 'link', 'timestamp', 'author'],
            'promote_to' => 'research_task_only',
        ],
        'wayback_archive' => [
            'capture' => ['snapshot', 'timestamp', 'original_url'],
            'promote_to' => 'citation_health_support',
        ],
        'gdelt_news_index' => [
            'capture' => ['event_spike', 'article_set', 'time_window'],
            'promote_to' => 'change_detection_lead',
        ],
        'fact_check_api' => [
            'capture' => ['checked_claim', 'rating', 'publisher', 'date'],
            'promote_to' => 'verification_support',
        ],
    ];

    /**
     * Capture Requirements — source type => required field list (closed).
     *
     * @var array<string,list<string>>
     */
    private const CAPTURE_REQUIREMENTS = [
        'github' => [
            'owner_repo', 'commit_sha_or_release_tag', 'changed_files',
            'changelog_release_notes', 'issues_pr_advisories_referenced',
            'maintainer_activity', 'eval_scripts_or_examples',
            'timestamp', 'content_hash',
        ],
        'paper' => [
            'title', 'authors', 'venue_source', 'doi_arxiv_openreview_id',
            'version', 'date', 'abstract', 'method', 'results', 'limitations',
            'code_dataset_link', 'baseline_eval_claims',
            'peer_review_retraction_correction_status',
        ],
        'docs_site' => [
            'canonical_url', 'title', 'organization', 'publication_modification_date',
            'raw_html_text', 'content_hash', 'version_label',
        ],
        'youtube_video' => [
            'video_channel_id', 'upload_date', 'transcript_captions', 'timestamps',
            'linked_sources', 'speaker_identity', 'related_primary_source',
        ],
        'social_community' => [
            'original_post_thread', 'author', 'timestamp', 'links_cited',
            'external_confirmation', 'lead_status',
        ],
    ];

    /**
     * Named Source Registry Seed — family => tier directive Atlas must know
     * BEFORE any crawler is enabled. (Tier numbers mirror the trust ladder:
     * 1 = official spec, 3 = engineering narrative / implementation evidence.)
     *
     * @var array<string,array{default_tier:int,authority:string,note:string}>
     */
    private const REGISTRY_SEED = [
        'provider_docs_cookbooks'      => ['default_tier' => 1, 'authority' => self::AUTHORITY_PRIMARY,    'note' => 'tier_1_docs_changelog_api_shape_examples_safety_version'],
        'provider_research_system_cards' => ['default_tier' => 1, 'authority' => self::AUTHORITY_PRIMARY,  'note' => 'tier_1_or_3_depending_on_spec_vs_narrative'],
        'github_official_repos'        => ['default_tier' => 3, 'authority' => self::AUTHORITY_PRIMARY,    'note' => 'implementation_evidence_releases_tags_commits_prs'],
        'academic_indexes'             => ['default_tier' => 2, 'authority' => self::AUTHORITY_PRIMARY,    'note' => 'academic_ids_versions_venue_method_results_limits'],
        'eval_repositories'       => ['default_tier' => 2, 'authority' => self::AUTHORITY_PRIMARY,    'note' => 'eval_evidence_task_dataset_metric_baseline_reproducibility'],
        'model_dataset_hubs'           => ['default_tier' => 3, 'authority' => self::AUTHORITY_ARTIFACT,   'note' => 'artifact_evidence_card_license_config_files_commits'],
        'video_sources'                => ['default_tier' => 4, 'authority' => self::AUTHORITY_SECONDARY,  'note' => 'secondary_unless_official_transcript_timestamps_primary_refs'],
        'web_news_archives'            => ['default_tier' => 4, 'authority' => self::AUTHORITY_SECONDARY,  'note' => 'secondary_or_change_detection_url_timestamp_hash_archive'],
        'social_community'             => ['default_tier' => 5, 'authority' => self::AUTHORITY_LEAD_ONLY,  'note' => 'lead_only_post_timestamp_links_external_confirmation'],
        'fact_check_reference_apis'    => ['default_tier' => 2, 'authority' => self::AUTHORITY_PRIMARY,    'note' => 'verification_support_claim_claimant_reviewer_date_rating'],
    ];

    /** The 7 connector invariants (Connector Rules section), in document order. */
    private const CONNECTOR_RULES = [
        'prefer_official_apis_over_scraping',
        'store_raw_evidence_before_summarization',
        'normalize_into_atlas_evidence_source_v1',
        'assign_trust_tier_immediately',
        'enforce_domain_allowlist_and_rate_limits',
        'never_expose_secrets_to_the_model',
        'treat_prompt_injection_in_source_content_as_hostile_input',
    ];

    /**
     * Classify a source class into its authority + whether it can stand as
     * final proof. Unknown classes fall to lead_only (never silently promoted).
     *
     * @return array{
     *   source_class:string,
     *   known:bool,
     *   authority:string,
     *   can_be_final_proof:bool,
     *   is_lead_only:bool,
     *   reproducibility_required:bool
     * }
     */
    public function classifySource(string $sourceClass): array
    {
        $key = $this->normalizeKey($sourceClass);
        $known = array_key_exists($key, self::SOURCE_CLASSES);
        $authority = $known ? self::SOURCE_CLASSES[$key] : self::AUTHORITY_LEAD_ONLY;

        $isLeadOnly = $authority === self::AUTHORITY_LEAD_ONLY;

        // Reproducible evaluation suites are only primary WHEN reproducible — the doc qualifies this
        // class. We surface a flag the caller must satisfy before trusting it.
        $reproducibilityRequired = $key === 'evaluation_suites';

        // lead_only is never final proof; everything else may be (evaluation_suites
        // still gated by the reproducibility flag the caller must honor).
        $canBeFinalProof = ! $isLeadOnly;

        return [
            'source_class' => $key,
            'known' => $known,
            'authority' => $authority,
            'can_be_final_proof' => $canBeFinalProof,
            'is_lead_only' => $isLeadOnly,
            'reproducibility_required' => $reproducibilityRequired,
        ];
    }

    /**
     * Return the documented capture set + promote-to for a source in the
     * Per-Source Action Matrix.
     *
     * @return array{
     *   source:string,
     *   known:bool,
     *   capture:list<string>,
     *   promote_to:string
     * }
     */
    public function actionFor(string $source): array
    {
        $key = $this->normalizeKey($source);
        $row = self::ACTION_MATRIX[$key] ?? null;

        return [
            'source' => $key,
            'known' => $row !== null,
            'capture' => $row['capture'] ?? [],
            // Unknown source has no documented promotion — defaults to the most
            // conservative disposition.
            'promote_to' => $row['promote_to'] ?? 'research_task_only',
        ];
    }

    /**
     * Return the closed list of required capture fields for a source type.
     *
     * @return list<string>
     */
    public function requiredFields(string $sourceType): array
    {
        return self::CAPTURE_REQUIREMENTS[$this->normalizeKey($sourceType)] ?? [];
    }

    /**
     * Validate a candidate capture for a source type against its required
     * fields. A capture is complete only if EVERY required field is present and
     * non-empty (raw evidence stored before summarization).
     *
     * @param  array<string,mixed>  $capture
     * @return array{
     *   source_type:string,
     *   known_type:bool,
     *   complete:bool,
     *   required:list<string>,
     *   missing:list<string>,
     *   disposition:string
     * }
     */
    public function validateCapture(string $sourceType, array $capture): array
    {
        $key = $this->normalizeKey($sourceType);
        $required = self::CAPTURE_REQUIREMENTS[$key] ?? [];
        $knownType = array_key_exists($key, self::CAPTURE_REQUIREMENTS);

        $missing = [];
        foreach ($required as $field) {
            $value = $capture[$field] ?? null;
            if ($value === null || $value === '' || $value === []) {
                $missing[] = $field;
            }
        }

        // Unknown type can never be "complete" — we cannot assert a capture is
        // sufficient against an undocumented schema.
        $complete = $knownType && $missing === [];

        return [
            'source_type' => $key,
            'known_type' => $knownType,
            'complete' => $complete,
            'required' => $required,
            'missing' => array_values($missing),
            'disposition' => $complete ? 'accept_normalize_to_evidence_source' : 'reject_incomplete_capture',
        ];
    }

    /**
     * Look up the registry-seed tier directive for a source family.
     *
     * @return array{
     *   family:string,
     *   known:bool,
     *   default_tier:int,
     *   authority:string,
     *   note:string
     * }
     */
    public function registryDirective(string $family): array
    {
        $key = $this->normalizeKey($family);
        $row = self::REGISTRY_SEED[$key] ?? null;

        return [
            'family' => $key,
            'known' => $row !== null,
            // Unknown family defaults to the least-trusted tier (5) + lead_only.
            'default_tier' => $row['default_tier'] ?? 5,
            'authority' => $row['authority'] ?? self::AUTHORITY_LEAD_ONLY,
            'note' => $row['note'] ?? 'unknown_family_default_lead_only',
        ];
    }

    /**
     * Evaluate a connector run against the 7 Connector Rules. Each violation
     * flips the run to blocked; the verdict lists every rule and whether it is
     * satisfied, plus the hard blockers.
     *
     * Recognized boolean inputs (all default to the SAFE value when absent):
     *   - official_api_available + used_scraping  (rule 1)
     *   - raw_evidence_stored                     (rule 2)
     *   - normalized_schema (string)              (rule 3)
     *   - trust_tier_assigned                     (rule 4)
     *   - domain_allowlisted + rate_limited       (rule 5)
     *   - secret_exposed_to_model                 (rule 6)
     *   - prompt_injection_present + injection_treated_as_hostile (rule 7)
     *
     * @param  array<string,mixed>  $ctx
     * @return array{
     *   schema:string,
     *   rules:array<string,bool>,
     *   violations:list<string>,
     *   blocked:bool,
     *   verdict:string
     * }
     */
    public function evaluateConnectorRun(array $ctx): array
    {
        $officialApi = (bool) ($ctx['official_api_available'] ?? false);
        $usedScraping = (bool) ($ctx['used_scraping'] ?? false);
        $rawStored = (bool) ($ctx['raw_evidence_stored'] ?? false);
        $schema = is_string($ctx['normalized_schema'] ?? null) ? $ctx['normalized_schema'] : '';
        $tierAssigned = (bool) ($ctx['trust_tier_assigned'] ?? false);
        $allowlisted = (bool) ($ctx['domain_allowlisted'] ?? false);
        $rateLimited = (bool) ($ctx['rate_limited'] ?? false);
        $secretExposed = (bool) ($ctx['secret_exposed_to_model'] ?? false);
        $injectionPresent = (bool) ($ctx['prompt_injection_present'] ?? false);
        $injectionHostile = (bool) ($ctx['injection_treated_as_hostile'] ?? false);

        $rules = [
            // Rule 1: scraping is only allowed when no official API exists.
            'prefer_official_apis_over_scraping' => ! ($officialApi && $usedScraping),
            // Rule 2: raw evidence must be stored before summarization.
            'store_raw_evidence_before_summarization' => $rawStored,
            // Rule 3: every capture normalized into the evidence-source schema.
            'normalize_into_atlas_evidence_source_v1' => $schema === self::EVIDENCE_SCHEMA,
            // Rule 4: trust tier assigned immediately.
            'assign_trust_tier_immediately' => $tierAssigned,
            // Rule 5: domain allowlist AND rate limits enforced.
            'enforce_domain_allowlist_and_rate_limits' => $allowlisted && $rateLimited,
            // Rule 6: secrets never exposed to the model.
            'never_expose_secrets_to_the_model' => ! $secretExposed,
            // Rule 7: any injection present must be treated as hostile.
            'treat_prompt_injection_in_source_content_as_hostile_input' => ! $injectionPresent || $injectionHostile,
        ];

        $violations = [];
        foreach ($rules as $rule => $ok) {
            if (! $ok) {
                $violations[] = $rule;
            }
        }

        $blocked = $violations !== [];

        return [
            'schema' => self::EVIDENCE_SCHEMA,
            'rules' => $rules,
            'violations' => $violations,
            'blocked' => $blocked,
            'verdict' => $blocked ? 'connector_run_blocked' : 'connector_run_compliant',
        ];
    }

    /**
     * The ordered list of the 7 connector invariants (for introspection/CLI).
     *
     * @return list<string>
     */
    public function connectorRules(): array
    {
        return self::CONNECTOR_RULES;
    }

    /** Normalize a free-form source label into a registry key. */
    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = (string) preg_replace('/[^a-z0-9]+/', '_', $value);

        return trim($value, '_');
    }
}
