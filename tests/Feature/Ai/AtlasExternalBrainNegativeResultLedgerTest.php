<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainNegativeResultLedger;
use Tests\TestCase;

final class AtlasExternalBrainNegativeResultLedgerTest extends TestCase
{
    private function fullEntry(array $overrides = []): array
    {
        return array_merge([
            'surface' => 'github_issues',
            'method' => 'keyword_scan',
            'evidence' => 'inspected 40 issues, none actionable',
            'inspected_count' => 40,
            'search_depth' => 2,
        ], $overrides);
    }

    public function test_vague_entries_missing_required_fields_are_rejected(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;

        $this->assertFalse($ledger->record($this->fullEntry(['surface' => '']))['accepted']);
        $this->assertFalse($ledger->record($this->fullEntry(['method' => '']))['accepted']);
        $this->assertFalse($ledger->record($this->fullEntry(['evidence' => '']))['accepted']);
        $this->assertFalse($ledger->record($this->fullEntry(['inspected_count' => 0]))['accepted']);
        $this->assertFalse($ledger->record($this->fullEntry(['search_depth' => 0]))['accepted']);
    }

    public function test_duplicate_surface_and_method_replaces_previous_entry(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;
        $ledger->record($this->fullEntry(['inspected_count' => 40]));
        $ledger->record($this->fullEntry(['inspected_count' => 100]));

        $list = $ledger->list();
        $this->assertSame(1, $list['count']);
        $this->assertSame(100, $list['entries'][0]['inspected_count']);
    }

    public function test_evaluate_returns_skip_surface_until_ttl_expires(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;
        $ledger->record($this->fullEntry(['recorded_at' => 1000, 'ttl_seconds' => 3600]));

        $stillFresh = $ledger->evaluate('github_issues', 'keyword_scan', 2000);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_SKIP_SURFACE, $stillFresh['decision']);

        $expired = $ledger->evaluate('github_issues', 'keyword_scan', 5000);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $expired['decision']);
    }

    public function test_evaluate_allows_retry_when_active_retry_condition_matches(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;
        $ledger->record($this->fullEntry([
            'recorded_at' => 1000,
            'ttl_seconds' => 3600,
            'retry_conditions' => ['new_release_published'],
        ]));

        $result = $ledger->evaluate('github_issues', 'keyword_scan', 1500, ['new_release_published']);
        $this->assertSame(AtlasExternalBrainNegativeResultLedger::DECISION_RETRY_ALLOWED, $result['decision']);
    }

    public function test_permanent_root_causes_produce_avoid_signals(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;

        foreach (['poison', 'contradictory_acceptance', 'capability_already_exists', 'forbidden_target'] as $rootCause) {
            $target = "target-$rootCause";
            $ledger->recordOutcome([
                'family' => 'wiring',
                'target' => $target,
                'root_cause' => $rootCause,
                'avoid_pattern' => 'never retarget without new evidence',
            ]);

            $result = $ledger->shouldAvoidTask('wiring', $target);
            $this->assertTrue($result['avoid']);
            $this->assertSame('permanent', $result['rule']['permanence']);
        }
    }

    public function test_temporary_root_causes_are_distinct_from_permanent(): void
    {
        $ledger = new AtlasExternalBrainNegativeResultLedger;
        $ledger->recordOutcome([
            'family' => 'wiring',
            'target' => 'target-temp',
            'root_cause' => 'missing_dependency',
            'avoid_pattern' => 'retry once dependency lands',
        ]);

        $result = $ledger->shouldAvoidTask('wiring', 'target-temp');
        $this->assertSame('temporary', $result['rule']['permanence']);
    }
}
