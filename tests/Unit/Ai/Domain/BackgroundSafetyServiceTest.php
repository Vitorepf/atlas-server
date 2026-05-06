<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\BackgroundSafetyService;
use Tests\TestCase;

class BackgroundSafetyServiceTest extends TestCase
{
    public function test_background_packet_is_review_only_and_requires_stop_conditions(): void
    {
        $packet = app(BackgroundSafetyService::class)->packet('background.schedule_review', [
            'job' => 'Atlas nightly self-improvement review',
            'scope' => 'review evidence, generate proposals, and never apply critical behavior automatically',
            'trigger' => 'cron',
            'schedule' => '02:00 daily',
            'permissions' => ['read evidence ledger', 'write proposal inbox'],
            'evidence_refs' => ['atlas:self-improvement:nightly'],
            'risk_class' => 'medium',
            'stop_conditions' => ['ledger unavailable', 'proposal risk high', 'operator pauses automation'],
        ]);

        $this->assertSame('atlas.background.packet.v1', $packet['schema_version']);
        $this->assertSame('background', $packet['domain']);
        $this->assertSame('background.schedule_review', $packet['flow']);
        $this->assertSame('schedule_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'background_contract.background_review_only'));
        $this->assertTrue(data_get($packet, 'background_contract.no_unbounded_background_work'));
        $this->assertTrue(data_get($packet, 'rules.does_not_start_jobs'));
        $this->assertTrue(data_get($packet, 'rules.does_not_change_schedule'));
        $this->assertContains('run_unbounded_loop', $packet['forbidden_actions']);
    }

    public function test_background_packet_marks_missing_stop_conditions(): void
    {
        $packet = app(BackgroundSafetyService::class)->packet('background.safe', [
            'job' => 'Monitor documentation drift',
            'scope' => 'review docs and open proposal only',
        ]);

        $this->assertContains('stop_conditions', data_get($packet, 'brief.missing_inputs'));
        $this->assertSame('missing', collect($packet['gates'])->firstWhere('id', 'stop_conditions')['status']);
    }
}
