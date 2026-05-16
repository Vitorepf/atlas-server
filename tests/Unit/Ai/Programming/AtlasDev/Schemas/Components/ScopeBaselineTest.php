<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeBaseline;
use PHPUnit\Framework\TestCase;

final class ScopeBaselineTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_canonical_array_is_sorted(): void
    {
        $sb = new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null);
        $this->assertSame(['git_diff_before_hash', 'git_status_before'], array_keys($sb->toCanonicalArray()));
        $this->assertCanonicalArrayKeysSorted($sb);
    }

    public function test_round_trip_with_nullable_diff_hash(): void
    {
        $sb = new ScopeBaseline(gitStatusBefore: ' M foo.php', gitDiffBeforeHash: 'abc123');
        $rebuilt = ScopeBaseline::fromArray(json_decode($sb->toJson(), true));
        $this->assertHashStable($sb, $rebuilt);
        $this->assertJsonRoundtripStable($sb);
    }

    public function test_hash_differs_when_status_changes(): void
    {
        $a = new ScopeBaseline(gitStatusBefore: 'clean', gitDiffBeforeHash: null);
        $b = new ScopeBaseline(gitStatusBefore: 'dirty', gitDiffBeforeHash: null);

        $this->assertHashIsSha256($a);
        $this->assertHashDiffers($a, $b);
    }
}
