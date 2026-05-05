<?php

namespace App\Services\Ai\Domain;

use App\Services\Ai\Kernel\Domain\AtlasDomainOrchestrator;

class AtlasMarketingOrchestrator implements AtlasDomainOrchestrator
{
    public function orchestratorId(): string
    {
        return 'AtlasMarketingOrchestrator';
    }

    public function supportedDomains(): array
    {
        return ['marketing'];
    }

    public function supportedFlows(): array
    {
        return [
            'marketing.strategy',
            'marketing.research',
            'marketing.positioning',
            'marketing.campaign',
            'marketing.creative',
            'marketing.copywriting',
            'marketing.media_plan',
            'marketing.landing_page',
            'marketing.email',
            'marketing.social',
            'marketing.video_script',
            'marketing.ab_test',
            'marketing.analytics',
            'marketing.brand_review',
            'marketing.forge',
        ];
    }

    public function maturity(): string
    {
        return 'implemented';
    }
}
