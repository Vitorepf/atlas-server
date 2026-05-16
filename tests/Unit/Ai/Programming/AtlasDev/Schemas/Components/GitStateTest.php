<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\GitState;
use PHPUnit\Framework\TestCase;

final class GitStateTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_constructs_with_explicit_values(): void
    {
        $state = new GitState(
            headSha: 'abc123',
            dirty: false,
            untrackedCount: 0,
            pendingChangesCount: 0,
        );

        $this->assertSame('abc123', $state->headSha);
        $this->assertFalse($state->dirty);
        $this->assertSame(0, $state->untrackedCount);
        $this->assertSame(0, $state->pendingChangesCount);
        $this->assertTrue($state->isProviderSafe());
        $this->assertSame('atlas.dev.components.git_state.v1', $state->schemaVersion());
    }

    public function test_canonical_array_is_alphabetically_sorted(): void
    {
        $state = new GitState(headSha: 'sha', dirty: true, untrackedCount: 1, pendingChangesCount: 2);
        $this->assertSame(
            ['dirty', 'head_sha', 'pending_changes_count', 'untracked_count'],
            array_keys($state->toCanonicalArray()),
        );
        $this->assertCanonicalArrayKeysSorted($state);
    }

    public function test_json_round_trip_is_deterministic(): void
    {
        $state = new GitState(headSha: null, dirty: true, untrackedCount: 3, pendingChangesCount: 4);

        $this->assertJsonRoundtripStable($state);

        $rebuilt = GitState::fromArray(json_decode($state->toJson(), true));
        $this->assertHashStable($state, $rebuilt);
    }

    public function test_hash_is_sha256_and_changes_when_field_changes(): void
    {
        $base = new GitState(headSha: 'a', dirty: false, untrackedCount: 0, pendingChangesCount: 0);
        $sameValues = new GitState(headSha: 'a', dirty: false, untrackedCount: 0, pendingChangesCount: 0);
        $different = new GitState(headSha: 'b', dirty: false, untrackedCount: 0, pendingChangesCount: 0);

        $this->assertHashIsSha256($base);
        $this->assertHashStable($base, $sameValues);
        $this->assertHashDiffers($base, $different);
    }

    public function test_provider_safe_is_explicit_default_true(): void
    {
        $defaulted = GitState::fromArray([
            'head_sha' => null,
            'dirty' => false,
            'untracked_count' => 0,
            'pending_changes_count' => 0,
        ]);
        $explicitFalse = GitState::fromArray([
            'head_sha' => null,
            'dirty' => false,
            'untracked_count' => 0,
            'pending_changes_count' => 0,
            'provider_safe' => false,
        ]);

        $this->assertTrue($defaulted->isProviderSafe());
        $this->assertFalse($explicitFalse->isProviderSafe());
    }
}
