<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaeosDepartmentMaturityBandClassifierWiringWiredTest extends TestCase
{
    public function test_department_status_command_includes_maturity_band_classification(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('maturity_band_classification', $payload);
        self::assertSame(
            'atlas.aaeos.department_maturity_band.v1',
            $payload['maturity_band_classification']['schema_version'],
        );
    }

    public function test_department_above_quality_bar_threshold_qualifies_for_meets_quality_bar_band(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // Engineering: threshold 0.85, current 0.92 -> qualifies.
        $engineering = $payload['maturity_band_classification']['departments']['Engineering'];
        self::assertSame('meets_quality_bar', $engineering['qualified_band']);
        self::assertFalse($engineering['all_bands_breached']);
    }

    public function test_department_below_quality_bar_threshold_breaches_all_bands(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        // Product: threshold 0.80, current 0.75 -> breaches.
        $product = $payload['maturity_band_classification']['departments']['Product'];
        self::assertTrue($product['all_bands_breached']);
        self::assertNull($product['qualified_band']);
    }
}
