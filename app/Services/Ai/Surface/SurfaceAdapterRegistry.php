<?php

namespace App\Services\Ai\Surface;

use App\Services\Ai\Kernel\Surface\SurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasApiInteractionSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasAppSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliChatSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliDevSurfaceAdapter;
use App\Services\Ai\Surface\Adapters\AtlasCliForgeSurfaceAdapter;
use InvalidArgumentException;

class SurfaceAdapterRegistry
{
    /**
     * @var array<int,class-string<SurfaceAdapter>>
     */
    private const ADAPTER_CLASSES = [
        AtlasCliDevSurfaceAdapter::class,
        AtlasCliChatSurfaceAdapter::class,
        AtlasCliForgeSurfaceAdapter::class,
        AtlasApiInteractionSurfaceAdapter::class,
        AtlasAppSurfaceAdapter::class,
    ];

    /**
     * @var array<string,string>
     */
    private const SURFACE_ALIASES = [
        'atlas_api' => 'atlas_api_interaction',
        'atlas_cli' => 'atlas_cli_dev',
    ];

    /**
     * @return array<int,SurfaceAdapter>
     */
    public function all(): array
    {
        return array_map(
            fn (string $class): SurfaceAdapter => app($class),
            self::ADAPTER_CLASSES,
        );
    }

    public function get(string $surfaceId): SurfaceAdapter
    {
        $surfaceId = $this->canonicalSurfaceId($surfaceId);

        foreach ($this->all() as $adapter) {
            if ($adapter->surfaceId() === $surfaceId) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException("Unsupported surface adapter [{$surfaceId}].");
    }

    /**
     * @return array<int,string>
     */
    public function surfaceIds(): array
    {
        return array_map(
            fn (SurfaceAdapter $adapter): string => $adapter->surfaceId(),
            $this->all(),
        );
    }

    public function canonicalSurfaceId(string $surfaceId): string
    {
        return self::SURFACE_ALIASES[$surfaceId] ?? $surfaceId;
    }

    /**
     * @return array<string,string>
     */
    public function aliases(): array
    {
        return self::SURFACE_ALIASES;
    }

    /**
     * @return array{ok:bool,count:int,surfaces:array<int,string>,errors:array<int,string>}
     */
    public function complianceReport(): array
    {
        $errors = [];
        $surfaces = [];

        foreach ($this->all() as $adapter) {
            $surfaceId = $adapter->surfaceId();
            $surfaces[] = $surfaceId;

            $report = $adapter->complianceReport();
            foreach ($report['errors'] as $error) {
                $errors[] = "{$surfaceId}: {$error}";
            }
        }

        $duplicates = collect($surfaces)
            ->countBy()
            ->filter(fn (int $count): bool => $count > 1)
            ->keys()
            ->values()
            ->all();

        foreach ($duplicates as $duplicate) {
            $errors[] = "duplicate surface adapter id [{$duplicate}]";
        }

        return [
            'ok' => $errors === [],
            'count' => count($surfaces),
            'surfaces' => $surfaces,
            'errors' => $errors,
        ];
    }
}
