<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognition;

use App\Services\Ai\Cognition\TemporalSupersessionClassifier;
use PHPUnit\Framework\TestCase;

final class TemporalSupersessionClassifierTest extends TestCase
{
    private TemporalSupersessionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new TemporalSupersessionClassifier();
    }

    public function testDifferentKeysCoexistRegardlessOfTimestampOrdering(): void
    {
        // Different keys must coexist no matter which timestamp is newer.
        $this->assertSame('coexist', $this->classifier->classify(1700, 1500, false));
        $this->assertSame('coexist', $this->classifier->classify(1500, 1700, false));
        $this->assertSame('coexist', $this->classifier->classify(1600, 1600, false));
    }

    public function testNewerTimestampAOnSameKeySupersedesB(): void
    {
        $this->assertSame('a_supersedes_b', $this->classifier->classify(2_000, 1_999, true));
    }

    public function testNewerTimestampBOnSameKeySupersedesA(): void
    {
        $this->assertSame('b_supersedes_a', $this->classifier->classify(1_999, 2_000, true));
    }

    public function testEqualTimestampsOnSameKeyTieAtBoundary(): void
    {
        $this->assertSame('tie_same_timestamp', $this->classifier->classify(1_725_000_000, 1_725_000_000, true));
    }
}
