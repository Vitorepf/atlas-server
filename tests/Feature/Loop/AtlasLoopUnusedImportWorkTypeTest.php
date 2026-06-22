<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopUnusedImportWorkType;
use PhpParser\ParserFactory;
use Tests\TestCase;

/**
 * A second deterministic, provider-less work-type: certify + remove provably-unused `use` imports. Pins the
 * load-bearing guarantees — it removes only imports whose short-name appears EXACTLY ONCE (the use line
 * itself), preserves every referenced import (incl. aliased + docblock + return-type uses), yields valid PHP,
 * and FAILS CLOSED on a clean file.
 */
final class AtlasLoopUnusedImportWorkTypeTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/atlas-uimp-'.bin2hex(random_bytes(5));
        @mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.'/*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function write(string $php): string
    {
        file_put_contents($this->dir.'/Sample.php', $php);

        return $this->dir.'/Sample.php';
    }

    private function parses(string $php): bool
    {
        try {
            return (new ParserFactory)->createForHostVersion()->parse($php) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    public function test_removes_an_unused_import_preserving_used_ones(): void
    {
        $this->write(<<<'PHP'
<?php

namespace Foo;

use App\Used\Thing;
use App\Unused\Gone;
use App\Aliased\Long as Keep;

class Sample
{
    public function go(): Thing
    {
        return new Thing($this->make());
    }

    private function make(): Keep
    {
        return new Keep();
    }
}
PHP);

        $result = (new AtlasLoopUnusedImportWorkType)->produceCertifiedRemoval($this->dir, 'Sample.php');

        $this->assertNotNull($result);
        $this->assertTrue($result['certified']);
        $this->assertFalse($result['provider_used']);
        $this->assertSame(['Gone'], $result['removed'], 'only the unused import is removed');
        $this->assertStringNotContainsString('App\Unused\Gone', $result['proposed'], 'the unused import line is gone');
        $this->assertStringContainsString('use App\Used\Thing;', $result['proposed'], 'a used import is preserved');
        $this->assertStringContainsString('use App\Aliased\Long as Keep;', $result['proposed'], 'an aliased-but-used import is preserved');
        $this->assertTrue($this->parses($result['proposed']), 'the cleaned source is valid PHP');
    }

    public function test_fails_closed_when_every_import_is_used(): void
    {
        $this->write(<<<'PHP'
<?php

namespace Foo;

use App\Used\Thing;

class Sample
{
    public function go(): Thing
    {
        return new Thing();
    }
}
PHP);

        $this->assertNull(
            (new AtlasLoopUnusedImportWorkType)->produceCertifiedRemoval($this->dir, 'Sample.php'),
            'no unused imports => null (no fake removal)'
        );
    }
}
