<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerClaimExecuteReportCycle;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the native-worker claim-execute-report cycle is live at the operator surface: it emits the structured
 * planned-step cycle and (preview-only) performs no claim/execution — dry_run is forced even when the supplied
 * options ask to apply.
 */
final class AtlasLoopClaimCyclePreviewCommandTest extends TestCase
{
    private function preview(array $options = []): array
    {
        $params = ['--json' => true];
        if ($options !== []) {
            $params['--options'] = (string) json_encode($options);
        }
        $exit = Artisan::call('atlas:loop:claim-cycle-preview', $params);

        return ['exit' => $exit, 'd' => json_decode(trim(Artisan::output()), true)];
    }

    public function test_default_preview_performs_no_claim_or_execution(): void
    {
        ['exit' => $exit, 'd' => $d] = $this->preview();

        $this->assertSame(0, $exit);
        $this->assertSame(AtlasNativeWorkerClaimExecuteReportCycle::SCHEMA, $d['schema_version']);
        $this->assertSame(AtlasNativeWorkerClaimExecuteReportCycle::STATUS_OK, $d['status'], (string) json_encode($d));
        $this->assertTrue($d['dry_run']);
        $this->assertNotEmpty($d['planned_steps']);  // structured plan
        $this->assertSame([], $d['applied_steps']);   // nothing claimed/executed
    }

    public function test_apply_request_is_forced_to_dry_run(): void
    {
        ['d' => $d] = $this->preview(['dry_run' => false, 'action_labels' => ['build_execution_envelope']]);

        $this->assertTrue($d['dry_run'], (string) json_encode($d));
        $this->assertSame([], $d['applied_steps']);
    }

    public function test_invalid_options_is_usage_error(): void
    {
        $exit = Artisan::call('atlas:loop:claim-cycle-preview', ['--options' => 'not json', '--json' => true]);

        $this->assertNotSame(0, $exit);
        $this->assertStringContainsString('usage_error', Artisan::output());
    }
}
