<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainEnqueueDecisionRecord;
use Tests\TestCase;

final class AtlasExternalBrainEnqueueDecisionRecordTest extends TestCase
{
    private const REQUIRED_ENQUEUE_EVIDENCE = [
        'target_uniqueness',
        'collision_check',
        'malformed_sweep_clean',
        'allowed_files_exist',
        'runnable_acceptance_present',
    ];

    private function enqueueInput(array $overrides = []): array
    {
        return array_merge([
            'decision' => 'enqueue',
            'batch_id' => 'batch-1',
            'validation_evidence' => self::REQUIRED_ENQUEUE_EVIDENCE,
            'admission_reason' => 'all evidence present',
        ], $overrides);
    }

    public function test_enqueue_is_accepted_only_when_all_required_evidence_present(): void
    {
        $result = (new AtlasExternalBrainEnqueueDecisionRecord)->record($this->enqueueInput());

        $this->assertTrue($result['accepted']);
        $this->assertSame('enqueue', $result['decision']);
    }

    public function test_enqueue_is_rejected_when_any_required_evidence_missing(): void
    {
        foreach (self::REQUIRED_ENQUEUE_EVIDENCE as $requiredKey) {
            $evidence = array_values(array_diff(self::REQUIRED_ENQUEUE_EVIDENCE, [$requiredKey]));
            $result = (new AtlasExternalBrainEnqueueDecisionRecord)->record($this->enqueueInput([
                'validation_evidence' => $evidence,
            ]));

            $this->assertFalse($result['accepted'], "expected rejection when missing: $requiredKey");
        }
    }

    public function test_forbidden_evidence_rejects_enqueue(): void
    {
        $forbiddenKeys = [
            'malformed_sweep',
            'collision_detected',
            'duplicate_target',
            'missing_allowed_files',
            'no_runnable_acceptance',
        ];

        foreach ($forbiddenKeys as $forbidden) {
            $result = (new AtlasExternalBrainEnqueueDecisionRecord)->record($this->enqueueInput([
                'validation_evidence' => array_merge(self::REQUIRED_ENQUEUE_EVIDENCE, [$forbidden]),
            ]));

            $this->assertFalse($result['accepted'], "expected rejection for forbidden evidence: $forbidden");
            $this->assertStringContainsString($forbidden, $result['rejection_reason']);
        }
    }

    public function test_defer_consolidate_reject_require_decline_reason(): void
    {
        foreach (['defer', 'consolidate', 'reject'] as $decision) {
            $rejected = (new AtlasExternalBrainEnqueueDecisionRecord)->record([
                'decision' => $decision,
                'batch_id' => 'batch-1',
            ]);
            $this->assertFalse($rejected['accepted'], "expected rejection for $decision without decline_reason");

            $accepted = (new AtlasExternalBrainEnqueueDecisionRecord)->record([
                'decision' => $decision,
                'batch_id' => 'batch-1',
                'decline_reason' => 'conservative pending more evidence',
            ]);
            $this->assertTrue($accepted['accepted']);
            $this->assertSame('conservative pending more evidence', $accepted['record']['decline_reason']);
        }
    }

    public function test_accepted_record_includes_all_required_audit_fields(): void
    {
        $result = (new AtlasExternalBrainEnqueueDecisionRecord)->record($this->enqueueInput([
            'queue_depth' => 5,
            'queue_pressure' => 'medium',
            'leverage_rationale' => 'unlocks downstream capability',
            'expected_downstream_value' => 'high',
            'risk_level' => 'low',
            'decision_reason' => 'coverage complete',
        ]));

        $record = $result['record'];
        $this->assertArrayHasKey('queue_snapshot', $record);
        $this->assertArrayHasKey('leverage_rationale', $record);
        $this->assertArrayHasKey('expected_downstream_value', $record);
        $this->assertArrayHasKey('risk', $record);
        $this->assertArrayHasKey('validation_evidence', $record);
        $this->assertArrayHasKey('decision_reason', $record);
        $this->assertArrayHasKey('batch_id', $record);
        $this->assertSame('batch-1', $record['batch_id']);
    }
}
