<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Components;

use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeFileDiff;
use App\Services\Ai\Programming\AtlasDev\Schemas\Components\ScopeObserved;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ScopeObservedTest extends TestCase
{
    use SchemaContractAssertions;

    public function test_round_trip(): void
    {
        $diff = new ScopeFileDiff(path: 'app/Foo.php', added: 1, removed: 1, fileHashAfter: 'h1');
        $obs = new ScopeObserved(
            gitDiffHash: 'deadbeef',
            changedFiles: ['app/Foo.php'],
            changedFilesCount: 1,
            fileDiffs: [$diff],
        );
        $rebuilt = ScopeObserved::fromArray($obs->toCanonicalArray());
        $this->assertHashStable($obs, $rebuilt);
        $this->assertContractSurface($obs);
    }

    public function test_count_mismatch_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new ScopeObserved(
            gitDiffHash: null,
            changedFiles: ['a', 'b'],
            changedFilesCount: 5,
            fileDiffs: [],
        );
    }
}
