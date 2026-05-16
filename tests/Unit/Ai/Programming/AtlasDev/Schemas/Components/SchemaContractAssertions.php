<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;

trait SchemaContractAssertions
{
    private function assertCanonicalArrayKeysSorted(AtlasDevSchemaContract $component): void
    {
        $this->assertKeysSortedRecursive($component->toCanonicalArray());
    }

    private function assertKeysSortedRecursive(mixed $value): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        if (! array_is_list($value)) {
            $keys = array_keys($value);
            $sorted = $keys;
            sort($sorted, SORT_STRING);
            $this->assertSame($sorted, $keys, 'Keys must be alphabetically sorted, got: '.implode(',', $keys));
        }

        foreach ($value as $child) {
            $this->assertKeysSortedRecursive($child);
        }
    }

    private function assertHashStable(AtlasDevSchemaContract $a, AtlasDevSchemaContract $b): void
    {
        $this->assertSame($a->hash(), $b->hash(), 'Hashes must match for equivalent payloads');
        $this->assertSame($a->toJson(), $b->toJson(), 'JSON must match for equivalent payloads');
    }

    private function assertHashDiffers(AtlasDevSchemaContract $a, AtlasDevSchemaContract $b): void
    {
        $this->assertNotSame($a->hash(), $b->hash(), 'Hashes must differ when content differs');
    }

    private function assertHashIsSha256(AtlasDevSchemaContract $component): void
    {
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $component->hash());
    }

    private function assertJsonRoundtripStable(AtlasDevSchemaContract $component): void
    {
        $decoded = json_decode($component->toJson(), true);
        $this->assertIsArray($decoded);
        $this->assertSame($component->toCanonicalArray(), $decoded);
    }

    private function assertProviderSafeProjectionIsHonest(AtlasDevSchemaContract $component): void
    {
        $canonical = $component->toCanonicalArray();
        $safe = $component->toProviderSafeArray();

        $this->assertIsArray($safe, 'toProviderSafeArray() must return an array');
        $this->assertSame(
            array_keys($canonical),
            array_keys($safe),
            'toProviderSafeArray() must preserve canonical key set; redaction never silently drops fields.'
        );
        $this->assertKeysSortedRecursive($safe);

        if ($component->isProviderSafe()) {
            $this->assertSame(
                $canonical,
                $safe,
                'When isProviderSafe() is true, toProviderSafeArray() must equal toCanonicalArray() (contracts doc 3.4).'
            );
        }
    }

    private function assertContractSurface(AtlasDevSchemaContract $component): void
    {
        $this->assertNotSame('', $component->schemaVersion());
        $this->assertHashIsSha256($component);
        $this->assertCanonicalArrayKeysSorted($component);
        $this->assertJsonRoundtripStable($component);
        $this->assertProviderSafeProjectionIsHonest($component);
    }
}
