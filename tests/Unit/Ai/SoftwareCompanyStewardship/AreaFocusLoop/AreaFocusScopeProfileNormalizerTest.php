<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScopeProfileNormalizer;
use Tests\TestCase;

final class AreaFocusScopeProfileNormalizerTest extends TestCase
{
    public function test_accepts_factory_max_case_insensitively(): void
    {
        $this->assertSame(
            AreaFocusScopeProfileNormalizer::FACTORY_MAX,
            AreaFocusScopeProfileNormalizer::normalize(' FACTORY_MAX '),
        );
    }

    public function test_defaults_unknown_blank_and_non_string_values_to_balanced(): void
    {
        $this->assertSame(AreaFocusScopeProfileNormalizer::BALANCED, AreaFocusScopeProfileNormalizer::normalize(''));
        $this->assertSame(AreaFocusScopeProfileNormalizer::BALANCED, AreaFocusScopeProfileNormalizer::normalize('wide_open'));
        $this->assertSame(AreaFocusScopeProfileNormalizer::BALANCED, AreaFocusScopeProfileNormalizer::normalize(true));
        $this->assertSame(AreaFocusScopeProfileNormalizer::BALANCED, AreaFocusScopeProfileNormalizer::normalize(null));
    }
}
