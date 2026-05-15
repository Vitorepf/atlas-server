<?php

namespace App\Services\Ai\Surface\Adapters;

use App\Services\Ai\Kernel\Surface\SurfaceCapability;

/**
 * Surface adapter para o Atlas Desktop AI (Meta 7 Atlas Dev Runtime).
 *
 * O Desktop manda surface_id=atlas_desktop_ai em /ai/interactions. Sem este
 * adapter o registry caía em "registered: false", o que desligava domain/flow
 * hints e empobrecia a injeção do Open Brain. Aqui declaramos os modos
 * suportados (Geral/Operacional/Programação) e o mapeamento canônico de
 * tarefas para flows programming.dev/review/repair, garantindo paridade com
 * o adapter do CLI dev e com o contrato do mobile.
 */
final class AtlasDesktopAiSurfaceAdapter extends BaseSurfaceAdapter
{
    protected const SURFACE_ID = 'atlas_desktop_ai';

    /**
     * @return array<int,string>
     */
    protected function capabilities(): array
    {
        return [
            SurfaceCapability::TEXT,
            SurfaceCapability::ATTACHMENTS,
            SurfaceCapability::IMAGE_UPLOADS,
            SurfaceCapability::FILES,
            SurfaceCapability::THREAD_CONTEXT,
            SurfaceCapability::CONVERSATION_CONTEXT,
            SurfaceCapability::WORKSPACE_CONTEXT,
            SurfaceCapability::DOMAIN_FLOW_SELECTION,
            SurfaceCapability::MEMORY_RECALL,
            SurfaceCapability::CONTEXT_COMPOSE,
            SurfaceCapability::TOOLS_RUNTIME,
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
            'task_flow_map' => [
                'direct' => 'general.answer',
                'plan' => 'programming.dev',
                'dev' => 'programming.dev',
                'debug' => 'programming.repair',
                'repair' => 'programming.repair',
                'review' => 'programming.review',
            ],
        ];
    }
}
