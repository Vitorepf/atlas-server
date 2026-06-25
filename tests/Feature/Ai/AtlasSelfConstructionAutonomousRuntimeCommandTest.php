<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeOrganPipelineComposer;
use App\Services\Ai\SelfConstruction\AutonomousRuntime\AtlasAutonomousRuntimeSafetyStopGate;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves atlas:self-construction:runtime: inspect, cycle, safety, plan are read-only; heartbeat is
 * dry_run without --ledger and appends a row when --ledger is supplied; unknown action returns
 * unknown_action.
 */
final class AtlasSelfConstructionAutonomousRuntimeCommandTest extends TestCase
{
    private string $factsPath;

    private string $ledgerPath;

    protected function setUp(): void
    {
        parent::setUp();
        $tag = bin2hex(random_bytes(6));
        $this->factsPath = sys_get_temp_dir().'/atlas_runtime_facts_'.$tag.'.json';
        $this->ledgerPath = sys_get_temp_dir().'/atlas_runtime_hb_'.$tag.'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->factsPath);
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function writeJson(string $path, array $data): void
    {
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }

    private function allOrgans(): array
    {
        $out = [];
        foreach (AtlasAutonomousRuntimeOrganPipelineComposer::ORGAN_ORDER as $o) {
            $out[$o] = ['observed_at_unix' => 1];
        }

        return $out;
    }

    public function test_inspect_lists_services_and_non_execution_guarantees(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime', ['action' => 'inspect', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(0, $exit);
        $this->assertContains(AtlasAutonomousRuntimeOrganPipelineComposer::SCHEMA, $p['services']);
        $this->assertFalse($p['non_execution_guarantees']['starts_workers']);
    }

    public function test_cycle_composes_pipeline_from_organ_facts(): void
    {
        $this->writeJson($this->factsPath, ['organ_facts' => $this->allOrgans()]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'cycle', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasAutonomousRuntimeOrganPipelineComposer::STATUS_READY, $p['cycle_plan']['plan_status']);
    }

    public function test_safety_returns_stop_on_red_court_verdict(): void
    {
        $this->writeJson($this->factsPath, ['safety_facts' => [
            'verification_court' => ['verdict' => 'failed', 'server_side_green' => false],
            'rollback_gate' => ['conformant' => true],
        ]]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'safety', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame(AtlasAutonomousRuntimeSafetyStopGate::ACTION_STOP, $p['safety_verdict']['action']);
    }

    public function test_plan_returns_both_cycle_plan_and_safety_verdict(): void
    {
        $this->writeJson($this->factsPath, [
            'organ_facts' => $this->allOrgans(),
            'safety_facts' => ['verification_court' => ['verdict' => 'passed', 'server_side_green' => true], 'rollback_gate' => ['conformant' => true]],
        ]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'plan', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertArrayHasKey('cycle_plan', $p);
        $this->assertArrayHasKey('safety_verdict', $p);
    }

    public function test_heartbeat_dry_run_without_ledger(): void
    {
        $this->writeJson($this->factsPath, ['heartbeat' => ['ts_iso8601' => '2026-06-25T00:00:00Z', 'cycle_id' => 'cyc-1']]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'heartbeat', '--facts' => $this->factsPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('dry_run', $p['status']);
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_heartbeat_appends_when_ledger_supplied_and_payload_valid(): void
    {
        $this->writeJson($this->factsPath, ['heartbeat' => ['ts_iso8601' => '2026-06-25T00:00:00Z', 'cycle_id' => 'cyc-2']]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'heartbeat', '--facts' => $this->factsPath, '--ledger' => $this->ledgerPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('ok', $p['status']);
        $this->assertFileExists($this->ledgerPath);
        $this->assertCount(1, file($this->ledgerPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function test_heartbeat_invalid_payload_yields_heartbeat_invalid(): void
    {
        $this->writeJson($this->factsPath, ['heartbeat' => ['cycle_id' => 'no-ts']]);
        Artisan::call('atlas:self-construction:runtime', ['action' => 'heartbeat', '--facts' => $this->factsPath, '--ledger' => $this->ledgerPath, '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertSame('heartbeat_invalid', $p['status']);
        $this->assertFileDoesNotExist($this->ledgerPath);
    }

    public function test_unknown_action_returns_unknown_action(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime', ['action' => 'bogus', '--json' => true]);
        $p = json_decode(trim(Artisan::output()), true);
        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_action', $p['status']);
    }
}
