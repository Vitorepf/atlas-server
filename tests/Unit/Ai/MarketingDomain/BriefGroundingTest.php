<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Decision\BriefGrounding;
use App\Services\Ai\MarketingDomain\Decision\BriefGroundingHelper;
use PHPUnit\Framework\TestCase;

final class BriefGroundingTest extends TestCase
{
    public function test_null_pattern_is_not_grounded(): void
    {
        $out = (new BriefGrounding)->groundBriefFromPattern(null, 'generic');
        $this->assertFalse($out['grounded']);
        $this->assertSame('generic', $out['brief_type']);
    }

    public function test_helper_alias_still_resolves(): void
    {
        $this->assertInstanceOf(BriefGrounding::class, new BriefGroundingHelper);
    }
}
