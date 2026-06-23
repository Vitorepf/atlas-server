<?php

namespace Tests\Unit\Ai\MarketingDomain;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\TransformationAssetSourcer;
use PHPUnit\Framework\TestCase;

/**
 * Locks the engine's ability to source the before/after proof from the offer's real creative assets:
 * explicit opts, a transformations.json manifest, and a before-N/after-N file scan — with base_url
 * prefixing for relative paths, incomplete pairs dropped, and an empty result when no assets exist
 * (so the engine fills the section itself, no manual wiring). Deterministic.
 */
class TransformationAssetSourcerTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir().'/ot-trans-'.bin2hex(random_bytes(4));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
    }

    private function asset(): AiMarketingVslAsset
    {
        return new AiMarketingVslAsset(['offer' => ['product_name' => 'Lipo Bliss']]);
    }

    public function test_reads_a_manifest_and_prefixes_relative_urls(): void
    {
        file_put_contents($this->dir.'/transformations.json', json_encode([
            ['name' => 'Amy', 'result' => '-41 lbs', 'weeks' => '12 weeks', 'before' => 'b1.jpg', 'after' => 'a1.jpg'],
            ['name' => 'NoAfter', 'before' => 'b2.jpg'],   // incomplete → dropped
            ['name' => 'Abs', 'before' => 'https://cdn.x/b.jpg', 'after' => 'https://cdn.x/a.jpg'],
        ]));

        $out = (new TransformationAssetSourcer)->source($this->asset(), ['assets_dir' => $this->dir, 'base_url' => 'https://site.com/assets']);

        $this->assertCount(2, $out);
        $this->assertSame('https://site.com/assets/b1.jpg', $out[0]['before_url']);
        $this->assertSame('12 weeks', $out[0]['weeks']);
        $this->assertSame('https://cdn.x/b.jpg', $out[1]['before_url']);   // absolute kept as-is
    }

    public function test_explicit_opts_win(): void
    {
        $out = (new TransformationAssetSourcer)->source($this->asset(), ['transformations' => [
            ['name' => 'Sue', 'result' => '-30 lbs', 'before_url' => 'https://x/b.jpg', 'after_url' => 'https://x/a.jpg'],
        ]]);

        $this->assertCount(1, $out);
        $this->assertSame('Sue', $out[0]['name']);
    }

    public function test_file_scan_convention(): void
    {
        foreach (['before-1.jpg', 'after-1.jpg', 'before-2.png', 'after-2.png'] as $f) {
            file_put_contents($this->dir.'/'.$f, 'x');
        }
        $out = (new TransformationAssetSourcer)->source($this->asset(), ['assets_dir' => $this->dir, 'base_url' => 'https://s/a']);

        $this->assertCount(2, $out);
        $this->assertSame('https://s/a/before-1.jpg', $out[0]['before_url']);
        $this->assertSame('https://s/a/after-2.png', $out[1]['after_url']);
    }

    public function test_empty_when_no_assets(): void
    {
        $this->assertSame([], (new TransformationAssetSourcer)->source($this->asset(), ['assets_dir' => $this->dir]));
    }
}
