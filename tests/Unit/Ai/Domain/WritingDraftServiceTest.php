<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\WritingDraftService;
use Tests\TestCase;

class WritingDraftServiceTest extends TestCase
{
    public function test_writing_packet_requires_brief_voice_and_human_review(): void
    {
        $packet = app(WritingDraftService::class)->packet('writing.publish_review', [
            'goal' => 'Publicar um manifesto curto sobre a arquitetura do Atlas.',
            'audience' => 'IA e humano implementando Atlas',
            'voice' => 'preciso, direto e enterprise',
            'source_material' => ['Architecture index', 'Flow visual map'],
        ]);

        $this->assertSame('atlas.writing.packet.v1', $packet['schema_version']);
        $this->assertSame('writing', $packet['domain']);
        $this->assertSame('writing.publish_review', $packet['flow']);
        $this->assertSame('publication_review', $packet['mode']);
        $this->assertSame([], data_get($packet, 'brief.missing_inputs'));
        $this->assertTrue(data_get($packet, 'output_contract.human_approval_required'));
        $this->assertFalse(data_get($packet, 'rules.external_publish_allowed'));
        $this->assertContains('auto_publish', $packet['forbidden_actions']);
        $this->assertContains('voice_alignment', collect($packet['gates'])->pluck('id')->all());
    }
}
