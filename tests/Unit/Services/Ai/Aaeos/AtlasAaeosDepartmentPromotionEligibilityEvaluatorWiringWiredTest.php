<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Aaeos;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasAaeosDepartmentPromotionEligibilityEvaluatorWiringWiredTest extends TestCase
{
    public function test_department_status_command_includes_promotion_eligibility(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayHasKey('promotion_eligibility', $payload);
        self::assertSame(
            'atlas.aaeos.department_promotion_eligibility_batch.v1',
            $payload['promotion_eligibility']['schema_version'],
        );
        self::assertArrayHasKey('product', $payload['promotion_eligibility']['departments']);
    }

    public function test_each_department_promotion_result_carries_evaluator_schema_and_verdict(): void
    {
        Artisan::call('atlas:aeos:department-status', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true, 512, JSON_THROW_ON_ERROR);

        $product = $payload['promotion_eligibility']['departments']['product'];
        self::assertSame('atlas.aaeos.department_promotion_eligibility.v1', $product['schema_version']);
        self::assertContains($product['verdict'], ['eligible', 'blocked']);
        self::assertFalse($product['promotion_allowed']);
    }
}
