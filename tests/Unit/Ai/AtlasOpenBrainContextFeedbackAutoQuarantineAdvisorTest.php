<?php

declare(strict_types=1);

namespace Tests\Unit\Ai;

use App\Services\Ai\AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor;
use Tests\TestCase;

final class AtlasOpenBrainContextFeedbackAutoQuarantineAdvisorTest extends TestCase
{
    private function advisor(): AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor
    {
        return new AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor;
    }

    private function record(array $overrides = []): array
    {
        return array_merge([
            'context_pack_hash' => 'pack_abc123',
            'source_ref' => 'app/Services/Ai/Foo.php',
            'issue_code' => 'noisy',
            'severity' => 'low',
            'observed_effect' => 'context budget wasted on irrelevant ref',
            'suggested_filter' => null,
        ], $overrides);
    }

    public function test_consumes_bounded_feedback_records_with_all_named_fields(): void
    {
        $result = $this->advisor()->advise([$this->record()]);

        $this->assertNotEmpty($result['proposals']);
        $proposal = $result['proposals'][0];
        $this->assertArrayHasKey('source_ref', $proposal);
        $this->assertArrayHasKey('action', $proposal);
        $this->assertArrayHasKey('evidence_counts', $proposal);
    }

    public function test_repeated_stale_feedback_for_same_source_ref_recommends_demote(): void
    {
        $records = array_fill(0, 3, $this->record(['source_ref' => 'docs/stale-doc.md', 'issue_code' => 'stale', 'severity' => 'low']));

        $result = $this->advisor()->advise($records);

        $proposal = $result['proposals'][0];
        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_DEMOTE, $proposal['action']);
        $this->assertSame(3, $proposal['evidence_counts']['stale']);
    }

    public function test_single_stale_flag_is_no_action_insufficient_evidence(): void
    {
        $result = $this->advisor()->advise([$this->record(['issue_code' => 'stale'])]);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_NO_ACTION, $result['proposals'][0]['action']);
    }

    public function test_repeated_noisy_feedback_recommends_demote(): void
    {
        $records = array_fill(0, 2, $this->record(['source_ref' => 'app/Services/Noisy.php', 'issue_code' => 'noisy']));

        $result = $this->advisor()->advise($records);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_DEMOTE, $result['proposals'][0]['action']);
    }

    public function test_hostile_memory_regression_case_recommends_sanitization_or_quarantine(): void
    {
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'memory/decision-x.md', 'issue_code' => 'hostile_memory', 'severity' => 'high']),
        ]);

        $action = $result['proposals'][0]['action'];
        $this->assertContains($action, [
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_REQUIRE_SANITIZATION,
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE,
        ]);
    }

    public function test_repeated_hostile_memory_flags_escalate_to_quarantine(): void
    {
        $records = array_fill(0, 2, $this->record(['source_ref' => 'memory/decision-y.md', 'issue_code' => 'hostile_memory', 'severity' => 'high']));

        $result = $this->advisor()->advise($records);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE, $result['proposals'][0]['action']);
    }

    public function test_never_mutates_promotes_or_deletes_memory(): void
    {
        $result = $this->advisor()->advise([$this->record()]);

        $this->assertFalse($result['mutates_memory']);
        $this->assertFalse($result['auto_promotes']);
        $this->assertFalse($result['auto_deletes']);
    }

    public function test_feedback_grouped_by_distinct_source_ref(): void
    {
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'a.php']),
            $this->record(['source_ref' => 'b.php']),
        ]);

        $sourceRefs = array_column($result['proposals'], 'source_ref');
        $this->assertContains('a.php', $sourceRefs);
        $this->assertContains('b.php', $sourceRefs);
        $this->assertCount(2, $result['proposals']);
    }

    public function test_instruction_like_memory_high_severity_recommends_sanitization_or_quarantine(): void
    {
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'memory/injected.md', 'issue_code' => 'instruction_like', 'severity' => 'high']),
        ]);

        $action = $result['proposals'][0]['action'];
        $this->assertContains($action, [
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_REQUIRE_SANITIZATION,
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE,
        ]);
    }

    public function test_repeated_instruction_like_flags_escalate_to_quarantine(): void
    {
        $records = array_fill(0, 2, $this->record(['source_ref' => 'memory/injected2.md', 'issue_code' => 'instruction_like', 'severity' => 'high']));

        $result = $this->advisor()->advise($records);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE, $result['proposals'][0]['action']);
    }

    public function test_emotional_manipulation_high_severity_recommends_sanitization_or_quarantine(): void
    {
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'memory/guilt-trip.md', 'issue_code' => 'emotional_manipulation', 'severity' => 'high']),
        ]);

        $action = $result['proposals'][0]['action'];
        $this->assertContains($action, [
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_REQUIRE_SANITIZATION,
            AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE,
        ]);
    }

    public function test_repeated_emotional_manipulation_flags_escalate_to_quarantine(): void
    {
        $records = array_fill(0, 2, $this->record(['source_ref' => 'memory/guilt-trip2.md', 'issue_code' => 'emotional_manipulation', 'severity' => 'high']));

        $result = $this->advisor()->advise($records);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE, $result['proposals'][0]['action']);
    }

    public function test_low_severity_emotional_or_instruction_flags_do_not_force_quarantine(): void
    {
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'memory/mild.md', 'issue_code' => 'instruction_like', 'severity' => 'low']),
        ]);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_NO_ACTION, $result['proposals'][0]['action']);
    }

    public function test_mixed_high_risk_codes_aggregate_toward_quarantine(): void
    {
        // One hostile + one instruction-like, both high severity, same source_ref — must escalate.
        $result = $this->advisor()->advise([
            $this->record(['source_ref' => 'memory/mixed.md', 'issue_code' => 'hostile_memory', 'severity' => 'high']),
            $this->record(['source_ref' => 'memory/mixed.md', 'issue_code' => 'instruction_like', 'severity' => 'high']),
        ]);

        $this->assertSame(AtlasOpenBrainContextFeedbackAutoQuarantineAdvisor::ACTION_QUARANTINE, $result['proposals'][0]['action']);
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $advisor = $this->advisor();
        $records = [$this->record(['issue_code' => 'stale']), $this->record(['issue_code' => 'stale'])];

        $this->assertSame($advisor->advise($records), $advisor->advise($records));
    }
}
