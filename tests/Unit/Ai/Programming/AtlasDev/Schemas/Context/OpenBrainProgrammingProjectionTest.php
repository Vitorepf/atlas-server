<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Context;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use App\Services\Ai\Programming\AtlasDev\Schemas\OpenBrainProgrammingProjection;
use InvalidArgumentException;
use Tests\TestCase;

final class OpenBrainProgrammingProjectionTest extends TestCase
{
    private function loadFixture(): array
    {
        $raw = file_get_contents(__DIR__.'/../../../../../../Fixtures/AtlasDev/context/valid_open_brain_projection_truncated.json');
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function makeProjection(array $overrides = []): OpenBrainProgrammingProjection
    {
        return OpenBrainProgrammingProjection::fromArray(array_replace($this->loadFixture(), $overrides));
    }

    public function test_constructs_from_fixture_and_implements_contract(): void
    {
        $p = $this->makeProjection();

        $this->assertInstanceOf(AtlasDevSchemaContract::class, $p);
        $this->assertSame('atlas.open_brain.programming_projection.v1', $p->schemaVersion());
        $this->assertSame('programming', $p->mode);
        $this->assertSame(OpenBrainProgrammingProjection::MODE, $p->mode);
        $this->assertTrue($p->isTruncated());
        $this->assertNotEmpty($p->memoryRefs);
        $this->assertInstanceOf(ContextRef::class, $p->memoryRefs[0]);
    }

    public function test_mode_must_be_programming(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeProjection(['mode' => 'chat']);
    }

    public function test_canonical_array_is_sorted_and_deterministic(): void
    {
        $p = $this->makeProjection();

        $canonical = $p->toCanonicalArray();
        $keys = array_keys($canonical);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);

        $this->assertSame($canonical, $p->toCanonicalArray());
    }

    public function test_round_trip_through_canonical_array(): void
    {
        $p = $this->makeProjection();
        $rebuilt = OpenBrainProgrammingProjection::fromArray($p->toCanonicalArray());

        $this->assertSame($p->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($p->hash(), $rebuilt->hash());
    }

    public function test_hash_is_deterministic(): void
    {
        $p = $this->makeProjection();
        $this->assertSame($p->hash(), $p->hash());
    }

    public function test_hash_changes_when_truncation_changes(): void
    {
        $a = $this->makeProjection();
        $b = $this->makeProjection([
            'truncation' => ['truncated' => false, 'reasons' => []],
        ]);

        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function test_hash_ignores_projection_hash_field_itself(): void
    {
        $a = $this->makeProjection(['projection_hash' => 'h-one']);
        $b = $this->makeProjection(['projection_hash' => 'h-two']);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_provider_safe_is_explicit(): void
    {
        $safe = $this->makeProjection(['provider_safe' => true]);
        $unsafe = $this->makeProjection(['provider_safe' => false]);

        $this->assertTrue($safe->isProviderSafe());
        $this->assertFalse($unsafe->isProviderSafe());
    }

    public function test_missing_sources_surfaced_as_list(): void
    {
        $p = $this->makeProjection();
        $this->assertContains('doc://memory/contracts.md#section-12', $p->missingSources);
    }
}
