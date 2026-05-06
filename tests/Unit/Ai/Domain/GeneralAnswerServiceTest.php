<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\GeneralAnswerService;
use Tests\TestCase;

class GeneralAnswerServiceTest extends TestCase
{
    public function test_general_packet_is_answer_or_triage_only(): void
    {
        $packet = app(GeneralAnswerService::class)->packet('general.answer', [
            'question' => 'Qual documento explica o fluxo visual do Atlas?',
            'context' => ['architecture docs'],
            'desired_output' => 'short answer',
        ]);

        $this->assertSame('atlas.general.packet.v1', $packet['schema_version']);
        $this->assertSame('general', $packet['domain']);
        $this->assertSame('general.answer', $packet['flow']);
        $this->assertSame('general_answer', $packet['mode']);
        $this->assertSame('general', data_get($packet, 'brief.suspected_domain'));
        $this->assertTrue(data_get($packet, 'general_contract.answer_or_triage_only'));
        $this->assertTrue(data_get($packet, 'general_contract.must_handoff_specialized_requests'));
        $this->assertTrue(data_get($packet, 'rules.does_not_call_tools'));
        $this->assertContains('provider_override', $packet['forbidden_actions']);
    }

    public function test_general_packet_detects_specialized_handoff(): void
    {
        $packet = app(GeneralAnswerService::class)->packet('general.answer', [
            'question' => 'Preciso corrigir um bug no runtime de programming.',
        ]);

        $this->assertSame('programming', data_get($packet, 'brief.suspected_domain'));
        $this->assertSame('passed', collect($packet['gates'])->firstWhere('id', 'domain_handoff_review')['status']);
    }
}
