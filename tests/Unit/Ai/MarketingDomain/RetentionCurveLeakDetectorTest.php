<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\Content\RetentionCurveLeakDetector;
use App\Services\Ai\MarketingDomain\Knowledge\VideoCreativeAnatomyLibrary;
use PHPUnit\Framework\TestCase;

/**
 * RetentionCurveLeakDetector — the flat-middle warning. True-positive structural warnings (no score):
 * a middle segment that opens no re-hook/loop is where watch-through dies.
 */
class RetentionCurveLeakDetectorTest extends TestCase
{
    public function test_flags_a_flat_middle(): void
    {
        // Hook, then a flat middle with no re-hook, then a CTA.
        $copy = 'There is a hidden reason you cannot lose the weight and it is not what you think. '
            .'The body stores fat in cells. Metabolism processes energy. Hormones regulate things. Diets reduce calories. '
            .'Order the protocol now.';
        $r = (new RetentionCurveLeakDetector)->detect($copy, 3);
        $this->assertNotEmpty($r['flaws']);
        $this->assertSame('flat_middle', $r['flaws'][0]['type']);
    }

    public function test_a_rehooked_middle_is_clean(): void
    {
        $copy = 'There is a hidden reason you cannot lose the weight and it is not what you think. '
            .'But wait — it gets worse, and in a second I will show you the part they hide. Keep watching. '
            .'Order the protocol now.';
        $r = (new RetentionCurveLeakDetector)->detect($copy, 3);
        $this->assertSame([], $r['flaws']);
        $this->assertStringContainsString('ok', $r['note']);
    }

    public function test_short_copy_is_graceful(): void
    {
        $r = (new RetentionCurveLeakDetector)->detect('Too short.', 3);
        $this->assertSame([], $r['flaws']);
        $this->assertSame([], $r['segments']);
    }

    public function test_library_has_the_rehook_category(): void
    {
        $lib = new VideoCreativeAnatomyLibrary;
        $this->assertContains('re_hook', $lib->categories());
        $rehooks = array_filter($lib->all(), static fn ($p) => $p['category'] === 're_hook');
        $this->assertGreaterThanOrEqual(3, count($rehooks));
    }
}
