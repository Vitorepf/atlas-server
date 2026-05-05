<?php

namespace App\Services\Ai\Kernel\Surface;

final class SurfaceHintKey
{
    public const AGENT_SLUG = 'agent_slug';
    public const APP_SURFACE = 'app_surface';
    public const ATLAS_MODE = 'atlas_mode';
    public const ATLAS_WORKFLOW_MODE = 'atlas_workflow_mode';
    public const CURRENT_MODE = 'current_mode';
    public const DOMAIN_CATALOG_SELECTION = 'domain_catalog_selection';
    public const DOMAIN_ID = 'domain_id';
    public const EXECUTOR = 'executor';
    public const FLOW_ID = 'flow_id';
    public const MODE = 'mode';
    public const PRODUCT_DOMAIN = 'product_domain';
    public const PROVIDER = 'provider';
    public const ROUTING_DOMAIN = 'routing_domain';
    public const ROUTING_TASK = 'routing_task';
    public const SOURCE = 'source';
    public const SOURCE_TYPE = 'source_type';
    public const SURFACE_CAPABILITIES = 'surface_capabilities';
    public const SURFACE_ID = 'surface_id';
    public const TASK = 'task';
    public const THREAD_ID = 'thread_id';
    public const WORKSPACE = 'workspace';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::AGENT_SLUG,
            self::APP_SURFACE,
            self::ATLAS_MODE,
            self::ATLAS_WORKFLOW_MODE,
            self::CURRENT_MODE,
            self::DOMAIN_CATALOG_SELECTION,
            self::DOMAIN_ID,
            self::EXECUTOR,
            self::FLOW_ID,
            self::MODE,
            self::PRODUCT_DOMAIN,
            self::PROVIDER,
            self::ROUTING_DOMAIN,
            self::ROUTING_TASK,
            self::SOURCE,
            self::SOURCE_TYPE,
            self::SURFACE_CAPABILITIES,
            self::SURFACE_ID,
            self::TASK,
            self::THREAD_ID,
            self::WORKSPACE,
        ];
    }

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
