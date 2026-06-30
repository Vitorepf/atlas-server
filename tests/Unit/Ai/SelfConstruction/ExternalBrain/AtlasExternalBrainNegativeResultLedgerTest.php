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
            'surface'         => 'github_issues',
            'method'          => 'keyword_scan',
            'evidence'        => 'Scanned 120 issues; 0 matched schema keywords.',
            'inspected_count' => 120,
            'search_depth'    => 2,
            'reason'          => 'No seedable content found.',
            'recorded_at'     => 1000,
            'ttl_seconds'     => 3600,
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
        $this->assertSame(120, $entry['inspected_count']);
        $this->assertSame(2, $entry['search_depth']);
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

    public function test_rejects_missing_inspected_count(): void
    {
        $result = $this->ledger()->record($this->validEntry(['inspected_count' => 0]));
        $this->assertFalse($result['accepted']);
        $this->assertSame('inspected_count_missing', $result['rejection_reason']);
    }

    public function test_rejects_missing_search_depth(): void
    {
        $result = $this->ledger()->record($this->validEntry(['search_depth' => 0]));
        $this->assertFalse($result['accepted']);
        $this->assertSame('search_depth_missing', $result['rejection_reason']);
    }

    public function test_rejects_negative_inspected_count(): void
    {
        $result = $this->ledger()->record($this->validEntry(['inspected_count' => -5]));
        $this->assertFalse($result['accepted']);
        $this->assertSame('inspected_count_missing', $result['rejection_reason']);
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

    // ── recordOutcome: negative-result learning ────────────────────────────────

    private function validOutcome(array $overrides = []): array
    {
        return array_merge([
            'family' => 'external_brain',
            'target' => 'app/Services/Foo/Bar.php',
            'root_cause' => 'contradictory_acceptance',
            'avoid_pattern' => 'do not re-propose wiring tasks against this target without spec clarification',
        ], $overrides);
    }

    public function test_record_outcome_accepts_valid_entry(): void
    {
        $l = $this->ledger();
        $result = $l->recordOutcome($this->validOutcome());

        $this->assertSame(AtlasExternalBrainNegativeResultLedger::SCHEMA, $result['schema']);
        $this->assertTrue($result['accepted']);
        $this->assertSame('external_brain', $result['entry']['family']);
        $this->assertSame('app/Services/Foo/Bar.php', $result['entry']['target']);
        $this->assertSame('contradictory_acceptance', $result['entry']['root_cause']);
        $this->assertArrayHasKey('avoid_pattern', $result['entry']);
        $this->assertArrayHasKey('retry_after_condition', $result['entry']);
    }

    public function test_record_outcome_rejects_missing_family(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['family' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('family_missing', $result['rejection_reason']);
    }

    public function test_record_outcome_rejects_missing_target(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['target' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('target_missing', $result['rejection_reason']);
    }

    public function test_record_outcome_rejects_missing_root_cause(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['root_cause' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('root_cause_missing', $result['rejection_reason']);
    }

    public function test_record_outcome_rejects_missing_avoid_pattern(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['avoid_pattern' => '']));
        $this->assertFalse($result['accepted']);
        $this->assertSame('avoid_pattern_missing', $result['rejection_reason']);
    }

    public function test_permanent_root_causes_classify_as_permanent(): void
    {
        foreach (['poison', 'contradictory_acceptance', 'capability_already_exists', 'forbidden_target'] as $cause) {
            $result = $this->ledger()->recordOutcome($this->validOutcome(['root_cause' => $cause, 'target' => "t-{$cause}"]));
            $this->assertSame('permanent', $result['entry']['permanence'], "{$cause} should be permanent");
        }
    }

    public function test_temporary_root_causes_classify_as_temporary(): void
    {
        foreach (['missing_dependency', 'stale_context'] as $cause) {
            $result = $this->ledger()->recordOutcome($this->validOutcome(['root_cause' => $cause, 'target' => "t-{$cause}"]));
            $this->assertSame('temporary', $result['entry']['permanence'], "{$cause} should be temporary");
        }
    }

    public function test_unknown_root_cause_defaults_to_temporary(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['root_cause' => 'unclear_spec']));
        $this->assertSame('temporary', $result['entry']['permanence']);
    }

    public function test_explicit_permanence_overrides_root_cause_default(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome(['root_cause' => 'stale_context', 'permanence' => 'permanent']));
        $this->assertSame('permanent', $result['entry']['permanence']);
    }

    public function test_retry_after_condition_is_stored(): void
    {
        $result = $this->ledger()->recordOutcome($this->validOutcome([
            'root_cause' => 'missing_dependency',
            'retry_after_condition' => 'dependency_published',
        ]));
        $this->assertSame('dependency_published', $result['entry']['retry_after_condition']);
    }

    public function test_should_avoid_task_true_for_recorded_pair(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome());

        $result = $l->shouldAvoidTask('external_brain', 'app/Services/Foo/Bar.php');
        $this->assertTrue($result['avoid']);
        $this->assertNotNull($result['rule']);
    }

    public function test_should_avoid_task_false_for_unrelated_target(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome());

        $result = $l->shouldAvoidTask('external_brain', 'app/Services/Unrelated/Baz.php');
        $this->assertFalse($result['avoid']);
        $this->assertNull($result['rule']);

        // Unrelated family/target combo is never blocked by another family's avoid rule.
        $other = $l->shouldAvoidTask('other_family', 'app/Services/Foo/Bar.php');
        $this->assertFalse($other['avoid']);
    }

    public function test_summarize_avoid_rules_returns_all_recorded_rules(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome(['target' => 'a.php']));
        $l->recordOutcome($this->validOutcome(['target' => 'b.php']));

        $result = $l->summarizeAvoidRules();
        $this->assertSame(2, $result['count']);
    }

    public function test_summarize_avoid_rules_filters_by_family(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome(['family' => 'external_brain', 'target' => 'a.php']));
        $l->recordOutcome($this->validOutcome(['family' => 'self_construction', 'target' => 'b.php']));

        $result = $l->summarizeAvoidRules(['family' => 'external_brain']);
        $this->assertSame(1, $result['count']);
        $this->assertSame('external_brain', $result['rules'][0]['family']);
    }

    public function test_summarize_avoid_rules_filters_by_permanence(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome(['root_cause' => 'poison', 'target' => 'a.php']));
        $l->recordOutcome($this->validOutcome(['root_cause' => 'stale_context', 'target' => 'b.php']));

        $permanent = $l->summarizeAvoidRules(['permanence' => 'permanent']);
        $this->assertSame(1, $permanent['count']);
        $this->assertSame('poison', $permanent['rules'][0]['root_cause']);

        $temporary = $l->summarizeAvoidRules(['permanence' => 'temporary']);
        $this->assertSame(1, $temporary['count']);
    }

    public function test_second_record_outcome_replaces_first_for_same_family_target(): void
    {
        $l = $this->ledger();
        $l->recordOutcome($this->validOutcome(['root_cause' => 'stale_context']));
        $l->recordOutcome($this->validOutcome(['root_cause' => 'poison']));

        $result = $l->summarizeAvoidRules();
        $this->assertSame(1, $result['count']);
        $this->assertSame('poison', $result['rules'][0]['root_cause']);
    }
}
