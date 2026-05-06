<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\StrategicDecisionReviewService;
use Tests\TestCase;

class StrategicDecisionReviewServiceTest extends TestCase
{
    public function test_review_packet_is_plan_only_and_preserves_operator_agency(): void
    {
        $packet = app(StrategicDecisionReviewService::class)->packet([
            'title' => 'Escolher direcao do Atlas',
            'decision' => 'Priorizar arquitetura-mae antes de features novas',
            'options' => ['arquitetura-mae', 'features novas'],
            'values' => ['qualidade', 'clareza'],
            'impact' => 'high',
            'horizon_days' => 180,
        ]);

        $this->assertSame(StrategicDecisionReviewService::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame('plan_only', $packet['mode']);
        $this->assertSame('strategic_decision.review', $packet['flow']);
        $this->assertTrue(data_get($packet, 'cooldown.required'));
        $this->assertTrue(data_get($packet, 'rules.preserve_operator_agency'));
        $this->assertContains('auto_life_decision', $packet['forbidden_actions']);
        $this->assertSame('passed', collect($packet['gates'])->firstWhere('id', 'operator_agency')['status']);
    }
}
