<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Surface;

use App\Services\Ai\Programming\AtlasDev\Surface\AtlasApiInteractionAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasAppAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasCliDevAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasDesktopAiAdapter;
use App\Services\Ai\Programming\AtlasDev\Surface\AtlasDevSurfaceAdapter;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Parity test for AtlasDev/Surface adapters.
 *
 * Every shipped surface (Desktop, CLI, App, API) must:
 *  - implement {@see AtlasDevSurfaceAdapter};
 *  - advertise its canonical surface_id constant;
 *  - drop its old `*Stub` companion class so the registry can't bind a
 *    half-implemented adapter by mistake.
 */
final class AtlasDevSurfaceStubsTest extends TestCase
{
    /**
     * @return array<string, array{0:class-string, 1:string}>
     */
    public function adapters(): array
    {
        return [
            'desktop' => [AtlasDesktopAiAdapter::class, 'atlas_desktop_ai'],
            'cli_dev' => [AtlasCliDevAdapter::class, 'atlas_cli_dev'],
            'app' => [AtlasAppAdapter::class, 'atlas_app'],
            'api_interaction' => [AtlasApiInteractionAdapter::class, 'atlas_api_interaction'],
        ];
    }

    public function test_all_four_adapters_implement_the_surface_interface_with_canonical_ids(): void
    {
        foreach ($this->adapters() as $label => [$fqcn, $expectedSurfaceId]) {
            $reflection = new ReflectionClass($fqcn);
            $this->assertTrue(
                $reflection->implementsInterface(AtlasDevSurfaceAdapter::class),
                "{$label} ({$fqcn}) must implement AtlasDevSurfaceAdapter",
            );

            $instance = $reflection->newInstanceWithoutConstructor();
            $this->assertSame(
                $expectedSurfaceId,
                $instance->surfaceId(),
                "{$label} surface_id must be canonical",
            );
        }
    }

    /**
     * The stub variants existed only as a parking lot during the Surface
     * bring-up. They must NOT be re-introduced — a stub class binding via
     * the container would let a half-implemented adapter answer a real
     * request.
     */
    public function test_legacy_stub_classes_do_not_exist_anymore(): void
    {
        foreach ([
            'App\\Services\\Ai\\Programming\\AtlasDev\\Surface\\AtlasAppAdapterStub',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Surface\\AtlasApiInteractionAdapterStub',
            'App\\Services\\Ai\\Programming\\AtlasDev\\Surface\\AtlasCliDevAdapterStub',
        ] as $legacy) {
            $this->assertFalse(class_exists($legacy), "{$legacy} must not exist; it was a parking-lot class.");
        }
    }
}
