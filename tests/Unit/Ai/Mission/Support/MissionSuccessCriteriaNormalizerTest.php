<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Mission\Support;

use App\Services\Ai\Mission\Support\MissionSuccessCriteriaNormalizer;
use PHPUnit\Framework\TestCase;

final class MissionSuccessCriteriaNormalizerTest extends TestCase
{
    public function test_descriptions_normalizes_string_and_structured_criteria(): void
    {
        $this->assertSame(
            ['First criterion', 'Second criterion', '0', '42'],
            MissionSuccessCriteriaNormalizer::descriptions([
                ' First criterion ',
                '',
                '   ',
                ['description' => ' Second criterion '],
                ['description' => '0'],
                ['description' => 42],
                ['description' => false],
                ['other' => 'ignored'],
                99,
            ]),
        );
    }

    public function test_descriptions_accepts_scalar_string_and_blank_values(): void
    {
        $this->assertSame(['Ship it'], MissionSuccessCriteriaNormalizer::descriptions(' Ship it '));
        $this->assertSame([], MissionSuccessCriteriaNormalizer::descriptions('   '));
        $this->assertSame([], MissionSuccessCriteriaNormalizer::descriptions(null));
    }
}
