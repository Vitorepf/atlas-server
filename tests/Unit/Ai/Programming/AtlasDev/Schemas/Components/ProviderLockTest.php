<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ProviderLock;
use PHPUnit\Framework\TestCase;

final class ProviderLockTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_default_fallback_is_false_and_provider_safe_is_true(): void
    {
        $lock = new ProviderLock(provider: 'claude_cli', modelFamily: 'sonnet');

        $this->assertFalse($lock->fallbackAllowed);
        $this->assertTrue($lock->isProviderSafe());
    }

    public function test_canonical_array_is_sorted(): void
    {
        $lock = new ProviderLock(provider: 'claude_cli', modelFamily: 'sonnet');
        $this->assertSame(
            ['fallback_allowed', 'model_family', 'provider'],
            array_keys($lock->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($lock);
    }

    public function test_round_trip_via_from_array(): void
    {
        $lock = new ProviderLock(provider: 'claude_cli', modelFamily: 'sonnet', fallbackAllowed: false);
        $rebuilt = ProviderLock::fromArray(json_decode($lock->toJson(), true));
        $this->assertHashStable($lock, $rebuilt);
        $this->assertJsonRoundtripStable($lock);
    }

    public function test_hash_differs_on_provider_change(): void
    {
        $a = new ProviderLock(provider: 'claude_cli', modelFamily: 'sonnet');
        $b = new ProviderLock(provider: 'codex_cli', modelFamily: 'sonnet');

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
