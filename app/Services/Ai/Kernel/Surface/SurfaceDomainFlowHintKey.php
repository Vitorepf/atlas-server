<?php

namespace App\Services\Ai\Kernel\Surface;

final class SurfaceDomainFlowHintKey
{
    public const ACCEPTS_CATALOG_DOMAIN_FLOW_SELECTION = 'accepts_catalog_domain_flow_selection';
    public const ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION = 'accepts_explicit_domain_flow_selection';
    public const DEFAULT_DOMAIN_ID = 'default_domain_id';
    public const DEFAULT_FLOW_ID = 'default_flow_id';
    public const PREFER_DEFAULT_FLOW = 'prefer_default_flow';
    public const SUPPORTED_DOMAIN_IDS = 'supported_domain_ids';
    public const SUPPORTED_FLOW_IDS = 'supported_flow_ids';
    public const SURFACE_ID = 'surface_id';
    public const TASK_FLOW_MAP = 'task_flow_map';

    /**
     * @return array<int,string>
     */
    public static function all(): array
    {
        return [
            self::ACCEPTS_CATALOG_DOMAIN_FLOW_SELECTION,
            self::ACCEPTS_EXPLICIT_DOMAIN_FLOW_SELECTION,
            self::DEFAULT_DOMAIN_ID,
            self::DEFAULT_FLOW_ID,
            self::PREFER_DEFAULT_FLOW,
            self::SUPPORTED_DOMAIN_IDS,
            self::SUPPORTED_FLOW_IDS,
            self::SURFACE_ID,
            self::TASK_FLOW_MAP,
        ];
    }

    public static function isKnown(string $key): bool
    {
        return in_array($key, self::all(), true);
    }
}
