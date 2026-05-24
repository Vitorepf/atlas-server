<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Context;

use App\Services\Ai\Programming\AtlasDev\Schemas\CodeDiscoveryManifest;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\CodeCandidate;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ContextRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\MissingRef;
use App\Services\Ai\Programming\AtlasDev\Schemas\Contracts\AtlasDevSchemaContract;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class CodeDiscoveryManifestTest extends TestCase
{
    private function loadFixture(string $name): array
    {
        $raw = file_get_contents(__DIR__.'/../../../../../../Fixtures/AtlasDev/context/'.$name);
        $this->assertIsString($raw);
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded);

        return $decoded;
    }

    private function makeStrong(array $overrides = []): CodeDiscoveryManifest
    {
        return CodeDiscoveryManifest::fromArray(array_replace(
            $this->loadFixture('valid_code_discovery_strong_inference.json'),
            $overrides,
        ));
    }

    private function makeBlocking(array $overrides = []): CodeDiscoveryManifest
    {
        return CodeDiscoveryManifest::fromArray(array_replace(
            $this->loadFixture('valid_code_discovery_blocking_ambiguity.json'),
            $overrides,
        ));
    }

    public function test_strong_inference_fixture_constructs_and_implements_contract(): void
    {
        $m = $this->makeStrong();

        $this->assertInstanceOf(AtlasDevSchemaContract::class, $m);
        $this->assertSame('atlas.dev.code_discovery_manifest.v1', $m->schemaVersion());
        $this->assertSame(CodeDiscoveryManifest::CONFIDENCE_STRONG_INFERENCE, $m->confidence);
        $this->assertFalse($m->isBlocking());
        $this->assertCount(1, $m->likelyFiles);
        $this->assertInstanceOf(CodeCandidate::class, $m->likelyFiles[0]);
        $this->assertSame(0.95, $m->likelyFiles[0]->confidence);
        $this->assertInstanceOf(ContextRef::class, $m->relatedSymbols[0]);
    }

    public function test_blocking_ambiguity_with_missing_refs_is_supported(): void
    {
        $m = $this->makeBlocking();

        $this->assertTrue($m->isBlocking());
        $this->assertSame([], $m->likelyFiles);
        $this->assertNotEmpty($m->missingRefs);
        $this->assertInstanceOf(MissingRef::class, $m->missingRefs[0]);
    }

    public function test_invalid_confidence_value_is_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->makeStrong(['confidence' => 'maybe_who_knows']);
    }

    public function test_canonical_array_is_sorted_and_deterministic(): void
    {
        $m = $this->makeStrong();

        $canonical = $m->toCanonicalArray();
        $keys = array_keys($canonical);
        $sorted = $keys;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $keys);

        $this->assertSame($canonical, $m->toCanonicalArray());
    }

    public function test_round_trip_through_canonical_array(): void
    {
        $m = $this->makeStrong();
        $rebuilt = CodeDiscoveryManifest::fromArray($m->toCanonicalArray());

        $this->assertSame($m->toCanonicalArray(), $rebuilt->toCanonicalArray());
        $this->assertSame($m->hash(), $rebuilt->hash());
    }

    public function test_hash_is_deterministic(): void
    {
        $m = $this->makeStrong();
        $this->assertSame($m->hash(), $m->hash());
    }

    public function test_hash_changes_when_confidence_changes(): void
    {
        $a = $this->makeStrong();
        $b = $this->makeStrong(['confidence' => CodeDiscoveryManifest::CONFIDENCE_CONFIRMED_FACT]);

        $this->assertNotSame($a->hash(), $b->hash());
    }

    public function test_hash_ignores_manifest_hash_field_itself(): void
    {
        $a = $this->makeStrong(['manifest_hash' => 'first']);
        $b = $this->makeStrong(['manifest_hash' => 'second']);

        $this->assertSame($a->hash(), $b->hash());
    }

    public function test_provider_safe_is_explicit(): void
    {
        $safe = $this->makeStrong(['provider_safe' => true]);
        $unsafe = $this->makeStrong(['provider_safe' => false]);

        $this->assertTrue($safe->isProviderSafe());
        $this->assertFalse($unsafe->isProviderSafe());
    }

    public function test_code_candidate_rejects_out_of_range_confidence(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new CodeCandidate(path: '/x', reason: 'r', confidence: 1.5);
    }
}
