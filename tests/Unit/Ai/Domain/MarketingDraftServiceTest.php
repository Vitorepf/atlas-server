<?php

namespace Tests\Unit\Ai\Domain;

use App\Services\Ai\Domain\MarketingDraftService;
use Tests\TestCase;

class MarketingDraftServiceTest extends TestCase
{
    public function test_marketing_packet_is_draft_and_review_with_quality_gates(): void
    {
        $packet = app(MarketingDraftService::class)->packet('marketing.campaign', [
            'business_context' => 'blackink',
            'goal' => 'Criar campanha para oferta principal',
            'offer' => 'Produto X com garantia',
            'audience' => 'compradores frios interessados em performance',
            'brand_voice' => 'direto, premium e baseado em prova',
            'claims' => ['reduz tempo de setup com evidência interna'],
            'channels' => ['landing_page', 'email'],
        ]);

        $this->assertSame(MarketingDraftService::SCHEMA_VERSION, $packet['schema_version']);
        $this->assertSame('draft_and_review', $packet['mode']);
        $this->assertSame('marketing.campaign', $packet['flow']);
        $this->assertSame('blackink', $packet['business_context']);
        $this->assertTrue(data_get($packet, 'rules.draft_until_operator_approval'));
        $this->assertFalse(data_get($packet, 'rules.external_publish_allowed'));
        $this->assertContains('auto_ad_spend', $packet['forbidden_actions']);
        $this->assertSame('passed', collect($packet['gates'])->firstWhere('id', 'claim_substantiation')['status']);
    }
}
