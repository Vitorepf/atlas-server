<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainProviderPoolOutputNormalizer;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasExternalBrainProviderPoolOutputNormalizer::toStringList drops
 * non-scalar list elements without throwing.
 */
final class AtlasExternalBrainProviderPoolOutputNormalizerHardeningTest extends TestCase
{
    private function normalizer(): AtlasExternalBrainProviderPoolOutputNormalizer
    {
        return new AtlasExternalBrainProviderPoolOutputNormalizer();
    }

    private function invokeToStringList(AtlasExternalBrainProviderPoolOutputNormalizer $normalizer, mixed $value): array
    {
        $method = new \ReflectionMethod($normalizer, 'toStringList');
        return $method->invoke($normalizer, $value);
    }

    // ── Non-scalar elements are dropped ─────────────────────────────────────────

    public function test_nested_array_element_is_dropped_without_throwing(): void
    {
        $mixed = ['app/Foo.php', ['nested' => 'array'], 'app/Bar.php'];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame(['app/Foo.php', 'app/Bar.php'], $result);
    }

    public function test_object_element_is_dropped_without_throwing(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        $mixed = ['app/Foo.php', $obj, 'app/Bar.php'];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame(['app/Foo.php', 'app/Bar.php'], $result);
    }

    public function test_null_element_is_dropped(): void
    {
        $mixed = ['app/Foo.php', null, 'app/Bar.php'];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame(['app/Foo.php', 'app/Bar.php'], $result);
    }

    public function test_all_non_scalars_returns_empty(): void
    {
        $mixed = [['nested'], ['another'], new \stdClass()];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame([], $result);
    }

    public function test_scalar_integers_are_coerced(): void
    {
        $mixed = ['app/Foo.php', 42, true, 'app/Bar.php'];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame(['app/Foo.php', '42', '1', 'app/Bar.php'], $result);
    }

    public function test_normal_string_list_passes_through(): void
    {
        $strings = ['app/Foo.php', 'app/Bar.php', 'tests/BazTest.php'];
        $result = $this->invokeToStringList($this->normalizer(), $strings);

        $this->assertSame(['app/Foo.php', 'app/Bar.php', 'tests/BazTest.php'], $result);
    }

    public function test_empty_strings_are_filtered(): void
    {
        $mixed = ['app/Foo.php', '', 'app/Bar.php'];
        $result = $this->invokeToStringList($this->normalizer(), $mixed);

        $this->assertSame(['app/Foo.php', 'app/Bar.php'], $result);
    }
}
