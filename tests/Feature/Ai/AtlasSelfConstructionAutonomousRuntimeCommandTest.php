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

    public function test_heartbeat_ledger_dry_run_without_ledger(): void
    {
        $factsFile = $this->writeFactsFile([
            'heartbeat' => $this->validHeartbeat(),
        ]);

        Artisan::call('atlas:self-construction:runtime', [
            'action' => 'heartbeat-ledger',
            '--facts' => $factsFile,
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame('dry_run', $payload['status']);
        $this->assertArrayHasKey('heartbeat', $payload);
    }

    public function test_heartbeat_ledger_appends_via_validated_organ_when_payload_valid(): void
    {
        $ledgerPath = sys_get_temp_dir().'/autonomous-runtime-ledger-'.uniqid('', true).'.jsonl';
        try {
            $factsFile = $this->writeFactsFile([
                'heartbeat' => $this->validHeartbeat(),
            ]);

            $exit = Artisan::call('atlas:self-construction:runtime', [
                'action' => 'heartbeat-ledger',
                '--facts' => $factsFile,
                '--ledger' => $ledgerPath,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame(0, $exit);
            $this->assertSame('ok', $payload['status']);
            $this->assertTrue($payload['appended']);
            $this->assertSame($ledgerPath, $payload['wrote']);
            $this->assertFileExists($ledgerPath);

            $row = json_decode(trim((string) file_get_contents($ledgerPath)), true);
            $this->assertSame('atlas.autonomous_runtime.heartbeat.v1', $row['schema_version']);
            $this->assertSame('cycle-7', $row['cycle_id']);
            $this->assertSame('running', $row['state']);
            $this->assertSame('proceed', $row['decision']);
            $this->assertSame('safe', $row['safety_verdict']);
            $this->assertSame('plan-hash-xyz', $row['plan_hash']);
            $this->assertSame(['ref-a', 'ref-b'], $row['evidence_refs']);
            $this->assertIsInt($row['ts_unix']);
        } finally {
            @unlink($ledgerPath);
        }
    }

    public function test_heartbeat_ledger_blocked_when_required_fields_missing(): void
    {
        $ledgerPath = sys_get_temp_dir().'/autonomous-runtime-ledger-'.uniqid('', true).'.jsonl';
        try {
            // Missing ts_unix, plan_hash, evidence_refs.
            $factsFile = $this->writeFactsFile([
                'heartbeat' => [
                    'cycle_id' => 'cycle-7',
                    'state' => 'running',
                    'decision' => 'proceed',
                    'safety_verdict' => 'safe',
                ],
            ]);

            Artisan::call('atlas:self-construction:runtime', [
                'action' => 'heartbeat-ledger',
                '--facts' => $factsFile,
                '--ledger' => $ledgerPath,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame('heartbeat_invalid', $payload['status']);
            $this->assertNotEmpty($payload['blockers']);
            // The file must NEVER be touched on a blocked verdict.
            $this->assertFileDoesNotExist($ledgerPath);
        } finally {
            @unlink($ledgerPath);
        }
    }

    public function test_heartbeat_ledger_blocked_when_evidence_refs_not_list(): void
    {
        $ledgerPath = sys_get_temp_dir().'/autonomous-runtime-ledger-'.uniqid('', true).'.jsonl';
        try {
            $factsFile = $this->writeFactsFile([
                'heartbeat' => array_merge($this->validHeartbeat(), ['evidence_refs' => 'not-a-list']),
            ]);

            Artisan::call('atlas:self-construction:runtime', [
                'action' => 'heartbeat-ledger',
                '--facts' => $factsFile,
                '--ledger' => $ledgerPath,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame('heartbeat_invalid', $payload['status']);
            $this->assertContains('evidence_refs_not_list', $payload['blockers']);
        } finally {
            @unlink($ledgerPath);
        }
    }

    public function test_inspect_verb_lists_heartbeat_ledger_in_supported_verbs(): void
    {
        $exit = Artisan::call('atlas:self-construction:runtime', [
            'action' => 'inspect',
            '--json' => true,
        ]);
        $payload = json_decode(trim(Artisan::output()), true);

        $this->assertSame(0, $exit);
        $this->assertContains('heartbeat-ledger', $payload['verbs']);
    }

    public function test_heartbeat_ledger_without_facts_returns_usage_error(): void
    {
        $ledgerPath = sys_get_temp_dir().'/autonomous-runtime-ledger-'.uniqid('', true).'.jsonl';
        $empty = tempnam(sys_get_temp_dir(), 'autonomous-runtime-empty-');

        try {
            Artisan::call('atlas:self-construction:runtime', [
                'action' => 'heartbeat-ledger',
                '--facts' => $empty,
                '--ledger' => $ledgerPath,
                '--json' => true,
            ]);
            $payload = json_decode(trim(Artisan::output()), true);

            $this->assertSame('usage_error', $payload['status']);
        } finally {
            @unlink($empty);
            @unlink($ledgerPath);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function validHeartbeat(): array
    {
        return [
            'cycle_id' => 'cycle-7',
            'state' => 'running',
            'decision' => 'proceed',
            'safety_verdict' => 'safe',
            'plan_hash' => 'plan-hash-xyz',
            'evidence_refs' => ['ref-a', 'ref-b'],
            'ts_unix' => time(),
        ];
    }

    /**
     * @param  array<string,mixed>  $facts
     */
    private function writeFactsFile(array $facts): string
    {
        $path = tempnam(sys_get_temp_dir(), 'autonomous-runtime-facts-');
        file_put_contents($path, (string) json_encode($facts, JSON_UNESCAPED_SLASHES));

        return $path;
    }

}
