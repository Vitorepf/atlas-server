<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

final class AtlasVoiceRealtimeSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'voice_realtime';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::VOICE_AUDIO,
            SurfaceCapability::THREAD_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function domainFlowHints(): array
    {
        return [
            'accepts_catalog_domain_flow_selection' => true,
            'default_domain_id' => 'general',
            'default_flow_id' => 'general.answer',
            'supported_flow_ids' => [
                'general.answer',
                'programming.dev',
                'programming.review',
                'programming.repair',
                'strategic_decision.review',
                'personal_development.reflect',
                'finance.compliance_review',
                'marketing.copywriting',
                'writing.draft',
                'health.review',
            ],
            'task_flow_map' => [
                'ask' => 'general.answer',
                'chat' => 'general.answer',
                'dev' => 'programming.dev',
                'debug' => 'programming.repair',
                'repair' => 'programming.repair',
                'review' => 'programming.review',
                'strategy' => 'strategic_decision.review',
                'finance' => 'finance.compliance_review',
                'marketing' => 'marketing.copywriting',
                'writing' => 'writing.draft',
                'health' => 'health.review',
            ],
        ];
    }
}
