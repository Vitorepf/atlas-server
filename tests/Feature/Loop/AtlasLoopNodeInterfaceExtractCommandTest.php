<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the node-interface extractor is live at the operator surface: it parses a PHP file and reports its
 * exported surface (type, public methods, extends/implements, imports, container service-refs); a missing file
 * yields a parsed=false empty surface.
 */
final class AtlasLoopNodeInterfaceExtractCommandTest extends TestCase
{
    private string $file = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = sys_get_temp_dir().'/atlas-node-iface-'.bin2hex(random_bytes(5)).'.php';
        file_put_contents($this->file, <<<'PHP'
            <?php
            namespace Fixture\Node;
            use App\Contracts\Thing;
            use App\Services\Helper;
            final class Widget extends BaseWidget implements Thing {
                public function alpha(): void { app(Helper::class); }
                public function beta(): void {}
                private function secret(): void {}
            }
            PHP);
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    public function test_extracts_exported_interface(): void
    {
        $exit = Artisan::call('atlas:loop:node-interface-extract', ['--file' => $this->file, '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.node_interface.v1', $decoded['schema']);
        $this->assertTrue($decoded['parsed']);
        $this->assertSame('Fixture\\Node', $decoded['namespace']);

        $this->assertCount(1, $decoded['types'], (string) json_encode($decoded));
        $type = $decoded['types'][0];
        $this->assertSame('Fixture\\Node\\Widget', $type['fqn']);
        $this->assertSame('class', $type['kind']);
        $this->assertSame(['alpha', 'beta'], $type['public_methods']); // private excluded, sorted
        $this->assertSame(['Thing'], $type['implements']);
        $this->assertSame(['BaseWidget'], $type['extends']);

        $this->assertContains('App\\Contracts\\Thing', $decoded['imports']);
        $this->assertContains('Helper', $decoded['service_refs']); // app(Helper::class) is AST-visible
    }

    public function test_missing_file_yields_unparsed_empty_surface(): void
    {
        $exit = Artisan::call('atlas:loop:node-interface-extract', [
            '--file' => '/nonexistent/atlas-node-iface-missing.php',
            '--json' => true,
        ]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertFalse($decoded['parsed']);
        $this->assertSame([], $decoded['types']);
    }

    public function test_missing_file_option_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:node-interface-extract', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
