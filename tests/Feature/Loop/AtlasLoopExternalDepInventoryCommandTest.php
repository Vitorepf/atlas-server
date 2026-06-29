<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the external-dependency inventory reporter is live at the operator surface: the command emits the
 * deterministic inventory facts (packages + presence flags) over the project's real composer manifest.
 */
final class AtlasLoopExternalDepInventoryCommandTest extends TestCase
{
    public function test_external_dep_inventory_emits_packages_and_presence_flags(): void
    {
        $exit = Artisan::call('atlas:loop:external-dep-inventory', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertIsArray($decoded['packages']);
        $this->assertTrue($decoded['composer_json_present'], 'the project composer.json is present');
        $this->assertArrayHasKey('lock_present', $decoded);
        $this->assertNotEmpty($decoded['packages'], 'the real composer.json declares dependencies');

        $first = $decoded['packages'][0];
        foreach (['name', 'dependency_type', 'declared_constraint', 'resolved_version'] as $key) {
            $this->assertArrayHasKey($key, $first);
        }
    }
}
