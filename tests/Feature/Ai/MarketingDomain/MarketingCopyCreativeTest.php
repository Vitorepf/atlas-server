<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\CopyBriefService;
use App\Services\Ai\MarketingDomain\CreativeBriefService;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingCopyCreativeTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_copy_brief_requires_call_to_action(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(CopyBriefService::class)->draft($run, [
            'headline' => 'H', 'audience' => 'A', 'channel' => 'C', 'message' => 'M',
        ]);
    }

    public function test_copy_brief_is_marked_no_unsubstantiated_claim_and_no_auto_publish(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $copy = app(CopyBriefService::class)->draft($run, [
            'headline' => 'H', 'audience' => 'A', 'channel' => 'C', 'message' => 'M', 'call_to_action' => 'CTA',
        ]);
        $this->assertSame(MarketingDomainCanon::ARTIFACT_COPY, $copy->artifact_type);
        $this->assertTrue($copy->payload['no_unsubstantiated_claim']);
        $this->assertFalse($copy->payload['side_effect_policy']['auto_publish']);
    }

    public function test_creative_brief_requires_visual_direction(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(CreativeBriefService::class)->draft($run, [
            'concept' => 'C', 'format' => 'video', 'audience' => 'A',
        ]);
    }
}
