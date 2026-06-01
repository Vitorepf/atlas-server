<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSourceConnectorsAndCaptureService;
use Tests\TestCase;

/**
 * Pins the documented Source Connectors And Capture rules.
 *
 * @see docs/engineering-knowledge-base/research-self-improvement/source-connectors-and-capture.md
 */
class AtlasSourceConnectorsAndCaptureTest extends TestCase
{
    private function service(): AtlasSourceConnectorsAndCaptureService
    {
        return new AtlasSourceConnectorsAndCaptureService();
    }

    /**
     * Source Classes table: social/community is "Lead only" and can never be
     * final proof; an unknown class also falls to lead_only (never silently
     * promoted to primary).
     */
    public function test_social_is_lead_only_and_unknown_falls_to_lead_only(): void
    {
        $svc = $this->service();

        $social = $svc->classifySource('social_community');
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_LEAD_ONLY, $social['authority']);
        $this->assertTrue($social['is_lead_only']);
        $this->assertFalse($social['can_be_final_proof']);

        $unknown = $svc->classifySource('some_random_blog_aggregator');
        $this->assertFalse($unknown['known']);
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_LEAD_ONLY, $unknown['authority']);
        $this->assertFalse($unknown['can_be_final_proof']);

        // Official docs are primary and CAN be final proof.
        $docs = $svc->classifySource('official_docs_changelogs');
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_PRIMARY, $docs['authority']);
        $this->assertTrue($docs['can_be_final_proof']);
    }

    /**
     * Reproducible evaluation suites are "Primary when reproducible" — the service must surface the
     * reproducibility requirement flag for that class and not for others.
     */
    public function test_eval_suites_carry_reproducibility_requirement(): void
    {
        $svc = $this->service();

        $evalSuite = $svc->classifySource('evaluation_suites');
        $this->assertTrue($evalSuite['reproducibility_required']);

        $paper = $svc->classifySource('papers');
        $this->assertFalse($paper['reproducibility_required']);
    }

    /**
     * Per-Source Action Matrix: a GitHub release/tag captures the documented
     * set and promotes to an implementation/eval candidate; a security
     * advisory promotes to a critical alert + guarded AP.
     */
    public function test_action_matrix_promotes_to_documented_targets(): void
    {
        $svc = $this->service();

        $release = $svc->actionFor('github_release_tag');
        $this->assertTrue($release['known']);
        $this->assertContains('commit_sha', $release['capture']);
        $this->assertContains('breaking_changes', $release['capture']);
        $this->assertSame('implementation_candidate_or_eval_task', $release['promote_to']);

        $cve = $svc->actionFor('security_advisory_cve_nvd');
        $this->assertContains('cve_id', $cve['capture']);
        $this->assertSame('critical_alert_and_guarded_ap', $cve['promote_to']);

        // Unknown source defaults to the most conservative disposition.
        $unknown = $svc->actionFor('mystery_source');
        $this->assertFalse($unknown['known']);
        $this->assertSame('research_task_only', $unknown['promote_to']);
    }

    /**
     * Capture Requirements: a GitHub capture missing the content hash and
     * timestamp is incomplete and must be rejected; a complete capture is
     * accepted and normalized to the evidence-source schema.
     */
    public function test_incomplete_github_capture_is_rejected(): void
    {
        $svc = $this->service();

        // All required github fields EXCEPT content_hash + timestamp.
        $partial = [
            'owner_repo' => 'acme/widget',
            'commit_sha_or_release_tag' => 'v1.2.3',
            'changed_files' => ['a.php'],
            'changelog_release_notes' => 'notes',
            'issues_pr_advisories_referenced' => ['#1'],
            'maintainer_activity' => 'active',
            'eval_scripts_or_examples' => ['eval.sh'],
        ];
        $resPartial = $svc->validateCapture('github', $partial);
        $this->assertFalse($resPartial['complete']);
        $this->assertContains('content_hash', $resPartial['missing']);
        $this->assertContains('timestamp', $resPartial['missing']);
        $this->assertSame('reject_incomplete_capture', $resPartial['disposition']);

        // Now fill the two missing fields -> complete + accepted.
        $full = $partial + ['timestamp' => '2026-06-01T00:00:00Z', 'content_hash' => 'sha256:abc'];
        $resFull = $svc->validateCapture('github', $full);
        $this->assertTrue($resFull['complete']);
        $this->assertSame([], $resFull['missing']);
        $this->assertSame('accept_normalize_to_evidence_source', $resFull['disposition']);
    }

    /**
     * Connector Rules: scraping while an official API exists is a violation;
     * an untreated prompt injection is a violation; any violation BLOCKS the
     * run. A fully compliant run passes and is normalized to the schema.
     */
    public function test_connector_rules_block_scraping_and_untreated_injection(): void
    {
        $svc = $this->service();

        $bad = $svc->evaluateConnectorRun([
            'official_api_available' => true,
            'used_scraping' => true,            // violation: API existed
            'raw_evidence_stored' => true,
            'normalized_schema' => AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA,
            'trust_tier_assigned' => true,
            'domain_allowlisted' => true,
            'rate_limited' => true,
            'secret_exposed_to_model' => false,
            'prompt_injection_present' => true,
            'injection_treated_as_hostile' => false, // violation: untreated
        ]);
        $this->assertTrue($bad['blocked']);
        $this->assertContains('prefer_official_apis_over_scraping', $bad['violations']);
        $this->assertContains('treat_prompt_injection_in_source_content_as_hostile_input', $bad['violations']);
        $this->assertSame('connector_run_blocked', $bad['verdict']);

        $good = $svc->evaluateConnectorRun([
            'official_api_available' => false,  // scraping ok: no API
            'used_scraping' => true,
            'raw_evidence_stored' => true,
            'normalized_schema' => AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA,
            'trust_tier_assigned' => true,
            'domain_allowlisted' => true,
            'rate_limited' => true,
            'secret_exposed_to_model' => false,
            'prompt_injection_present' => true,
            'injection_treated_as_hostile' => true,
        ]);
        $this->assertFalse($good['blocked']);
        $this->assertSame([], $good['violations']);
        $this->assertSame('connector_run_compliant', $good['verdict']);
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA, $good['schema']);
    }

    /**
     * "Never expose secrets to the model" is a hard blocker on its own, even
     * when every other rule is satisfied.
     */
    public function test_secret_exposure_alone_blocks_the_run(): void
    {
        $svc = $this->service();

        $res = $svc->evaluateConnectorRun([
            'official_api_available' => false,
            'used_scraping' => false,
            'raw_evidence_stored' => true,
            'normalized_schema' => AtlasSourceConnectorsAndCaptureService::EVIDENCE_SCHEMA,
            'trust_tier_assigned' => true,
            'domain_allowlisted' => true,
            'rate_limited' => true,
            'secret_exposed_to_model' => true, // the only violation
            'prompt_injection_present' => false,
        ]);

        $this->assertTrue($res['blocked']);
        $this->assertSame(['never_expose_secrets_to_the_model'], $res['violations']);
    }

    /**
     * Named Source Registry Seed: provider docs default to tier 1 (primary),
     * social/community to tier 5 (lead only), and an unknown family falls to
     * the least-trusted tier 5 / lead_only.
     */
    public function test_registry_seed_tier_directives(): void
    {
        $svc = $this->service();

        $providerDocs = $svc->registryDirective('provider_docs_cookbooks');
        $this->assertSame(1, $providerDocs['default_tier']);
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_PRIMARY, $providerDocs['authority']);

        $social = $svc->registryDirective('social_community');
        $this->assertSame(5, $social['default_tier']);
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_LEAD_ONLY, $social['authority']);

        $unknown = $svc->registryDirective('totally_unlisted_family');
        $this->assertFalse($unknown['known']);
        $this->assertSame(5, $unknown['default_tier']);
        $this->assertSame(AtlasSourceConnectorsAndCaptureService::AUTHORITY_LEAD_ONLY, $unknown['authority']);
    }
}
