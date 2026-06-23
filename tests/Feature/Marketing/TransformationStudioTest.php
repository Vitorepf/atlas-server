<?php

namespace Tests\Feature\Marketing;

use App\Models\AiMarketingVslAsset;
use App\Services\Ai\MarketingDomain\Content\TransformationAssetSourcer;
use App\Services\Ai\MarketingDomain\Content\TransformationStudio;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Locks the ingest half of the before/after flow: the studio downloads the producer's real
 * before/after creatives into the offer's assets dir, writes the manifest, skips incomplete pairs,
 * and the sourcer reads the result straight back — so the engine builds before/after pages from real,
 * release-approved photos with no manual wiring.
 */
class TransformationStudioTest extends TestCase
{
    public function test_ingests_real_pairs_and_the_sourcer_reads_them_back(): void
    {
        Http::fake([
            '*before*' => Http::response('JPEGBYTES', 200, ['Content-Type' => 'image/jpeg']),
            '*after*' => Http::response('PNGBYTES', 200, ['Content-Type' => 'image/png']),
        ]);

        $root = sys_get_temp_dir().'/ts-'.bin2hex(random_bytes(4));
        $studio = new TransformationStudio;

        $res = $studio->ingest('Lipo Bliss', [
            ['name' => 'Amy R.', 'result' => '-41 lbs', 'weeks' => '12 weeks', 'before_url' => 'https://res.center/before-amy.jpg', 'after_url' => 'https://res.center/after-amy.png'],
            ['name' => 'Incomplete', 'before_url' => 'https://res.center/before-x.jpg'],   // no after → skipped
        ], ['assets_root' => $root]);

        $this->assertSame(1, $res['ingested']);
        $this->assertSame(1, $res['skipped']);
        $this->assertFileExists($res['dir'].'/transformations.json');
        $this->assertFileExists($res['dir'].'/before-1.jpg');
        $this->assertFileExists($res['dir'].'/after-1.png');

        $sourced = (new TransformationAssetSourcer)->source(
            new AiMarketingVslAsset(['offer' => ['product_name' => 'Lipo Bliss']]),
            ['assets_dir' => $res['dir'], 'base_url' => 'https://mysite.com/a']
        );
        $this->assertCount(1, $sourced);
        $this->assertSame('Amy R.', $sourced[0]['name']);
        $this->assertSame('https://mysite.com/a/before-1.jpg', $sourced[0]['before_url']);

        array_map('unlink', glob($res['dir'].'/*') ?: []);
        @rmdir($res['dir']);
    }

    public function test_non_image_response_is_rejected(): void
    {
        Http::fake(['*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);
        $root = sys_get_temp_dir().'/ts-'.bin2hex(random_bytes(4));

        $res = (new TransformationStudio)->ingest('Test Offer', [
            ['name' => 'X', 'before_url' => 'https://x/b.jpg', 'after_url' => 'https://x/a.jpg'],
        ], ['assets_root' => $root]);

        $this->assertSame(0, $res['ingested']);
        @unlink($res['dir'].'/transformations.json');
        @rmdir($res['dir']);
    }
}
