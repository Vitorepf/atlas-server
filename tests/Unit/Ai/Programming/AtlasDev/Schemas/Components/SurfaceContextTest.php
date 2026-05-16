<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\SurfaceContext;
use PHPUnit\Framework\TestCase;

final class SurfaceContextTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_constructs_with_minimum_required_fields(): void
    {
        $sc = new SurfaceContext(productSurface: 'atlas_ai_desktop_mac');

        $this->assertSame('atlas_ai_desktop_mac', $sc->productSurface);
        $this->assertNull($sc->threadId);
        $this->assertNull($sc->providerChoice);
        $this->assertTrue($sc->isProviderSafe());
        $this->assertSame('atlas.dev.components.surface_context.v1', $sc->schemaVersion());
    }

    public function test_canonical_array_is_sorted_and_carries_all_fields(): void
    {
        $sc = new SurfaceContext(
            productSurface: 'cli_dev',
            threadId: 't',
            conversationId: 'c',
            composerMode: 'programming',
            composerTask: 'dev',
            providerChoice: 'auto',
        );

        $this->assertSame(
            ['composer_mode', 'composer_task', 'conversation_id', 'product_surface', 'provider_choice', 'thread_id'],
            array_keys($sc->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($sc);
    }

    public function test_round_trip_via_from_array_preserves_hash(): void
    {
        $sc = new SurfaceContext(productSurface: 'atlas_app', composerMode: 'general');
        $rebuilt = SurfaceContext::fromArray(json_decode($sc->toJson(), true));
        $this->assertHashStable($sc, $rebuilt);
        $this->assertJsonRoundtripStable($sc);
    }

    public function test_hash_changes_when_product_surface_changes(): void
    {
        $a = new SurfaceContext(productSurface: 'atlas_ai_desktop_mac');
        $b = new SurfaceContext(productSurface: 'cli_dev');

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }

    public function test_provider_safe_explicit(): void
    {
        $unsafe = new SurfaceContext(productSurface: 'cli_dev', providerSafe: false);
        $this->assertFalse($unsafe->isProviderSafe());
    }
}
