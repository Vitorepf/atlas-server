<?php

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionAntiFraudMatrixService;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionCompletionAntiFraudMatrixServiceTest extends TestCase
{
    private function passingEvidence(): array
    {
        return [
            'signed_by' => 'vitor',
            'kind' => 'real_provider_packet_claim_to_completion',
            'provider_call_observed' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_response_hash' => str_repeat('a', 64),
            'runtime_autopromotion' => false,
            'completion_autopromoted' => false,
            'token_spend_observed' => true,
            'cost_event_hash' => str_repeat('b', 64),
            'dispatch_allowed' => true,
            'signed_dispatch_policy_hash' => str_repeat('c', 64),
            'process_started_by_atlas' => false,
            'hash_mutation_detected' => false,
            'before_snapshot_id' => 'snap-1',
            'operator_reason' => 'verified manually',
            'replay_mismatch' => false,
            'final_completion_allowed' => true,
        ];
    }

    public function test_passing_evidence_has_empty_claim_rejection_reason_map(): void
    {
        $result = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($this->passingEvidence());

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['completion_claim_allowed']);
        $this->assertArrayHasKey('claim_rejection_reason_map', $result);
        $this->assertSame([], $result['claim_rejection_reason_map']);
    }

    public function test_failed_evidence_has_claim_rejection_reason_map_entries_with_category_and_action_hint(): void
    {
        $evidence = array_merge($this->passingEvidence(), [
            'signed_by' => '',
            'replay_mismatch' => true,
        ]);

        $result = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($evidence);

        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['completion_claim_allowed']);

        $map = $result['claim_rejection_reason_map'];
        $this->assertArrayHasKey('fake_human_signature', $map);
        $this->assertArrayHasKey('replay_mismatch', $map);

        foreach ($map as $entry) {
            $this->assertArrayHasKey('category', $entry);
            $this->assertArrayHasKey('action_hint', $entry);
            $this->assertNotEmpty($entry['category']);
            $this->assertNotEmpty($entry['action_hint']);
        }

        $this->assertSame('hard_fraud', $map['fake_human_signature']['category']);
        $this->assertSame('replay_mismatch', $map['replay_mismatch']['category']);
    }

    public function test_passing_evidence_with_final_completion_disallowed_is_still_not_claimable_but_no_reasons(): void
    {
        $evidence = array_merge($this->passingEvidence(), ['final_completion_allowed' => false]);

        $result = (new AtlasSelfConstructionCompletionAntiFraudMatrixService)->evaluate($evidence);

        $this->assertSame('passed', $result['status']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertSame([], $result['claim_rejection_reason_map']);
    }
}
