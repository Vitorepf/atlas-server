<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\HealthReviewService;
use Tests\TestCase;

class HealthReviewServiceTest extends TestCase
{
    public function test_health_packet_is_non_clinical_review_only(): void
    {
        $packet = app(HealthReviewService::class)->packet('health.routine_review', [
            'topic' => 'sleep routine',
            'goal' => 'improve consistency without medical claims',
            'signals' => ['late caffeine', 'irregular bedtime'],
            'constraints' => ['no medication advice'],
            'evidence_refs' => ['personal routine notes'],
        ]);

        $this->assertSame('atlas.health.packet.v1', $packet['schema_version']);
        $this->assertSame('health', $packet['domain']);
        $this->assertSame('health.routine_review', $packet['flow']);
        $this->assertSame('routine_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'health_contract.non_clinical_review_only'));
        $this->assertTrue(data_get($packet, 'health_contract.diagnosis_forbidden'));
        $this->assertTrue(data_get($packet, 'rules.does_not_prescribe'));
        $this->assertContains('medical_diagnosis', $packet['forbidden_actions']);
    }

    public function test_health_packet_flags_red_flags_for_review(): void
    {
        $packet = app(HealthReviewService::class)->packet('health.safety_review', [
            'topic' => 'shortness of breath after workout',
            'goal' => 'understand safety boundary',
            'signals' => ['shortness of breath'],
        ]);

        $this->assertContains('shortness of breath', data_get($packet, 'brief.risk_flags'));
        $this->assertSame('review_required', collect($packet['gates'])->firstWhere('id', 'red_flag_escalation')['status']);
    }
}
