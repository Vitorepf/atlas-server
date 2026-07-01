<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Replenishment;

use App\Services\Ai\SelfConstruction\Replenishment\AgentControlPlaneReplenishmentStableHasher;
use PHPUnit\Framework\TestCase;

/**
 * Focused contract test: proves normalizeForHash excludes the volatile identity fields
 * (generated_at, auto_replenishment_hash), recursively ksorts associative arrays while
 * preserving semantic list order, stableHash is deterministic regardless of key order,
 * and the fingerprint is sensitive to substantive replenishment-field changes.
 */
final class AgentControlPlaneReplenishmentStableHasherTest extends TestCase
{
    private AgentControlPlaneReplenishmentStableHasher $hasher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hasher = new AgentControlPlaneReplenishmentStableHasher;
    }

    public function test_normalize_for_hash_excludes_volatile_identity_fields(): void
    {
        $out = $this->hasher->normalizeForHash([
            'generated_at' => '2026-07-01T00:00:00Z',
            'auto_replenishment_hash' => 'stale-hash',
            'queue_depth' => 4,
        ]);

        self::assertArrayNotHasKey('generated_at', $out);
        self::assertArrayNotHasKey('auto_replenishment_hash', $out);
        self::assertArrayHasKey('queue_depth', $out);
    }

    public function test_recursive_associative_sorting_is_deep(): void
    {
        $out = $this->hasher->recursivelyKsort([
            'z_outer' => ['z_inner' => 1, 'a_inner' => 2],
            'a_outer' => 1,
        ]);

        self::assertSame(['a_outer', 'z_outer'], array_keys($out));
        self::assertSame(['a_inner', 'z_inner'], array_keys($out['z_outer']));
    }

    public function test_list_order_is_preserved_not_sorted(): void
    {
        $out = $this->hasher->recursivelyKsort(['depends_on' => ['task-c', 'task-a', 'task-b']]);

        self::assertSame(['task-c', 'task-a', 'task-b'], $out['depends_on']);
    }

    public function test_stable_hash_is_deterministic_across_key_order(): void
    {
        $h1 = $this->hasher->stableHash(['objective' => 'x', 'allowed_files' => ['a.php']]);
        $h2 = $this->hasher->stableHash(['allowed_files' => ['a.php'], 'objective' => 'x']);

        self::assertSame($h1, $h2);
    }

    public function test_hash_is_sensitive_to_substantive_changes_but_ignores_volatile_fields(): void
    {
        $payload = [
            'objective' => 'replenish queue with 5 packets',
            'allowed_files' => ['app/Foo.php'],
            'generated_at' => 'T1',
            'auto_replenishment_hash' => 'H1',
        ];
        $h0 = $this->hasher->stableHash($this->hasher->normalizeForHash($payload));

        // Only volatile fields differ — fingerprint stays the same.
        $sameSemantics = array_merge($payload, ['generated_at' => 'T2', 'auto_replenishment_hash' => 'H2']);
        self::assertSame($h0, $this->hasher->stableHash($this->hasher->normalizeForHash($sameSemantics)));

        // A substantive field changes — fingerprint must change.
        $changedSemantics = array_merge($payload, ['objective' => 'replenish queue with 10 packets']);
        self::assertNotSame($h0, $this->hasher->stableHash($this->hasher->normalizeForHash($changedSemantics)));
    }

    public function test_hasher_has_no_laravel_or_side_effect_surface(): void
    {
        $reflection = new \ReflectionClass(AgentControlPlaneReplenishmentStableHasher::class);

        self::assertSame([], $reflection->getProperties(), 'hasher must carry no instance state');
        foreach ($reflection->getMethods() as $method) {
            foreach ($method->getParameters() as $param) {
                $type = $param->getType();
                self::assertNotNull($type);
                self::assertStringNotContainsString('Illuminate', (string) $type, 'no Laravel framework types in the pure hasher surface');
            }
        }
    }
}
