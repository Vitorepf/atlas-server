<?php

namespace Tests\Feature\Ai\RuntimeReadiness;

use App\Services\Ai\RuntimeReadiness\AtlasAiRuntimeReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class AtlasAiRuntimeReadinessCommandTest extends TestCase
{
    /**
     * @return array{exit:int, json:array<string,mixed>|null, output:string}
     */
    private function runCmd(array $params): array
    {
        $exit = Artisan::call('atlas:ai:runtime-readiness', array_merge($params, ['--json' => true]));
        $output = Artisan::output();
        $json = json_decode($output, true);

        return ['exit' => $exit, 'json' => is_array($json) ? $json : null, 'output' => $output];
    }

    public function test_json_output_carrega_report_canon(): void
    {
        $result = $this->runCmd([]);

        $this->assertNotNull($result['json'], 'command must emit valid JSON');
        $this->assertArrayHasKey('action', $result['json']);
        $this->assertSame('runtime-readiness', $result['json']['action']);
        $this->assertArrayHasKey('report', $result['json']);
        $report = $result['json']['report'];

        $this->assertSame('atlas.ai.runtime_readiness.v1', $report['schema_version']);
        $this->assertContains($report['status'], ['ready', 'partial', 'blocked']);
        $this->assertArrayHasKey('certification_hash', $report);
        $this->assertSame(64, strlen($report['certification_hash']));
    }

    public function test_strict_flag_é_zero_apenas_quando_ready(): void
    {
        $result = $this->runCmd(['--strict' => true]);
        $status = $result['json']['report']['status'] ?? null;

        if ($status === AtlasAiRuntimeReadinessService::STATUS_READY) {
            $this->assertSame(0, $result['exit'], 'strict + ready → exit 0');
        } else {
            $this->assertSame(3, $result['exit'], 'strict + não-ready → exit 3 (gate falhou)');
        }
    }

    public function test_sem_strict_não_aplica_gate_exit_code(): void
    {
        $result = $this->runCmd([]);
        // Mesmo se status=partial/blocked, sem --strict exit deve ser 0.
        $this->assertSame(0, $result['exit'], 'sem --strict → exit sempre 0 se não houve exception');
    }

    public function test_payload_carrega_ok_flag_quando_ready(): void
    {
        $result = $this->runCmd([]);
        $status = $result['json']['report']['status'];
        $ok = $result['json']['ok'];

        $this->assertSame($status === AtlasAiRuntimeReadinessService::STATUS_READY, $ok);
    }

    public function test_strict_é_refletido_no_payload(): void
    {
        $resultDefault = $this->runCmd([]);
        $resultStrict = $this->runCmd(['--strict' => true]);

        $this->assertFalse($resultDefault['json']['strict']);
        $this->assertTrue($resultStrict['json']['strict']);
    }

    public function test_blockers_warnings_estão_no_payload(): void
    {
        $result = $this->runCmd([]);
        $report = $result['json']['report'];

        $this->assertArrayHasKey('blockers', $report);
        $this->assertArrayHasKey('warnings', $report);
        $this->assertIsArray($report['blockers']);
        $this->assertIsArray($report['warnings']);
    }
}
