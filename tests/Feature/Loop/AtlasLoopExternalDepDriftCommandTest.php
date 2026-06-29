<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the external-dependency drift detector is live at the operator surface: the command emits the
 * deterministic drift facts over the project's composer manifest. The snapshot root is a temp dir so the test
 * writes nothing real.
 */
final class AtlasLoopExternalDepDriftCommandTest extends TestCase
{
    private string $root = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-dep-drift-'.bin2hex(random_bytes(5));
        config(['atlas.loop.external_deps.snapshot_root' => $this->root]);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach ((array) glob($this->root.'/*') as $file) {
                @unlink((string) $file);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    public function test_external_dep_drift_emits_drift_facts(): void
    {
        $exit = Artisan::call('atlas:loop:external-dep-drift', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.loop.external_dep_drift.v1', $decoded['schema_version']);
        foreach (['added_packages', 'removed_packages', 'version_changed', 'content_hash_changed', 'unexpected'] as $key) {
            $this->assertArrayHasKey($key, $decoded);
        }
    }
}
