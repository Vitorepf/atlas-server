<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the real-provider smoke closure execution pack builder is live at the operator surface: with default
 * options it emits the pack carrying its schema/mode/blocker and the expected pack sections; invalid --options
 * is a usage error.
 */
final class AtlasLoopSmokePackCommandTest extends TestCase
{
    public function test_builds_pack_with_schema_and_sections(): void
    {
        $exit = Artisan::call('atlas:loop:smoke-pack', ['--json' => true]);
        $d = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::SCHEMA_VERSION, $d['schema_version']);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::MODE, $d['mode']);
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeClosureExecutionPackService::BLOCKER_ID, $d['blocker_id']);

        // expected pack sections present
        foreach (['status', 'provider_smoke_template', 'required_evidence_fields', 'runbook', 'operator_checklist', 'exact_commands', 'final_evidence_bundle'] as $section) {
            $this->assertArrayHasKey($section, $d, "missing pack section: {$section}");
        }
    }

    public function test_invalid_options_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:smoke-pack', ['--options' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
