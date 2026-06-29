<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceBundleService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the final-evidence replay service is live at the operator surface: an inconsistent completion bundle
 * replays to status=blocked, replay_green=false, with named violation codes — and the command never executes.
 */
final class AtlasLoopFinalEvidenceReplayCommandTest extends TestCase
{
    private string $bundlePath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bundlePath = sys_get_temp_dir().'/atlas-final-evidence-'.bin2hex(random_bytes(5)).'.json';
    }

    protected function tearDown(): void
    {
        @unlink($this->bundlePath);
        parent::tearDown();
    }

    private function replay(): array
    {
        $exit = Artisan::call('atlas:loop:final-evidence-replay', ['--bundle' => $this->bundlePath, '--json' => true]);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_invalid_schema_bundle_is_blocked(): void
    {
        file_put_contents($this->bundlePath, (string) json_encode(['schema_version' => 'WRONG.v0']));

        ['exit' => $exit, 'd' => $d] = $this->replay();

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', $d['status']);
        $this->assertFalse($d['replay_green']);
        $this->assertGreaterThan(0, $d['violation_count']);
        $this->assertContains('final_bundle_schema_invalid', array_column($d['violations'], 'code'), (string) json_encode($d));
        // read-only invariant surfaced in the verdict
        $this->assertFalse($d['execution_allowed']);
    }

    public function test_valid_schema_but_missing_components_is_blocked(): void
    {
        file_put_contents($this->bundlePath, (string) json_encode([
            'schema_version' => AtlasSelfConstructionFinalEvidenceBundleService::SCHEMA_VERSION,
        ]));

        ['exit' => $exit, 'd' => $d] = $this->replay();

        $this->assertSame(0, $exit);
        $this->assertSame('blocked', $d['status']);
        $codes = array_column($d['violations'], 'code');
        $this->assertContains('required_final_bundle_component_missing', $codes, (string) json_encode($d));
        $this->assertNotContains('final_bundle_schema_invalid', $codes); // schema_version was correct
    }

    public function test_missing_bundle_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:final-evidence-replay', ['--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
