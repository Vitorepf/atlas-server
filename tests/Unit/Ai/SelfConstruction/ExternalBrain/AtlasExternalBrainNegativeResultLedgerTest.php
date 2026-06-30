<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainNegativeResultLedger;
use Tests\TestCase;

final class AtlasExternalBrainNegativeResultLedgerTest extends TestCase
{
    private function ledger(): AtlasExternalBrainNegativeResultLedger
    {
        return new AtlasExternalBrainNegativeResultLedger();
    }

    private function validEntry(array $overrides = []): array
    {
        return array_merge([
            'surface'     => 'github_issues',
            'method'      => 'keyword_scan',
            'evidence'    => 'Scanned 120 issues; 0 matched schema keywords.',
            'reason'      => 'No seedable content found.',
            'recorded_at' => 1000,
            'ttl_seconds' => 3600,
        ], $overrides);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_on_record(): void
    {
        $result = $this->ledger()->record($this->validEntry());
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::SCHEMA, $result['schema']);
    }

    public function test_schema_on_evaluate(): void
    {
        $result = $this->ledger()->evaluate('github_issues', 'keyword_scan');
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::SCHEMA, $result['schema']);
    }

    public function test_schema_on_list(): void
    {
        $result = $this->ledger()->list();
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::SCHEMA, $result['schema']);
    }

    // ── record — acceptance ───────────────────────────────────────────────────

    public function test_record_accepts_valid_entry(): void
    {
        $result = $this->ledger()->record($this->validEntry());
        $this->assertTrue($result['accepted']);
        $this->assertArrayHasKey('entry', $result);
    }

    public function test_record_stores_all_fields(): void
    {
        $result = $this->ledger()->record($this->validEntry([
            'retry_conditions' => ['new_issue_published'],
        ]));

        $entry = $result['entry'];
        $this->assertSame('github_issues', $entry['surface']);
        $this->assertSame('keyword_scan', $entry['method']);
        $this->assertSame('Scanned 120 issues; 0 matched schema keywords.', $entry['evidence']);
        $this->assertSame(['new_issue_published'], $entry['retry_conditions']);
        $this->assertSame(1000 + 3600, $entry['expires_at']);
    }

    // ── record — rejection (vague entries) ───────────────────────────────────

    public function test_rejects_missing_surface(): void
    {
        $result = $this->ledger()->record($this->validEntry(['surface' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('surface_missing', $result['rejection_reason']);
    }

    public function test_rejects_missing_method(): void
    {
        $result = $this->ledger()->record($this->validEntry(['method' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('method_missing', $result['rejection_reason']);
    }

    public function test_rejects_missing_evidence(): void
    {
        $result = $this->ledger()->record($this->validEntry(['evidence' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('evidence_missing', $result['rejection_reason']);
    }

    public function test_rejected_entry_is_not_stored(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['evidence' => '']));
        $this->assertSame(0, $l->list()['count']);
    }

    // ── deduplication ─────────────────────────────────────────────────────────

    public function test_is_duplicate_false_before_record(): void
    {
        $this->assertFalse($this->ledger()->isDuplicate('github_issues', 'keyword_scan'));
    }

    public function test_is_duplicate_true_after_record(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry());
        $this->assertTrue($l->isDuplicate('github_issues', 'keyword_scan'));
    }

    public function test_second_record_replaces_first(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['reason' => 'first']));
        $l->record($this->validEntry(['reason' => 'second']));

        $entries = $l->list()['entries'];
        $this->assertCount(1, $entries);
        $this->assertSame('second', $entries[0]['reason']);
    }

    public function test_different_surface_method_pairs_are_independent(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['surface' => 'github_issues', 'method' => 'keyword_scan']));
        $l->record($this->validEntry(['surface' => 'arxiv', 'method' => 'semantic_search']));

        $this->assertSame(2, $l->list()['count']);
    }

    // ── evaluate — freshness ──────────────────────────────────────────────────

    public function test_evaluate_retry_allowed_when_not_in_ledger(): void
    {
        $result = $this->ledger()->evaluate('github_issues', 'keyword_scan', 5000);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $result['decision']);
        $this->assertSame('not_in_ledger', $result['reason']);
    }

    public function test_evaluate_skip_surface_when_still_fresh(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['recorded_at' => 1000, 'ttl_seconds' => 3600]));
        // expires_at = 4600; now = 3000 < 4600
        $result = $l->evaluate('github_issues', 'keyword_scan', 3000);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_SKIP_SURFACE, $result['decision']);
    }

    public function test_evaluate_retry_allowed_when_freshness_expired(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['recorded_at' => 1000, 'ttl_seconds' => 3600]));
        // expires_at = 4600; now = 5000 >= 4600
        $result = $l->evaluate('github_issues', 'keyword_scan', 5000);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $result['decision']);
        $this->assertSame('freshness_expired', $result['reason']);
    }

    public function test_evaluate_retry_allowed_at_exact_expiry_boundary(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['recorded_at' => 1000, 'ttl_seconds' => 3600]));
        $result = $l->evaluate('github_issues', 'keyword_scan', 4600);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $result['decision']);
    }

    // ── evaluate — retry conditions ───────────────────────────────────────────

    public function test_evaluate_retry_allowed_when_condition_met_even_if_fresh(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry([
            'recorded_at'      => 1000,
            'ttl_seconds'      => 86400,
            'retry_conditions' => ['new_issue_published'],
        ]));
        // still fresh (expires_at = 87400, now = 5000) but condition is active
        $result = $l->evaluate('github_issues', 'keyword_scan', 5000, ['new_issue_published']);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $result['decision']);
        $this->assertStringContainsString('retry_condition_met', $result['reason']);
    }

    public function test_evaluate_skip_when_conditions_not_active(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry([
            'recorded_at'      => 1000,
            'ttl_seconds'      => 86400,
            'retry_conditions' => ['new_issue_published'],
        ]));
        $result = $l->evaluate('github_issues', 'keyword_scan', 5000, ['other_condition']);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_SKIP_SURFACE, $result['decision']);
    }

    // ── list ──────────────────────────────────────────────────────────────────

    public function test_list_empty_initially(): void
    {
        $result = $this->ledger()->list();
        $this->assertSame(0, $result['count']);
        $this->assertSame([], $result['entries']);
    }

    public function test_list_returns_all_entries(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['surface' => 'github_issues']));
        $l->record($this->validEntry(['surface' => 'arxiv', 'method' => 'full_text_search']));
        $this->assertSame(2, $l->list()['count']);
    }

    public function test_list_filters_by_surface(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['surface' => 'github_issues']));
        $l->record($this->validEntry(['surface' => 'arxiv', 'method' => 'full_text_search']));
        $result = $l->list(['surface' => 'arxiv']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('arxiv', $result['entries'][0]['surface']);
    }

    // ── purgeExpired ──────────────────────────────────────────────────────────

    public function test_purge_expired_removes_stale_entries(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['recorded_at' => 1000, 'ttl_seconds' => 100]));  // expires 1100
        $l->record($this->validEntry(['surface' => 'arxiv', 'method' => 'sem', 'recorded_at' => 1000, 'ttl_seconds' => 9000]));  // expires 10000

        $purge = $l->purgeExpired(5000);
        $this->assertSame(1, $purge['purged']);
        $this->assertSame(1, $purge['remaining']);
    }

    public function test_purge_expired_keeps_fresh_entries(): void
    {
        $l = $this->ledger();
        $l->record($this->validEntry(['recorded_at' => 1000, 'ttl_seconds' => 9000]));
        $l->purgeExpired(5000);
        $this->assertSame(1, $l->list()['count']);
    }
}
